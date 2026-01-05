<?php

use WHMCS\Database\Capsule;

final class Repo
{
	public static function ensureSchema(): void
	{
		if (Capsule::schema()->hasTable('mod_guardian_licenses')) {
			// Ensure auxiliary tables.
			self::ensureNonceSchema();
			self::ensureRateLimitSchema();
			self::ensureLicenseColumns();
			return;
		}
		Capsule::schema()->create('mod_guardian_licenses', function ($table) {
			$table->increments('id');
			$table->integer('service_id')->unsigned()->unique();
			$table->string('license_id', 64)->unique();
			$table->string('domain', 255)->default('');
			$table->string('install_id', 64)->default('');
			$table->text('token')->nullable();
			$table->string('status', 32)->default('active'); // active|expired|suspended|terminated
			$table->integer('issued_at')->unsigned()->default(0);
			$table->integer('expires_at')->unsigned()->default(0);
			$table->integer('updated_at')->unsigned()->default(0);
		});

		self::ensureNonceSchema();
		self::ensureRateLimitSchema();
	}

	private static function ensureLicenseColumns(): void
	{
		// Lightweight migration for existing installs.
		if (!Capsule::schema()->hasColumn('mod_guardian_licenses', 'install_id')) {
			Capsule::schema()->table('mod_guardian_licenses', function ($table) {
				$table->string('install_id', 64)->default('');
			});
		}
	}

	private static function ensureNonceSchema(): void
	{
		if (Capsule::schema()->hasTable('mod_guardian_nonces')) {
			return;
		}
		Capsule::schema()->create('mod_guardian_nonces', function ($table) {
			$table->increments('id');
			$table->string('license_id', 64)->index();
			$table->string('nonce', 128)->unique();
			$table->integer('ts')->unsigned()->default(0);
			$table->string('ip', 64)->default('');
			$table->integer('created_at')->unsigned()->default(0);
		});
	}

	private static function ensureRateLimitSchema(): void
	{
		if (Capsule::schema()->hasTable('mod_guardian_rate_limits')) {
			return;
		}
		Capsule::schema()->create('mod_guardian_rate_limits', function ($table) {
			$table->increments('id');
			$table->string('key', 190)->unique();
			$table->integer('window_start')->unsigned()->default(0);
			$table->integer('count')->unsigned()->default(0);
			$table->integer('updated_at')->unsigned()->default(0);
		});
	}

	public static function nonceSeenOrStore(string $licenseId, string $nonce, int $ts, string $ip, int $ttlSeconds): bool
	{
		self::ensureSchema();
		$now = time();
		$cutoff = $now - max(60, $ttlSeconds);
		Capsule::table('mod_guardian_nonces')->where('created_at', '<', $cutoff)->delete();

		$exists = Capsule::table('mod_guardian_nonces')->where('nonce', $nonce)->exists();
		if ($exists) {
			return true;
		}

		Capsule::table('mod_guardian_nonces')->insert([
			'license_id' => $licenseId,
			'nonce' => $nonce,
			'ts' => $ts,
			'ip' => $ip,
			'created_at' => $now,
		]);
		return false;
	}

	/**
	 * Simple fixed-window rate limit. Returns true if allowed.
	 */
	public static function rateLimitAllow(string $key, int $windowSeconds, int $maxInWindow): bool
	{
		self::ensureSchema();
		$now = time();
		$windowStart = $now - ($now % max(1, $windowSeconds));

		$row = Capsule::table('mod_guardian_rate_limits')->where('key', $key)->first();
		if (!$row) {
			Capsule::table('mod_guardian_rate_limits')->insert([
				'key' => $key,
				'window_start' => $windowStart,
				'count' => 1,
				'updated_at' => $now,
			]);
			return true;
		}

		$curWindow = (int) $row->window_start;
		$curCount = (int) $row->count;
		if ($curWindow !== $windowStart) {
			Capsule::table('mod_guardian_rate_limits')->where('key', $key)->update([
				'window_start' => $windowStart,
				'count' => 1,
				'updated_at' => $now,
			]);
			return true;
		}

		if ($curCount >= $maxInWindow) {
			return false;
		}

		Capsule::table('mod_guardian_rate_limits')->where('key', $key)->update([
			'count' => $curCount + 1,
			'updated_at' => $now,
		]);
		return true;
	}

	/**
	 * Issue or refresh license for a service.
	 *
	 * $svc: ['service_id','client_id','domain','next_due','status','cycle','license_type']
	 */
	public static function getOrIssueForService(array $svc): array
	{
		self::ensureSchema();
		$serviceId = (int) ($svc['service_id'] ?? 0);
		if ($serviceId <= 0) {
			return ['ok' => false, 'message' => 'Invalid service'];
		}

		$row = Capsule::table('mod_guardian_licenses')->where('service_id', $serviceId)->first();
		$licenseId = $row ? (string) $row->license_id : self::newLicenseId();

		$domain = strtolower(trim((string) ($svc['domain'] ?? '')));
		$now = time();
		$exp = self::computeExpiry($svc);

		$status = self::mapServiceStatus((string) ($svc['status'] ?? 'Active'), $exp);

		$keys = Signer::loadKeysFromAddonSettings();
		if (empty($keys['private_b64'])) {
			return [
				'ok' => false,
				'license_id' => $licenseId,
				'status' => 'invalid',
				'message' => 'Missing signing keys in addon settings',
			];
		}

		$token = null;
		if ($status === 'active' && $domain !== '') {
			$payload = [
				'v' => 1,
				'lic' => $licenseId,
				'dom' => $domain,
				'iat' => $now,
				'exp' => $exp,
				'feat' => [
					'guardian' => true,
					'type' => (string) ($svc['license_type'] ?? ''),
				],
			];
			$token = Signer::signToken($payload, $keys['private_b64']);
		}

		$data = [
			'service_id' => $serviceId,
			'license_id' => $licenseId,
			'domain' => $domain,
			'token' => $token,
			'status' => $status,
			'issued_at' => $now,
			'expires_at' => $exp,
			'updated_at' => $now,
		];

		if ($row) {
			Capsule::table('mod_guardian_licenses')->where('service_id', $serviceId)->update($data);
		} else {
			Capsule::table('mod_guardian_licenses')->insert($data);
		}

		$data['ok'] = true;
		return $data;
	}

