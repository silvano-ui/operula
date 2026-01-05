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
     - `guardian_modules`: lista moduli (es: `integrity,backup,security,health`) oppure `backup+security`
   - in alternativa, il modulo usa il billing cycle e la `nextduedate` del servizio.
   - in alternativa/aggiunta, puoi usare **Product Addons** con nomi contenenti:
     - `backup` → abilita modulo backup
     - `security`/`vuln` → abilita modulo security
     - `health`/`performance`/`monitor` → abilita modulo health

### Endpoint di verifica (per integrazione plugin)

Il modulo espone un endpoint JSON:

- `/modules/addons/guardian_licensing/api/validate.php`
 - `/modules/addons/guardian_licensing/api/reset.php` (reset dominio)

Input (POST):
- `license_id`
- `domain`
 - **signed request** (consigliato/di default se `apiSecret` impostato):
   - `ts` (unix timestamp)
   - `nonce` (random, base64url)
   - `sig` (HMAC-SHA256 base64url)
 - legacy: `api_secret` (solo se non enforced)

Output:
- `status`: `active|expired|suspended|terminated|invalid`
- `token`: token firmato (solo se attivo/valido)
- `exp`: unix timestamp

### Firma richieste (HMAC)

Se `apiSecret` è configurato nell’addon e `Enforce signed requests` è ON (default), l’API richiede firma.

Stringa firmata:

- validate:
  - `POST\n{path}\n{license_id}\n{domain}\n{install_id}\n{ts}\n{nonce}`
- reset:
  - `POST\n{path}\n{license_id}\n{reset_kind}\n\n{ts}\n{nonce}`

`sig = base64url(HMAC_SHA256(apiSecret, message))`

Protezione aggiuntiva:
- **anti-replay**: i nonce vengono memorizzati per una finestra (skew+60s)
- **rate limit**: per `license_id + IP` (configurabile)
- **IP allowlist**: se configurata, blocca tutto il resto
- **install binding**: se abilitato, l’API richiede `install_id` e lo lega alla prima installazione (reset necessario per cambiare)
- **User-Agent allowlist**: se configurata, blocca tutto il resto (default `Guardian/`)

User-Agent allowlist mode:
- `substring` (default): match se UA contiene uno dei valori
- `exact`: match solo se UA == valore
- `regex`: match con pattern (se non delimitato viene wrappato con `~...~`)

### Requisito consigliato

- Usa **HTTPS** per gli endpoint WHMCS.

### Domain policy

Config (Addon module):
- `lock_first`: lega la licenza al primo dominio che valida (default)
- `allow_change`: aggiorna automaticamente il dominio al nuovo valore
- `reset_required`: se cambia dominio, l’API risponde `domain_reset_required` finché non fai reset (endpoint reset o azione admin)

### Piani (trial/annuale/biennale/triennale)

Il token include `feat.plan`:
- `trial` se `guardian_license_type=trial`
- altrimenti mappa il billing cycle WHMCS:
  - `Annually` → `annual`
  - `Biennially` → `biennial`
  - `Triennially` → `triennial`

Il token include anche:
- `feat.modules`: array moduli abilitati (es. `["core","integrity","backup"]`)

