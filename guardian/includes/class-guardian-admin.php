<?php

namespace Guardian;

final class Admin {
	private Storage $storage;
	private Scanner $scanner;
	private Backup $backup;

	public function __construct(Storage $storage, Scanner $scanner, Backup $backup) {
		$this->storage = $storage;
		$this->scanner = $scanner;
		$this->backup  = $backup;
	}

	public function register(): void {
		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('admin_post_guardian_create_snapshot', [$this, 'handle_create_snapshot']);
		add_action('admin_post_guardian_rollback_last', [$this, 'handle_rollback_last']);
		add_action('admin_post_guardian_restore_full_last', [$this, 'handle_restore_full_last']);
		add_action('admin_post_guardian_save_settings', [$this, 'handle_save_settings']);
	}

	public function admin_menu(): void {
		add_menu_page(
			__('Guardian', 'guardian'),
			__('Guardian', 'guardian'),
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

		$this->storage->ensure_directories();
		$snap = $this->scanner->create_snapshot('manual', [
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

		$op = $this->storage->get_last_operation();
		if ($op) {
			$this->backup->rollback_last_operation($op);
		}

		wp_safe_redirect(add_query_arg(['guardian_notice' => 'rollback_done'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function handle_restore_full_last(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_restore_full_last');

		$op = $this->storage->get_last_operation();
		$zip = $op && !empty($op['site_backup_zip']) ? (string) $op['site_backup_zip'] : '';
		$settings = $this->storage->get_settings();
		$includeWpConfig = !empty($settings['full_restore_include_wp_config']);

		$ok = false;
		if ($zip && defined('ABSPATH')) {
			$ok = $this->backup->restore_installation_from_backup($zip, ABSPATH, $includeWpConfig);
		}

		wp_safe_redirect(add_query_arg(['guardian_notice' => $ok ? 'full_restore_done' : 'full_restore_fail'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function handle_save_settings(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
		check_admin_referer('guardian_save_settings');

		$settings = $this->storage->get_settings();
		$settings['auto_backup_on_upgrade'] = !empty($_POST['auto_backup_on_upgrade']);
		$settings['auto_snapshot_on_upgrade'] = !empty($_POST['auto_snapshot_on_upgrade']);
		$settings['auto_rollback_on_fatal'] = !empty($_POST['auto_rollback_on_fatal']);
		$settings['include_uploads'] = !empty($_POST['include_uploads']);
		$settings['full_backup_on_upgrade'] = !empty($_POST['full_backup_on_upgrade']);
		$settings['full_restore_include_wp_config'] = !empty($_POST['full_restore_include_wp_config']);

		$this->storage->update_settings($settings);

		wp_safe_redirect(add_query_arg(['guardian_notice' => 'settings_saved'], admin_url('admin.php?page=guardian')));
		exit;
	}

	public function render_page(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}

		$op = $this->storage->get_last_operation();
		$settings = $this->storage->get_settings();

		$diffPath = isset($_GET['guardian_diff']) ? (string) $_GET['guardian_diff'] : '';
		if ($diffPath !== '') {
			$this->render_diff_view($diffPath, $op);
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>Guardian</h1>';

		$this->render_notices();

		echo '<h2>' . esc_html__('Azioni rapide', 'guardian') . '</h2>';
		echo '<p>';
		echo '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_create_snapshot'), 'guardian_create_snapshot')) . '">' . esc_html__('Crea snapshot ora', 'guardian') . '</a> ';
		echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_rollback_last'), 'guardian_rollback_last')) . '">' . esc_html__('Rollback ultima operazione', 'guardian') . '</a>';
		if ($op && !empty($op['site_backup_zip'])) {
			echo ' <a class="button" onclick="return confirm(\'Ripristinare l\\\'installazione dai file del backup completo?\\nOperazione potenzialmente distruttiva.\');" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_restore_full_last'), 'guardian_restore_full_last')) . '">' . esc_html__('Ripristina da backup completo (ultima op)', 'guardian') . '</a>';
		}
		echo '</p>';

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
				$old = $this->backup->read_file_from_backup_zip($zip, $inner);
			}
		} elseif ($zip && $type === 'theme') {
			$themeSlug = (string) ($op['theme'] ?? '');
			if ($themeSlug && strpos($rel, 'wp-content/themes/' . $themeSlug . '/') === 0) {
				$inner = $themeSlug . '/' . substr($rel, strlen('wp-content/themes/' . $themeSlug . '/'));
				$old = $this->backup->read_file_from_backup_zip($zip, $inner);
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
}

