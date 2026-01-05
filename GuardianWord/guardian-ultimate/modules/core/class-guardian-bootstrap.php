<?php

namespace Guardian;

/**
 * Single entrypoint for plugin initialization (clean bootstrap).
 */
final class Bootstrap {
	public static function init(): void {
		// Activation/deactivation callbacks (autoloadable).
		register_activation_hook(GUARDIAN_PLUGIN_FILE, ['Guardian\\Plugin', 'activate']);
		register_deactivation_hook(GUARDIAN_PLUGIN_FILE, ['Guardian\\Plugin', 'deactivate']);

		// Boot after plugins are available (priority 1).
		add_action('plugins_loaded', static function (): void {
			Plugin::instance()->boot();
		}, 1);
	}
}

