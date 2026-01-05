<?php

namespace Guardian;

/**
 * Licensing (offline token signed).
 *
 * Token format: base64url(JSON_PAYLOAD) . "." . base64url(SIGNATURE)
 * Signature: Ed25519 detached over raw JSON bytes.
 *
 * Payload fields:
 * - v: int (schema version)
 * - lic: string (license id)
 * - dom: string (domain/host, lowercased)
 * - iat: int (issued at, unix)
 * - exp: int (expires at, unix) - optional (0 = never)
 * - feat: array (optional feature flags)
 */
final class License {
	private const OPTION_LICENSE_TOKEN = 'guardian_license_token';
	private const OPTION_LICENSE_CACHE = 'guardian_license_cache';

	/**
	 * Put your Ed25519 public key here (base64).
	 * Generate with the provided tool in /GuardianWord/tools/.
	 */
	public const PUBLIC_KEY_B64 = 'REPLACE_WITH_PUBLIC_KEY_BASE64';

	private Storage $storage;

	public function __construct(Storage $storage) {
		$this->storage = $storage;
	}

	public function get_token(): string {
		$t = get_option(self::OPTION_LICENSE_TOKEN);
		return is_string($t) ? trim($t) : '';
	}

	public function save_token(string $token): void {
		update_option(self::OPTION_LICENSE_TOKEN, trim($token), false);
		delete_option(self::OPTION_LICENSE_CACHE);
	}

	public function status(): array {
		$token = $this->get_token();
		if ($token === '') {
			return ['ok' => false, 'code' => 'missing', 'message' => __('Licenza mancante.', 'guardian')];
		}
		$check = $this->verify_offline_token($token);
		return $check;
	}

	public function is_valid(): bool {
		$s = $this->status();
		return !empty($s['ok']);
	}

	public function get_payload(): ?array {
		$token = $this->get_token();
		if ($token === '') {
			return null;
		}
		$parts = explode('.', $token);
		if (count($parts) !== 2) {
			return null;
		}
		$payloadJson = $this->b64url_decode($parts[0]);
		if (!is_string($payloadJson)) {
			return null;
		}
		$data = json_decode($payloadJson, true);
		return is_array($data) ? $data : null;
	}

	private function verify_offline_token(string $token): array {
		// Hard fail if key not configured.
		if (self::PUBLIC_KEY_B64 === 'REPLACE_WITH_PUBLIC_KEY_BASE64') {
			return [
				'ok' => false,
				'code' => 'pubkey_missing',
				'message' => __('Guardian non è configurato: manca la chiave pubblica per verificare la licenza.', 'guardian'),
			];
		}

		$parts = explode('.', $token);
		if (count($parts) !== 2) {
			return ['ok' => false, 'code' => 'format', 'message' => __('Formato licenza non valido.', 'guardian')];
		}

		$payloadB64 = $parts[0];
		$sigB64 = $parts[1];

		$payloadJson = $this->b64url_decode($payloadB64);
		$sig = $this->b64url_decode($sigB64);
		$pub = base64_decode(self::PUBLIC_KEY_B64, true);

		if (!is_string($payloadJson) || !is_string($sig) || !is_string($pub)) {
			return ['ok' => false, 'code' => 'decode', 'message' => __('Licenza non decodificabile.', 'guardian')];
		}

		$data = json_decode($payloadJson, true);
		if (!is_array($data)) {
			return ['ok' => false, 'code' => 'payload', 'message' => __('Payload licenza non valido.', 'guardian')];
		}

		$v = isset($data['v']) ? (int) $data['v'] : 0;
		if ($v !== 1) {
			return ['ok' => false, 'code' => 'version', 'message' => __('Versione licenza non supportata.', 'guardian')];
		}

		$dom = isset($data['dom']) && is_string($data['dom']) ? strtolower(trim($data['dom'])) : '';
		if ($dom === '') {
			return ['ok' => false, 'code' => 'domain_missing', 'message' => __('Dominio licenza mancante.', 'guardian')];
		}

		$siteHost = $this->site_host();
		if ($siteHost === '') {
			return ['ok' => false, 'code' => 'site_host', 'message' => __('Impossibile determinare host del sito.', 'guardian')];
		}

		if ($dom !== $siteHost) {
			return [
				'ok' => false,
				'code' => 'domain_mismatch',
				'message' => sprintf(__('Licenza valida per "%s" ma questo sito è "%s".', 'guardian'), $dom, $siteHost),
			];
		}

		$now = time();
		$exp = isset($data['exp']) ? (int) $data['exp'] : 0;
		if ($exp > 0 && $now > $exp) {
			return ['ok' => false, 'code' => 'expired', 'message' => __('Licenza scaduta.', 'guardian')];
		}

		// Verify signature with libsodium.
		if (!function_exists('sodium_crypto_sign_verify_detached')) {
			return [
				'ok' => false,
				'code' => 'sodium_missing',
				'message' => __('Estensione libsodium mancante: impossibile verificare la licenza.', 'guardian'),
			];
		}

		$ok = @sodium_crypto_sign_verify_detached($sig, $payloadJson, $pub);
		if (!$ok) {
			return ['ok' => false, 'code' => 'bad_sig', 'message' => __('Firma licenza non valida.', 'guardian')];
		}

		return [
			'ok' => true,
			'code' => 'ok',
			'message' => __('Licenza valida.', 'guardian'),
			'payload' => $data,
		];
	}

	private function site_host(): string {
		$u = home_url('/');
		$host = parse_url($u, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return '';
		}
		return strtolower($host);
	}

	private function b64url_decode(string $s): ?string {
		$s = str_replace(['-', '_'], ['+', '/'], $s);
		$pad = strlen($s) % 4;
		if ($pad) {
			$s .= str_repeat('=', 4 - $pad);
		}
		$out = base64_decode($s, true);
		return is_string($out) ? $out : null;
	}
}

