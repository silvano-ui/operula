<?php

namespace Guardian;

final class AdminSectionLicense implements AdminSectionInterface {
	public function id(): string {
		return 'license';
	}

	public function label(): string {
		return __('Licenza', 'guardian');
	}

	public function register_actions(ModuleContext $ctx): void {
		add_action('admin_post_guardian_save_license', function () use ($ctx): void {
			$this->handle_save_license($ctx);
		});
		add_action('admin_post_guardian_fetch_license', function () use ($ctx): void {
			$this->handle_fetch_license($ctx);
		});
		add_action('admin_post_guardian_reset_domain', function () use ($ctx): void {
			$this->handle_reset_domain($ctx);
		});
		add_action('admin_post_guardian_reset_install', function () use ($ctx): void {
			$this->handle_reset_install($ctx);
		});
		add_action('admin_post_guardian_rotate_install_id', function () use ($ctx): void {
			$this->handle_rotate_install_id($ctx);
		});
	}

	public function render(ModuleContext $ctx): void {
		// Soft refresh in WHMCS mode when opening the license tab.
		if ($ctx->license->get_mode() === 'whmcs') {
			$ctx->license->refresh_from_whmcs_if_needed(false);
		}

		$licenseStatus = $ctx->license->status();
		$mode = $ctx->license->get_mode();

		echo '<p>' . esc_html((string) ($licenseStatus['message'] ?? '')) . '</p>';

		if (empty($licenseStatus['ok']) && $mode === 'whmcs' && !empty($licenseStatus['whmcs']['status'])) {
			$wst = (string) $licenseStatus['whmcs']['status'];
			if ($wst === 'domain_reset_required') {
				echo '<div class="notice notice-warning"><p><strong>Guardian</strong>: ' . esc_html__('Dominio cambiato: serve reset dominio su WHMCS.', 'guardian') . '</p></div>';
			} elseif ($wst === 'install_reset_required') {
				echo '<div class="notice notice-warning"><p><strong>Guardian</strong>: ' . esc_html__('Installazione diversa: serve reset install binding su WHMCS.', 'guardian') . '</p></div>';
			}
		}

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="guardian_save_license" />';
		wp_nonce_field('guardian_save_license');

		echo '<p>';
		echo '<label style="margin-right:12px;"><input type="radio" name="license_mode" value="offline" ' . checked($mode, 'offline', false) . ' /> ' . esc_html__('Token offline (incolla)', 'guardian') . '</label>';
		echo '<label><input type="radio" name="license_mode" value="whmcs" ' . checked($mode, 'whmcs', false) . ' /> ' . esc_html__('WHMCS (auto-recupero)', 'guardian') . '</label>';
		echo '</p>';

		$conf = $ctx->license->get_whmcs_conf();
		echo '<div style="padding:12px; border:1px solid #ddd; background:#fff; max-width:1100px;">';
		echo '<h3 style="margin-top:0;">' . esc_html__('Configurazione WHMCS', 'guardian') . '</h3>';
		echo '<p><label><strong>Validate URL</strong><br /><input type="url" name="whmcs_validate_url" style="width:100%;" value="' . esc_attr((string) ($conf['validate_url'] ?? '')) . '" placeholder="https://whmcs.example.com/modules/addons/guardian_licensing/api/validate.php" /></label></p>';
		echo '<p><label><strong>Reset URL</strong><br /><input type="url" name="whmcs_reset_url" style="width:100%;" value="' . esc_attr((string) ($conf['reset_url'] ?? '')) . '" placeholder="https://whmcs.example.com/modules/addons/guardian_licensing/api/reset.php" /></label></p>';
		echo '<p><label><strong>License ID</strong><br /><input type="text" name="whmcs_license_id" style="width:100%;" value="' . esc_attr((string) ($conf['license_id'] ?? '')) . '" placeholder="GL-..." /></label></p>';
		echo '<p><label><strong>API Secret (consigliato)</strong><br /><input type="password" name="whmcs_api_secret" style="width:100%;" value="' . esc_attr((string) ($conf['api_secret'] ?? '')) . '" /></label></p>';
		echo '<p><label><strong>Install ID (auto)</strong><br /><input type="text" readonly style="width:100%;" value="' . esc_attr($ctx->license->get_install_id()) . '" /></label></p>';
		echo '</div>';

		echo '<p style="max-width:1100px;"><strong>' . esc_html__('Token offline', 'guardian') . '</strong><br />';
		echo '<textarea name="license_token" rows="4" style="width: 100%;" placeholder="Incolla qui la licenza (token)">' . esc_textarea($ctx->license->get_token()) . '</textarea></p>';

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
	}

