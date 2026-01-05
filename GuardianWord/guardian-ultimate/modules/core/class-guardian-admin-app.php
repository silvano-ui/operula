<?php

namespace Guardian;

final class AdminApp {
	/** @var ModuleContext */
	private $ctx;

	public function __construct(ModuleContext $ctx) {
		$this->ctx = $ctx;
	}

	public function register(): void {
		if (!is_admin()) {
			return;
		}

		// Let all sections hook admin_post, etc (after modules registered).
		add_action('admin_init', function (): void {
			static $done = false;
			if ($done) {
				return;
			}
			$done = true;
			AdminRegistry::register_actions($this->ctx);
		});

		add_action('admin_menu', function (): void {
			add_menu_page(
				__('Guardian Ultimate', 'guardian'),
				__('Guardian Ultimate', 'guardian'),
				'manage_options',
				'guardian',
				[$this, 'render_page'],
				'dashicons-shield',
				56
			);
		});
	}

	public function render_page(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Insufficient permissions.', 'guardian'));
		}

		$sections = AdminRegistry::all();
		if (!$sections) {
			echo '<div class="wrap"><h1>' . esc_html__('Guardian Ultimate', 'guardian') . '</h1>';
			echo '<p>' . esc_html__('No admin sections are registered.', 'guardian') . '</p></div>';
			return;
		}

		$tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : '';
		if ($tab === '' || !isset($sections[$tab])) {
			$first = array_key_first($sections);
			$tab = is_string($first) ? $first : (string) $tab;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('Guardian Ultimate', 'guardian') . '</h1>';

		$this->render_notices();
		$this->render_tabs($sections, $tab);

		echo '<div style="margin-top: 16px;">';
		if (isset($sections[$tab])) {
			$sections[$tab]->render($this->ctx);
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * @param array<string, AdminSectionInterface> $sections
	 */
	private function render_tabs(array $sections, string $active): void {
		echo '<h2 class="nav-tab-wrapper">';
		foreach ($sections as $id => $section) {
			$url = add_query_arg(['page' => 'guardian', 'tab' => $id], admin_url('admin.php'));
			$cls = 'nav-tab' . ($id === $active ? ' nav-tab-active' : '');
			echo '<a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . esc_html($section->label()) . '</a>';
		}
		echo '</h2>';
	}

	private function render_notices(): void {
		// Basic status feedback from redirects.
		$ok = isset($_GET['guardian_ok']) ? sanitize_text_field((string) $_GET['guardian_ok']) : '';
		$err = isset($_GET['guardian_err']) ? sanitize_text_field((string) $_GET['guardian_err']) : '';

		if ($ok !== '') {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($ok) . '</p></div>';
		}
		if ($err !== '') {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($err) . '</p></div>';
		}
	}
}

