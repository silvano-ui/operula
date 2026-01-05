<?php
/**
 * MU Plugin Loader for Guardian.
 *
 * Copia questo file in: wp-content/mu-plugins/guardian-loader.php
 * oppure attiva Guardian e lascia che provi a copiarlo automaticamente (se i permessi lo permettono).
 */

if (!defined('ABSPATH')) {
	exit;
}

// Carica Guardian prima dei plugin "normali".
$guardianMain = defined('WP_PLUGIN_DIR') ? rtrim(WP_PLUGIN_DIR, '/\\') . '/guardian-ultimate/guardian-ultimate.php' : null;
if ($guardianMain && file_exists($guardianMain)) {
	require_once $guardianMain;
}

