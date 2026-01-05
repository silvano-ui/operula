<?php

namespace Guardian;

final class AdminSectionIntegrity implements AdminSectionInterface {
	public function id(): string {
		return 'integrity';
	}

	public function label(): string {
		return __('Integrità', 'guardian');
	}

	public function register_actions(ModuleContext $ctx): void {
		add_action('admin_post_guardian_create_snapshot', function () use ($ctx): void {
			$this->handle_create_snapshot($ctx);
		});
		add_action('admin_post_guardian_rollback_last', function () use ($ctx): void {
			$this->handle_rollback_last($ctx);
		});
		add_action('admin_post_guardian_restore_full_last', function () use ($ctx): void {
			$this->handle_restore_full_last($ctx);
		});
	}

	public function render(ModuleContext $ctx): void {
		$st = $ctx->license->status();
		$licensed = !empty($st['ok']);

		$op = $ctx->storage->get_last_operation();

		$diffPath = isset($_GET['guardian_diff']) ? (string) $_GET['guardian_diff'] : '';
		if ($diffPath !== '') {
			if (!$licensed) {
				wp_die(__('Licenza non valida: impossibile mostrare diff.', 'guardian'));
			}
			$this->render_diff_view($ctx, $diffPath, $op);
			return;
		}

		echo '<h2 style="margin-top:0;">' . esc_html__('Azioni rapide', 'guardian') . '</h2>';
		echo '<p>';
		echo '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_create_snapshot'), 'guardian_create_snapshot')) . '">' . esc_html__('Crea snapshot ora', 'guardian') . '</a> ';
		echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_rollback_last'), 'guardian_rollback_last')) . '">' . esc_html__('Rollback ultima operazione', 'guardian') . '</a>';
		if ($op && !empty($op['site_backup_zip'])) {
			echo ' <a class="button" onclick="return confirm(\'Ripristinare l\\\'installazione dai file del backup completo?\\nOperazione potenzialmente distruttiva.\');" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_restore_full_last'), 'guardian_restore_full_last')) . '">' . esc_html__('Ripristina da backup completo (ultima op)', 'guardian') . '</a>';
		}
		echo '</p>';

		if (!$licensed) {
			echo '<p><strong>' . esc_html__('Guardian è disattivato finché non inserisci una licenza valida.', 'guardian') . '</strong></p>';
			return;
		}

		echo '<hr />';
		echo '<h2 style="margin-top:0;">' . esc_html__('Ultima operazione monitorata', 'guardian') . '</h2>';
		if (!$op) {
			echo '<p>' . esc_html__('Nessuna operazione registrata ancora.', 'guardian') . '</p>';
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

		$this->render_last_report($ctx, $op);
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

	private function redirect_err(string $msg): void {
		wp_safe_redirect(add_query_arg(['guardian_err' => $msg, 'tab' => $this->id()], admin_url('admin.php?page=guardian')));
		exit;
	}

	private function handle_create_snapshot(ModuleContext $ctx): void {
		$this->ensure_manage_options();
		check_admin_referer('guardian_create_snapshot');
		$this->ensure_licensed_or_die($ctx);

		$ctx->storage->ensure_directories();
		$snap = $ctx->scanner()->create_snapshot('manual', [
			'operation' => [
				'type' => 'manual',
			],
		]);

		if ($snap) {
			$this->redirect_ok((string) __('Snapshot creato.', 'guardian'));
		}
		$this->redirect_err((string) __('Snapshot non riuscito.', 'guardian'));
	}

	private function handle_rollback_last(ModuleContext $ctx): void {
		$this->ensure_manage_options();
		check_admin_referer('guardian_rollback_last');
		$this->ensure_licensed_or_die($ctx);

		$op = $ctx->storage->get_last_operation();
		if ($op) {
			$ctx->backup()->rollback_last_operation($op);
		}

		$this->redirect_ok((string) __('Rollback eseguito (best-effort).', 'guardian'));
	}

	private function handle_restore_full_last(ModuleContext $ctx): void {
		$this->ensure_manage_options();
		check_admin_referer('guardian_restore_full_last');
		$this->ensure_licensed_or_die($ctx);

		$op = $ctx->storage->get_last_operation();
		$zip = $op && !empty($op['site_backup_zip']) ? (string) $op['site_backup_zip'] : '';
		$settings = $ctx->storage->get_settings();
		$includeWpConfig = !empty($settings['full_restore_include_wp_config']);

		$ok = false;
		if ($zip && defined('ABSPATH')) {
			$ok = $ctx->backup()->restore_installation_from_backup($zip, ABSPATH, $includeWpConfig);
		}

		if ($ok) {
			$this->redirect_ok((string) __('Ripristino completo applicato (best-effort).', 'guardian'));
		}
		$this->redirect_err((string) __('Ripristino completo non riuscito.', 'guardian'));
	}

	private function render_last_report(ModuleContext $ctx, array $op): void {
		$reportPath = isset($op['report_path']) && is_string($op['report_path']) ? $op['report_path'] : null;
		if (!$reportPath || !file_exists($reportPath)) {
			return;
		}
		$report = $ctx->storage->read_json_gz($reportPath);
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

		$this->render_file_list($ctx, 'Modificati', $modified, true);
		$this->render_file_list($ctx, 'Aggiunti', $added, false);
		$this->render_file_list($ctx, 'Rimossi', $removed, false);
	}

	private function render_file_list(ModuleContext $ctx, string $title, array $items, bool $withDiffLink): void {
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
					['page' => 'guardian', 'tab' => $this->id(), 'guardian_diff' => (string) $path],
					admin_url('admin.php')
				);
				$li .= ' — <a href="' . esc_url($url) . '">' . esc_html__('Diff', 'guardian') . '</a>';
			}
			echo '<li>' . $li . '</li>';
		}
		echo '</ul>';
	}

	private function render_diff_view(ModuleContext $ctx, string $relPathRaw, ?array $op): void {
		if (!$op) {
			wp_die(__('Nessuna operazione disponibile per calcolare diff.', 'guardian'));
		}

		$rel = ltrim(str_replace('\\', '/', $relPathRaw), '/');
		if ($rel === '' || strpos($rel, '..') !== false) {
			wp_die(__('Path non valido.', 'guardian'));
		}

		echo '<h2 style="margin-top:0;">Guardian — Diff</h2>';
		echo '<p><a href="' . esc_url(add_query_arg(['page' => 'guardian', 'tab' => $this->id()], admin_url('admin.php'))) . '">&larr; ' . esc_html__('Torna a Integrità', 'guardian') . '</a></p>';
		echo '<h3><code>' . esc_html($rel) . '</code></h3>';

		$zip = (string) ($op['backup_zip'] ?? '');
		$type = (string) ($op['type'] ?? '');

		$old = null;
		if ($zip && $type === 'plugin') {
			$pluginFile = (string) ($op['plugin'] ?? '');
			$pluginDirName = $this->plugin_dirname_from_plugin_file($pluginFile);
			if ($pluginDirName && strpos($rel, 'wp-content/plugins/' . $pluginDirName . '/') === 0) {
				$inner = $pluginDirName . '/' . substr($rel, strlen('wp-content/plugins/' . $pluginDirName . '/'));
				$old = $ctx->backup()->read_file_from_backup_zip($zip, $inner);
			}
		} elseif ($zip && $type === 'theme') {
			$themeSlug = (string) ($op['theme'] ?? '');
			if ($themeSlug && strpos($rel, 'wp-content/themes/' . $themeSlug . '/') === 0) {
				$inner = $themeSlug . '/' . substr($rel, strlen('wp-content/themes/' . $themeSlug . '/'));
				$old = $ctx->backup()->read_file_from_backup_zip($zip, $inner);
			}
		}

		$newAbs = defined('ABSPATH') ? rtrim(ABSPATH, '/\\') . '/' . $rel : null;
		$new = ($newAbs && file_exists($newAbs)) ? file_get_contents($newAbs) : null;

		$maxBytes = (int) ($ctx->storage->get_settings()['max_diff_bytes'] ?? (1024 * 1024));
		if (is_string($old) && strlen($old) > $maxBytes) {
			$old = substr($old, 0, $maxBytes) . "\n\n/* …TRONCATO… */\n";
		}
		if (is_string($new) && strlen($new) > $maxBytes) {
			$new = substr($new, 0, $maxBytes) . "\n\n/* …TRONCATO… */\n";
		}

		if (!is_string($old)) {
			echo '<p><em>' . esc_html__('Versione precedente non disponibile (file fuori dall’area backuppata o nessun backup ZIP).', 'guardian') . '</em></p>';
			return;
		}
		if (!is_string($new)) {
			echo '<p><em>' . esc_html__('File attuale non trovato (potrebbe essere stato rimosso).', 'guardian') . '</em></p>';
			return;
		}
		if (!$this->is_probably_text($old) || !$this->is_probably_text($new)) {
			echo '<p><em>' . esc_html__('Diff disponibile solo per file testuali (questo sembra binario).', 'guardian') . '</em></p>';
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
		return strpos($s, "\0") === false;
	}
}

