<?php
/**
 * Guardian license generator (OFFLINE).
 *
 * Requirements:
 * - PHP with libsodium enabled (ext-sodium)
 *
 * Usage:
 * 1) Generate keys (once):
 *    php license_gen.php gen-keys
 *
 * 2) Generate a token for a domain:
 *    php license_gen.php gen-token --private-key-b64 "<PRIVATE_KEY_B64>" --domain "example.com" --license-id "LIC-001" --expires-days 365
 *
 * Notes:
 * - Keep the PRIVATE KEY secret.
 * - Paste the PUBLIC KEY (base64) into the plugin constant License::PUBLIC_KEY_B64.
 * - The resulting token must be pasted in WP Admin > Guardian > Licenza.
 */

function b64url_encode(string $s): string {
	return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function die_usage(string $msg = ''): void {
	if ($msg !== '') {
		fwrite(STDERR, $msg . PHP_EOL);
	}
	fwrite(STDERR, "Commands:\n");
	fwrite(STDERR, "  gen-keys\n");
	fwrite(STDERR, "  gen-token --private-key-b64 <...> --domain <example.com> --license-id <LIC-001> [--expires-days 365]\n");
	exit(1);
}

if (!function_exists('sodium_crypto_sign_keypair')) {
	die_usage("libsodium missing: enable ext-sodium");
}

$argv = $_SERVER['argv'] ?? [];
$cmd = $argv[1] ?? '';

if ($cmd === 'gen-keys') {
	$kp = sodium_crypto_sign_keypair();
	$sk = sodium_crypto_sign_secretkey($kp);
	$pk = sodium_crypto_sign_publickey($kp);

	echo "PUBLIC_KEY_B64=" . base64_encode($pk) . PHP_EOL;
	echo "PRIVATE_KEY_B64=" . base64_encode($sk) . PHP_EOL;
	exit(0);
}

if ($cmd === 'gen-token') {
	$args = [];
	for ($i = 2; $i < count($argv); $i++) {
		if (strpos($argv[$i], '--') === 0) {
			$k = substr($argv[$i], 2);
			$v = $argv[$i + 1] ?? '';
			$args[$k] = $v;
			$i++;
		}
	}

	$skB64 = $args['private-key-b64'] ?? '';
	$domain = strtolower(trim((string) ($args['domain'] ?? '')));
	$licId = trim((string) ($args['license-id'] ?? ''));
	$expDays = (int) ($args['expires-days'] ?? 0);

	if ($skB64 === '' || $domain === '' || $licId === '') {
		die_usage("Missing required args.");
	}

	$sk = base64_decode($skB64, true);
	if (!is_string($sk)) {
		die_usage("Invalid private key b64.");
	}

	$now = time();
	$exp = 0;
	if ($expDays > 0) {
		$exp = $now + ($expDays * 86400);
	}

	$payload = [
		'v' => 1,
		'lic' => $licId,
		'dom' => $domain,
		'iat' => $now,
		'exp' => $exp,
		'feat' => [
			'guardian' => true,
		],
	];
	$json = json_encode($payload, JSON_UNESCAPED_SLASHES);
	if (!is_string($json)) {
		die_usage("Failed to encode payload.");
	}

	$sig = sodium_crypto_sign_detached($json, $sk);
	$token = b64url_encode($json) . '.' . b64url_encode($sig);

	echo $token . PHP_EOL;
	exit(0);
}

die_usage("Unknown command: {$cmd}");

