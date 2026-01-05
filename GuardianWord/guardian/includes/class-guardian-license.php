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
	private const OPTION_LICENSE_MODE  = 'guardian_license_mode'; // offline|whmcs
	private const OPTION_WHMCS_CONF    = 'guardian_whmcs_conf';

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

	public function get_mode(): string {
		$m = get_option(self::OPTION_LICENSE_MODE);
		$m = is_string($m) ? $m : 'offline';
		return in_array($m, ['offline', 'whmcs'], true) ? $m : 'offline';
	}

	public function set_mode(string $mode): void {
		$mode = in_array($mode, ['offline', 'whmcs'], true) ? $mode : 'offline';
		update_option(self::OPTION_LICENSE_MODE, $mode, false);
		delete_option(self::OPTION_LICENSE_CACHE);
	}

	public function get_whmcs_conf(): array {
		$conf = get_option(self::OPTION_WHMCS_CONF);
		$conf = is_array($conf) ? $conf : [];
		return array_merge([
			'validate_url' => '',
			'reset_url' => '',
			'license_id' => '',
			'api_secret' => '',
			'install_id' => '',
			'cache_ttl' => 3600,
		], $conf);
	}

	public function save_whmcs_conf(array $conf): void {
		$cur = $this->get_whmcs_conf();
		$new = array_merge($cur, $conf);
		update_option(self::OPTION_WHMCS_CONF, $new, false);
		delete_option(self::OPTION_LICENSE_CACHE);
	}

	public function get_install_id(): string {
		$conf = $this->get_whmcs_conf();
		$id = isset($conf['install_id']) && is_string($conf['install_id']) ? trim($conf['install_id']) : '';
		if ($id !== '') {
			return $id;
		}
		// Autogenera e salva.
		$id = 'GW-' . wp_generate_password(22, false, false);
		$this->save_whmcs_conf(['install_id' => $id]);
		return $id;
	}

	public function rotate_install_id(): string {
		$id = 'GW-' . wp_generate_password(22, false, false);
		$this->save_whmcs_conf(['install_id' => $id]);
		return $id;
	}

	public function status(): array {
		$mode = $this->get_mode();
		if ($mode === 'whmcs') {
			// Best-effort: usa cache; se scaduta prova fetch.
			$cached = $this->get_cached_status();
			if ($cached && !empty($cached['ok'])) {
				return $cached;
			}
			$fresh = $this->refresh_from_whmcs_if_needed(true);
			if ($fresh) {
				return $fresh;
			}
			// fallback: se esiste token locale, verifica offline
		}

		$token = $this->get_token();
		if ($token === '') {
			return ['ok' => false, 'code' => 'missing', 'message' => __('Licenza mancante.', 'guardian')];
		}
		$check = $this->verify_offline_token($token);
		$this->set_cached_status($check);
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

	/**
	 * Refresh token via WHMCS validate endpoint.
	 *
	 * @param bool $force se true forza chiamata (no cache)
	 * @return array|null status array
	 */
	public function refresh_from_whmcs_if_needed(bool $force = false): ?array {
		$conf = $this->get_whmcs_conf();
		$url = (string) ($conf['validate_url'] ?? '');
		$licenseId = (string) ($conf['license_id'] ?? '');
		$ttl = (int) ($conf['cache_ttl'] ?? 3600);
		if ($ttl <= 60) {
			$ttl = 3600;
		}

		if (!$force) {
			$cached = $this->get_cached_status();
			if ($cached && isset($cached['_cached_at']) && (time() - (int) $cached['_cached_at']) < $ttl) {
				return $cached;
			}
		}

		if ($url === '' || $licenseId === '') {
			$st = ['ok' => false, 'code' => 'whmcs_missing', 'message' => __('Config WHMCS incompleta (validate_url/license_id).', 'guardian')];
			$this->set_cached_status($st);
			return $st;
		}

		$domain = $this->site_host();
		$ts = time();
		$nonce = $this->new_nonce();
		$installId = $this->get_install_id();
		$sig = $this->sign_whmcs_request('POST', $url, $licenseId, $domain, $installId, $ts, $nonce);
		$body = [
			'license_id' => $licenseId,
			'domain' => $domain,
			'install_id' => $installId,
			'ts' => $ts,
			'nonce' => $nonce,
			'sig' => $sig,
		];
		// Compat: se secret non impostato e firma vuota, invia eventualmente api_secret (legacy).
		if ($sig === '') {
			$apiSecret = (string) ($conf['api_secret'] ?? '');
			if ($apiSecret !== '') {
				$body['api_secret'] = $apiSecret;
			}
		}

		$res = wp_remote_post($url, [
			'timeout' => 12,
			'headers' => ['Accept' => 'application/json'],
			'body' => $body,
		]);

		if (is_wp_error($res)) {
			$st = ['ok' => false, 'code' => 'whmcs_http', 'message' => __('Errore chiamata WHMCS.', 'guardian') . ' ' . $res->get_error_message()];
			$this->set_cached_status($st);
			return $st;
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$raw = (string) wp_remote_retrieve_body($res);
		$data = json_decode($raw, true);
		if (!is_array($data)) {
			$st = ['ok' => false, 'code' => 'whmcs_parse', 'message' => __('Risposta WHMCS non valida.', 'guardian')];
			$this->set_cached_status($st);
			return $st;
		}

		if ($code >= 400 || empty($data['ok'])) {
			$msg = isset($data['message']) && is_string($data['message']) ? $data['message'] : __('Licenza WHMCS non valida.', 'guardian');
			$st = ['ok' => false, 'code' => 'whmcs_denied', 'message' => $msg, 'whmcs' => $data];
			$this->set_cached_status($st);
			return $st;
		}

		$token = isset($data['token']) && is_string($data['token']) ? trim($data['token']) : '';
		if ($token === '') {
			$st = ['ok' => false, 'code' => 'whmcs_no_token', 'message' => __('WHMCS non ha fornito un token.', 'guardian')];
			$this->set_cached_status($st);
			return $st;
		}

		// Salva token locale e verifica firma offline (difesa in profondità).
		$this->save_token($token);
		$check = $this->verify_offline_token($token);
		$check['whmcs'] = $data;
		$this->set_cached_status($check);
		return $check;
	}

	public function request_domain_reset(): array {
		$conf = $this->get_whmcs_conf();
		$url = (string) ($conf['reset_url'] ?? '');
		$licenseId = (string) ($conf['license_id'] ?? '');
		if ($url === '' || $licenseId === '') {
			return ['ok' => false, 'code' => 'whmcs_missing', 'message' => __('Config WHMCS incompleta (reset_url/license_id).', 'guardian')];
		}
		$ts = time();
		$nonce = $this->new_nonce();
		$sig = $this->sign_whmcs_request('POST', $url, $licenseId, 'domain', '', $ts, $nonce);
		$body = [
			'license_id' => $licenseId,
			'reset_kind' => 'domain',
			'ts' => $ts,
			'nonce' => $nonce,
			'sig' => $sig,
		];
		if ($sig === '') {
			$apiSecret = (string) ($conf['api_secret'] ?? '');
			if ($apiSecret !== '') {
				$body['api_secret'] = $apiSecret;
			}
		}
		$res = wp_remote_post($url, [
			'timeout' => 12,
			'headers' => ['Accept' => 'application/json'],
			'body' => $body,
		]);
		if (is_wp_error($res)) {
			return ['ok' => false, 'code' => 'whmcs_http', 'message' => $res->get_error_message()];
		}
		$raw = (string) wp_remote_retrieve_body($res);
		$data = json_decode($raw, true);
		if (!is_array($data) || empty($data['ok'])) {
			$msg = is_array($data) && isset($data['message']) && is_string($data['message']) ? $data['message'] : __('Reset dominio non riuscito.', 'guardian');
			return ['ok' => false, 'code' => 'whmcs_reset_fail', 'message' => $msg, 'whmcs' => $data];
		}
		return ['ok' => true, 'code' => 'ok', 'message' => __('Dominio resettato su WHMCS.', 'guardian')];
	}

	public function request_install_reset(): array {
		$conf = $this->get_whmcs_conf();
		$url = (string) ($conf['reset_url'] ?? '');
		$licenseId = (string) ($conf['license_id'] ?? '');
		if ($url === '' || $licenseId === '') {
			return ['ok' => false, 'code' => 'whmcs_missing', 'message' => __('Config WHMCS incompleta (reset_url/license_id).', 'guardian')];
		}
		$ts = time();
		$nonce = $this->new_nonce();
		$sig = $this->sign_whmcs_request('POST', $url, $licenseId, 'install', '', $ts, $nonce);
		$body = [
			'license_id' => $licenseId,
			'reset_kind' => 'install',
			'ts' => $ts,
			'nonce' => $nonce,
			'sig' => $sig,
		];
		if ($sig === '') {
			$apiSecret = (string) ($conf['api_secret'] ?? '');
			if ($apiSecret !== '') {
				$body['api_secret'] = $apiSecret;
			}
		}
		$res = wp_remote_post($url, [
			'timeout' => 12,
			'headers' => ['Accept' => 'application/json'],
			'body' => $body,
		]);
		if (is_wp_error($res)) {
			return ['ok' => false, 'code' => 'whmcs_http', 'message' => $res->get_error_message()];
		}
		$raw = (string) wp_remote_retrieve_body($res);
		$data = json_decode($raw, true);
		if (!is_array($data) || empty($data['ok'])) {
			$msg = is_array($data) && isset($data['message']) && is_string($data['message']) ? $data['message'] : __('Reset install non riuscito.', 'guardian');
			return ['ok' => false, 'code' => 'whmcs_reset_fail', 'message' => $msg, 'whmcs' => $data];
		}
		return ['ok' => true, 'code' => 'ok', 'message' => __('Install binding resettato su WHMCS.', 'guardian')];
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

	private function get_cached_status(): ?array {
		$c = get_option(self::OPTION_LICENSE_CACHE);
		return is_array($c) ? $c : null;
	}

	private function set_cached_status(array $st): void {
		$st['_cached_at'] = time();
		update_option(self::OPTION_LICENSE_CACHE, $st, false);
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

	private function new_nonce(): string {
		$bin = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
		$bin = is_string($bin) ? $bin : (string) wp_generate_password(16, true, true);
		return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
	}

	/**
	 * Firma richiesta WHMCS con HMAC-SHA256 (anti-replay).
	 *
	 * Message format:
	 * POST\n/path\n{licenseId}\n{domain}\n{ts}\n{nonce}
	 */
	private function sign_whmcs_request(string $method, string $url, string $licenseId, string $domain, string $installId, int $ts, string $nonce): string {
		$conf = $this->get_whmcs_conf();
		$secret = (string) ($conf['api_secret'] ?? '');
		if ($secret === '') {
			// Compat: se secret non impostato, firma vuota (WHMCS può accettare richieste non firmate se non enforced).
			return '';
		}
		$path = parse_url($url, PHP_URL_PATH);
		$path = is_string($path) ? $path : '';
		$domain = (string) $domain;
		$installId = (string) $installId;
		$msg = strtoupper($method) . "\n" . $path . "\n" . $licenseId . "\n" . $domain . "\n" . $installId . "\n" . $ts . "\n" . $nonce;
		$raw = hash_hmac('sha256', $msg, $secret, true);
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}
}

