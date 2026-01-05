<?php

namespace Guardian;

/**
 * DB snapshot (best-effort) stored as gzipped SQL.
 *
 * This is intentionally conservative:
 * - limited time budget
 * - limited table set by default
 */
final class DbBackup {
	private Storage $storage;

	public function __construct(Storage $storage) {
		$this->storage = $storage;
	}

	public function export(array $opts): array {
		global $wpdb;

		$start = microtime(true);
		$maxSeconds = isset($opts['max_seconds']) ? (int) $opts['max_seconds'] : 20;
		if ($maxSeconds < 5) {
			$maxSeconds = 5;
		}

		$mode = isset($opts['tables_mode']) ? (string) $opts['tables_mode'] : 'wp_core';
		$mode = in_array($mode, ['wp_core', 'all_prefix', 'custom'], true) ? $mode : 'wp_core';

		$tables = $this->select_tables($mode, (string) ($opts['custom_tables'] ?? ''));
		$id = isset($opts['restore_point_id']) ? sanitize_file_name((string) $opts['restore_point_id']) : gmdate('Ymd-His') . '-db';
		$path = $this->db_dump_path($id);

		$counts = ['tables' => 0, 'rows' => 0, 'statements' => 0, 'truncated' => 0];

		if (!$path) {
			return ['ok' => false, 'message' => 'no path'];
		}
		wp_mkdir_p(dirname($path));

		$gz = gzopen($path, 'wb6');
		if (!$gz) {
			return ['ok' => false, 'message' => 'cannot open dump file'];
		}

		$w = function (string $s) use ($gz): void {
			gzwrite($gz, $s);
		};

		$w("-- Guardian Ultimate DB snapshot\n");
		$w("-- Created: " . gmdate('c') . " UTC\n");
		$w("SET FOREIGN_KEY_CHECKS=0;\n");

		foreach ($tables as $table) {
			if ((microtime(true) - $start) > $maxSeconds) {
				$counts['truncated'] = 1;
				break;
			}

			$table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
			if ($table === '') {
				continue;
			}

			$createRow = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
			if (!is_array($createRow) || empty($createRow[1])) {
				continue;
			}
			$createSql = (string) $createRow[1];

			$w("\n-- Table: {$table}\n");
			$w("DROP TABLE IF EXISTS `{$table}`;\n");
			$w($createSql . ";\n");
			$counts['statements'] += 2;
			$counts['tables']++;

			// Dump data in chunks.
			$offset = 0;
			$limit = 500;

			while (true) {
				if ((microtime(true) - $start) > $maxSeconds) {
					$counts['truncated'] = 1;
					break 2;
				}
				$rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT {$limit} OFFSET {$offset}", ARRAY_A);
				if (!$rows) {
					break;
				}
				foreach ($rows as $row) {
					$cols = array_keys($row);
					$vals = [];
					foreach ($cols as $c) {
						$v = $row[$c];
						$vals[] = $this->sql_value($v);
					}
					$colList = '`' . implode('`,`', array_map('strval', $cols)) . '`';
					$valList = implode(',', $vals);
					$w("INSERT INTO `{$table}` ({$colList}) VALUES ({$valList});\n");
					$counts['rows']++;
					$counts['statements']++;
				}
				$offset += $limit;
			}
		}

		$w("SET FOREIGN_KEY_CHECKS=1;\n");
		gzclose($gz);

		return [
			'ok' => true,
			'path' => $path,
			'mode' => $mode,
			'tables' => $tables,
			'counts' => $counts,
			'created_gm' => gmdate('c'),
		];
	}

	public function restore_from_manifest(array $dbMeta): array {
		global $wpdb;
		$path = isset($dbMeta['path']) && is_string($dbMeta['path']) ? $dbMeta['path'] : '';
		if ($path === '' || !file_exists($path)) {
			return ['ok' => false, 'message' => 'dump not found'];
		}

		$gz = gzopen($path, 'rb');
		if (!$gz) {
			return ['ok' => false, 'message' => 'cannot open dump'];
		}

		$statements = 0;
		$errors = 0;
		$buf = '';
		$inSingle = false;
		$inDouble = false;
		$inBacktick = false;
		$prev = '';

		while (!gzeof($gz)) {
			$chunk = gzread($gz, 8192);
			if (!is_string($chunk)) {
				break;
			}
			$buf .= $chunk;

			// Process buffer character by character to split on semicolons safely.
			$len = strlen($buf);
			$startIdx = 0;
			for ($i = 0; $i < $len; $i++) {
				$ch = $buf[$i];
				if ($ch === "'" && !$inDouble && !$inBacktick && $prev !== '\\') {
					$inSingle = !$inSingle;
				} elseif ($ch === '"' && !$inSingle && !$inBacktick && $prev !== '\\') {
					$inDouble = !$inDouble;
				} elseif ($ch === '`' && !$inSingle && !$inDouble && $prev !== '\\') {
					$inBacktick = !$inBacktick;
				}

				if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
					$sql = trim(substr($buf, $startIdx, $i - $startIdx + 1));
					$startIdx = $i + 1;
					if ($sql === '' || strpos($sql, '--') === 0) {
						continue;
					}
					$r = $wpdb->query($sql);
					$statements++;
					if ($r === false) {
						$errors++;
					}
				}
				$prev = $ch;
			}

			// Keep remaining tail.
			$buf = substr($buf, $startIdx);
		}

		gzclose($gz);
		return [
			'ok' => $errors === 0,
			'message' => $errors === 0 ? 'db restored' : 'db restored with errors',
			'statements' => $statements,
			'errors' => $errors,
		];
	}

	private function db_dump_path(string $id): ?string {
		$base = $this->storage->base_dir();
		if (!$base) {
			return null;
		}
		return $base . '/db/' . sanitize_file_name($id) . '.sql.gz';
	}

	private function select_tables(string $mode, string $customTables): array {
		global $wpdb;
		$prefix = $wpdb->prefix;

		if ($mode === 'custom') {
			$lines = preg_split('/\R/', $customTables);
			$out = [];
			foreach ($lines as $l) {
				$l = trim((string) $l);
				if ($l !== '') {
					$out[] = $l;
				}
			}
			return $out;
		}

		if ($mode === 'all_prefix') {
			$rows = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $prefix . '%'));
			return is_array($rows) ? array_values($rows) : [];
		}

		// wp_core
		$core = [
			$prefix . 'options',
			$prefix . 'users',
			$prefix . 'usermeta',
			$prefix . 'posts',
			$prefix . 'postmeta',
			$prefix . 'terms',
			$prefix . 'term_taxonomy',
			$prefix . 'term_relationships',
			$prefix . 'termmeta',
			$prefix . 'comments',
			$prefix . 'commentmeta',
		];
		// Filter to existing tables.
		$existing = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $prefix . '%'));
		$existing = is_array($existing) ? array_flip($existing) : [];
		$out = [];
		foreach ($core as $t) {
			if (isset($existing[$t])) {
				$out[] = $t;
			}
		}
		return $out;
	}

	private function sql_value($v): string {
		if ($v === null) {
			return 'NULL';
		}
		if (is_bool($v)) {
			return $v ? '1' : '0';
		}
		if (is_int($v) || is_float($v)) {
			return (string) $v;
		}
		$s = (string) $v;
		$s = str_replace("\0", '', $s);
		return "'" . addslashes($s) . "'";
	}
}

