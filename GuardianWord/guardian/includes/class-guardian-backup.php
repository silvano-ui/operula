<?php

namespace Guardian;

final class Backup {
	private Storage $storage;

	public function __construct(Storage $storage) {
		$this->storage = $storage;
	}

	/**
	 * Crea uno ZIP di una directory (best-effort).
	 *
	 * @return array|null ['id'=>string,'zip'=>string,'source'=>string,'created_gm'=>string]
	 */
	public function backup_directory(string $source_dir, string $label): ?array {
		$source_dir = rtrim($source_dir, '/\\');
		if (!is_dir($source_dir) || !is_readable($source_dir)) {
			return null;
		}

		if (!class_exists(\ZipArchive::class)) {
			return null;
		}

		$id = gmdate('Ymd-His') . '-' . sanitize_key($label) . '-' . wp_generate_password(5, false, false);
		$zipPath = $this->storage->backup_path($id);
		if (!$zipPath) {
			return null;
		}

		$zip = new \ZipArchive();
		if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			return null;
		}

		$baseName = basename($source_dir);
		$rootPrefix = $baseName . '/';

		$flags = \FilesystemIterator::SKIP_DOTS;
		$dirIter = new \RecursiveDirectoryIterator($source_dir, $flags);
		$iter = new \RecursiveIteratorIterator($dirIter, \RecursiveIteratorIterator::LEAVES_ONLY);

		foreach ($iter as $fileInfo) {
			/** @var \SplFileInfo $fileInfo */
			if (!$fileInfo->isFile() || $fileInfo->isLink()) {
				continue;
			}

			$abs = $fileInfo->getPathname();
			$rel = str_replace('\\', '/', substr($abs, strlen($source_dir) + 1));
			$zipRel = $rootPrefix . $rel;
			$zip->addFile($abs, $zipRel);
		}

		$zip->close();

