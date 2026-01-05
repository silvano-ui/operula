<?php

namespace Guardian;

/**
 * Incremental restore points (file-level dedup).
 *
 * Storage model:
 * - blobs: uploads/guardian/blobs/aa/<sha256>.gz  (gz of raw bytes)
 * - manifest: uploads/guardian/restore-points/<id>.json.gz
 *
 * MVP scope: files only (DB backup is out-of-scope for now).
 */
final class RestorePoints {
	private Storage $storage;

	public function __construct(Storage $storage) {
		$this->storage = $storage;
	}

	public function create_scheduled_from_settings(array $settings, bool $backupPro = false): ?array {
		$paths = [];
		$exclude = [
			'wp-content/cache/',
			'wp-content/upgrade/',
		];
		if (empty($settings['rp_scope_uploads'])) {
			$exclude[] = 'wp-content/uploads/';
		}
		if (!empty($settings['rp_scope_plugins_themes'])) {
			$paths[] = 'wp-content/plugins';
			$paths[] = 'wp-content/themes';
		}
		if (!empty($settings['rp_scope_wp_config'])) {
			$paths[] = 'wp-config.php';
		}
		if (!empty($settings['rp_scope_core'])) {
			$paths[] = 'wp-admin';
			$paths[] = 'wp-includes';
			// Some root files that matter.
			$paths[] = 'index.php';
			$paths[] = 'wp-load.php';
			$paths[] = 'wp-settings.php';
		}
		if (!empty($settings['rp_scope_uploads'])) {
			$paths[] = 'wp-content/uploads';
		}

		$opts = [
			'include_db' => !empty($settings['rp_include_db']),
			'db_tables_mode' => (string) ($settings['rp_db_tables'] ?? 'wp_core'),
			'db_custom_tables' => (string) ($settings['rp_db_custom_tables'] ?? ''),
			'db_max_seconds' => (int) ($settings['rp_db_max_seconds'] ?? 20),
			'db_engine' => $backupPro ? (string) ($settings['rp_db_engine'] ?? 'basic') : 'basic',
			'backup_pro' => $backupPro,
		];

		return $this->create('scheduled', $paths, $exclude, $opts);
	}

	public function ensure_dirs(): void {
		$base = $this->storage->base_dir();
		if (!$base) {
			return;
		}
		$this->mkdir_p($base . '/restore-points');
		$this->mkdir_p($base . '/blobs');
	}