	private function ensure_manage_options(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
	}

	private function redirect_ok(string $msg): void {
		wp_safe_redirect(add_query_arg(['guardian_ok' => $msg, 'tab' => $this->id()], admin_url('admin.php?page=guardian')));
		exit;
	}

	private function redirect_err(string $msg): void {
		wp_safe_redirect(add_query_arg(['guardian_err' => $msg, 'tab' => $this->id()], admin_url('admin.php?page=guardian')));
		exit;
	}

	private function handle_save_license(ModuleContext $ctx): void {
		$this->ensure_manage_options();
		check_admin_referer('guardian_save_license');

		$mode = isset($_POST['license_mode']) ? (string) wp_unslash($_POST['license_mode']) : 'offline';
		$ctx->license->set_mode($mode);

		if ($mode === 'whmcs') {
			$conf = [
				'validate_url' => isset($_POST['whmcs_validate_url']) ? (string) wp_unslash($_POST['whmcs_validate_url']) : '',
				'reset_url' => isset($_POST['whmcs_reset_url']) ? (string) wp_unslash($_POST['whmcs_reset_url']) : '',
				'license_id' => isset($_POST['whmcs_license_id']) ? (string) wp_unslash($_POST['whmcs_license_id']) : '',
				'api_secret' => isset($_POST['whmcs_api_secret']) ? (string) wp_unslash($_POST['whmcs_api_secret']) : '',
			];
			$ctx->license->save_whmcs_conf($conf);
		} else {
			$token = isset($_POST['license_token']) ? (string) wp_unslash($_POST['license_token']) : '';
			$ctx->license->save_token($token);
		}

		$st = $ctx->license->status();
		if (!empty($st['ok'])) {
			$this->redirect_ok((string) __('Licenza valida.', 'guardian'));
		}
		$this->redirect_err((string) __('Licenza non valida.', 'guardian'));
	}

	private function handle_fetch_license(ModuleContext $ctx): void {
		$this->ensure_manage_options();
		check_admin_referer('guardian_fetch_license');

		$st = $ctx->license->refresh_from_whmcs_if_needed(true);
		if ($st && !empty($st['ok'])) {
			$this->redirect_ok((string) __('Licenza aggiornata.', 'guardian'));
		}
		$this->redirect_err((string) __('Impossibile aggiornare la licenza.', 'guardian'));
	}

	private function handle_reset_domain(ModuleContext $ctx): void {
		$this->ensure_manage_options();
		check_admin_referer('guardian_reset_domain');

		$r = $ctx->license->request_domain_reset();
		if (!empty($r['ok'])) {
			$this->redirect_ok((string) __('Dominio resettato (WHMCS).', 'guardian'));
		}
		$this->redirect_err((string) __('Reset dominio non riuscito.', 'guardian'));
	}

	private function handle_reset_install(ModuleContext $ctx): void {
		$this->ensure_manage_options();
		check_admin_referer('guardian_reset_install');

		$r = $ctx->license->request_install_reset();
		if (!empty($r['ok'])) {
			$this->redirect_ok((string) __('Install binding resettato (WHMCS).', 'guardian'));
		}
		$this->redirect_err((string) __('Reset install binding non riuscito.', 'guardian'));
	}

	private function handle_rotate_install_id(ModuleContext $ctx): void {
		$this->ensure_manage_options();
		check_admin_referer('guardian_rotate_install_id');

		$ctx->license->rotate_install_id();
		$this->redirect_ok((string) __('Install ID rigenerato.', 'guardian'));
	}
}

