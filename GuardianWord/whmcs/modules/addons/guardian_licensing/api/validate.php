<?php

/**
 * Guardian Licensing validate endpoint (JSON).
 *
 * POST:
 * - license_id (required)
 * - domain (required)
 * - api_secret (optional if configured in addon)
 */

define('WHMCS_CLIENTAREA', true);
define('WHMCS_PUBLIC', true);

require_once dirname(__DIR__, 4) . '/init.php';

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../lib/Signer.php';
require_once __DIR__ . '/../lib/Repo.php';
require_once __DIR__ . '/../lib/ServiceResolver.php';

header('Content-Type: application/json; charset=utf-8');

try {
	Repo::ensureSchema();

	$licenseId = isset($_POST['license_id']) ? trim((string) $_POST['license_id']) : '';
	$domain = isset($_POST['domain']) ? strtolower(trim((string) $_POST['domain'])) : '';
	$apiSecretInput = isset($_POST['api_secret']) ? (string) $_POST['api_secret'] : '';

	if ($licenseId === '' || $domain === '') {
		http_response_code(400);
		echo json_encode(['ok' => false, 'status' => 'invalid', 'message' => 'license_id and domain required']);
		exit;
	}

	$apiSecret = Signer::loadApiSecretFromAddonSettings();
	if ($apiSecret !== '') {
		if (!hash_equals($apiSecret, $apiSecretInput)) {
			http_response_code(403);
			echo json_encode(['ok' => false, 'status' => 'forbidden', 'message' => 'invalid api_secret']);
			exit;
		}
	}

	$row = Repo::findByLicenseId($licenseId);
	if (!$row) {
		http_response_code(404);
		echo json_encode(['ok' => false, 'status' => 'invalid', 'message' => 'license not found']);
		exit;
	}

	// Update domain if empty or changed (policy: lock to first domain unless admin changes?).
	// Here: allow updating if empty; if different, return mismatch.
	if ($row['domain'] === '') {
		Repo::updateDomain($licenseId, $domain);
		$row['domain'] = $domain;
	} elseif ($row['domain'] !== $domain) {
		http_response_code(409);
		echo json_encode(['ok' => false, 'status' => 'invalid', 'message' => 'domain mismatch', 'licensed_domain' => $row['domain']]);
		exit;
	}

	// Refresh from WHMCS service (renew/upgrade changes) on each validate.
	$svc = ServiceResolver::serviceById((int) $row['service_id']);
	if ($svc) {
		$issued = Repo::getOrIssueForService($svc);
		$row = array_merge($row, $issued);
	}

	$status = (string) ($row['status'] ?? 'invalid');
	$ok = $status === 'active';

	echo json_encode([
		'ok' => $ok,
		'status' => $status,
		'license_id' => $licenseId,
		'domain' => $row['domain'] ?? '',
		'exp' => (int) ($row['expires_at'] ?? 0),
		'token' => $ok ? ($row['token'] ?? null) : null,
	]);
} catch (\Throwable $e) {
	http_response_code(500);
	echo json_encode(['ok' => false, 'status' => 'error', 'message' => $e->getMessage()]);
}

