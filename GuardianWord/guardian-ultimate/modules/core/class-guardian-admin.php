<?php

namespace Guardian;

final class Admin {
	private Storage $storage;
	private License $license;
	private ?Scanner $scanner = null;
	private ?Backup $backup = null;
	private ?RestorePoints $restorePoints = null;

	public function __construct(Storage $storage, License $license) {
		$this->storage = $storage;
		$this->license = $license;
	}

	public function register(): void {
		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('admin_post_guardian_save_license', [$this, 'handle_save_license']);
		add_action('admin_post_guardian_fetch_license', [$this, 'handle_fetch_license']);
		add_action('admin_post_guardian_reset_domain', [$this, 'handle_reset_domain']);
		add_action('admin_post_guardian_reset_install', [$this, 'handle_reset_install']);
		add_action('admin_post_guardian_rotate_install_id', [$this, 'handle_rotate_install_id']);
		add_action('admin_post_guardian_save_modules', [$this, 'handle_save_modules']);
		add_action('admin_post_guardian_create_restore_point', [$this, 'handle_create_restore_point']);
		add_action('admin_post_guardian_restore_from_point', [$this, 'handle_restore_from_point']);
		add_action('admin_post_guardian_dbpro_restore_start', [$this, 'handle_dbpro_restore_start']);
		add_action('admin_post_guardian_create_snapshot', [$this, 'handle_create_snapshot']);
		add_action('admin_post_guardian_rollback_last', [$this, 'handle_rollback_last']);
		add_action('admin_post_guardian_restore_full_last', [$this, 'handle_restore_full_last']);
		add_action('admin_post_guardian_save_settings', [$this, 'handle_save_settings']);
	}

	public function admin_menu(): void {
		add_menu_page(
			__('Guardian Ultimate', 'guardian'),
			__('Guardian Ultimate', 'guardian'),
			'manage_options',
			'guardian',
			[$this, 'render_page'],
			'dashicons-shield-alt',
			80
		);
	}

	public function handle_create_snapshot(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_create_snapshot');
		if (!$this->ensure_licensed_or_die()) {
			return;
		}

		$this->storage->ensure_directories();
		$snap = $this->scanner()->create_snapshot('manual', [
			'operation' => [
				'type' => 'manual',
			],
		]);

		$q = $snap ? ['guardian_notice' => 'snapshot_ok'] : ['guardian_notice' => 'snapshot_fail'];
		wp_safe_redirect(add_query_arg($q, admin_url('admin.php?page=guardian')));
		exit;
	}

