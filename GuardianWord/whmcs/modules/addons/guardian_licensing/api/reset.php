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
require_once __DIR__ . '/../lib/Security.php';

header('Content-Type: application/json; charset=utf-8');

try {
	Repo::ensureSchema();
	$licenseId = isset($_POST['license_id']) ? trim((string) $_POST['license_id']) : '';
	$apiSecretInput = isset($_POST['api_secret']) ? (string) $_POST['api_secret'] : '';
	$ts = isset($_POST['ts']) ? (int) $_POST['ts'] : 0;
	$nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
	$sig = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
	$resetKind = isset($_POST['reset_kind']) ? (string) $_POST['reset_kind'] : 'domain';

	if ($licenseId === '') {
		http_response_code(400);
		echo json_encode(['ok' => false, 'status' => 'invalid', 'message' => 'license_id required']);
		exit;
	}

	$ip = Security::clientIp();
	$allowlist = Repo::getSetting('ipAllowlist', '');
	if (!Security::ipAllowed($ip, $allowlist)) {
		http_response_code(403);
		echo json_encode(['ok' => false, 'status' => 'forbidden', 'message' => 'ip not allowed']);
		exit;
	}

	$apiSecret = Signer::loadApiSecretFromAddonSettings();
	if ($apiSecret !== '') {
		$enforce = Repo::getSetting('enforceSignedRequests', 'on');
		$enforce = strtolower((string) $enforce) !== '' && strtolower((string) $enforce) !== 'off';

		$skew = (int) Repo::getSetting('maxClockSkewSeconds', '300');
		if ($skew <= 0) {
			$skew = 300;
		}

		$rate = (int) Repo::getSetting('rateLimitPerMinute', '30');
		if ($rate <= 0) {
			$rate = 30;
		}

		$ip = Security::clientIp();
		$rlKey = 'reset:' . $licenseId . ':' . $ip;
		if (!Repo::rateLimitAllow($rlKey, 60, $rate)) {
			http_response_code(429);
			echo json_encode(['ok' => false, 'status' => 'rate_limited', 'message' => 'too many requests']);
			exit;
		}

		if ($sig !== '' && $ts > 0 && $nonce !== '') {
			if (abs(time() - $ts) > $skew) {
				http_response_code(401);
				echo json_encode(['ok' => false, 'status' => 'unauthorized', 'message' => 'timestamp skew']);
				exit;
			}
			if (Repo::nonceSeenOrStore($licenseId, $nonce, $ts, $ip, $skew + 60)) {
				http_response_code(401);
				echo json_encode(['ok' => false, 'status' => 'unauthorized', 'message' => 'replay detected']);
				exit;
			}
			$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
			$path = is_string($path) ? $path : '';
			$rk = strtolower(trim((string) $resetKind));
			$msg = Security::message('POST', $path, $licenseId, $rk, '', $ts, $nonce);
			$expect = Security::b64url(hash_hmac('sha256', $msg, $apiSecret, true));
			if (!hash_equals($expect, $sig)) {
				http_response_code(403);
				echo json_encode(['ok' => false, 'status' => 'forbidden', 'message' => 'bad signature']);
				exit;
			}
		} else {
			if ($enforce) {
				http_response_code(401);
				echo json_encode(['ok' => false, 'status' => 'unauthorized', 'message' => 'signature required']);
				exit;
			}
			if (!hash_equals($apiSecret, $apiSecretInput)) {
				http_response_code(403);
				echo json_encode(['ok' => false, 'status' => 'forbidden', 'message' => 'invalid api_secret']);
				exit;
			}
		}
	}

	$row = Repo::findByLicenseId($licenseId);
	if (!$row) {
		http_response_code(404);
		echo json_encode(['ok' => false, 'status' => 'invalid', 'message' => 'license not found']);
		exit;
	}

	$resetKind = strtolower(trim($resetKind));
	if ($resetKind === 'all') {
		Repo::clearDomain($licenseId); // clears domain + install_id
		echo json_encode(['ok' => true, 'status' => 'ok', 'message' => 'domain and install binding cleared']);
		exit;
	}
	if ($resetKind === 'install') {
		Repo::clearInstallId($licenseId);
		echo json_encode(['ok' => true, 'status' => 'ok', 'message' => 'install binding cleared']);
		exit;
	}
	Repo::clearDomain($licenseId);
	echo json_encode(['ok' => true, 'status' => 'ok', 'message' => 'domain cleared']);
} catch (\Throwable $e) {
	http_response_code(500);
	echo json_encode(['ok' => false, 'status' => 'error', 'message' => $e->getMessage()]);
}

