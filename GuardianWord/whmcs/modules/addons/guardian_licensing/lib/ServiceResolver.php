<?php

use WHMCS\Database\Capsule;

final class ServiceResolver
{
	/**
	 * Returns services for a client with minimal fields.
	 */
	public static function servicesForClient(int $clientId): array
	{
		$rows = Capsule::table('tblhosting')
			->where('userid', $clientId)
			->orderBy('id', 'desc')
			->get(['id', 'userid', 'domain', 'nextduedate', 'domainstatus', 'billingcycle', 'packageid']);

		$out = [];
		foreach ($rows as $r) {
			$out[] = self::toSvc($r);
		}
		return $out;
	}

	public static function serviceById(int $serviceId): ?array
	{
		$r = Capsule::table('tblhosting')->where('id', $serviceId)->first([
			'id', 'userid', 'domain', 'nextduedate', 'domainstatus', 'billingcycle', 'packageid',
		]);
		return $r ? self::toSvc($r) : null;
	}

	private static function configOptionValueForService(int $serviceId, string $key): string
	{
		$opt = Capsule::table('tblproductconfigoptions')
			->where('optionname', 'like', '%' . $key . '%')
			->first(['id', 'optionname']);
		if (!$opt) {
			return '';
		}
		$optId = (int) $opt->id;

		$rel = Capsule::table('tblhostingconfigoptions')
			->where('relid', $serviceId)
			->where('configid', $optId)
			->value('optionid');
		if (!$rel) {
			return '';
		}

		$val = Capsule::table('tblproductconfigoptionssub')
			->where('id', (int) $rel)
			->value('optionname');
		if (!is_string($val)) {
			return '';
		}
		// WHMCS often stores "Label|something"; take left side.
		$val = explode('|', $val, 2)[0];
		return strtolower(trim($val));
	}

	private static function addonsForService(int $serviceId): array
	{
		$rows = Capsule::table('tblhostingaddons')
			->where('hostingid', $serviceId)
			->whereIn('status', ['Active', 'Completed'])
			->get(['addonid']);
		$addonIds = [];
		foreach ($rows as $r) {
			$addonIds[] = (int) $r->addonid;
		}
		if (!$addonIds) {
			return [];
		}
		$names = Capsule::table('tbladdons')
			->whereIn('id', $addonIds)
			->get(['name']);
		$out = [];
		foreach ($names as $n) {
			if (isset($n->name) && is_string($n->name)) {
				$out[] = strtolower(trim($n->name));
			}
		}
		return $out;
	}

	/**
	 * Best-effort: reads a configurable option named "guardian_license_type" if present.
	 */
	private static function licenseTypeForService(int $serviceId): string
	{
		return self::configOptionValueForService($serviceId, 'guardian_license_type');
	}

	private static function modulesForService(int $serviceId): array
	{
		$raw = self::configOptionValueForService($serviceId, 'guardian_modules');
		if ($raw === '') {
			return [];
		}
		$raw = str_replace(['+', ';'], [',', ','], $raw);
		$parts = array_filter(array_map('trim', explode(',', $raw)));
		$out = [];
		foreach ($parts as $p) {
			$p = strtolower(trim($p));
			if ($p !== '') {
				$out[] = $p;
			}
		}
		return array_values(array_unique($out));
	}

	private static function toSvc($r): array
	{
		$serviceId = (int) $r->id;
		return [
			'service_id' => $serviceId,
			'client_id' => (int) $r->userid,
			'domain' => (string) ($r->domain ?? ''),
			'next_due' => (string) ($r->nextduedate ?? ''),
			'status' => (string) ($r->domainstatus ?? ''),
			'cycle' => (string) ($r->billingcycle ?? ''),
			'package_id' => (int) ($r->packageid ?? 0),
			'license_type' => self::licenseTypeForService($serviceId),
			'modules' => self::modulesForService($serviceId),
			'addons' => self::addonsForService($serviceId),
		];
	}
}

