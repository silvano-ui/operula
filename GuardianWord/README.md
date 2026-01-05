## GuardianWord

Questa cartella contiene:

- `guardian-ultimate/`: plugin WordPress “Guardian Ultimate” (modulare)
- `tools/license_gen.php`: generatore offline di licenze (token firmati Ed25519)
- `whmcs/`: addon module WHMCS per gestione licenze/token + API

### Flusso licenze (offline)

1) Genera le chiavi (una volta):

```bash
php tools/license_gen.php gen-keys
```

2) Copia `PUBLIC_KEY_B64` e incollala nel plugin in:

- `guardian-ultimate/includes/class-guardian-license.php` → `License::PUBLIC_KEY_B64`

3) Genera un token per un dominio:

```bash
php tools/license_gen.php gen-token --private-key-b64 "<PRIVATE_KEY_B64>" --domain "example.com" --license-id "LIC-001" --expires-days 365
```

4) In WordPress: **Bacheca > Guardian Ultimate > Licenza** → incolla il token.

### Flusso licenze con WHMCS (consigliato)

1) Installa il modulo WHMCS in `whmcs/` (vedi `whmcs/README.md`)
2) In WHMCS genera le chiavi e copia `PUBLIC_KEY_B64` nel plugin (`License::PUBLIC_KEY_B64`)
3) In WordPress: **Bacheca > Guardian Ultimate > Licenza**
   - seleziona **WHMCS (auto-recupero)**
   - imposta:
     - Validate URL: `.../modules/addons/guardian_licensing/api/validate.php`
     - Reset URL: `.../modules/addons/guardian_licensing/api/reset.php`
     - License ID (es. `GL-...`)
     - API Secret (se impostato nel modulo; usato per firmare le richieste HMAC con `ts/nonce/sig`)
4) Guardian farà refresh automatico (cache + job hourly) e userà sempre token firmati.

Nota: l’API WHMCS può essere ulteriormente “chiusa” con allowlist IP e allowlist User-Agent (default `Guardian/`).

### Moduli / Bundle / Addon

Guardian Ultimate abilita moduli in base a `feat.modules` nel token (WHMCS) e consente toggle locali (solo per moduli inclusi nel piano).

### Backup incrementale (MVP)

Nel modulo **Backup** è disponibile un “restore point” incrementale con dedup (file-level) e restore granulare + scheduler:
- creazione manuale da Admin
- creazione automatica pre-upgrade per plugin/tema (quando il modulo Backup è attivo)
- creazione automatica schedulata (hourly/daily/off) con scope configurabile
- restore di un path (file o directory) a partire da un restore point
- (opzionale) snapshot DB dentro il restore point (best-effort) + restore DB

### Backup Pro (a pagamento)

Se la licenza include `feat.backup_pro` (WHMCS: `guardian_backup_tier=pro`), puoi selezionare **DB engine = Pro**:
- snapshot DB a chunk con resume (più stabile)
- restore DB per step (schema + chunk per tabella)
- **Pro+**: export/restore DB come job in background con progress

### Architettura / modularità futura

`guardian-ultimate/` ora usa una struttura modulare:
- `modules/core`, `modules/integrity`, `modules/backup`, …
- i file in `includes/` sono stub di retro-compatibilità che caricano i file reali dai moduli.

Bootstrap:
- `guardian-ultimate.php` è minimale (costanti + autoloader + `Bootstrap::init()`).

