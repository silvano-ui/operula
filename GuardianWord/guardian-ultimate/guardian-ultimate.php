<?php
/**
 * Plugin Name: Guardian Ultimate
 * Description: Manutenzione, integrità, backup/restore e sicurezza modulare con licenze (offline/WHMCS).
 * Version: 0.2.0
 * Author: Guardian
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: guardian
 */

if (!defined('ABSPATH')) {
	exit;
}

define('GUARDIAN_VERSION', '0.2.0');
define('GUARDIAN_PLUGIN_FILE', __FILE__);
define('GUARDIAN_PLUGIN_DIR', __DIR__);

require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-autoloader.php';
Guardian\Autoloader::register();

require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-plugin.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-storage.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-license.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-modules.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-restore-points.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-db-backup.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-db-backup-pro.php';

// Existing modules (integrity/backup).
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-scanner.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-backup.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-upgrader-hooks.php';
require_once GUARDIAN_PLUGIN_DIR . '/includes/class-guardian-admin.php';

register_activation_hook(__FILE__, ['Guardian\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Guardian\\Plugin', 'deactivate']);

add_action('plugins_loaded', static function () {
	Guardian\Plugin::instance()->boot();
}, 1);

