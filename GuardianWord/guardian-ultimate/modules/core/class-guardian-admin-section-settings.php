<?php

namespace Guardian;

final class AdminSectionSettings implements AdminSectionInterface {
	public function id(): string {
		return 'settings';
	}

	public function label(): string {
		return __('Impostazioni', 'guardian');
	}

	public function register_actions(ModuleContext $ctx): void {
		add_action('admin_post_guardian_save_settings', function () use ($ctx): void {
			$this->handle_save_settings($ctx);
		});
	}

	public function render(ModuleContext $ctx): void {
		$st = $ctx->license->status();
		if (empty($st['ok'])) {
			echo '<p><strong>' . esc_html__('Guardian è disattivato finché non inserisci una licenza valida.', 'guardian') . '</strong></p>';
			return;
		}

		$settings = $ctx->storage->get_settings();
		$payload = $ctx->license->get_payload();
		$backupPro = !empty($payload['feat']['backup_pro']);

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
	}

	private function ensure_manage_options(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
	}

	private function ensure_licensed_or_die(ModuleContext $ctx): void {
		$st = $ctx->license->status();
		if (!empty($st['ok'])) {
			return;
		}
		wp_die(esc_html($st['message'] ?? __('Licenza non valida.', 'guardian')));
	}

	private function redirect_ok(string $msg): void {
		wp_safe_redirect(add_query_arg(['guardian_ok' => $msg, 'tab' => $this->id()], admin_url('admin.php?page=guardian')));
		exit;
	}

	private function handle_save_settings(ModuleContext $ctx): void {
		$this->ensure_manage_options();
		check_admin_referer('guardian_save_settings');
		$this->ensure_licensed_or_die($ctx);

		$settings = $ctx->storage->get_settings();
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

		$ctx->storage->update_settings($settings);
		Plugin::reschedule_restore_points();

		$this->redirect_ok((string) __('Impostazioni salvate.', 'guardian'));
	}
}

