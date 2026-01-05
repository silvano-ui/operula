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
		$legacy = strtolower(str_replace('\\', '-', $short));
		$kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', str_replace('\\', '', $short)));

		$candidates = [
			GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-' . $legacy . '.php',
			GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-' . $kebab . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/core/class-guardian-' . $legacy . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/core/class-guardian-' . $kebab . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/core/class-guardian-module-' . $kebab . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/core/class-guardian-module-' . $legacy . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/integrity/class-guardian-' . $legacy . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/integrity/class-guardian-' . $kebab . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/integrity/class-guardian-module-' . $kebab . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/integrity/class-guardian-module-' . $legacy . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/backup/class-guardian-' . $legacy . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/backup/class-guardian-' . $kebab . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/backup/class-guardian-module-' . $kebab . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/backup/class-guardian-module-' . $legacy . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/security/class-guardian-' . $legacy . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/security/class-guardian-' . $kebab . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/health/class-guardian-' . $legacy . '.php',
			GUARDIAN_PLUGIN_DIR . '/modules/health/class-guardian-' . $kebab . '.php',
		];

		foreach ($candidates as $p) {
			if (file_exists($p)) {
				require_once $p;
				return;
			}
		}
	}
}

