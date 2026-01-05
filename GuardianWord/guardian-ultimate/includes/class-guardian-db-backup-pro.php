<?php

namespace Guardian;

/**
 * Backup Pro: chunked/resumable DB snapshot + step restore.
 *
 * Storage:
 * - jobs: uploads/guardian/db-pro/jobs/<jobId>.json
 * - dumps: uploads/guardian/db-pro/dumps/<restorePointId>/<table>/chunk-000001.sql.gz
 * - schema: uploads/guardian/db-pro/dumps/<restorePointId>/schema.sql.gz
 */
final class DbBackupPro {
	private Storage $storage;

	public function __construct(Storage $storage) {
		$this->storage = $storage;
	}

	public function start_export_job(array $opts): array {
		$rpId = sanitize_file_name((string) ($opts['restore_point_id'] ?? ''));
		if ($rpId === '') {
			$rpId = gmdate('Ymd-His') . '-rp';
		}
		$jobId = gmdate('Ymd-His') . '-dbpro-' . wp_generate_password(6, false, false);

		$tablesMode = (string) ($opts['tables_mode'] ?? 'wp_core');
		$customTables = (string) ($opts['custom_tables'] ?? '');
		$chunkRows = (int) ($opts['chunk_rows'] ?? 500);
		if ($chunkRows < 100) {
			$chunkRows = 100;
		}
		$maxSeconds = (int) ($opts['max_seconds'] ?? 20);
		if ($maxSeconds < 5) {
			$maxSeconds = 5;
		}

		$tables = (new DbBackup($this->storage))->select_tables_public($tablesMode, $customTables);
		$job = [
			'id' => $jobId,
			'restore_point_id' => $rpId,
			'status' => 'pending',
			'created_gm' => gmdate('c'),
			'updated_gm' => gmdate('c'),
			'tables_mode' => $tablesMode,
			'custom_tables' => $customTables,
			'chunk_rows' => $chunkRows,
			'max_seconds' => $maxSeconds,
			'tables' => $tables,
			'cursor' => [
				'table_index' => 0,
				'offset' => 0,
				'schema_done' => false,
			],
			'counts' => [
				'tables' => 0,
				'chunks' => 0,
				'rows' => 0,
				'truncated' => 0,
			],
			'errors' => [],
		];

		$this->ensure_dirs($rpId);
		$this->write_job($jobId, $job);

		return [
			'ok' => true,
			'engine' => 'pro',
			'job_id' => $jobId,
			'status' => 'pending',
			'restore_point_id' => $rpId,
		];
	}