	public function handle_rollback_last(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_rollback_last');
		if (!$this->ensure_licensed_or_die()) {
			return;
		}

		$op = $this->storage->get_last_operation();
		if ($op) {
			$this->backup()->rollback_last_operation($op);
		}

		wp_safe_redirect(add_query_arg(['guardian_notice' => 'rollback_done'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function handle_restore_full_last(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_restore_full_last');
		if (!$this->ensure_licensed_or_die()) {
			return;
		}

		$op = $this->storage->get_last_operation();
		$zip = $op && !empty($op['site_backup_zip']) ? (string) $op['site_backup_zip'] : '';
		$settings = $this->storage->get_settings();
		$includeWpConfig = !empty($settings['full_restore_include_wp_config']);

		$ok = false;
		if ($zip && defined('ABSPATH')) {
			$ok = $this->backup()->restore_installation_from_backup($zip, ABSPATH, $includeWpConfig);
		}

		wp_safe_redirect(add_query_arg(['guardian_notice' => $ok ? 'full_restore_done' : 'full_restore_fail'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function handle_save_settings(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_save_settings');
		if (!$this->ensure_licensed_or_die()) {
			return;
		}

		$settings = $this->storage->get_settings();
		$settings['auto_backup_on_upgrade'] = !empty($_POST['auto_backup_on_upgrade']);
		$settings['auto_snapshot_on_upgrade'] = !empty($_POST['auto_snapshot_on_upgrade']);
		$settings['auto_rollback_on_fatal'] = !empty($_POST['auto_rollback_on_fatal']);
		$settings['include_uploads'] = !empty($_POST['include_uploads']);
		$settings['full_backup_on_upgrade'] = !empty($_POST['full_backup_on_upgrade']);
		$settings['full_restore_include_wp_config'] = !empty($_POST['full_restore_include_wp_config']);

		// Restore point schedule/options
		$settings['rp_schedule'] = isset($_POST['rp_schedule']) ? sanitize_text_field((string) wp_unslash($_POST['rp_schedule'])) : 'daily';
		$settings['rp_scope_plugins_themes'] = !empty($_POST['rp_scope_plugins_themes']);
		$settings['rp_scope_wp_config'] = !empty($_POST['rp_scope_wp_config']);
		$settings['rp_scope_core'] = !empty($_POST['rp_scope_core']);
		$settings['rp_scope_uploads'] = !empty($_POST['rp_scope_uploads']);
		$settings['rp_include_db'] = !empty($_POST['rp_include_db']);
		$settings['rp_db_engine'] = isset($_POST['rp_db_engine']) ? sanitize_text_field((string) wp_unslash($_POST['rp_db_engine'])) : 'basic';
		$settings['rp_db_tables'] = isset($_POST['rp_db_tables']) ? sanitize_text_field((string) wp_unslash($_POST['rp_db_tables'])) : 'wp_core';
		$settings['rp_db_custom_tables'] = isset($_POST['rp_db_custom_tables']) ? (string) wp_unslash($_POST['rp_db_custom_tables']) : '';
		$settings['rp_db_max_seconds'] = isset($_POST['rp_db_max_seconds']) ? (int) $_POST['rp_db_max_seconds'] : 20;
		$settings['rp_pre_upgrade_include_db'] = !empty($_POST['rp_pre_upgrade_include_db']);
		$settings['rp_pre_upgrade_core_files'] = !empty($_POST['rp_pre_upgrade_core_files']);

		$this->storage->update_settings($settings);
		Plugin::reschedule_restore_points();

		wp_safe_redirect(add_query_arg(['guardian_notice' => 'settings_saved'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function handle_save_license(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_save_license');

		$mode = isset($_POST['license_mode']) ? (string) wp_unslash($_POST['license_mode']) : 'offline';
		$this->license->set_mode($mode);

		if ($mode === 'whmcs') {
			$conf = [
				'validate_url' => isset($_POST['whmcs_validate_url']) ? (string) wp_unslash($_POST['whmcs_validate_url']) : '',
				'reset_url' => isset($_POST['whmcs_reset_url']) ? (string) wp_unslash($_POST['whmcs_reset_url']) : '',
				'license_id' => isset($_POST['whmcs_license_id']) ? (string) wp_unslash($_POST['whmcs_license_id']) : '',
				'api_secret' => isset($_POST['whmcs_api_secret']) ? (string) wp_unslash($_POST['whmcs_api_secret']) : '',
			];
			$this->license->save_whmcs_conf($conf);
		} else {
			$token = isset($_POST['license_token']) ? (string) wp_unslash($_POST['license_token']) : '';
			$this->license->save_token($token);
		}
		$st = $this->license->status();
		wp_safe_redirect(add_query_arg(['guardian_notice' => !empty($st['ok']) ? 'license_ok' : 'license_fail'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function handle_fetch_license(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_fetch_license');

		$st = $this->license->refresh_from_whmcs_if_needed(true);
		$ok = $st && !empty($st['ok']);
		wp_safe_redirect(add_query_arg(['guardian_notice' => $ok ? 'license_ok' : 'license_fail'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function handle_reset_domain(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_reset_domain');

		$r = $this->license->request_domain_reset();
		wp_safe_redirect(add_query_arg(['guardian_notice' => !empty($r['ok']) ? 'domain_reset_ok' : 'domain_reset_fail'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function handle_reset_install(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_reset_install');

		$r = $this->license->request_install_reset();
		wp_safe_redirect(add_query_arg(['guardian_notice' => !empty($r['ok']) ? 'install_reset_ok' : 'install_reset_fail'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function handle_rotate_install_id(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_rotate_install_id');

		$this->license->rotate_install_id();
		wp_safe_redirect(add_query_arg(['guardian_notice' => 'install_id_rotated'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function handle_save_modules(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_save_modules');

		$st = $this->license->status();
		if (empty($st['ok'])) {
			wp_die(esc_html($st['message'] ?? __('Licenza non valida.', 'guardian')));
		}
		$payload = $this->license->get_payload();
		$allowed = Modules::allowed_from_license($payload);

		$mods = isset($_POST['enabled_modules']) ? (array) $_POST['enabled_modules'] : [];
		$mods = array_map('sanitize_text_field', $mods);
		$mods = Modules::normalize($mods);
		$mods = array_values(array_intersect($mods, $allowed));

		$settings = $this->storage->get_settings();
		$settings['enabled_modules'] = $mods;
		$this->storage->update_settings($settings);

		wp_safe_redirect(add_query_arg(['guardian_notice' => 'modules_saved'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function handle_create_restore_point(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_create_restore_point');
		if (!$this->ensure_licensed_or_die()) {
			return;
		}
		$settings = $this->storage->get_settings();
		$enabled = isset($settings['enabled_modules']) && is_array($settings['enabled_modules']) ? $settings['enabled_modules'] : [];
		if (!in_array('backup', $enabled, true)) {
			wp_die(__('Modulo Backup non abilitato.', 'guardian'));
		}

		// MVP scope: plugins + themes + wp-config.php
		$paths = [
			'wp-content/plugins',
			'wp-content/themes',
			'wp-config.php',
		];
		$exclude = [
			'wp-content/uploads/',
			'wp-content/cache/',
			'wp-content/upgrade/',
		];
		$res = $this->restorePoints()->create('manual', $paths, $exclude);
		$ok = is_array($res);

		wp_safe_redirect(add_query_arg(['guardian_notice' => $ok ? 'rp_created' : 'rp_create_fail'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function handle_restore_from_point(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_restore_from_point');
		if (!$this->ensure_licensed_or_die()) {
			return;
		}
		$settings = $this->storage->get_settings();
		$enabled = isset($settings['enabled_modules']) && is_array($settings['enabled_modules']) ? $settings['enabled_modules'] : [];
		if (!in_array('backup', $enabled, true)) {
			wp_die(__('Modulo Backup non abilitato.', 'guardian'));
		}

		$id = isset($_POST['restore_point_id']) ? (string) wp_unslash($_POST['restore_point_id']) : '';
		$path = isset($_POST['restore_rel_path']) ? (string) wp_unslash($_POST['restore_rel_path']) : '';
		$custom = isset($_POST['restore_rel_path_custom']) ? (string) wp_unslash($_POST['restore_rel_path_custom']) : '';
		if (trim($custom) !== '') {
			$path = $custom;
		}
		$deleteFirst = !empty($_POST['delete_first']);
		$restoreDb = !empty($_POST['restore_db']);

		$r = $this->restorePoints()->restore_path($id, $path, $deleteFirst);
		$ok = !empty($r['ok']);
		if ($ok && $restoreDb) {
			// If DB engine is Pro, do non-blocking restore job.
			$manifest = $this->storage->base_dir() ? $this->storage->base_dir() . '/restore-points/' . sanitize_file_name($id) . '.json.gz' : null;
			$m = ($manifest && file_exists($manifest)) ? $this->storage->read_json_gz($manifest) : null;
			$engine = is_array($m) && isset($m['db']['engine']) ? (string) $m['db']['engine'] : 'basic';
			if ($engine === 'pro') {
				$pro = new DbBackupPro($this->storage);
				$job = $pro->start_restore_job((array) $m['db']);
				$ok = !empty($job['ok']);
				wp_safe_redirect(add_query_arg(['guardian_notice' => $ok ? 'dbpro_restore_started' : 'rp_restore_fail'], admin_url('admin.php?page=guardian')));
				exit;
			}
			$db = $this->restorePoints()->restore_db($id);
			$ok = !empty($db['ok']);
		}

		wp_safe_redirect(add_query_arg(['guardian_notice' => $ok ? 'rp_restore_ok' : 'rp_restore_fail'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function handle_dbpro_restore_start(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_dbpro_restore_start');
		if (!$this->ensure_licensed_or_die()) {
			return;
		}
		$rpId = isset($_POST['restore_point_id']) ? (string) wp_unslash($_POST['restore_point_id']) : '';
		$manifest = $this->storage->base_dir() ? $this->storage->base_dir() . '/restore-points/' . sanitize_file_name($rpId) . '.json.gz' : null;
		$m = ($manifest && file_exists($manifest)) ? $this->storage->read_json_gz($manifest) : null;
		if (!is_array($m) || empty($m['db']) || !is_array($m['db'])) {
			wp_safe_redirect(add_query_arg(['guardian_notice' => 'rp_restore_fail'], admin_url('admin.php?page=guardian')));
			exit;
		}
		$pro = new DbBackupPro($this->storage);
		$job = $pro->start_restore_job((array) $m['db']);
		$ok = !empty($job['ok']);
		wp_safe_redirect(add_query_arg(['guardian_notice' => $ok ? 'dbpro_restore_started' : 'rp_restore_fail'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function render_page(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}

		$op = $this->storage->get_last_operation();
		$settings = $this->storage->get_settings();
		// In modalità WHMCS: prova refresh soft (rispetta cache) quando apri la pagina.
		if ($this->license->get_mode() === 'whmcs') {
			$this->license->refresh_from_whmcs_if_needed(false);
		}

		$licenseStatus = $this->license->status();
		$licensed = !empty($licenseStatus['ok']);
		if (!$licensed && $this->license->get_mode() === 'whmcs' && !empty($licenseStatus['whmcs']['status'])) {
			$wst = (string) $licenseStatus['whmcs']['status'];
			if ($wst === 'domain_reset_required') {
				// Evidenzia azione consigliata.
				echo '<div class="notice notice-warning"><p><strong>Guardian</strong>: ' . esc_html__('Dominio cambiato: serve reset dominio su WHMCS.', 'guardian') . '</p></div>';
			} elseif ($wst === 'install_reset_required') {
				echo '<div class="notice notice-warning"><p><strong>Guardian</strong>: ' . esc_html__('Installazione diversa: serve reset install binding su WHMCS.', 'guardian') . '</p></div>';
			}
		}

		$diffPath = isset($_GET['guardian_diff']) ? (string) $_GET['guardian_diff'] : '';
		if ($diffPath !== '') {
			if (!$licensed) {
				wp_die(__('Licenza non valida: impossibile mostrare diff.', 'guardian'));
			}
			$this->render_diff_view($diffPath, $op);
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>Guardian Ultimate</h1>';

		$this->render_notices();

		echo '<h2>' . esc_html__('Licenza', 'guardian') . '</h2>';
		echo '<p>' . esc_html($licenseStatus['message'] ?? '') . '</p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="guardian_save_license" />';
		wp_nonce_field('guardian_save_license');

		$mode = $this->license->get_mode();
		echo '<p>';
		echo '<label style="margin-right:12px;"><input type="radio" name="license_mode" value="offline" ' . checked($mode, 'offline', false) . ' /> ' . esc_html__('Token offline (incolla)', 'guardian') . '</label>';
		echo '<label><input type="radio" name="license_mode" value="whmcs" ' . checked($mode, 'whmcs', false) . ' /> ' . esc_html__('WHMCS (auto-recupero)', 'guardian') . '</label>';
		echo '</p>';

		$conf = $this->license->get_whmcs_conf();
		echo '<div style="padding:12px; border:1px solid #ddd; background:#fff; max-width:1100px;">';
		echo '<h3 style="margin-top:0;">' . esc_html__('Configurazione WHMCS', 'guardian') . '</h3>';
		echo '<p><label><strong>Validate URL</strong><br /><input type="url" name="whmcs_validate_url" style="width:100%;" value="' . esc_attr((string) $conf['validate_url']) . '" placeholder="https://whmcs.example.com/modules/addons/guardian_licensing/api/validate.php" /></label></p>';
		echo '<p><label><strong>Reset URL</strong><br /><input type="url" name="whmcs_reset_url" style="width:100%;" value="' . esc_attr((string) $conf['reset_url']) . '" placeholder="https://whmcs.example.com/modules/addons/guardian_licensing/api/reset.php" /></label></p>';
		echo '<p><label><strong>License ID</strong><br /><input type="text" name="whmcs_license_id" style="width:100%;" value="' . esc_attr((string) $conf['license_id']) . '" placeholder="GL-..." /></label></p>';
		echo '<p><label><strong>API Secret (consigliato)</strong><br /><input type="password" name="whmcs_api_secret" style="width:100%;" value="' . esc_attr((string) $conf['api_secret']) . '" /></label></p>';
		echo '<p><label><strong>Install ID (auto)</strong><br /><input type="text" readonly style="width:100%;" value="' . esc_attr($this->license->get_install_id()) . '" /></label></p>';
		echo '</div>';

		echo '<p style="max-width:1100px;"><strong>' . esc_html__('Token offline', 'guardian') . '</strong><br />';
		echo '<textarea name="license_token" rows="4" style="width: 100%;" placeholder="Incolla qui la licenza (token)">' . esc_textarea($this->license->get_token()) . '</textarea></p>';

		submit_button(__('Salva impostazioni licenza', 'guardian'));
		echo '</form>';

		if ($mode === 'whmcs') {
			echo '<p style="max-width:1100px;">';
			echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_fetch_license'), 'guardian_fetch_license')) . '">' . esc_html__('Forza refresh licenza da WHMCS', 'guardian') . '</a> ';
			echo '<a class="button" onclick="return confirm(\'Reset dominio su WHMCS per questa licenza?\\nDopo il reset, WHMCS legherà la licenza al nuovo dominio al prossimo validate.\');" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_reset_domain'), 'guardian_reset_domain')) . '">' . esc_html__('Reset dominio (WHMCS)', 'guardian') . '</a>';
			echo ' <a class="button" onclick="return confirm(\'Reset install binding su WHMCS?\\nServe se sposti il sito o rigeneri Install ID.\');" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_reset_install'), 'guardian_reset_install')) . '">' . esc_html__('Reset install binding (WHMCS)', 'guardian') . '</a>';
			echo ' <a class="button" onclick="return confirm(\'Rigenerare Install ID locale?\\nDovrai anche resettare install binding su WHMCS.\');" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_rotate_install_id'), 'guardian_rotate_install_id')) . '">' . esc_html__('Rigenera Install ID', 'guardian') . '</a>';
			echo '</p>';
		}

		if (!$licensed) {
			echo '<hr />';
			echo '<p><strong>' . esc_html__('Guardian è disattivato finché non inserisci una licenza valida.', 'guardian') . '</strong></p>';
			echo '</div>';
			return;
		}

		$payload = $this->license->get_payload();
		$allowed = Modules::allowed_from_license($payload);
		$labels = Modules::labels();
		$enabled = $this->storage->get_settings()['enabled_modules'] ?? [];
		$enabled = is_array($enabled) ? Modules::normalize($enabled) : [Modules::CORE];

		echo '<hr />';
		echo '<h2>' . esc_html__('Moduli (bundle/addon)', 'guardian') . '</h2>';
		echo '<p>' . esc_html__('I moduli disponibili dipendono dal tuo piano licenza (WHMCS/addon/bundle).', 'guardian') . '</p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="guardian_save_modules" />';
		wp_nonce_field('guardian_save_modules');
		foreach (Modules::all() as $m) {
			$can = in_array($m, $allowed, true);
			$isEnabled = in_array($m, $enabled, true);
			$disabledAttr = $can ? '' : ' disabled';
			$checkedAttr = $isEnabled ? ' checked' : '';
			$label = isset($labels[$m]) ? $labels[$m] : $m;
			echo '<label style="display:block; margin: 6px 0;">';
			echo '<input type="checkbox" name="enabled_modules[]" value="' . esc_attr($m) . '"' . $checkedAttr . $disabledAttr . ' /> ';
			echo esc_html($label);
			if (!$can) {
				echo ' <em>(' . esc_html__('non incluso nel piano', 'guardian') . ')</em>';
			}
			echo '</label>';
		}
		submit_button(__('Salva moduli', 'guardian'));
		echo '</form>';

		echo '<h2>' . esc_html__('Azioni rapide', 'guardian') . '</h2>';
		echo '<p>';
		echo '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_create_snapshot'), 'guardian_create_snapshot')) . '">' . esc_html__('Crea snapshot ora', 'guardian') . '</a> ';
		echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_rollback_last'), 'guardian_rollback_last')) . '">' . esc_html__('Rollback ultima operazione', 'guardian') . '</a>';
		if ($op && !empty($op['site_backup_zip'])) {
			echo ' <a class="button" onclick="return confirm(\'Ripristinare l\\\'installazione dai file del backup completo?\\nOperazione potenzialmente distruttiva.\');" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_restore_full_last'), 'guardian_restore_full_last')) . '">' . esc_html__('Ripristina da backup completo (ultima op)', 'guardian') . '</a>';
		}
		echo '</p>';

		echo '<hr />';
		echo '<h2>' . esc_html__('Backup incrementale (restore point)', 'guardian') . '</h2>';
		if (!in_array('backup', (array) ($this->storage->get_settings()['enabled_modules'] ?? []), true)) {
			echo '<p><em>' . esc_html__('Modulo Backup disabilitato (o non incluso nel piano).', 'guardian') . '</em></p>';
		} else {
			echo '<p>';
			echo '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_create_restore_point'), 'guardian_create_restore_point')) . '">' . esc_html__('Crea restore point adesso', 'guardian') . '</a>';
			echo '</p>';

			$list = $this->restorePoints()->list(10);
			if (!$list) {
				echo '<p><em>' . esc_html__('Nessun restore point ancora.', 'guardian') . '</em></p>';
			} else {
				echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr><th>ID</th><th>Label</th><th>Creato</th><th>Files</th><th>DB</th><th>Azioni</th></tr></thead><tbody>';
				foreach ($list as $rp) {
					$id = (string) ($rp['id'] ?? '');
					$label = (string) ($rp['label'] ?? '');
					$created = (string) ($rp['created_gm'] ?? '');
					$cnt = is_array($rp['counts'] ?? null) ? (int) ($rp['counts']['files'] ?? 0) : 0;
					echo '<tr>';
					echo '<td><code>' . esc_html($id) . '</code></td>';
					echo '<td>' . esc_html($label) . '</td>';
					echo '<td>' . esc_html($created) . '</td>';
					echo '<td>' . esc_html((string) $cnt) . '</td>';
					// DB info (best-effort: read manifest).
					$dbCell = '';
					$manifestPath = $this->storage->base_dir() ? $this->storage->base_dir() . '/restore-points/' . sanitize_file_name($id) . '.json.gz' : null;
					$m = ($manifestPath && file_exists($manifestPath)) ? $this->storage->read_json_gz($manifestPath) : null;
					if (is_array($m) && !empty($m['db']) && is_array($m['db'])) {
						$engine = isset($m['db']['engine']) ? (string) $m['db']['engine'] : 'basic';
						$status = isset($m['db']['status']) ? (string) $m['db']['status'] : '';
						$prog = isset($m['db']['progress']['percent']) ? (int) $m['db']['progress']['percent'] : null;
						$dbCell = esc_html($engine) . ($status ? (' / ' . esc_html($status)) : '');
						if ($prog !== null) {
							$dbCell .= ' / ' . esc_html((string) $prog) . '%';
						}
					} else {
						$dbCell = '<em>' . esc_html__('(none)', 'guardian') . '</em>';
					}
					echo '<td>' . $dbCell . '</td>';
					echo '<td>';
					echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
					echo '<input type="hidden" name="action" value="guardian_restore_from_point" />';
					wp_nonce_field('guardian_restore_from_point');
					echo '<input type="hidden" name="restore_point_id" value="' . esc_attr($id) . '" />';
					echo '<select name="restore_rel_path" style="width: 220px;">';
					echo '<option value="">' . esc_html__('(seleziona target)', 'guardian') . '</option>';
					echo '<option value="wp-content/plugins/">' . esc_html__('Plugins (tutti)', 'guardian') . '</option>';
					echo '<option value="wp-content/themes/">' . esc_html__('Themes (tutti)', 'guardian') . '</option>';
					echo '</select> ';
					echo '<input type="text" name="restore_rel_path_custom" style="width: 240px;" placeholder="oppure path custom: wp-content/plugins/slug/" />';
					echo ' <label><input type="checkbox" name="delete_first" value="1" /> ' . esc_html__('cancella prima', 'guardian') . '</label>';
					echo ' <label><input type="checkbox" name="restore_db" value="1" /> ' . esc_html__('restore DB', 'guardian') . '</label>';
					echo ' <button class="button" type="submit" onclick="return confirm(\'Eseguire restore del path indicato da questo restore point?\');">' . esc_html__('Restore', 'guardian') . '</button>';
					echo '</form>';
					echo '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
				echo '<p><small>' . esc_html__('Suggerimento: per ripristinare un plugin usa: wp-content/plugins/nome-plugin/', 'guardian') . '</small></p>';
			}
		}

		// DB Pro jobs dashboard (paid).
		echo '<hr />';
		echo '<h2>' . esc_html__('DB Pro jobs (Backup Pro)', 'guardian') . '</h2>';
		$payload = $this->license->get_payload();
		$backupPro = !empty($payload['feat']['backup_pro']);
		if (!$backupPro) {
			echo '<p><em>' . esc_html__('Backup Pro non incluso nel piano.', 'guardian') . '</em></p>';
		} else {
			$pro = new DbBackupPro($this->storage);
			$expJobs = $pro->list_export_jobs(10);
			$resJobs = $pro->list_restore_jobs(10);
			echo '<h3>' . esc_html__('Export jobs', 'guardian') . '</h3>';
			if (!$expJobs) {
				echo '<p><em>(none)</em></p>';
			} else {
				echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr><th>ID</th><th>RP</th><th>Status</th><th>Progress</th><th>Updated</th></tr></thead><tbody>';
				foreach ($expJobs as $j) {
					$pct = isset($j['progress']['percent']) ? (int) $j['progress']['percent'] : 0;
					echo '<tr><td><code>' . esc_html((string) $j['id']) . '</code></td><td>' . esc_html((string) $j['restore_point_id']) . '</td><td>' . esc_html((string) $j['status']) . '</td><td>' . esc_html((string) $pct) . '%</td><td>' . esc_html((string) $j['updated_gm']) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '<h3>' . esc_html__('Restore jobs', 'guardian') . '</h3>';
			if (!$resJobs) {
				echo '<p><em>(none)</em></p>';
			} else {
				echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr><th>ID</th><th>Status</th><th>Progress</th><th>Updated</th></tr></thead><tbody>';
				foreach ($resJobs as $j) {
					$pct = isset($j['progress']['percent']) ? (int) $j['progress']['percent'] : 0;
					echo '<tr><td><code>' . esc_html((string) $j['id']) . '</code></td><td>' . esc_html((string) $j['status']) . '</td><td>' . esc_html((string) $pct) . '%</td><td>' . esc_html((string) $j['updated_gm']) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}

		echo '<hr />';

		echo '<h2>' . esc_html__('Impostazioni', 'guardian') . '</h2>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="guardian_save_settings" />';
		wp_nonce_field('guardian_save_settings');
		echo '<label><input type="checkbox" name="auto_backup_on_upgrade" ' . checked(!empty($settings['auto_backup_on_upgrade']), true, false) . ' /> ' . esc_html__('Backup ZIP automatico prima di install/upgrade plugin/tema', 'guardian') . '</label><br />';
		echo '<label><input type="checkbox" name="auto_snapshot_on_upgrade" ' . checked(!empty($settings['auto_snapshot_on_upgrade']), true, false) . ' /> ' . esc_html__('Snapshot (hash) automatico pre/post upgrade', 'guardian') . '</label><br />';
		echo '<label><input type="checkbox" name="auto_rollback_on_fatal" ' . checked(!empty($settings['auto_rollback_on_fatal']), true, false) . ' /> ' . esc_html__('Auto-rollback su fatal (consigliato con MU-loader)', 'guardian') . '</label><br />';
		echo '<label><input type="checkbox" name="include_uploads" ' . checked(!empty($settings['include_uploads']), true, false) . ' /> ' . esc_html__('Includi wp-content/uploads negli snapshot (può essere molto lento)', 'guardian') . '</label><br />';
		echo '<label><input type="checkbox" name="full_backup_on_upgrade" ' . checked(!empty($settings['full_backup_on_upgrade']), true, false) . ' /> ' . esc_html__('Crea backup ZIP completo dell’installazione prima di install/upgrade (molto lento/pesante)', 'guardian') . '</label><br />';
		echo '<label style="margin-left: 18px;"><input type="checkbox" name="full_restore_include_wp_config" ' . checked(!empty($settings['full_restore_include_wp_config']), true, false) . ' /> ' . esc_html__('Nel ripristino completo includi anche wp-config.php (rischioso)', 'guardian') . '</label><br />';

		echo '<hr style="max-width:1100px; margin: 16px 0;" />';
		echo '<h3>' . esc_html__('Restore point schedulati (Backup incrementale)', 'guardian') . '</h3>';
		echo '<p style="max-width:1100px;">' . esc_html__('Crea automaticamente restore point incrementali. Consigliato per “set and forget”.', 'guardian') . '</p>';
		echo '<p><label><strong>' . esc_html__('Frequenza', 'guardian') . '</strong> ';
		$rpSchedule = (string) ($settings['rp_schedule'] ?? 'daily');
		echo '<select name="rp_schedule">';
		echo '<option value="off"' . selected($rpSchedule, 'off', false) . '>' . esc_html__('Off', 'guardian') . '</option>';
		echo '<option value="hourly"' . selected($rpSchedule, 'hourly', false) . '>' . esc_html__('Hourly', 'guardian') . '</option>';
		echo '<option value="daily"' . selected($rpSchedule, 'daily', false) . '>' . esc_html__('Daily', 'guardian') . '</option>';
		echo '</select></label></p>';

		echo '<p><strong>' . esc_html__('Scope', 'guardian') . '</strong><br />';
		echo '<label><input type="checkbox" name="rp_scope_plugins_themes" ' . checked(!empty($settings['rp_scope_plugins_themes']), true, false) . ' /> ' . esc_html__('Plugin + Temi', 'guardian') . '</label><br />';
		echo '<label><input type="checkbox" name="rp_scope_wp_config" ' . checked(!empty($settings['rp_scope_wp_config']), true, false) . ' /> ' . esc_html__('Include wp-config.php', 'guardian') . '</label><br />';
		echo '<label><input type="checkbox" name="rp_scope_core" ' . checked(!empty($settings['rp_scope_core']), true, false) . ' /> ' . esc_html__('Include core (wp-admin/wp-includes) (pesante)', 'guardian') . '</label><br />';
		echo '<label><input type="checkbox" name="rp_scope_uploads" ' . checked(!empty($settings['rp_scope_uploads']), true, false) . ' /> ' . esc_html__('Include uploads (molto pesante)', 'guardian') . '</label></p>';

		echo '<p><strong>' . esc_html__('Database (opzionale)', 'guardian') . '</strong><br />';
		echo '<label><input type="checkbox" name="rp_include_db" ' . checked(!empty($settings['rp_include_db']), true, false) . ' /> ' . esc_html__('Include snapshot DB nel restore point (best-effort)', 'guardian') . '</label><br />';
		$payload = $this->license->get_payload();
		$backupPro = !empty($payload['feat']['backup_pro']);
		$rpDbEngine = (string) ($settings['rp_db_engine'] ?? 'basic');
		echo '<label>' . esc_html__('DB engine', 'guardian') . ' ';
		echo '<select name="rp_db_engine">';
		echo '<option value="basic"' . selected($rpDbEngine, 'basic', false) . '>' . esc_html__('Basic (single dump, no resume)', 'guardian') . '</option>';
		echo '<option value="pro"' . selected($rpDbEngine, 'pro', false) . ($backupPro ? '' : ' disabled') . '>' . esc_html__('Pro (chunk/resume) - paid', 'guardian') . '</option>';
		echo '</select></label><br />';
		$rpDbTables = (string) ($settings['rp_db_tables'] ?? 'wp_core');
		echo '<label>' . esc_html__('Tabelle', 'guardian') . ' ';
		echo '<select name="rp_db_tables">';
		echo '<option value="wp_core"' . selected($rpDbTables, 'wp_core', false) . '>' . esc_html__('WP core (posts/options/users/…) ', 'guardian') . '</option>';
		echo '<option value="all_prefix"' . selected($rpDbTables, 'all_prefix', false) . '>' . esc_html__('Tutte le tabelle con prefisso WP', 'guardian') . '</option>';
		echo '<option value="custom"' . selected($rpDbTables, 'custom', false) . '>' . esc_html__('Custom (lista)', 'guardian') . '</option>';
		echo '</select></label><br />';
		echo '<label>' . esc_html__('Custom tables (una per riga)', 'guardian') . '<br /><textarea name="rp_db_custom_tables" rows="3" style="width:100%; max-width:1100px;">' . esc_textarea((string) ($settings['rp_db_custom_tables'] ?? '')) . '</textarea></label><br />';
		echo '<label>' . esc_html__('Max seconds per dump', 'guardian') . ' <input type="number" name="rp_db_max_seconds" value="' . esc_attr((string) ((int) ($settings['rp_db_max_seconds'] ?? 20))) . '" min="5" max="120" /></label>';
		echo '</p>';

		echo '<p><strong>' . esc_html__('Pre-upgrade', 'guardian') . '</strong><br />';
		echo '<label><input type="checkbox" name="rp_pre_upgrade_include_db" ' . checked(!empty($settings['rp_pre_upgrade_include_db']), true, false) . ' /> ' . esc_html__('Include DB nei restore point pre-upgrade (plugin/tema)', 'guardian') . '</label><br />';
		echo '<label><input type="checkbox" name="rp_pre_upgrade_core_files" ' . checked(!empty($settings['rp_pre_upgrade_core_files']), true, false) . ' /> ' . esc_html__('Crea restore point anche prima di core update (pesante)', 'guardian') . '</label></p>';

		submit_button(__('Salva impostazioni', 'guardian'));
		echo '</form>';

		echo '<hr />';

		echo '<h2>' . esc_html__('Ultima operazione monitorata', 'guardian') . '</h2>';
		if (!$op) {
			echo '<p>' . esc_html__('Nessuna operazione registrata ancora.', 'guardian') . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped" style="max-width: 1100px;">';
		echo '<tbody>';
		$this->tr('ID', esc_html((string) ($op['id'] ?? '')));
		$this->tr('Tipo', esc_html((string) ($op['type'] ?? '')));
		$this->tr('Stato', esc_html((string) ($op['status'] ?? '')));
		$this->tr('Inizio', esc_html((string) ($op['started_gm'] ?? '')));
		$this->tr('Fine', esc_html((string) ($op['ended_gm'] ?? '')));
		$this->tr('Backup ZIP', !empty($op['backup_zip']) ? esc_html((string) $op['backup_zip']) : esc_html__('(non disponibile)', 'guardian'));
		$this->tr('Snapshot pre', esc_html((string) ($op['snapshot_before'] ?? '')));
		$this->tr('Snapshot post', esc_html((string) ($op['snapshot_after'] ?? '')));
		$this->tr('Report', esc_html((string) ($op['report_id'] ?? '')));
		echo '</tbody>';
		echo '</table>';

		$this->render_last_report($op);

		echo '</div>';
	}

	private function render_last_report(array $op): void {
		$reportPath = isset($op['report_path']) && is_string($op['report_path']) ? $op['report_path'] : null;
		if (!$reportPath || !file_exists($reportPath)) {
			return;
		}
		$report = $this->storage->read_json_gz($reportPath);
		if (!$report || empty($report['diff']) || !is_array($report['diff'])) {
			return;
		}

		$diff = $report['diff'];
		$added = isset($diff['added']) && is_array($diff['added']) ? $diff['added'] : [];
		$removed = isset($diff['removed']) && is_array($diff['removed']) ? $diff['removed'] : [];
		$modified = isset($diff['modified']) && is_array($diff['modified']) ? $diff['modified'] : [];

		echo '<h3>' . esc_html__('Differenze rilevate (pre → post)', 'guardian') . '</h3>';
		echo '<p>';
		echo esc_html(sprintf(__('Aggiunti: %d — Rimossi: %d — Modificati: %d', 'guardian'), count($added), count($removed), count($modified)));
		echo '</p>';

		$this->render_file_list('Modificati', $modified, true);
		$this->render_file_list('Aggiunti', $added, false);
		$this->render_file_list('Rimossi', $removed, false);
	}

	private function render_file_list(string $title, array $items, bool $withDiffLink): void {
		$max = 80;
		echo '<h4>' . esc_html($title) . '</h4>';
		if (!$items) {
			echo '<p><em>' . esc_html__('(nessuno)', 'guardian') . '</em></p>';
			return;
		}
		echo '<ul style="max-width: 1100px;">';
		$i = 0;
		foreach ($items as $path => $_meta) {
			$i++;
			if ($i > $max) {
				echo '<li><em>' . esc_html__('…lista troncata…', 'guardian') . '</em></li>';
				break;
			}
			$li = esc_html((string) $path);
			if ($withDiffLink) {
				$url = add_query_arg(
					['page' => 'guardian', 'guardian_diff' => (string) $path],
					admin_url('admin.php')
				);
				$li .= ' — <a href="' . esc_url($url) . '">' . esc_html__('Diff', 'guardian') . '</a>';
			}
			echo '<li>' . $li . '</li>';
		}
		echo '</ul>';
	}

	private function render_diff_view(string $relPathRaw, ?array $op): void {
		if (!$op) {
			wp_die(__('Nessuna operazione disponibile per calcolare diff.', 'guardian'));
		}

		$rel = ltrim(str_replace('\\', '/', $relPathRaw), '/');

		// Basic safety: il path deve restare relativo e non contenere traversal.
		if ($rel === '' || strpos($rel, '..') !== false) {
			wp_die(__('Path non valido.', 'guardian'));
		}

		echo '<div class="wrap">';
		echo '<h1>Guardian — Diff</h1>';
		echo '<p><a href="' . esc_url(admin_url('admin.php?page=guardian')) . '">&larr; ' . esc_html__('Torna a Guardian', 'guardian') . '</a></p>';
		echo '<h2><code>' . esc_html($rel) . '</code></h2>';

		$zip = (string) ($op['backup_zip'] ?? '');
		$type = (string) ($op['type'] ?? '');

		$old = null;
		if ($zip && $type === 'plugin') {
			$pluginFile = (string) ($op['plugin'] ?? '');
			$pluginDirName = $this->plugin_dirname_from_plugin_file($pluginFile);
			if ($pluginDirName && strpos($rel, 'wp-content/plugins/' . $pluginDirName . '/') === 0) {
				$inner = $pluginDirName . '/' . substr($rel, strlen('wp-content/plugins/' . $pluginDirName . '/'));
				$old = $this->backup()->read_file_from_backup_zip($zip, $inner);
			}
		} elseif ($zip && $type === 'theme') {
			$themeSlug = (string) ($op['theme'] ?? '');
			if ($themeSlug && strpos($rel, 'wp-content/themes/' . $themeSlug . '/') === 0) {
				$inner = $themeSlug . '/' . substr($rel, strlen('wp-content/themes/' . $themeSlug . '/'));
				$old = $this->backup()->read_file_from_backup_zip($zip, $inner);
			}
		}

		$newAbs = defined('ABSPATH') ? rtrim(ABSPATH, '/\\') . '/' . $rel : null;
		$new = ($newAbs && file_exists($newAbs)) ? file_get_contents($newAbs) : null;

		$maxBytes = (int) ($this->storage->get_settings()['max_diff_bytes'] ?? (1024 * 1024));
		if (is_string($old) && strlen($old) > $maxBytes) {
			$old = substr($old, 0, $maxBytes) . "\n\n/* …TRONCATO… */\n";
		}
		if (is_string($new) && strlen($new) > $maxBytes) {
			$new = substr($new, 0, $maxBytes) . "\n\n/* …TRONCATO… */\n";
		}

		if (!is_string($old)) {
			echo '<p><em>' . esc_html__('Versione precedente non disponibile (file fuori dall’area backuppata o nessun backup ZIP).', 'guardian') . '</em></p>';
			echo '</div>';
			return;
		}
		if (!is_string($new)) {
			echo '<p><em>' . esc_html__('File attuale non trovato (potrebbe essere stato rimosso).', 'guardian') . '</em></p>';
			echo '</div>';
			return;
		}

		if (!$this->is_probably_text($old) || !$this->is_probably_text($new)) {
			echo '<p><em>' . esc_html__('Diff disponibile solo per file testuali (questo sembra binario).', 'guardian') . '</em></p>';
			echo '</div>';
			return;
		}

		if (function_exists('wp_text_diff')) {
			$diffHtml = wp_text_diff($old, $new, ['show_split_view' => true]);
			echo $diffHtml ? $diffHtml : '<p><em>' . esc_html__('Nessuna differenza testuale.', 'guardian') . '</em></p>';
		} else {
			echo '<pre style="white-space: pre-wrap; max-width: 1100px; background: #fff; padding: 12px; border: 1px solid #ddd;">';
			echo esc_html($old);
			echo "\n\n-----\n\n";
			echo esc_html($new);
			echo '</pre>';
		}

		echo '</div>';
	}

	private function render_notices(): void {
		$notice = isset($_GET['guardian_notice']) ? (string) $_GET['guardian_notice'] : '';
		$map = [
			'snapshot_ok' => ['success', __('Snapshot creato.', 'guardian')],
			'snapshot_fail' => ['error', __('Snapshot non riuscito.', 'guardian')],
			'rollback_done' => ['success', __('Rollback eseguito (best-effort).', 'guardian')],
			'full_restore_done' => ['success', __('Ripristino completo applicato (best-effort).', 'guardian')],
			'full_restore_fail' => ['error', __('Ripristino completo non riuscito.', 'guardian')],
			'settings_saved' => ['success', __('Impostazioni salvate.', 'guardian')],
			'license_ok' => ['success', __('Licenza valida.', 'guardian')],
			'license_fail' => ['error', __('Licenza non valida.', 'guardian')],
			'domain_reset_ok' => ['success', __('Dominio resettato (WHMCS).', 'guardian')],
			'domain_reset_fail' => ['error', __('Reset dominio non riuscito.', 'guardian')],
			'install_reset_ok' => ['success', __('Install binding resettato (WHMCS).', 'guardian')],
			'install_reset_fail' => ['error', __('Reset install binding non riuscito.', 'guardian')],
			'install_id_rotated' => ['success', __('Install ID rigenerato.', 'guardian')],
			'modules_saved' => ['success', __('Moduli salvati.', 'guardian')],
			'rp_created' => ['success', __('Restore point creato.', 'guardian')],
			'rp_create_fail' => ['error', __('Creazione restore point non riuscita.', 'guardian')],
			'rp_restore_ok' => ['success', __('Restore completato.', 'guardian')],
			'rp_restore_fail' => ['error', __('Restore non riuscito.', 'guardian')],
			'dbpro_restore_started' => ['success', __('DB Pro restore avviato (background).', 'guardian')],
		];
		if ($notice && isset($map[$notice])) {
			[$cls, $msg] = $map[$notice];
			echo '<div class="notice notice-' . esc_attr($cls) . '"><p>' . esc_html($msg) . '</p></div>';
		}
	}

	private function tr(string $k, string $v): void {
		echo '<tr><th style="width: 180px;">' . esc_html($k) . '</th><td>' . $v . '</td></tr>';
	}

	private function plugin_dirname_from_plugin_file(string $pluginFile): ?string {
		$pluginFile = str_replace('\\', '/', $pluginFile);
		$parts = explode('/', $pluginFile);
		return count($parts) >= 2 ? $parts[0] : null;
	}

	private function is_probably_text(string $s): bool {
		// Heuristica semplice: se contiene byte null è quasi certamente binario.
		return strpos($s, "\0") === false;
	}

	private function ensure_licensed_or_die(): bool {
		$st = $this->license->status();
		if (!empty($st['ok'])) {
			return true;
		}
		wp_die(esc_html($st['message'] ?? __('Licenza non valida.', 'guardian')));
		return false;
	}

	private function scanner(): Scanner {
		if ($this->scanner === null) {
			$this->scanner = new Scanner($this->storage);
		}
		return $this->scanner;
	}

	private function backup(): Backup {
		if ($this->backup === null) {
			$this->backup = new Backup($this->storage);
		}
		return $this->backup;
	}

	private function restorePoints(): RestorePoints {
		if ($this->restorePoints === null) {
			$this->restorePoints = new RestorePoints($this->storage);
		}
		return $this->restorePoints;
	}
}

