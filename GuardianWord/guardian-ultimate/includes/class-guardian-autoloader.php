<?php

namespace Guardian;

/**
 * Minimal autoloader for Guardian Ultimate.
 * Keeps current file layout but allows future modular structure.
 */
final class Autoloader {
	public static function register(): void {
		spl_autoload_register([self::class, 'autoload'], true, true);
	}

	public static function autoload(string $class): void {
		if (strpos($class, __NAMESPACE__ . '\\') !== 0) {
			return;
		}
		$short = substr($class, strlen(__NAMESPACE__) + 1);
		// Try legacy map:
		// Guardian\FooBar -> class-guardian-foobar.php
		$legacy = strtolower(str_replace('\\', '-', $short));
		$path1 = GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-' . $legacy . '.php';
		if (file_exists($path1)) {
			require_once $path1;
			return;
		}

		// Try wp-style map:
		// Guardian\DbBackupPro -> class-guardian-db-backup-pro.php
		$kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', str_replace('\\', '', $short)));
		$path2 = GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-' . $kebab . '.php';
		if (file_exists($path2)) {
			require_once $path2;
		}
	}
}

