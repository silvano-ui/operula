<?php

namespace Guardian;

final class Plugin {
	private static ?Plugin $instance = null;

	private Storage $storage;
	private License $license;
	private array $enabledModules = [];
	private Scanner $scanner;
	private Backup $backup;
	private UpgraderHooks $upgrader;
	private Admin $admin;

	public static function instance(): Plugin {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	private const CRON_LICENSE_REFRESH = 'guardian_license_refresh';
	private const CRON_RESTORE_POINT = 'guardian_restore_point_scheduled';
	private const CRON_DBPRO_EXPORT = 'guardian_dbpro_export';
	private const CRON_DBPRO_RESTORE = 'guardian_dbpro_restore';

	public static function activate(): void {
		if (!function_exists('wp_upload_dir')) {
			return;
		}

		$storage = new Storage();
		$storage->ensure_directories();

		// Prova a installare il MU-loader se possibile (opzionale).
		$storage->maybe_install_mu_loader();

		// Pianifica refresh licenza (WHMCS mode) se non già pianificato.
		if (!wp_next_scheduled(self::CRON_LICENSE_REFRESH)) {
			wp_schedule_event(time() + 300, 'hourly', self::CRON_LICENSE_REFRESH);
		}

		self::reschedule_restore_points();
	}

	public static function deactivate(): void {
		// Non rimuoviamo snapshot/backup automaticamente.
		$ts = wp_next_scheduled(self::CRON_LICENSE_REFRESH);
		if ($ts) {
			wp_unschedule_event($ts, self::CRON_LICENSE_REFRESH);
		}
		$ts2 = wp_next_scheduled(self::CRON_RESTORE_POINT);
		if ($ts2) {
			wp_unschedule_event($ts2, self::CRON_RESTORE_POINT);
		}
		$ts3 = wp_next_scheduled(self::CRON_DBPRO_EXPORT);
		if ($ts3) {
			wp_unschedule_event($ts3, self::CRON_DBPRO_EXPORT);
		}
		$ts4 = wp_next_scheduled(self::CRON_DBPRO_RESTORE);
		if ($ts4) {
			wp_unschedule_event($ts4, self::CRON_DBPRO_RESTORE);
		}
	}

	public static function reschedule_restore_points(): void {
		if (!function_exists('wp_next_scheduled')) {
			return;
		}
		$storage = new Storage();
		$settings = $storage->get_settings();
		$schedule = isset($settings['rp_schedule']) ? (string) $settings['rp_schedule'] : 'daily';
		$schedule = in_array($schedule, ['off', 'hourly', 'daily'], true) ? $schedule : 'daily';

		$ts = wp_next_scheduled(self::CRON_RESTORE_POINT);
		if ($ts) {
			wp_unschedule_event($ts, self::CRON_RESTORE_POINT);
		}
		if ($schedule === 'off') {
			return;
		}
		// Start in 10 minutes, then follow schedule.
		wp_schedule_event(time() + 600, $schedule, self::CRON_RESTORE_POINT);
	}

	public function boot(): void {
		$this->storage = new Storage();
		$this->license = new License($this->storage);

		// Admin UI resta disponibile per inserire la licenza.
		$this->admin = new Admin($this->storage, $this->license);
		$this->admin->register();

		add_action(self::CRON_LICENSE_REFRESH, function (): void {
			if ($this->license->get_mode() === 'whmcs') {
				$this->license->refresh_from_whmcs_if_needed(false);
			}
		});

		add_action(self::CRON_RESTORE_POINT, function (): void {
			// Create scheduled restore point (only if licensed + backup module enabled).
			if (!$this->license->is_valid()) {
				return;
			}
			$payload = $this->license->get_payload();
			$allowed = Modules::allowed_from_license($payload);
			if (!in_array(Modules::BACKUP, $allowed, true)) {
				return;
			}
			$settings = $this->storage->get_settings();
			$enabled = isset($settings['enabled_modules']) && is_array($settings['enabled_modules']) ? $settings['enabled_modules'] : [];
			if (!in_array('backup', $enabled, true)) {
				return;
			}
			$rp = new RestorePoints($this->storage);
			$backupPro = !empty($payload['feat']['backup_pro']);
			$rp->create_scheduled_from_settings($settings, $backupPro);
		});

		add_action(self::CRON_DBPRO_EXPORT, function (string $jobId = ''): void {
			if ($jobId === '') {
				return;
			}
			$pro = new DbBackupPro($this->storage);
			$pro->continue_export_job($jobId);
		}, 10, 1);

		add_action(self::CRON_DBPRO_RESTORE, function (string $jobId = ''): void {
			if ($jobId === '') {
				return;
			}
			$pro = new DbBackupPro($this->storage);
			$pro->continue_restore_job($jobId);
		}, 10, 1);

		// Se licenza non valida, non attiviamo i moduli.
		if (!$this->license->is_valid()) {
			add_action('admin_notices', function (): void {
				if (!current_user_can('manage_options')) {
					return;
				}
				$st = $this->license->status();
				echo '<div class="notice notice-error"><p><strong>Guardian</strong>: ' . esc_html($st['message'] ?? 'Licenza non valida.') . ' <a href="' . esc_url(admin_url('admin.php?page=guardian')) . '">' . esc_html__('Inserisci licenza', 'guardian') . '</a></p></div>';
			});
			return;
		}

		$payload = $this->license->get_payload();
		$allowed = Modules::allowed_from_license($payload);
		$settings = $this->storage->get_settings();
		$wanted = isset($settings['enabled_modules']) && is_array($settings['enabled_modules']) ? $settings['enabled_modules'] : [];
		$wanted = Modules::normalize($wanted);
		$this->enabledModules = array_values(array_intersect($wanted, $allowed));

		$this->scanner = new Scanner($this->storage);
		$this->backup  = new Backup($this->storage);

		if (in_array(Modules::INTEGRITY, $this->enabledModules, true) || in_array(Modules::BACKUP, $this->enabledModules, true)) {
			$this->upgrader = new UpgraderHooks($this->storage, $this->scanner, $this->backup);
			$this->upgrader->register();
		}

		// Crash guard: efficace soprattutto se caricato come MU-plugin.
		$this->register_crash_guard();
		$this->maybe_run_deferred_rollback();
		$this->maybe_disarm_operation();
	}

	private function register_crash_guard(): void {
		if (defined('GUARDIAN_DISABLE_CRASH_GUARD') && GUARDIAN_DISABLE_CRASH_GUARD) {
			return;
		}
		$settings = $this->storage->get_settings();
		if (empty($settings['auto_rollback_on_fatal'])) {
			return;
		}

		register_shutdown_function(function (): void {
			$err = error_get_last();
			if (!$err || empty($err['type']) || empty($err['file'])) {
				return;
			}

			$fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
			if (!in_array((int) $err['type'], $fatal_types, true)) {
				return;
			}

			$op = $this->storage->get_last_operation();
			if (!$op || empty($op['status'])) {
				return;
			}
			$status = (string) $op['status'];
			if ($status === 'pending') {
				// ok
			} elseif ($status === 'armed') {
				$until = isset($op['armed_until']) ? (int) $op['armed_until'] : 0;
				if ($until > 0 && time() > $until) {
					return;
				}
			} else {
				return;
			}

			// Se il fatal arriva subito dopo un upgrade/install, marca rollback richiesto.
			$this->storage->set_deferred_rollback([
				'reason' => 'fatal_error',
				'error'  => [
					'type'    => (int) $err['type'],
					'message' => (string) ($err['message'] ?? ''),
					'file'    => (string) $err['file'],
					'line'    => (int) ($err['line'] ?? 0),
				],
				'op'     => $op,
				'ts'     => time(),
			]);
		});
	}

	/**
	 * Esegue eventuale rollback "rimasto in sospeso" (es. dopo fatal in richiesta precedente).
	 */
	private function maybe_run_deferred_rollback(): void {
		$payload = $this->storage->get_deferred_rollback();
		if (!$payload || empty($payload['op'])) {
			return;
		}

		$op = $payload['op'];
		$this->storage->clear_deferred_rollback();

		$this->backup->rollback_last_operation($op);
	}

	private function maybe_disarm_operation(): void {
		$op = $this->storage->get_last_operation();
		if (!$op || empty($op['status']) || (string) $op['status'] !== 'armed') {
			return;
		}
		$until = isset($op['armed_until']) ? (int) $op['armed_until'] : 0;
		if ($until > 0 && time() > $until) {
			$op['status'] = 'completed';
			$this->storage->set_last_operation($op);
		}
	}
}

