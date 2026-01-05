## WHMCS module: Guardian Licensing

Questa cartella contiene un **addon module WHMCS** che gestisce:

- generazione e gestione **chiavi Ed25519** (pubblica/privata)
- emissione **token licenza** (firma Ed25519) compatibili con il plugin WordPress Guardian
- recupero licenza lato cliente (client area)
- rinnovo/upgrade/cambio piano (rigenera token e scadenza)
- tipi licenza (trial/annuale/biennale ecc.) basati su billing cycle e/o configurable options

### Installazione

1) Copia il contenuto di `GuardianWord/whmcs/` nella root della tua installazione WHMCS:

- `modules/addons/guardian_licensing/...`
- `includes/hooks/guardian_licensing.php`

2) In WHMCS Admin: **Configuration → System Settings → Addon Modules**
   - attiva **Guardian Licensing**
   - configura le opzioni (trial days, ecc.)
   - genera le chiavi (pubblica/privata)

3) Configura i tuoi prodotti WHMCS:
   - usa **configurable options** (opzionale) per definire tipo licenza, es:
     - `guardian_license_type`: `trial`, `annual`, `biennial`
   - in alternativa, il modulo usa il billing cycle e la `nextduedate` del servizio.

### Endpoint di verifica (per integrazione plugin)

Il modulo espone un endpoint JSON:

- `/modules/addons/guardian_licensing/api/validate.php`
 - `/modules/addons/guardian_licensing/api/reset.php` (reset dominio)

Input (POST):
- `license_id`
- `domain`
 - `api_secret` (se configurato; consigliato)

Output:
- `status`: `active|expired|suspended|terminated|invalid`
- `token`: token firmato (solo se attivo/valido)
- `exp`: unix timestamp

> Nota: per produzione è consigliato aggiungere autenticazione (es. secret per installazione o firma HMAC). Nel codice è predisposta l’opzione “API Secret”.

### Domain policy

Config (Addon module):
- `lock_first`: lega la licenza al primo dominio che valida (default)
- `allow_change`: aggiorna automaticamente il dominio al nuovo valore
- `reset_required`: se cambia dominio, l’API risponde `domain_reset_required` finché non fai reset (endpoint reset o azione admin)