	/**
	 * Continue export job within time budget.
	 */
	public function continue_export_job(string $jobId): array {
		global $wpdb;

		$job = $this->read_job($jobId);
		if (!$job) {
			return ['ok' => false, 'message' => 'job not found'];
		}
		if (($job['status'] ?? '') === 'done') {
			return ['ok' => true, 'message' => 'already done', 'job' => $job];
		}
		$rpId = (string) ($job['restore_point_id'] ?? '');
		$tables = isset($job['tables']) && is_array($job['tables']) ? $job['tables'] : [];
		$cursor = isset($job['cursor']) && is_array($job['cursor']) ? $job['cursor'] : [];
		$ti = (int) ($cursor['table_index'] ?? 0);
		$offset = (int) ($cursor['offset'] ?? 0);
		$schemaDone = !empty($cursor['schema_done']);
		$chunkRows = (int) ($job['chunk_rows'] ?? 500);
		$maxSeconds = (int) ($job['max_seconds'] ?? 20);
		if ($maxSeconds < 5) {
			$maxSeconds = 5;
		}

		$start = microtime(true);
		$this->ensure_dirs($rpId);

		if (!$schemaDone) {
			$schemaPath = $this->schema_path($rpId);
			$gz = gzopen($schemaPath, 'wb6');
			if ($gz) {
				gzwrite($gz, "-- Guardian Ultimate DB Pro schema\n");
				gzwrite($gz, "-- Created: " . gmdate('c') . " UTC\n");
				gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n");
				foreach ($tables as $table) {
					$table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
					if ($table === '') {
						continue;
					}
					$createRow = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
					if (!is_array($createRow) || empty($createRow[1])) {
						continue;
					}
					$createSql = (string) $createRow[1];
					gzwrite($gz, "\nDROP TABLE IF EXISTS `{$table}`;\n");
					gzwrite($gz, $createSql . ";\n");
				}
				gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
				gzclose($gz);
				$schemaDone = true;
				$job['cursor']['schema_done'] = true;
			} else {
				$job['errors'][] = 'cannot write schema';
			}
		}

		// Dump data in chunks.
		while ($ti < count($tables)) {
			if ((microtime(true) - $start) > $maxSeconds) {
				$job['counts']['truncated'] = 1;
				break;
			}

			$table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $tables[$ti]);
			if ($table === '') {
				$ti++;
				$offset = 0;
				continue;
			}

			$rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT {$chunkRows} OFFSET {$offset}", ARRAY_A);
			if (!$rows) {
				$job['counts']['tables'] = max((int) ($job['counts']['tables'] ?? 0), $ti + 1);
				$ti++;
				$offset = 0;
				continue;
			}

			$chunkIndex = (int) floor($offset / $chunkRows) + 1;
			$chunkPath = $this->chunk_path($rpId, $table, $chunkIndex);
			wp_mkdir_p(dirname($chunkPath));
			$gz = gzopen($chunkPath, 'wb6');
			if (!$gz) {
				$job['errors'][] = "cannot write chunk {$table} {$chunkIndex}";
				$offset += $chunkRows;
				continue;
			}
			gzwrite($gz, "-- Table: {$table} chunk {$chunkIndex}\n");
			foreach ($rows as $row) {
				$cols = array_keys($row);
				$vals = [];
				foreach ($cols as $c) {
					$vals[] = $this->sql_value($row[$c]);
				}
				$colList = '`' . implode('`,`', array_map('strval', $cols)) . '`';
				$valList = implode(',', $vals);
				gzwrite($gz, "INSERT INTO `{$table}` ({$colList}) VALUES ({$valList});\n");
				$job['counts']['rows'] = (int) ($job['counts']['rows'] ?? 0) + 1;
			}
			gzclose($gz);

			$job['counts']['chunks'] = (int) ($job['counts']['chunks'] ?? 0) + 1;
			$offset += $chunkRows;
			$job['cursor']['table_index'] = $ti;
			$job['cursor']['offset'] = $offset;
			$job['updated_gm'] = gmdate('c');
			$this->write_job($jobId, $job);
		}

		if ($schemaDone && $ti >= count($tables)) {
			$job['status'] = 'done';
			$job['updated_gm'] = gmdate('c');
			$this->write_job($jobId, $job);
		} else {
			$job['status'] = 'pending';
			$job['updated_gm'] = gmdate('c');
			$this->write_job($jobId, $job);
		}

