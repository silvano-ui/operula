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

Guardian\Bootstrap::init();

