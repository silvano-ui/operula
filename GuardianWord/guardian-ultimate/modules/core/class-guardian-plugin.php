<?php

namespace Guardian;

final class Plugin {
	private static ?Plugin $instance = null;

	private Storage $storage;
	private License $license;
	private array $enabledModules = [];
	private ?ModuleContext $ctx = null;
	private array $modules = [];

	public static function instance(): Plugin {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public static function activate(): void {
		if (!function_exists('wp_upload_dir')) {
			return;
		}

		$storage = new Storage();
		$storage->ensure_directories();

		// Prova a installare il MU-loader se possibile (opzionale).
		$storage->maybe_install_mu_loader();

		// Activate module-level schedules.
		ModuleCore::activate($storage);
		ModuleBackup::reschedule_restore_points($storage);
	}

	public static function deactivate(): void {
		ModuleCore::deactivate();
		ModuleBackup::deactivate();
	}

	public static function reschedule_restore_points(): void {
		$storage = new Storage();
		ModuleBackup::reschedule_restore_points($storage);
	}

	public function boot(): void {
		$this->storage = new Storage();
		$this->license = new License($this->storage);

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

		$this->ctx = new ModuleContext($this->storage, $this->license, $settings, is_array($payload) ? $payload : [], $this->enabledModules);
		$this->modules = ModuleManager::load($this->enabledModules);
		foreach ($this->modules as $m) {
			$m->register($this->ctx);
		}

		// Apply deferred rollback if present (requires backup module).
		$this->maybe_run_deferred_rollback();
		$this->maybe_disarm_operation();
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
		// Backup module required.
		$bak = new Backup($this->storage);
		$bak->rollback_last_operation($op);
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

