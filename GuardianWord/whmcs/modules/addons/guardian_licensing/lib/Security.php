<?php

final class Security
{
	public static function clientIp(): string
	{
		// Minimal: trust REMOTE_ADDR only.
		return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	}

	/**
	 * allowlist: comma-separated IPs or CIDRs. Empty means allow all.
	 */
	public static function ipAllowed(string $ip, string $allowlist): bool
	{
		$allowlist = trim($allowlist);
		if ($allowlist === '') {
			return true;
		}
		$parts = array_filter(array_map('trim', explode(',', $allowlist)));
		foreach ($parts as $rule) {
			if ($rule === '') {
				continue;
			}
			if (strpos($rule, '/') !== false) {
				if (self::cidrMatch($ip, $rule)) {
					return true;
				}
			} else {
				if ($ip === $rule) {
					return true;
				}
			}
		}
		return false;
	}

	private static function cidrMatch(string $ip, string $cidr): bool
	{
		[$subnet, $mask] = array_pad(explode('/', $cidr, 2), 2, '');
		$mask = (int) $mask;
		if ($mask <= 0 || $mask > 32) {
			return false;
		}
		$ipLong = ip2long($ip);
		$subLong = ip2long($subnet);
		if ($ipLong === false || $subLong === false) {
			return false;
		}
		$maskLong = -1 << (32 - $mask);
		return (($ipLong & $maskLong) === ($subLong & $maskLong));
	}

	public static function b64url(string $bin): string
	{
		return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
	}

	public static function userAgent(): string
	{
		return isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
	}

	/**
	 * allowlist: comma-separated substrings. Empty means allow all.
	 */
	public static function uaAllowed(string $ua, string $allowlist, string $mode = 'substring'): bool
	{
		$allowlist = trim($allowlist);
		if ($allowlist === '') {
			return true;
		}
		$ua = (string) $ua;
		$mode = in_array($mode, ['substring', 'exact', 'regex'], true) ? $mode : 'substring';
		$parts = array_filter(array_map('trim', explode(',', $allowlist)));
		foreach ($parts as $p) {
			if ($p === '') {
				continue;
			}
			if ($mode === 'exact') {
				if ($ua === $p) {
					return true;
				}
				continue;
			}
			if ($mode === 'regex') {
				$pattern = $p;
				// If not delimited, wrap with ~...~ (escape ~).
				$delim = substr($pattern, 0, 1);
				$last = substr($pattern, -1);
				$isDelimited = ($delim === $last) && in_array($delim, ['~', '/', '#', '!'], true) && strlen($pattern) >= 2;
				if (!$isDelimited) {
					$pattern = '~' . str_replace('~', '\~', $pattern) . '~';
				}
				$ok = @preg_match($pattern, $ua);
				if ($ok === 1) {
					return true;
				}
				continue;
			}
			// substring (default)
			if (strpos($ua, $p) !== false) {
				return true;
			}
		}
		return false;
	}

	public static function message(string $method, string $path, string $licenseId, string $domain, string $installId, int $ts, string $nonce): string
	{
		return strtoupper($method) . "\n" . $path . "\n" . $licenseId . "\n" . $domain . "\n" . $installId . "\n" . $ts . "\n" . $nonce;
	}
}