	public function create(string $label, array $paths, array $excludePrefixes = [], array $opts = []): ?array {
		if (!defined('ABSPATH')) {
			return null;
		}
		$this->ensure_dirs();

		$id = gmdate('Ymd-His') . '-rp-' . wp_generate_password(6, false, false);
		$manifestPath = $this->manifest_path($id);
		if (!$manifestPath) {
			return null;
		}

		$settings = $this->storage->get_settings();
		$maxBlobBytes = (int) ($settings['rp_max_blob_bytes'] ?? (20 * 1024 * 1024)); // 20MB default
		if ($maxBlobBytes <= 0) {
			$maxBlobBytes = 20 * 1024 * 1024;
		}

		$files = [];
		$counts = ['files' => 0, 'blobs_new' => 0, 'skipped_large' => 0, 'missing' => 0];

		foreach ($paths as $p) {
			$p = (string) $p;
			$p = $this->abs_path($p);
			if (!$p) {
				continue;
			}
			$scanned = $this->scan_path($p, $excludePrefixes);
			foreach ($scanned as $abs => $rel) {
				if (!file_exists($abs) || !is_file($abs) || is_link($abs)) {
					$counts['missing']++;
					continue;
				}
				$size = (int) filesize($abs);
				$mtime = (int) filemtime($abs);
				$hash = @hash_file('sha256', $abs);
				if (!is_string($hash) || $hash === '') {
					continue;
				}

				$skipped = false;
				if ($size > $maxBlobBytes) {
					$skipped = true;
					$counts['skipped_large']++;
				} else {
					if (!$this->blob_exists($hash)) {
						$ok = $this->write_blob($hash, $abs);
						if ($ok) {
							$counts['blobs_new']++;
						}
					}
				}

				$files[$rel] = [
					'h' => $hash,
					's' => $size,
					'm' => $mtime,
					'x' => $skipped ? 1 : 0,
				];
				$counts['files']++;
			}
		}

		ksort($files);

		$manifest = [
			'meta' => [
				'id' => $id,
				'label' => $label,
				'created_gm' => gmdate('c'),
				'abspath' => ABSPATH,
				'max_blob_bytes' => $maxBlobBytes,
			],
			'scope' => [
				'paths' => array_values($paths),
				'exclude_prefixes' => array_values($excludePrefixes),
			],
			'counts' => $counts,
			'files' => $files,
		];

		// Optional DB snapshot (best-effort).
		if (!empty($opts['include_db'])) {
			$engine = (string) ($opts['db_engine'] ?? 'basic');
			$engine = in_array($engine, ['basic', 'pro'], true) ? $engine : 'basic';
			if ($engine === 'pro' && !empty($opts['backup_pro'])) {
				$pro = new DbBackupPro($this->storage);
				$job = $pro->start_export_job([
					'restore_point_id' => $id,
					'tables_mode' => (string) ($opts['db_tables_mode'] ?? 'wp_core'),
					'custom_tables' => (string) ($opts['db_custom_tables'] ?? ''),
					'max_seconds' => (int) ($opts['db_max_seconds'] ?? 20),
				]);
				$manifest['db'] = $job;

				// Kick job now and schedule continuation.
				if (!empty($job['job_id'])) {
					$pro->continue_export_job((string) $job['job_id']);
					if (!wp_next_scheduled('guardian_dbpro_export', [(string) $job['job_id']])) {
						wp_schedule_single_event(time() + 60, 'guardian_dbpro_export', [(string) $job['job_id']]);
					}
					$manifest['db'] = $pro->build_manifest_meta_from_job((string) $job['job_id']);
				}
			} else {
				$db = new DbBackup($this->storage);
				$dbRes = $db->export([
					'tables_mode' => (string) ($opts['db_tables_mode'] ?? 'wp_core'),
					'custom_tables' => (string) ($opts['db_custom_tables'] ?? ''),
					'max_seconds' => (int) ($opts['db_max_seconds'] ?? 20),
					'label' => $label,
					'restore_point_id' => $id,
				]);
				$dbRes['engine'] = 'basic';
				$manifest['db'] = $dbRes;
			}
		}

		$ok = $this->storage->write_json_gz($manifestPath, $manifest);
		if (!$ok) {
			return null;
		}

		$this->prune_old_restore_points();
		return ['id' => $id, 'manifest' => $manifestPath, 'counts' => $counts];
	}

	public function restore_db(string $restorePointId): array {
		$manifest = $this->load_manifest($restorePointId);
		if (!$manifest || empty($manifest['db']) || !is_array($manifest['db'])) {
			return ['ok' => false, 'message' => 'no db snapshot in restore point'];
		}
		$engine = isset($manifest['db']['engine']) ? (string) $manifest['db']['engine'] : 'basic';
		if ($engine === 'pro') {
			$pro = new DbBackupPro($this->storage);
			return $pro->restore_from_manifest($manifest['db']);
		}
		$db = new DbBackup($this->storage);
		return $db->restore_from_manifest($manifest['db']);
	}

	public function list(int $limit = 20): array {
		$dir = $this->restore_points_dir();
		if (!$dir || !is_dir($dir)) {
			return [];
		}
		$items = [];
		$it = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
		foreach ($it as $fi) {
			if (!$fi->isFile()) {
				continue;
			}
			$name = $fi->getFilename();
			if (substr($name, -8) !== '.json.gz') {
				continue;
			}
			$id = substr($name, 0, -8);
			$items[] = ['id' => $id, 'path' => $fi->getPathname(), 'mtime' => $fi->getMTime()];
		}
		usort($items, static function ($a, $b) {
			return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
		});
		$items = array_slice($items, 0, max(1, $limit));
		$out = [];
		foreach ($items as $it) {
			$m = $this->storage->read_json_gz((string) $it['path']);
			if (!$m) {
				continue;
			}
			$out[] = [
				'id' => $m['meta']['id'] ?? $it['id'],
				'label' => $m['meta']['label'] ?? '',
				'created_gm' => $m['meta']['created_gm'] ?? '',
				'counts' => $m['counts'] ?? [],
			];
		}
		return $out;
	}

