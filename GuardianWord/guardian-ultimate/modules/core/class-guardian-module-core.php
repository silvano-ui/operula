<?php

namespace Guardian;

/**
 * Core module: admin UI + license refresh cron + crash guard.
 */
final class ModuleCore implements ModuleInterface {
	private const CRON_LICENSE_REFRESH = 'guardian_license_refresh';

	public function id(): string {
		return Modules::CORE;
	}

	public function register(ModuleContext $ctx): void {
		// Modular admin shell + core sections.
		AdminRegistry::add_section(new AdminSectionLicense());
		AdminRegistry::add_section(new AdminSectionModules());
		AdminRegistry::add_section(new AdminSectionSettings());
		$admin = new AdminApp($ctx);
		$admin->register();

		add_action(self::CRON_LICENSE_REFRESH, function () use ($ctx): void {
			if ($ctx->license->get_mode() === 'whmcs') {
				$ctx->license->refresh_from_whmcs_if_needed(false);
			}
		});

		$this->register_crash_guard($ctx);
	}

	public static function activate(Storage $storage): void {
		if (!wp_next_scheduled(self::CRON_LICENSE_REFRESH)) {
			wp_schedule_event(time() + 300, 'hourly', self::CRON_LICENSE_REFRESH);
		}
	}

	public static function deactivate(): void {
		$ts = wp_next_scheduled(self::CRON_LICENSE_REFRESH);
		if ($ts) {
			wp_unschedule_event($ts, self::CRON_LICENSE_REFRESH);
		}
	}

	private function register_crash_guard(ModuleContext $ctx): void {
		if (defined('GUARDIAN_DISABLE_CRASH_GUARD') && GUARDIAN_DISABLE_CRASH_GUARD) {
			return;
		}
		if (empty($ctx->settings['auto_rollback_on_fatal'])) {
			return;
		}
		register_shutdown_function(function () use ($ctx): void {
			$err = error_get_last();
			if (!$err || empty($err['type']) || empty($err['file'])) {
				return;
			}
			$fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
			if (!in_array((int) $err['type'], $fatal_types, true)) {
				return;
			}
			$op = $ctx->storage->get_last_operation();
			if (!$op || empty($op['status'])) {
				return;
			}
			$status = (string) $op['status'];
			if ($status !== 'pending' && $status !== 'armed') {
				return;
			}
			if ($status === 'armed') {
				$until = isset($op['armed_until']) ? (int) $op['armed_until'] : 0;
				if ($until > 0 && time() > $until) {
					return;
				}
			}
			$ctx->storage->set_deferred_rollback([
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
}

