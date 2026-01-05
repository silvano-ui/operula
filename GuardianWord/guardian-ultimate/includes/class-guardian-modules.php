<?php

namespace Guardian;

final class Modules {
	public const CORE = 'core';
	public const INTEGRITY = 'integrity';
	public const BACKUP = 'backup';
	public const SECURITY = 'security';
	public const HEALTH = 'health';

	public static function all(): array {
		return [self::CORE, self::INTEGRITY, self::BACKUP, self::SECURITY, self::HEALTH];
	}

	public static function labels(): array {
		return [
			self::CORE => __('Core', 'guardian'),
			self::INTEGRITY => __('Integrità & Change Tracking', 'guardian'),
			self::BACKUP => __('Backup Incrementale & Restore', 'guardian'),
			self::SECURITY => __('Sicurezza & Vulnerabilità', 'guardian'),
			self::HEALTH => __('Salute & Performance', 'guardian'),
		];
	}

	/**
	 * Feature flags from license payload.
	 * Supported formats:
	 * - feat.modules: ["core","integrity",...]
	 * - feat.core=true etc.
	 */
	public static function allowed_from_license(?array $payload): array {
		// Always require core.
		$allowed = [self::CORE => true];
		foreach (self::all() as $m) {
			$allowed[$m] = ($m === self::CORE);
		}
		if (!$payload || empty($payload['feat']) || !is_array($payload['feat'])) {
			return array_keys(array_filter($allowed));
		}
		$feat = $payload['feat'];
		if (!empty($feat['modules']) && is_array($feat['modules'])) {
			foreach ($feat['modules'] as $m) {
				if (is_string($m) && in_array($m, self::all(), true)) {
					$allowed[$m] = true;
				}
			}
		}
		foreach (self::all() as $m) {
			if (!empty($feat[$m])) {
				$allowed[$m] = true;
			}
		}
		return array_keys(array_filter($allowed));
	}

	public static function normalize(array $mods): array {
		$out = [];
		foreach ($mods as $m) {
			if (is_string($m) && in_array($m, self::all(), true)) {
				$out[$m] = true;
			}
		}
		$out[self::CORE] = true;
		return array_keys($out);
	}
}