		return [
			'id' => $id,
			'zip' => $zipPath,
			'source' => $source_dir,
			'created_gm' => gmdate('c'),
			'prefix' => $rootPrefix,
		];
	}

	/**
	 * Ripristina una directory da uno ZIP creato da backup_directory().
	 */
	public function restore_directory_from_backup(string $zip_path, string $target_dir): bool {
		if (!file_exists($zip_path) || !is_string($zip_path)) {
			return false;
		}
		if (!class_exists(\ZipArchive::class)) {
			return false;
		}

		$target_dir = rtrim($target_dir, '/\\');
		$parent = dirname($target_dir);
		if (!is_dir($parent) || !is_writable($parent)) {
			// Best-effort: tenta comunque (in alcuni hosting is_writable è inaffidabile).
		}

		$zip = new \ZipArchive();
		if ($zip->open($zip_path) !== true) {
			return false;
		}

		// Determina prefisso root (prima cartella nello zip).
		$rootPrefix = null;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if (!is_string($name)) {
				continue;
			}
			$name = str_replace('\\', '/', $name);
			if (strpos($name, '/') !== false) {
				$rootPrefix = substr($name, 0, strpos($name, '/') + 1);
				break;
			}
		}
		if (!$rootPrefix) {
			$zip->close();
			return false;
		}

		// Cancella target_dir e ricrea.
		$this->rmdir_recursive($target_dir);
		wp_mkdir_p($target_dir);

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if (!is_string($name)) {
				continue;
			}
			$name = str_replace('\\', '/', $name);
			if (strpos($name, $rootPrefix) !== 0) {
				continue;
			}
			$inner = substr($name, strlen($rootPrefix));
			if ($inner === '') {
				continue;
			}
			// Protezione zip-slip.
			if (strpos($inner, '..') !== false || strpos($inner, ':') !== false || strpos($inner, '\\') !== false) {
				continue;
			}

			$dest = $target_dir . '/' . $inner;

			if (substr($name, -1) === '/') {
				wp_mkdir_p($dest);
				continue;
			}

			$dir = dirname($dest);
			wp_mkdir_p($dir);
			$contents = $zip->getFromIndex($i);
			if (!is_string($contents)) {
				continue;
			}
			file_put_contents($dest, $contents, LOCK_EX);
		}

		$zip->close();
		return true;
	}

	/**
	 * Ripristino "installazione" da ZIP (sovrascrive/ricrea file presenti nel backup).
	 * Non cancella file aggiunti dopo il backup.
	 *
	 * Nota: per sicurezza, per default NON sovrascrive wp-config.php (config DB/chiavi).
	 */
	public function restore_installation_from_backup(string $zip_path, string $target_root, bool $include_wp_config = false): bool {
		if (!file_exists($zip_path) || !class_exists(\ZipArchive::class)) {
			return false;
		}

		$target_root = rtrim($target_root, '/\\');
		if ($target_root === '' || !is_dir($target_root)) {
			return false;
		}

		$zip = new \ZipArchive();
		if ($zip->open($zip_path) !== true) {
			return false;
		}

		// Root prefix = prima cartella nello zip (come creato da backup_directory()).
		$rootPrefix = null;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if (!is_string($name)) {
				continue;
			}
			$name = str_replace('\\', '/', $name);
			if (strpos($name, '/') !== false) {
				$rootPrefix = substr($name, 0, strpos($name, '/') + 1);
				break;
			}
		}
		if (!$rootPrefix) {
			$zip->close();
			return false;
		}

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if (!is_string($name)) {
				continue;
			}
			$name = str_replace('\\', '/', $name);
			if (strpos($name, $rootPrefix) !== 0) {
				continue;
			}
			$inner = substr($name, strlen($rootPrefix));
			if ($inner === '' || substr($name, -1) === '/') {
				continue;
			}
			if (strpos($inner, '..') !== false || strpos($inner, ':') !== false || strpos($inner, '\\') !== false) {
				continue;
			}

			// Safety: non toccare wp-config.php a meno che esplicitamente richiesto.
			if (!$include_wp_config && $inner === 'wp-config.php') {
				continue;
			}

			$dest = $target_root . '/' . $inner;
			wp_mkdir_p(dirname($dest));
			$contents = $zip->getFromIndex($i);
			if (!is_string($contents)) {
				continue;
			}
			file_put_contents($dest, $contents, LOCK_EX);
		}

		$zip->close();
		return true;
	}

	public function read_file_from_backup_zip(string $zip_path, string $inner_path): ?string {
		if (!class_exists(\ZipArchive::class) || !file_exists($zip_path)) {
			return null;
		}
		$zip = new \ZipArchive();
		if ($zip->open($zip_path) !== true) {
			return null;
		}
		$inner_path = str_replace('\\', '/', ltrim($inner_path, '/'));
		$data = $zip->getFromName($inner_path);
		$zip->close();
		return is_string($data) ? $data : null;
	}

	/**
	 * Rollback best-effort per l'ultima operazione (plugin/theme) basata su backup ZIP.
	 */
	public function rollback_last_operation(array $op): void {
		$type = (string) ($op['type'] ?? '');
		$zip  = (string) ($op['backup_zip'] ?? '');

		if ($type === 'plugin') {
			$pluginFile = (string) ($op['plugin'] ?? '');
			$pluginDir = $this->plugin_dir_from_plugin_file($pluginFile);
			if ($zip && $pluginDir) {
				$this->restore_directory_from_backup($zip, $pluginDir);
			}
			if ($pluginFile) {
				$this->deactivate_plugin($pluginFile);
			}
		} elseif ($type === 'theme') {
			$themeSlug = (string) ($op['theme'] ?? '');
			$themeDir = $this->theme_dir_from_slug($themeSlug);
			if ($zip && $themeDir) {
				$this->restore_directory_from_backup($zip, $themeDir);
			}
			// Best-effort: se tema attivo e ha causato fatal, passa a default.
			$this->switch_to_default_theme();
		} else {
			// Core rollback non è gestito in modo affidabile senza fonti ufficiali e permessi filesystem completi.
		}
	}

	private function plugin_dir_from_plugin_file(string $pluginFile): ?string {
		if ($pluginFile === '' || !defined('WP_PLUGIN_DIR')) {
			return null;
		}
		$parts = explode('/', str_replace('\\', '/', $pluginFile));
		if (count($parts) < 2) {
			return null;
		}
		$dir = $parts[0];
		return rtrim(WP_PLUGIN_DIR, '/\\') . '/' . $dir;
	}

	private function theme_dir_from_slug(string $slug): ?string {
		if ($slug === '' || !defined('WP_CONTENT_DIR')) {
			return null;
		}
		return rtrim(WP_CONTENT_DIR, '/\\') . '/themes/' . $slug;
	}

	private function deactivate_plugin(string $pluginFile): void {
		$active = get_option('active_plugins');
		if (!is_array($active)) {
			return;
		}
		$new = array_values(array_filter($active, static function ($p) use ($pluginFile) {
			return $p !== $pluginFile;
		}));
		if ($new !== $active) {
			update_option('active_plugins', $new, false);
		}
	}

	private function switch_to_default_theme(): void {
		$default = defined('WP_DEFAULT_THEME') ? WP_DEFAULT_THEME : '';
		if ($default === '') {
			return;
		}
		// Evita loop: se già default, non fare nulla.
		$stylesheet = (string) get_option('stylesheet', '');
		if ($stylesheet === $default) {
			return;
		}
		update_option('template', $default, false);
		update_option('stylesheet', $default, false);
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

