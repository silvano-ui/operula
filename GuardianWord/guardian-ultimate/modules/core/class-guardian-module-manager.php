<?php

namespace Guardian;

final class ModuleManager {
	/**
	 * Fixed registry (no filesystem scanning).
	 */
	public static function registry(): array {
		return [
			Modules::CORE => ['file' => GUARDIAN_PLUGIN_DIR . '/modules/core/module.php'],
			Modules::INTEGRITY => ['file' => GUARDIAN_PLUGIN_DIR . '/modules/integrity/module.php'],
			Modules::BACKUP => ['file' => GUARDIAN_PLUGIN_DIR . '/modules/backup/module.php'],
			Modules::SECURITY => ['file' => GUARDIAN_PLUGIN_DIR . '/modules/security/module.php'],
			Modules::HEALTH => ['file' => GUARDIAN_PLUGIN_DIR . '/modules/health/module.php'],
		];
	}

	/**
	 * Loads module manifests and instantiates enabled modules.
	 *
	 * @return ModuleInterface[]
	 */
	public static function load(array $enabledModules): array {
		$enabledModules = Modules::normalize($enabledModules);
		$mods = [];
		$reg = self::registry();
		foreach ($enabledModules as $id) {
			if (!isset($reg[$id])) {
				continue;
			}
			$file = (string) ($reg[$id]['file'] ?? '');
			if ($file === '' || !file_exists($file)) {
				continue;
			}
			$manifest = require $file;
			if (!is_array($manifest) || empty($manifest['class'])) {
				continue;
			}
			$cls = (string) $manifest['class'];
			if ($cls === '' || !class_exists($cls)) {
				continue;
			}
			$inst = new $cls();
			if ($inst instanceof ModuleInterface) {
				$mods[] = $inst;
			}
		}
		return $mods;
	}
}

