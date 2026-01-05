<?php

/**
 * Hooks for Guardian Licensing (renew/upgrade/suspend/terminate).
 *
 * Place this file in: WHMCS_ROOT/includes/hooks/guardian_licensing.php
 */

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../modules/addons/guardian_licensing/lib/Signer.php';
require_once __DIR__ . '/../../modules/addons/guardian_licensing/lib/Repo.php';
require_once __DIR__ . '/../../modules/addons/guardian_licensing/lib/ServiceResolver.php';

add_hook('AfterModuleCreate', 1, function ($vars) {
	Repo::ensureSchema();
	$serviceId = (int) ($vars['params']['serviceid'] ?? 0);
	if ($serviceId > 0) {
		$svc = ServiceResolver::serviceById($serviceId);
		if ($svc) {
			Repo::getOrIssueForService($svc);
		}
	}
});

add_hook('AfterModuleRenewal', 1, function ($vars) {
	Repo::ensureSchema();
	$serviceId = (int) ($vars['params']['serviceid'] ?? 0);
	if ($serviceId > 0) {
		$svc = ServiceResolver::serviceById($serviceId);
		if ($svc) {
			Repo::getOrIssueForService($svc);
		}
	}
});

// Fallback: quando viene pagata una fattura di rinnovo hosting, rigenera token.
add_hook('InvoicePaid', 1, function ($vars) {
	Repo::ensureSchema();
	$invoiceId = (int) ($vars['invoiceid'] ?? 0);
	if ($invoiceId <= 0) {
		return;
	}

	$items = Capsule::table('tblinvoiceitems')
		->where('invoiceid', $invoiceId)
		->where('type', 'Hosting')
		->get(['relid']);

	foreach ($items as $it) {
		$serviceId = (int) ($it->relid ?? 0);
		if ($serviceId > 0) {
			$svc = ServiceResolver::serviceById($serviceId);
			if ($svc) {
				Repo::getOrIssueForService($svc);
			}
		}
	}
});

add_hook('AfterModuleChangePackage', 1, function ($vars) {
	Repo::ensureSchema();
	$serviceId = (int) ($vars['params']['serviceid'] ?? 0);
	if ($serviceId > 0) {
		$svc = ServiceResolver::serviceById($serviceId);
		if ($svc) {
			Repo::getOrIssueForService($svc);
		}
	}
});

add_hook('AfterModuleSuspend', 1, function ($vars) {
	Repo::ensureSchema();
	$serviceId = (int) ($vars['params']['serviceid'] ?? 0);
	if ($serviceId > 0) {
		Capsule::table('mod_guardian_licenses')->where('service_id', $serviceId)->update([
			'status' => 'suspended',
			'updated_at' => time(),
		]);
	}
});

add_hook('AfterModuleUnsuspend', 1, function ($vars) {
	Repo::ensureSchema();
	$serviceId = (int) ($vars['params']['serviceid'] ?? 0);
	if ($serviceId > 0) {
		$svc = ServiceResolver::serviceById($serviceId);
		if ($svc) {
			Repo::getOrIssueForService($svc);
		}
	}
});

add_hook('AfterModuleTerminate', 1, function ($vars) {
	Repo::ensureSchema();
	$serviceId = (int) ($vars['params']['serviceid'] ?? 0);
	if ($serviceId > 0) {
		Capsule::table('mod_guardian_licenses')->where('service_id', $serviceId)->update([
			'status' => 'terminated',
			'updated_at' => time(),
		]);
	}
});