		return ['ok' => true, 'job' => $job];
	}

	public function build_manifest_meta_from_job(string $jobId): array {
		$job = $this->read_job($jobId);
		if (!$job) {
			return ['ok' => false, 'engine' => 'pro', 'status' => 'missing'];
		}
		$rpId = (string) ($job['restore_point_id'] ?? '');
		$status = (string) ($job['status'] ?? 'pending');
		return [
			'ok' => $status === 'done',
			'engine' => 'pro',
			'status' => $status,
			'job_id' => $jobId,
			'restore_point_id' => $rpId,
			'schema' => $this->schema_path($rpId),
			'root' => $this->dump_root($rpId),
			'counts' => $job['counts'] ?? [],
		];
	}

	public function restore_from_manifest(array $dbMeta): array {
		global $wpdb;
		$root = isset($dbMeta['root']) && is_string($dbMeta['root']) ? $dbMeta['root'] : '';
		$schema = isset($dbMeta['schema']) && is_string($dbMeta['schema']) ? $dbMeta['schema'] : '';
		if ($root === '' || !is_dir($root) || $schema === '' || !file_exists($schema)) {
			return ['ok' => false, 'message' => 'missing pro dump'];
		}

		// Apply schema.
		$rSchema = $this->exec_sql_gz($schema);
		if (empty($rSchema['ok'])) {
			return ['ok' => false, 'message' => 'schema failed', 'detail' => $rSchema];
		}

		// Apply chunks.
		$tables = glob($root . '/*', GLOB_ONLYDIR) ?: [];
		sort($tables);
		$chunksApplied = 0;
		$errors = 0;
		foreach ($tables as $tDir) {
			$chunkFiles = glob($tDir . '/chunk-*.sql.gz') ?: [];
			sort($chunkFiles);
			foreach ($chunkFiles as $cf) {
				$r = $this->exec_sql_gz($cf);
				$chunksApplied++;
				if (empty($r['ok'])) {
					$errors++;
				}
			}
		}

		return [
			'ok' => $errors === 0,
			'message' => $errors === 0 ? 'db pro restored' : 'db pro restored with errors',
			'chunks' => $chunksApplied,
			'errors' => $errors,
		];
	}

	private function exec_sql_gz(string $path): array {
		global $wpdb;
		$gz = gzopen($path, 'rb');
		if (!$gz) {
			return ['ok' => false, 'message' => 'cannot open'];
		}
		$buf = '';
		$statements = 0;
		$errors = 0;
		while (!gzeof($gz)) {
			$chunk = gzread($gz, 8192);
			if (!is_string($chunk)) {
				break;
			}
			$buf .= $chunk;
			while (($pos = strpos($buf, ";\n")) !== false) {
				$sql = trim(substr($buf, 0, $pos + 1));
				$buf = substr($buf, $pos + 2);
				if ($sql === '' || strpos($sql, '--') === 0) {
					continue;
				}
				$r = $wpdb->query($sql);
				$statements++;
				if ($r === false) {
					$errors++;
				}
			}
		}
		gzclose($gz);
		return ['ok' => $errors === 0, 'statements' => $statements, 'errors' => $errors];
	}

	private function ensure_dirs(string $rpId): void {
		$base = $this->storage->base_dir();
		if (!$base) {
			return;
		}
		wp_mkdir_p($base . '/db-pro/jobs');
		wp_mkdir_p($base . '/db-pro/dumps/' . $rpId);
	}

	private function job_path(string $jobId): ?string {
		$base = $this->storage->base_dir();
		if (!$base) {
			return null;
		}
		return $base . '/db-pro/jobs/' . sanitize_file_name($jobId) . '.json';
	}

	private function dump_root(string $rpId): string {
		$base = $this->storage->base_dir();
		return rtrim((string) $base, '/\\') . '/db-pro/dumps/' . sanitize_file_name($rpId);
	}

	private function schema_path(string $rpId): string {
		return $this->dump_root($rpId) . '/schema.sql.gz';
	}

	private function chunk_path(string $rpId, string $table, int $chunkIndex): string {
		$dir = $this->dump_root($rpId) . '/' . $table;
		return $dir . '/chunk-' . str_pad((string) $chunkIndex, 6, '0', STR_PAD_LEFT) . '.sql.gz';
	}

	private function read_job(string $jobId): ?array {
		$p = $this->job_path($jobId);
		if (!$p || !file_exists($p)) {
			return null;
		}
		$raw = file_get_contents($p);
		if (!is_string($raw) || $raw === '') {
			return null;
		}
		$d = json_decode($raw, true);
		return is_array($d) ? $d : null;
	}

	private function write_job(string $jobId, array $job): void {
		$p = $this->job_path($jobId);
		if (!$p) {
			return;
		}
		wp_mkdir_p(dirname($p));
		file_put_contents($p, wp_json_encode($job), LOCK_EX);
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

