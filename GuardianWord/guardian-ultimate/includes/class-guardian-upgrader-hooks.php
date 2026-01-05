<?php

namespace Guardian;

final class UpgraderHooks {
	private Storage $storage;
	private Scanner $scanner;
	private Backup $backup;
	private RestorePoints $restorePoints;

	public function __construct(Storage $storage, Scanner $scanner, Backup $backup) {
		$this->storage = $storage;
		$this->scanner = $scanner;
		$this->backup  = $backup;
		$this->restorePoints = new RestorePoints($storage);
	}

	public function register(): void {
		add_filter('upgrader_pre_install', [$this, 'pre_install'], 10, 2);
		add_filter('upgrader_post_install', [$this, 'post_install'], 10, 3);
	}

	/**
	 * @param mixed $return
	 */
	public function pre_install($return, array $hook_extra) {
		if (is_wp_error($return)) {
			return $return;
		}

		$type = $this->detect_type($hook_extra);
		if ($type === null) {
			return $return;
		}

		$settings = $this->storage->get_settings();
		$opId = gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);

		$snapshotBefore = null;
		if (!empty($settings['auto_snapshot_on_upgrade'])) {
			$snap = $this->scanner->create_snapshot('pre_' . $type, [
				'operation' => [
					'id' => $opId,
					'type' => $type,
					'hook_extra' => $hook_extra,
					'stage' => 'pre',
				],
			]);
			$snapshotBefore = $snap['id'] ?? null;
		}

		$backupZip = null;
		$siteBackupZip = null;
		$restorePointId = null;
		if (!empty($settings['auto_backup_on_upgrade'])) {
			if ($type === 'plugin') {
				$pluginFile = (string) ($hook_extra['plugin'] ?? '');
				$dir = $this->plugin_dir_from_plugin_file($pluginFile);
				if ($dir && is_dir($dir)) {
					$bak = $this->backup->backup_directory($dir, 'plugin');
					$backupZip = $bak['zip'] ?? null;
				}
			} elseif ($type === 'theme') {
				$theme = (string) ($hook_extra['theme'] ?? '');
				$dir = $this->theme_dir_from_slug($theme);
				if ($dir && is_dir($dir)) {
					$bak = $this->backup->backup_directory($dir, 'theme');
					$backupZip = $bak['zip'] ?? null;
				}
			}
		}
		if (!empty($settings['full_backup_on_upgrade'])) {
			// Backup completo (attenzione: può essere enorme). Best-effort.
			$bak = $this->backup->backup_directory(ABSPATH, 'site');
			$siteBackupZip = $bak['zip'] ?? null;
		}

		// Incremental restore point (granulare) per plugin/tema prima dell'upgrade.
		if (!empty($settings['enabled_modules']) && is_array($settings['enabled_modules']) && in_array('backup', $settings['enabled_modules'], true)) {
			$paths = [];
			if ($type === 'plugin') {
				$pluginFile = (string) ($hook_extra['plugin'] ?? '');
				$dir = $this->plugin_dir_from_plugin_file($pluginFile);
				if ($dir && is_dir($dir)) {
					// Convert to rel path.
					$paths[] = ltrim(str_replace('\\', '/', substr($dir, strlen(rtrim(ABSPATH, '/\\')))), '/');
				}
			} elseif ($type === 'theme') {
				$theme = (string) ($hook_extra['theme'] ?? '');
				$dir = $this->theme_dir_from_slug($theme);
				if ($dir && is_dir($dir)) {
					$paths[] = ltrim(str_replace('\\', '/', substr($dir, strlen(rtrim(ABSPATH, '/\\')))), '/');
				}
			}
			if ($paths) {
				$exclude = ['wp-content/uploads/', 'wp-content/cache/', 'wp-content/upgrade/'];
				$rp = $this->restorePoints->create('pre_' . $type, $paths, $exclude);
				$restorePointId = is_array($rp) ? ($rp['id'] ?? null) : null;
			}
		}

