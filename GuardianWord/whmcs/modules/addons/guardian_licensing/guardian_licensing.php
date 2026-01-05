<?php
/**
 * WHMCS Addon Module: guardian_licensing
 */

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
	die("This file cannot be accessed directly");
}

require_once __DIR__ . '/lib/Signer.php';
require_once __DIR__ . '/lib/Repo.php';
require_once __DIR__ . '/lib/ServiceResolver.php';

function guardian_licensing_config()
{
	return [
		"name" => "Guardian Licensing",
		"description" => "Gestione licenze e token firmati per il plugin WordPress Guardian.",
		"version" => "0.1.0",
		"author" => "GuardianWord",
		"fields" => [
			"apiSecret" => [
				"FriendlyName" => "API Secret (opzionale)",
				"Type" => "text",
				"Size" => "50",
				"Default" => "",
				"Description" => "Se valorizzato, l'endpoint validate richiede anche api_secret.",
			],
			"trialDays" => [
				"FriendlyName" => "Trial days",
				"Type" => "text",
				"Size" => "5",
				"Default" => "14",
			],
		],
	];
}

function guardian_licensing_activate()
{
	try {
		Repo::ensureSchema();
		return [
			"status" => "success",
			"description" => "Schema creato/verificato.",
		];
	} catch (\Throwable $e) {
		return [
			"status" => "error",
			"description" => $e->getMessage(),
		];
	}
}

function guardian_licensing_deactivate()
{
	// Non drop della tabella automaticamente.
	return [
		"status" => "success",
		"description" => "Disattivato (nessun dato cancellato).",
	];
}

function guardian_licensing_output($vars)
{
	Repo::ensureSchema();

	$action = isset($_POST['gl_action']) ? (string) $_POST['gl_action'] : '';
	$csrfOk = true;
	if (function_exists('check_token')) {
		$csrfOk = (bool) check_token('WHMCS.admin.default');
	}

	if ($action === 'gen_keys' && $csrfOk) {
		$keypair = Signer::generateKeypair();
		Signer::saveKeysToAddonSettings($keypair['public_b64'], $keypair['private_b64']);
		echo '<div class="alert alert-success">Chiavi generate e salvate.</div>';
	}

	if ($action === 'rotate_secret' && $csrfOk) {
		$secret = bin2hex(random_bytes(32));
		Signer::saveApiSecretToAddonSettings($secret);
		echo '<div class="alert alert-success">API Secret rigenerato.</div>';
	}

	$keys = Signer::loadKeysFromAddonSettings();
	$apiSecret = Signer::loadApiSecretFromAddonSettings();

	echo '<h2>Guardian Licensing</h2>';

	echo '<h3>Chiavi Ed25519</h3>';
	if (!empty($keys['public_b64'])) {
		echo '<p><strong>PUBLIC_KEY_B64</strong></p>';
		echo '<textarea style="width:100%;max-width:1100px" rows="3" readonly>' . htmlspecialchars($keys['public_b64']) . '</textarea>';
	} else {
		echo '<p><em>Nessuna chiave configurata.</em></p>';
	}
	echo '<form method="post">';
	if (function_exists('generate_token')) {
		echo generate_token('WHMCS.admin.default');
	}
	echo '<input type="hidden" name="gl_action" value="gen_keys" />';
	echo '<button class="btn btn-primary" type="submit">Genera nuove chiavi</button>';
	echo '</form>';

	echo '<hr />';

	echo '<h3>API Secret (opzionale)</h3>';
	echo '<textarea style="width:100%;max-width:1100px" rows="2" readonly>' . htmlspecialchars($apiSecret ?? '') . '</textarea>';
	echo '<form method="post">';
	if (function_exists('generate_token')) {
		echo generate_token('WHMCS.admin.default');
	}
	echo '<input type="hidden" name="gl_action" value="rotate_secret" />';
	echo '<button class="btn btn-default" type="submit">Rigenera API Secret</button>';
	echo '</form>';

	echo '<hr />';

	echo '<h3>Endpoint</h3>';
	echo '<p><code>/modules/addons/guardian_licensing/api/validate.php</code></p>';
	echo '<p>Se imposti API Secret, richiede anche <code>api_secret</code>.</p>';
}

function guardian_licensing_clientarea($vars)
{
	Repo::ensureSchema();

	// Mostra licenze legate all'utente loggato (services).
	$clientId = (int) ($vars['clientid'] ?? 0);
	if ($clientId <= 0) {
		return [
			"pagetitle" => "Guardian Licensing",
			"templatefile" => "clientarea",
			"requirelogin" => true,
			"vars" => [
				"error" => "Login richiesto.",
			],
		];
	}

	$services = ServiceResolver::servicesForClient($clientId);
	$licenses = [];
	foreach ($services as $svc) {
		$licenses[] = Repo::getOrIssueForService($svc);
	}

	return [
		"pagetitle" => "Guardian Licensing",
		"breadcrumb" => ["index.php?m=guardian_licensing" => "Guardian Licensing"],
		"templatefile" => "clientarea",
		"requirelogin" => true,
		"vars" => [
			"licenses" => $licenses,
		],
	];
}

