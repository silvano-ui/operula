<?php

namespace Guardian;

final class AdminSectionBackup implements AdminSectionInterface {
	public function id(): string {
		return 'backup';
	}

	public function label(): string {
		return __('Backup', 'guardian');
	}

	public function register_actions(ModuleContext $ctx): void {
		add_action('admin_post_guardian_create_restore_point', function () use ($ctx): void {
			$this->handle_create_restore_point($ctx);
		});
		add_action('admin_post_guardian_restore_from_point', function () use ($ctx): void {
			$this->handle_restore_from_point($ctx);
		});
		add_action('admin_post_guardian_dbpro_restore_start', function () use ($ctx): void {
			$this->handle_dbpro_restore_start($ctx);
		});
	}

	public function render(ModuleContext $ctx): void {
		if (!$this->ensure_licensed_or_print($ctx)) {
			return;
		}

		echo '<p>';
		echo '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=guardian_create_restore_point'), 'guardian_create_restore_point')) . '">' . esc_html__('Crea restore point adesso', 'guardian') . '</a>';
		echo '</p>';

		$list = $ctx->restorePoints()->list(10);
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

				$dbCell = '';
				$manifestPath = $ctx->storage->base_dir() ? $ctx->storage->base_dir() . '/restore-points/' . sanitize_file_name($id) . '.json.gz' : null;
				$m = ($manifestPath && file_exists($manifestPath)) ? $ctx->storage->read_json_gz($manifestPath) : null;
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

		echo '<hr />';
		echo '<h2 style="margin-top:0;">' . esc_html__('DB Pro jobs (Backup Pro)', 'guardian') . '</h2>';
		$backupPro = !empty($ctx->payload['feat']['backup_pro']);
		if (!$backupPro) {
			echo '<p><em>' . esc_html__('Backup Pro non incluso nel piano.', 'guardian') . '</em></p>';
			return;
		}

		$pro = new DbBackupPro($ctx->storage);
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

	private function ensure_manage_options(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Non autorizzato.', 'guardian'));
		}
	}

	private function ensure_licensed_or_print(ModuleContext $ctx): bool {
		$st = $ctx->license->status();
		if (!empty($st['ok'])) {
			return true;
		}
		echo '<p><strong>' . esc_html__('Guardian è disattivato finché non inserisci una licenza valida.', 'guardian') . '</strong></p>';
		return false;
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

	private function handle_create_restore_point(ModuleContext $ctx): void {
		$this->ensure_manage_options();
		check_admin_referer('guardian_create_restore_point');
		$this->ensure_licensed_or_die($ctx);

		$ctx->storage->ensure_directories();

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
		$res = $ctx->restorePoints()->create('manual', $paths, $exclude);
		$ok = is_array($res);
		if ($ok) {
			$this->redirect_ok((string) __('Restore point creato.', 'guardian'));
		}
		$this->redirect_err((string) __('Creazione restore point non riuscita.', 'guardian'));
	}

	private function handle_restore_from_point(ModuleContext $ctx): void {
		$this->ensure_manage_options();
		check_admin_referer('guardian_restore_from_point');
		$this->ensure_licensed_or_die($ctx);

		$id = isset($_POST['restore_point_id']) ? (string) wp_unslash($_POST['restore_point_id']) : '';
		$path = isset($_POST['restore_rel_path']) ? (string) wp_unslash($_POST['restore_rel_path']) : '';
		$custom = isset($_POST['restore_rel_path_custom']) ? (string) wp_unslash($_POST['restore_rel_path_custom']) : '';
		if (trim($custom) !== '') {
			$path = $custom;
		}
		$deleteFirst = !empty($_POST['delete_first']);
		$restoreDb = !empty($_POST['restore_db']);

		$r = $ctx->restorePoints()->restore_path($id, $path, $deleteFirst);
		$ok = !empty($r['ok']);

		if ($ok && $restoreDb) {
			$manifest = $ctx->storage->base_dir() ? $ctx->storage->base_dir() . '/restore-points/' . sanitize_file_name($id) . '.json.gz' : null;
			$m = ($manifest && file_exists($manifest)) ? $ctx->storage->read_json_gz($manifest) : null;
			$engine = is_array($m) && isset($m['db']['engine']) ? (string) $m['db']['engine'] : 'basic';
			if ($engine === 'pro' && is_array($m) && !empty($m['db']) && is_array($m['db'])) {
				$pro = new DbBackupPro($ctx->storage);
				$job = $pro->start_restore_job((array) $m['db']);
				$ok = !empty($job['ok']);
				if ($ok) {
					$this->redirect_ok((string) __('DB Pro restore avviato (background).', 'guardian'));
				}
				$this->redirect_err((string) __('DB Pro restore non riuscito.', 'guardian'));
			}

			$db = $ctx->restorePoints()->restore_db($id);
			$ok = !empty($db['ok']);
		}

		if ($ok) {
			$this->redirect_ok((string) __('Restore completato.', 'guardian'));
		}
		$this->redirect_err((string) __('Restore non riuscito.', 'guardian'));
	}

	private function handle_dbpro_restore_start(ModuleContext $ctx): void {
		$this->ensure_manage_options();
		check_admin_referer('guardian_dbpro_restore_start');
		$this->ensure_licensed_or_die($ctx);

		$rpId = isset($_POST['restore_point_id']) ? (string) wp_unslash($_POST['restore_point_id']) : '';
		$manifest = $ctx->storage->base_dir() ? $ctx->storage->base_dir() . '/restore-points/' . sanitize_file_name($rpId) . '.json.gz' : null;
		$m = ($manifest && file_exists($manifest)) ? $ctx->storage->read_json_gz($manifest) : null;
		if (!is_array($m) || empty($m['db']) || !is_array($m['db'])) {
			$this->redirect_err((string) __('Manifest DB non valido.', 'guardian'));
		}

		$pro = new DbBackupPro($ctx->storage);
		$job = $pro->start_restore_job((array) $m['db']);
		$ok = !empty($job['ok']);
		if ($ok) {
			$this->redirect_ok((string) __('DB Pro restore avviato (background).', 'guardian'));
		}
		$this->redirect_err((string) __('DB Pro restore non riuscito.', 'guardian'));
	}
}

