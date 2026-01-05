<?php

use WHMCS\Database\Capsule;

final class Signer
{
	private const ADDON = 'guardian_licensing';
	private const KEY_PUBLIC = 'ed25519PublicB64';
	private const KEY_PRIVATE = 'ed25519PrivateB64Enc';
	private const KEY_API_SECRET = 'apiSecret';

	public static function generateKeypair(): array
	{
		if (!function_exists('sodium_crypto_sign_keypair')) {
			throw new RuntimeException("ext-sodium required");
		}
		$kp = sodium_crypto_sign_keypair();
		$sk = sodium_crypto_sign_secretkey($kp);
		$pk = sodium_crypto_sign_publickey($kp);
		return [
			'public_b64' => base64_encode($pk),
			'private_b64' => base64_encode($sk),
		];
	}

	public static function signToken(array $payload, string $privateKeyB64): string
	{
		if (!function_exists('sodium_crypto_sign_detached')) {
			throw new RuntimeException("ext-sodium required");
		}
		$sk = base64_decode($privateKeyB64, true);
		if (!is_string($sk)) {
			throw new RuntimeException("Invalid private key");
		}
		$json = json_encode($payload, JSON_UNESCAPED_SLASHES);
		if (!is_string($json)) {
			throw new RuntimeException("JSON encode failed");
		}
		$sig = sodium_crypto_sign_detached($json, $sk);
		return self::b64url($json) . '.' . self::b64url($sig);
	}

	public static function b64url(string $bin): string
	{
		return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
	}

	public static function loadKeysFromAddonSettings(): array
	{
		$rows = Capsule::table('tbladdonmodules')->where('module', self::ADDON)->get();
		$map = [];
		foreach ($rows as $r) {
			$map[$r->setting] = $r->value;
		}
		$pub = isset($map[self::KEY_PUBLIC]) ? (string) $map[self::KEY_PUBLIC] : '';
		$privEnc = isset($map[self::KEY_PRIVATE]) ? (string) $map[self::KEY_PRIVATE] : '';
		$priv = $privEnc !== '' ? decrypt($privEnc) : '';
		return [
			'public_b64' => $pub,
			'private_b64' => $priv,
		];
	}

	public static function saveKeysToAddonSettings(string $publicB64, string $privateB64): void
	{
		self::saveSetting(self::KEY_PUBLIC, $publicB64);
		self::saveSetting(self::KEY_PRIVATE, encrypt($privateB64));
	}

	public static function loadApiSecretFromAddonSettings(): string
	{
		$val = Capsule::table('tbladdonmodules')
			->where('module', self::ADDON)
			->where('setting', self::KEY_API_SECRET)
			->value('value');
		return is_string($val) ? $val : '';
	}

	public static function saveApiSecretToAddonSettings(string $secret): void
	{
		self::saveSetting(self::KEY_API_SECRET, $secret);
	}

	private static function saveSetting(string $setting, string $value): void
	{
		$exists = Capsule::table('tbladdonmodules')
			->where('module', self::ADDON)
			->where('setting', $setting)
			->exists();
		if ($exists) {
			Capsule::table('tbladdonmodules')
				->where('module', self::ADDON)
				->where('setting', $setting)
				->update(['value' => $value]);
		} else {
			Capsule::table('tbladdonmodules')->insert([
				'module' => self::ADDON,
				'setting' => $setting,
				'value' => $value,
			]);
		}
	}
}

