<?php

namespace Guardian;

final class Storage {
	private const OPTION_LAST_OPERATION     = 'guardian_last_operation';
	private const OPTION_DEFERRED_ROLLBACK  = 'guardian_deferred_rollback';
	private const OPTION_SETTINGS           = 'guardian_settings';

	public function ensure_directories(): void {
		$base = $this->base_dir();
		if (!$base) {
			return;
		}

		$this->mkdir_p($base);
		$this->mkdir_p($base . '/snapshots');
		$this->mkdir_p($base . '/reports');
		$this->mkdir_p($base . '/backups');
		$this->mkdir_p($base . '/logs');
		$this->mkdir_p($base . '/restore-points');
		$this->mkdir_p($base . '/blobs');
	}

	public function base_dir(): ?string {
		$u = wp_upload_dir(null, false);
		if (!is_array($u) || empty($u['basedir']) || !is_string($u['basedir'])) {
			return null;
		}
		return rtrim($u['basedir'], '/\\') . '/guardian';
	}

	public function base_url(): ?string {
		$u = wp_upload_dir(null, false);
		if (!is_array($u) || empty($u['baseurl']) || !is_string($u['baseurl'])) {
			return null;
		}
		return rtrim($u['baseurl'], '/\\') . '/guardian';
	}

	public function snapshot_path(string $id): ?string {
		$base = $this->base_dir();
		if (!$base) {
			return null;
		}
		return $base . '/snapshots/' . sanitize_file_name($id) . '.json.gz';
	}

	public function report_path(string $id): ?string {
		$base = $this->base_dir();
		if (!$base) {
			return null;
		}
		return $base . '/reports/' . sanitize_file_name($id) . '.json.gz';
	}

	public function backup_path(string $id): ?string {
		$base = $this->base_dir();
		if (!$base) {
			return null;
		}
		return $base . '/backups/' . sanitize_file_name($id) . '.zip';
	}

	public function write_json_gz(string $path, array $data): bool {
		$dir = dirname($path);
		$this->mkdir_p($dir);

		$json = wp_json_encode($data);
		if (!is_string($json)) {
			return false;
		}

		$gz = gzencode($json, 6);
		if (!is_string($gz)) {
			return false;
		}

		return (bool) file_put_contents($path, $gz, LOCK_EX);
	}

	public function read_json_gz(string $path): ?array {
		if (!file_exists($path)) {
			return null;
		}
		$raw = file_get_contents($path);
		if (!is_string($raw) || $raw === '') {
			return null;
		}
		$json = gzdecode($raw);
		if (!is_string($json)) {
			return null;
		}
		$data = json_decode($json, true);
		return is_array($data) ? $data : null;
	}

	public function get_settings(): array {
		$defaults = [
			'auto_backup_on_upgrade' => true,
			'auto_snapshot_on_upgrade' => true,
			'auto_rollback_on_fatal' => true,
			'include_uploads' => false,
			'full_backup_on_upgrade' => false,
			'full_restore_include_wp_config' => false,
			'max_diff_bytes' => 1024 * 1024, // 1 MB
			'enabled_modules' => ['core', 'integrity', 'backup'],
			'rp_keep_last' => 10,
			'rp_max_blob_bytes' => 20 * 1024 * 1024, // 20MB
			// Restore point schedule/options
			'rp_schedule' => 'daily', // off|hourly|daily
			'rp_scope_plugins_themes' => true,
			'rp_scope_wp_config' => true,
			'rp_scope_core' => false,
			'rp_scope_uploads' => false,
			// DB snapshot inside restore point (best-effort)
			'rp_include_db' => false,
			'rp_db_tables' => 'wp_core', // wp_core|all_prefix|custom
			'rp_db_custom_tables' => '',
			'rp_db_max_seconds' => 20,
			// Pre-upgrade restore points
			'rp_pre_upgrade_include_db' => false,
			'rp_pre_upgrade_core_files' => false,
		];
		$opt = get_option(self::OPTION_SETTINGS);
		return is_array($opt) ? array_merge($defaults, $opt) : $defaults;
	}

	public function update_settings(array $settings): void {
		update_option(self::OPTION_SETTINGS, $settings, false);
	}

	public function set_last_operation(array $op): void {
		update_option(self::OPTION_LAST_OPERATION, $op, false);
	}

	public function get_last_operation(): ?array {
		$op = get_option(self::OPTION_LAST_OPERATION);
		return is_array($op) ? $op : null;
	}

	public function set_deferred_rollback(array $payload): void {
		update_option(self::OPTION_DEFERRED_ROLLBACK, $payload, false);
	}

	public function get_deferred_rollback(): ?array {
		$p = get_option(self::OPTION_DEFERRED_ROLLBACK);
		return is_array($p) ? $p : null;
	}

	public function clear_deferred_rollback(): void {
		delete_option(self::OPTION_DEFERRED_ROLLBACK);
	}

	public function maybe_install_mu_loader(): void {
		if (!defined('WPMU_PLUGIN_DIR') || !is_string(WPMU_PLUGIN_DIR)) {
			return;
		}

		$source = GUARDIAN_PLUGIN_DIR . '/mu-plugin/guardian-loader.php';
		if (!file_exists($source)) {
			return;
		}

		$this->mkdir_p(WPMU_PLUGIN_DIR);

		$target = rtrim(WPMU_PLUGIN_DIR, '/\\') . '/guardian-loader.php';
		if (file_exists($target)) {
			return;
		}

		// Best-effort: se permessi ok, copia il loader.
		@copy($source, $target);
	}

	private function mkdir_p(string $dir): void {
		if (is_dir($dir)) {
			return;
		}
		wp_mkdir_p($dir);
	}
}

