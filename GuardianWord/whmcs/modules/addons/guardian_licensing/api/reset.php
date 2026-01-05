<?php

/**
 * Reset domain binding for a license (JSON).
 *
 * POST:
 * - license_id (required)
 * - api_secret (required if configured; strongly recommended)
 */

define('WHMCS_CLIENTAREA', true);
define('WHMCS_PUBLIC', true);

require_once dirname(__DIR__, 4) . '/init.php';

require_once __DIR__ . '/../lib/Signer.php';
require_once __DIR__ . '/../lib/Repo.php';

header('Content-Type: application/json; charset=utf-8');

try {
	Repo::ensureSchema();
	$licenseId = isset($_POST['license_id']) ? trim((string) $_POST['license_id']) : '';
	$apiSecretInput = isset($_POST['api_secret']) ? (string) $_POST['api_secret'] : '';

	if ($licenseId === '') {
		http_response_code(400);
		echo json_encode(['ok' => false, 'status' => 'invalid', 'message' => 'license_id required']);
		exit;
	}

	$apiSecret = Signer::loadApiSecretFromAddonSettings();
	if ($apiSecret !== '') {
		if (!hash_equals($apiSecret, $apiSecretInput)) {
			http_response_code(403);
			echo json_encode(['ok' => false, 'status' => 'forbidden', 'message' => 'invalid api_secret']);
			exit;
		}
	} else {
		// No secret configured: still allow, but warn in response.
	}

	$row = Repo::findByLicenseId($licenseId);
	if (!$row) {
		http_response_code(404);
		echo json_encode(['ok' => false, 'status' => 'invalid', 'message' => 'license not found']);
		exit;
	}

	Repo::clearDomain($licenseId);
	echo json_encode(['ok' => true, 'status' => 'ok', 'message' => 'domain cleared']);
} catch (\Throwable $e) {
	http_response_code(500);
	echo json_encode(['ok' => false, 'status' => 'error', 'message' => $e->getMessage()]);
}

