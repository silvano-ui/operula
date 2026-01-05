## GuardianWord

Questa cartella contiene:

- `guardian/`: plugin WordPress “Guardian”
- `tools/license_gen.php`: generatore offline di licenze (token firmati Ed25519)

### Flusso licenze (offline)

1) Genera le chiavi (una volta):

```bash
php tools/license_gen.php gen-keys
```

2) Copia `PUBLIC_KEY_B64` e incollala nel plugin in:

- `guardian/includes/class-guardian-license.php` → `License::PUBLIC_KEY_B64`

3) Genera un token per un dominio:

```bash
php tools/license_gen.php gen-token --private-key-b64 "<PRIVATE_KEY_B64>" --domain "example.com" --license-id "LIC-001" --expires-days 365
```

4) In WordPress: **Bacheca > Guardian > Licenza** → incolla il token.

### Nota WHMCS

Se vuoi integrare WHMCS Licensing Addon, dimmelo e ti preparo un “mode” alternativo di verifica remota (endpoint WHMCS + caching), mantenendo comunque un fallback offline.

