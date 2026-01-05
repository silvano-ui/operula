<?php

namespace Guardian;

final class AdminSectionModules implements AdminSectionInterface {
	public function id(): string {
		return 'modules';
	}

	public function label(): string {
		return __('Moduli', 'guardian');
	}

	public function register_actions(ModuleContext $ctx): void {
		add_action('admin_post_guardian_save_modules', function () use ($ctx): void {
			$this->handle_save_modules($ctx);
		});
	}

	public function render(ModuleContext $ctx): void {
		$st = $ctx->license->status();
		if (empty($st['ok'])) {
			echo '<p><strong>' . esc_html__('Guardian è disattivato finché non inserisci una licenza valida.', 'guardian') . '</strong></p>';
			return;
		}

		$payload = $ctx->license->get_payload();
		$allowed = Modules::allowed_from_license($payload);
		$labels = Modules::labels();
		$enabled = $ctx->settings['enabled_modules'] ?? [];
		$enabled = is_array($enabled) ? Modules::normalize($enabled) : [Modules::CORE];

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

	private function handle_save_modules(ModuleContext $ctx): void {
		$this->ensure_manage_options();
		check_admin_referer('guardian_save_modules');

		$st = $ctx->license->status();
		if (empty($st['ok'])) {
			wp_die(esc_html($st['message'] ?? __('Licenza non valida.', 'guardian')));
		}

		$payload = $ctx->license->get_payload();
		$allowed = Modules::allowed_from_license($payload);

		$mods = isset($_POST['enabled_modules']) ? (array) $_POST['enabled_modules'] : [];
		$mods = array_map('sanitize_text_field', $mods);
		$mods = Modules::normalize($mods);
		$mods = array_values(array_intersect($mods, $allowed));

		$settings = $ctx->storage->get_settings();
		$settings['enabled_modules'] = $mods;
		$ctx->storage->update_settings($settings);

		$this->redirect_ok((string) __('Moduli salvati.', 'guardian'));
	}
}