		$op = [
			'id' => $opId,
			'type' => $type,
			'status' => 'pending',
			'started_gm' => gmdate('c'),
			'hook_extra' => $hook_extra,
			'snapshot_before' => $snapshotBefore,
			'backup_zip' => $backupZip,
			'site_backup_zip' => $siteBackupZip,
			'restore_point_before' => $restorePointId,
		];
		if ($type === 'plugin') {
			$op['plugin'] = (string) ($hook_extra['plugin'] ?? '');
		}
		if ($type === 'theme') {
			$op['theme'] = (string) ($hook_extra['theme'] ?? '');
		}

		$this->storage->set_last_operation($op);
		return $return;
	}

	/**
	 * @param mixed $response
	 * @param array $hook_extra
	 * @param array $result
	 * @return mixed
	 */
	public function post_install($response, array $hook_extra, array $result) {
		$op = $this->storage->get_last_operation();
		if (!$op || ($op['status'] ?? '') !== 'pending') {
			return $response;
		}

		$type = $this->detect_type($hook_extra);
		if ($type === null || ($op['type'] ?? '') !== $type) {
			return $response;
		}

		$settings = $this->storage->get_settings();
		$snapshotAfter = null;
		if (!empty($settings['auto_snapshot_on_upgrade'])) {
			$snap = $this->scanner->create_snapshot('post_' . $type, [
				'operation' => [
					'id' => $op['id'] ?? null,
					'type' => $type,
					'hook_extra' => $hook_extra,
					'stage' => 'post',
				],
			]);
			$snapshotAfter = $snap['id'] ?? null;
		}

		$diff = null;
		if (!empty($op['snapshot_before']) && $snapshotAfter) {
			$before = $this->scanner->load_snapshot((string) $op['snapshot_before']);
			$after  = $this->scanner->load_snapshot((string) $snapshotAfter);
			if (is_array($before) && is_array($after)) {
				$diff = $this->scanner->diff_snapshots($before, $after);
			}
		}

		$reportId = gmdate('Ymd-His') . '-' . ($op['id'] ?? 'op') . '-report';
		$reportPath = $this->storage->report_path($reportId);
		if ($reportPath) {
			$this->storage->write_json_gz($reportPath, [
				'meta' => [
					'id' => $reportId,
					'created_gm' => gmdate('c'),
				],
				'operation' => $op,
				'result' => $result,
				'diff' => $diff,
				'snapshot_after' => $snapshotAfter,
			]);
		}

		if (is_wp_error($response)) {
			$op['status'] = 'failed';
		} else {
			// "Armed": per un breve periodo post-upgrade possiamo auto-rollback su fatal.
			$op['status'] = 'armed';
			$op['armed_until'] = time() + 600; // 10 minuti
		}
		$op['ended_gm'] = gmdate('c');
		$op['snapshot_after'] = $snapshotAfter;
		$op['report_id'] = $reportId;
		$op['report_path'] = $reportPath;

		$this->storage->set_last_operation($op);
		return $response;
	}

	private function detect_type(array $hook_extra): ?string {
		// Plugin install/update.
		if (!empty($hook_extra['plugin'])) {
			return 'plugin';
		}
		// Theme install/update.
		if (!empty($hook_extra['theme'])) {
			return 'theme';
		}
		// Core update (non gestito a livello di backup/rollback in questa versione).
		if (!empty($hook_extra['core'])) {
			return 'core';
		}
		return null;
	}

	private function plugin_dir_from_plugin_file(string $pluginFile): ?string {
		if ($pluginFile === '' || !defined('WP_PLUGIN_DIR')) {
			return null;
		}
		$pluginFile = str_replace('\\', '/', $pluginFile);
		$dir = dirname($pluginFile);
		if ($dir === '.' || $dir === '/') {
			return null;
		}
		return rtrim(WP_PLUGIN_DIR, '/\\') . '/' . $dir;
	}

	private function theme_dir_from_slug(string $slug): ?string {
		if ($slug === '' || !defined('WP_CONTENT_DIR')) {
			return null;
		}
		return rtrim(WP_CONTENT_DIR, '/\\') . '/themes/' . $slug;
	}
}