	public function restore_path(string $restorePointId, string $relPath, bool $delete_target_first = false): array {
		$relPath = ltrim(str_replace('\\', '/', $relPath), '/');
		if ($relPath === '' || strpos($relPath, '..') !== false) {
			return ['ok' => false, 'message' => 'invalid path'];
		}

		$manifest = $this->load_manifest($restorePointId);
		if (!$manifest) {
			return ['ok' => false, 'message' => 'restore point not found'];
		}
		$files = isset($manifest['files']) && is_array($manifest['files']) ? $manifest['files'] : [];

		$isDir = substr($relPath, -1) === '/';
		$prefix = $isDir ? $relPath : ($relPath . '/');

		$targets = [];
		if (isset($files[$relPath])) {
			$targets[$relPath] = $files[$relPath];
		}
		foreach ($files as $path => $meta) {
			if ($isDir) {
				if (strpos($path, $prefix) === 0) {
					$targets[$path] = $meta;
				}
			} else {
				// If user passed a file, don't include siblings.
			}
		}
		if (!$targets) {
			return ['ok' => false, 'message' => 'path not in restore point'];
		}

		$abs = rtrim(ABSPATH, '/\\') . '/' . $relPath;
		if ($delete_target_first) {
			if ($isDir) {
				$this->rmdir_recursive($abs);
				wp_mkdir_p($abs);
			} else {
				@unlink($abs);
				wp_mkdir_p(dirname($abs));
			}
		}

		$restored = 0;
		$skipped = 0;
		foreach ($targets as $path => $meta) {
			$hash = isset($meta['h']) ? (string) $meta['h'] : '';
			$skip = !empty($meta['x']);
			if ($skip) {
				$skipped++;
				continue;
			}
			if ($hash === '' || !$this->blob_exists($hash)) {
				$skipped++;
				continue;
			}
			$bytes = $this->read_blob($hash);
			if (!is_string($bytes)) {
				$skipped++;
				continue;
			}
			$dest = rtrim(ABSPATH, '/\\') . '/' . $path;
			wp_mkdir_p(dirname($dest));
			file_put_contents($dest, $bytes, LOCK_EX);
			$restored++;
		}

		return [
			'ok' => true,
			'message' => 'restored',
			'restored' => $restored,
			'skipped' => $skipped,
		];
	}

	private function prune_old_restore_points(): void {
		$settings = $this->storage->get_settings();
		$keep = (int) ($settings['rp_keep_last'] ?? 10);
		if ($keep <= 0) {
			$keep = 10;
		}
		$dir = $this->restore_points_dir();
		if (!$dir || !is_dir($dir)) {
			return;
		}
		$files = [];
		$it = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
		foreach ($it as $fi) {
			if ($fi->isFile() && substr($fi->getFilename(), -8) === '.json.gz') {
				$files[] = ['path' => $fi->getPathname(), 'mtime' => $fi->getMTime()];
			}
		}
		usort($files, static function ($a, $b) {
			return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
		});
		$delete = array_slice($files, $keep);
		foreach ($delete as $d) {
			@unlink((string) $d['path']);
		}
	}

	private function load_manifest(string $id): ?array {
		$p = $this->manifest_path($id);
		return $p ? $this->storage->read_json_gz($p) : null;
	}

	private function manifest_path(string $id): ?string {
		$dir = $this->restore_points_dir();
		if (!$dir) {
			return null;
		}
		$id = sanitize_file_name($id);
		return $dir . '/' . $id . '.json.gz';
	}

