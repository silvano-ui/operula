<?php
/**
 * Plugin Name: Guardian
 * Description: Monitoraggio integrità file, snapshot, diff e rollback (plugin/temi) durante installazioni/aggiornamenti WordPress.
 * Version: 0.1.0
 * Author: Guardian
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: guardian
 */

if (!defined('ABSPATH')) {
	exit;
}

define('GUARDIAN_VERSION', '0.1.0');
define('GUARDIAN_PLUGIN_FILE', __FILE__);
define('GUARDIAN_PLUGIN_DIR', __DIR__);

require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-plugin.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-storage.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-license.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-scanner.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-backup.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-upgrader-hooks.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-admin.php';

register_activation_hook(__FILE__, ['Guardian\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Guardian\\Plugin', 'deactivate']);

add_action('plugins_loaded', static function () {
	// Se usi il MU-loader, Guardian verrà caricato prima e la protezione crash è più efficace.
	Guardian\Plugin::instance()->boot();
}, 1);

