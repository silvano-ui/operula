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

	/**
	 * Best-effort: reads a configurable option named "guardian_license_type" if present.
	 */
	private static function licenseTypeForService(int $serviceId): string
	{
		// Find configurable option id by option name (guarded).
		$opt = Capsule::table('tblproductconfigoptions')
			->where('optionname', 'like', '%guardian_license_type%')
			->first(['id']);
		if (!$opt) {
			return '';
		}

		$rel = Capsule::table('tblhostingconfigoptions')
			->where('relid', $serviceId)
			->where('configid', (int) $opt->id)
			->value('optionid');
		if (!$rel) {
			return '';
		}

		// optionid points to tblproductconfigoptionssub.id; get optionname value.
		$val = Capsule::table('tblproductconfigoptionssub')
			->where('id', (int) $rel)
			->value('optionname');
		return is_string($val) ? strtolower(trim($val)) : '';
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
		];
	}
}