	public static function findByLicenseId(string $licenseId): ?array
	{
		self::ensureSchema();
		$row = Capsule::table('mod_guardian_licenses')->where('license_id', $licenseId)->first();
		if (!$row) {
			return null;
		}
		return [
			'service_id' => (int) $row->service_id,
			'license_id' => (string) $row->license_id,
			'domain' => (string) $row->domain,
			'install_id' => isset($row->install_id) ? (string) $row->install_id : '',
			'token' => is_string($row->token) ? $row->token : null,
			'status' => (string) $row->status,
			'issued_at' => (int) $row->issued_at,
			'expires_at' => (int) $row->expires_at,
		];
	}

	public static function listLicenses(int $limit = 50): array
	{
		self::ensureSchema();
		$rows = Capsule::table('mod_guardian_licenses')
			->orderBy('updated_at', 'desc')
			->limit(max(1, min(500, $limit)))
			->get(['service_id', 'license_id', 'domain', 'install_id', 'status', 'expires_at', 'updated_at']);

		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'service_id' => (int) $r->service_id,
				'license_id' => (string) $r->license_id,
				'domain' => (string) $r->domain,
				'install_id' => isset($r->install_id) ? (string) $r->install_id : '',
				'status' => (string) $r->status,
				'expires_at' => (int) $r->expires_at,
				'updated_at' => (int) $r->updated_at,
			];
		}
		return $out;
	}

	public static function clearDomain(string $licenseId): void
	{
		self::ensureSchema();
		Capsule::table('mod_guardian_licenses')
			->where('license_id', $licenseId)
			->update([
				'domain' => '',
				'install_id' => '',
				'updated_at' => time(),
			]);
	}

	public static function updateInstallId(string $licenseId, string $installId): void
	{
		self::ensureSchema();
		Capsule::table('mod_guardian_licenses')
			->where('license_id', $licenseId)
			->update([
				'install_id' => trim($installId),
				'updated_at' => time(),
			]);
	}

	public static function clearInstallId(string $licenseId): void
	{
		self::ensureSchema();
		Capsule::table('mod_guardian_licenses')
			->where('license_id', $licenseId)
			->update([
				'install_id' => '',
				'updated_at' => time(),
			]);
	}

	public static function reissueTokenByLicenseId(string $licenseId): void
	{
		self::ensureSchema();
		$row = Capsule::table('mod_guardian_licenses')->where('license_id', $licenseId)->first(['service_id']);
		if (!$row) {
			return;
		}
		$svc = ServiceResolver::serviceById((int) $row->service_id);
		if ($svc) {
			self::getOrIssueForService($svc);
		}
	}

	public static function updateDomain(string $licenseId, string $domain): void
	{
		self::ensureSchema();
		Capsule::table('mod_guardian_licenses')
			->where('license_id', $licenseId)
			->update([
				'domain' => strtolower(trim($domain)),
				'updated_at' => time(),
			]);
	}

	private static function newLicenseId(): string
	{
		return 'GL-' . bin2hex(random_bytes(12));
	}

	private static function mapServiceStatus(string $whmcsStatus, int $exp): string
	{
		$whmcsStatus = strtolower($whmcsStatus);
		if ($whmcsStatus === 'terminated' || $whmcsStatus === 'cancelled') {
			return 'terminated';
		}
		if ($whmcsStatus === 'suspended' || $whmcsStatus === 'fraud') {
			return 'suspended';
		}
		if ($exp > 0 && time() > $exp) {
			return 'expired';
		}
		return 'active';
	}

	/**
	 * Expiry rules:
	 * - if license_type == trial: now + trialDays (addon setting)
	 * - else: use next due date (service) end-of-day UTC (best-effort)
	 */
	private static function computeExpiry(array $svc): int
	{
		$type = strtolower((string) ($svc['license_type'] ?? ''));
		if ($type === 'trial') {
			$val = Capsule::table('tbladdonmodules')
				->where('module', 'guardian_licensing')
				->where('setting', 'trialDays')
				->value('value');
			$trialDays = is_string($val) ? (int) $val : 14;
			if ($trialDays <= 0) {
				$trialDays = 14;
			}
			return time() + ($trialDays * 86400);
		}

		$nextDue = (string) ($svc['next_due'] ?? '');
		if ($nextDue === '' || $nextDue === '0000-00-00') {
			return 0;
		}
		$ts = strtotime($nextDue . ' 23:59:59 UTC');
		return $ts ? (int) $ts : 0;
	}

	public static function getSetting(string $setting, string $default = ''): string
	{
		$val = Capsule::table('tbladdonmodules')
			->where('module', 'guardian_licensing')
			->where('setting', $setting)
			->value('value');
		return is_string($val) ? $val : $default;
	}
}

