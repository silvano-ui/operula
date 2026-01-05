<?php

namespace Guardian;

final class ModuleBackup implements ModuleInterface {
	private const CRON_RESTORE_POINT = 'guardian_restore_point_scheduled';
	private const CRON_DBPRO_EXPORT = 'guardian_dbpro_export';
	private const CRON_DBPRO_RESTORE = 'guardian_dbpro_restore';

	public function id(): string {
		return Modules::BACKUP;
	}

	public function register(ModuleContext $ctx): void {
		AdminRegistry::add_section(new AdminSectionBackup());

		add_action(self::CRON_RESTORE_POINT, function () use ($ctx): void {
			$backupPro = !empty($ctx->payload['feat']['backup_pro']);
			$ctx->restorePoints()->create_scheduled_from_settings($ctx->settings, $backupPro);
		});

		add_action(self::CRON_DBPRO_EXPORT, function (string $jobId = '') use ($ctx): void {
			if ($jobId === '') {
				return;
			}
			$pro = new DbBackupPro($ctx->storage);
			$pro->continue_export_job($jobId);
		}, 10, 1);

		add_action(self::CRON_DBPRO_RESTORE, function (string $jobId = '') use ($ctx): void {
			if ($jobId === '') {
				return;
			}
			$pro = new DbBackupPro($ctx->storage);
			$pro->continue_restore_job($jobId);
		}, 10, 1);
	}

	public static function reschedule_restore_points(Storage $storage): void {
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
		wp_schedule_event(time() + 600, $schedule, self::CRON_RESTORE_POINT);
	}

	public static function deactivate(): void {
		$ts = wp_next_scheduled(self::CRON_RESTORE_POINT);
		if ($ts) {
			wp_unschedule_event($ts, self::CRON_RESTORE_POINT);
		}
		$ts2 = wp_next_scheduled(self::CRON_DBPRO_EXPORT);
		if ($ts2) {
			wp_unschedule_event($ts2, self::CRON_DBPRO_EXPORT);
		}
		$ts3 = wp_next_scheduled(self::CRON_DBPRO_RESTORE);
		if ($ts3) {
			wp_unschedule_event($ts3, self::CRON_DBPRO_RESTORE);
		}
	}
}

