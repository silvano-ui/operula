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
	$ts = isset($_POST['ts']) ? (int) $_POST['ts'] : 0;
	$nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
	$sig = isset($_POST['sig']) ? (string) $_POST['sig'] : '';

	if ($licenseId === '' || $domain === '') {
		http_response_code(400);
		echo json_encode(['ok' => false, 'status' => 'invalid', 'message' => 'license_id and domain required']);
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

		$rate = (int) Repo::getSetting('rateLimitPerMinute', '60');
		if ($rate <= 0) {
			$rate = 60;
		}

		$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$rlKey = 'validate:' . $licenseId . ':' . $ip;
		if (!Repo::rateLimitAllow($rlKey, 60, $rate)) {
			http_response_code(429);
			echo json_encode(['ok' => false, 'status' => 'rate_limited', 'message' => 'too many requests']);
			exit;
		}

		// Prefer signed requests; allow legacy api_secret only if not enforced.
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
			$msg = "POST\n{$path}\n{$licenseId}\n{$domain}\n{$ts}\n{$nonce}";
			$expect = rtrim(strtr(base64_encode(hash_hmac('sha256', $msg, $apiSecret, true)), '+/', '-_'), '=');
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

	$policy = Repo::getSetting('domainPolicy', 'lock_first');
	$policy = is_string($policy) ? $policy : 'lock_first';

	// Domain binding rules.
	if ($row['domain'] === '') {
		Repo::updateDomain($licenseId, $domain);
		$row['domain'] = $domain;
	} elseif ($row['domain'] !== $domain) {
		if ($policy === 'allow_change') {
			Repo::updateDomain($licenseId, $domain);
			$row['domain'] = $domain;
		} elseif ($policy === 'reset_required') {
			http_response_code(409);
			echo json_encode([
				'ok' => false,
				'status' => 'domain_reset_required',
				'message' => 'domain mismatch; reset required',
				'licensed_domain' => $row['domain'],
			]);
			exit;
		} else { // lock_first
			http_response_code(409);
			echo json_encode([
				'ok' => false,
				'status' => 'domain_mismatch',
				'message' => 'domain mismatch',
				'licensed_domain' => $row['domain'],
			]);
			exit;
		}
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

