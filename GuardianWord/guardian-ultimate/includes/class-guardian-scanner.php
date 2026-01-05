<?php

namespace Guardian;

final class Scanner {
	private Storage $storage;

	public function __construct(Storage $storage) {
		$this->storage = $storage;
	}

	/**
	 * Crea uno snapshot completo (hash) dell'installazione.
	 */
	public function create_snapshot(string $label, array $extra_meta = []): ?array {
		if (!defined('ABSPATH')) {
			return null;
		}

		$id = gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);
		$path = $this->storage->snapshot_path($id);
		if (!$path) {
			return null;
		}

		$files = $this->scan_root(ABSPATH);
		$data = [
			'meta'  => array_merge([
				'id'        => $id,
				'label'     => $label,
				'created_gm'=> gmdate('c'),
				'wp'        => [
					'version' => get_bloginfo('version'),
					'abspath' => ABSPATH,
				],
			], $extra_meta),
			'files' => $files,
		];

		$ok = $this->storage->write_json_gz($path, $data);
		return $ok ? ['id' => $id, 'path' => $path, 'count' => count($files)] : null;
	}

	public function load_snapshot(string $id): ?array {
		$path = $this->storage->snapshot_path($id);
		return $path ? $this->storage->read_json_gz($path) : null;
	}

	/**
	 * Calcola differenze tra due snapshot (added/removed/modified).
	 */
	public function diff_snapshots(array $old, array $new): array {
		$oldFiles = isset($old['files']) && is_array($old['files']) ? $old['files'] : [];
		$newFiles = isset($new['files']) && is_array($new['files']) ? $new['files'] : [];

		$added = [];
		$removed = [];
		$modified = [];

		foreach ($newFiles as $path => $meta) {
			if (!isset($oldFiles[$path])) {
				$added[$path] = $meta;
				continue;
			}
			$om = $oldFiles[$path];
			if (($om['h'] ?? null) !== ($meta['h'] ?? null) || ($om['s'] ?? null) !== ($meta['s'] ?? null)) {
				$modified[$path] = ['old' => $om, 'new' => $meta];
			}
		}

		foreach ($oldFiles as $path => $meta) {
			if (!isset($newFiles[$path])) {
				$removed[$path] = $meta;
			}
		}

		ksort($added);
		ksort($removed);
		ksort($modified);

		return [
			'added' => $added,
			'removed' => $removed,
			'modified' => $modified,
		];
	}

	private function scan_root(string $root): array {
		$root = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
		$settings = $this->storage->get_settings();

		$excludePrefixes = [
			'.git/',
			'wp-content/cache/',
			'wp-content/upgrade/',
			'wp-content/guardian/',
		];
		if (empty($settings['include_uploads'])) {
			$excludePrefixes[] = 'wp-content/uploads/';
		}

		$results = [];
		$flags = \FilesystemIterator::SKIP_DOTS;
		$dirIter = new \RecursiveDirectoryIterator($root, $flags);
		$iter = new \RecursiveIteratorIterator($dirIter, \RecursiveIteratorIterator::LEAVES_ONLY);

		foreach ($iter as $fileInfo) {
			/** @var \SplFileInfo $fileInfo */
			if (!$fileInfo->isFile() || $fileInfo->isLink()) {
				continue;
			}

			$abs = $fileInfo->getPathname();
			$rel = str_replace('\\', '/', substr($abs, strlen($root)));

			if ($this->is_excluded($rel, $excludePrefixes)) {
				continue;
			}

			$size = (int) $fileInfo->getSize();
			$mtime = (int) $fileInfo->getMTime();

			// Limite soft: file enormi vengono marcati come "skipped" ma tracciati.
			$maxHashBytes = (int) ($settings['max_hash_bytes'] ?? (200 * 1024 * 1024)); // 200 MB
			$hash = null;
			$skipped = false;
			if ($size > $maxHashBytes) {
				$skipped = true;
			} else {
				$hash = @hash_file('sha256', $abs);
			}

			$results[$rel] = [
				'h' => is_string($hash) ? $hash : null,
				's' => $size,
				'm' => $mtime,
				'x' => $skipped ? 1 : 0,
			];
		}

		return $results;
	}

	private function is_excluded(string $rel, array $excludePrefixes): bool {
		foreach ($excludePrefixes as $p) {
			if ($p !== '' && strpos($rel, $p) === 0) {
				return true;
			}
		}
		return false;
	}
}