	private function restore_points_dir(): ?string {
		$base = $this->storage->base_dir();
		return $base ? ($base . '/restore-points') : null;
	}

	private function blob_path(string $sha256): ?string {
		$base = $this->storage->base_dir();
		if (!$base) {
			return null;
		}
		$sha256 = strtolower($sha256);
		$prefix = substr($sha256, 0, 2);
		return $base . '/blobs/' . $prefix . '/' . $sha256 . '.gz';
	}

	private function blob_exists(string $sha256): bool {
		$p = $this->blob_path($sha256);
		return $p ? file_exists($p) : false;
	}

	private function write_blob(string $sha256, string $absFile): bool {
		$p = $this->blob_path($sha256);
		if (!$p) {
			return false;
		}
		$this->mkdir_p(dirname($p));
		$raw = file_get_contents($absFile);
		if (!is_string($raw)) {
			return false;
		}
		$gz = gzencode($raw, 6);
		if (!is_string($gz)) {
			return false;
		}
		return (bool) file_put_contents($p, $gz, LOCK_EX);
	}

	private function read_blob(string $sha256): ?string {
		$p = $this->blob_path($sha256);
		if (!$p || !file_exists($p)) {
			return null;
		}
		$gz = file_get_contents($p);
		if (!is_string($gz) || $gz === '') {
			return null;
		}
		$raw = gzdecode($gz);
		return is_string($raw) ? $raw : null;
	}

	private function scan_path(string $absPath, array $excludePrefixes): array {
		$absPath = rtrim($absPath, '/\\');
		$out = [];

		if (is_file($absPath)) {
			$rel = $this->rel_from_abspath($absPath);
			if ($rel && !$this->is_excluded($rel, $excludePrefixes)) {
				$out[$absPath] = $rel;
			}
			return $out;
		}

		if (!is_dir($absPath)) {
			return $out;
		}

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($absPath, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ($it as $fi) {
			/** @var \SplFileInfo $fi */
			if (!$fi->isFile() || $fi->isLink()) {
				continue;
			}
			$abs = $fi->getPathname();
			$rel = $this->rel_from_abspath($abs);
			if (!$rel) {
				continue;
			}
			if ($this->is_excluded($rel, $excludePrefixes)) {
				continue;
			}
			$out[$abs] = $rel;
		}
		return $out;
	}

	private function is_excluded(string $rel, array $excludePrefixes): bool {
		$rel = str_replace('\\', '/', $rel);
		foreach ($excludePrefixes as $p) {
			$p = str_replace('\\', '/', (string) $p);
			if ($p !== '' && strpos($rel, $p) === 0) {
				return true;
			}
		}
		return false;
	}

	private function rel_from_abspath(string $abs): ?string {
		if (!defined('ABSPATH')) {
			return null;
		}
		$root = rtrim(ABSPATH, '/\\') . DIRECTORY_SEPARATOR;
		$absN = str_replace('\\', '/', $abs);
		$rootN = str_replace('\\', '/', $root);
		if (strpos($absN, $rootN) !== 0) {
			return null;
		}
		return ltrim(substr($absN, strlen($rootN)), '/');
	}

	private function abs_path(string $path): ?string {
		$path = (string) $path;
		$path = str_replace('\\', '/', $path);
		if ($path === '') {
			return null;
		}
		// Allow passing relative-to-ABSPATH (recommended).
		if ($path[0] !== '/' && !preg_match('/^[A-Za-z]:\\//', $path)) {
			return rtrim(ABSPATH, '/\\') . '/' . ltrim($path, '/');
		}
		return $path;
	}

	private function mkdir_p(string $dir): void {
		if (is_dir($dir)) {
			return;
		}
		wp_mkdir_p($dir);
	}

	private function rmdir_recursive(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			/** @var \SplFileInfo $item */
			if ($item->isDir()) {
				@rmdir($item->getPathname());
			} else {
				@unlink($item->getPathname());
			}
		}
		@rmdir($dir);
	}
}

