=== Guardian Ultimate ===
Contributors: guardian
Tags: security, integrity, backup, rollback, monitor
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Guardian Ultimate è un sistema modulare (bundle/addon) per integrità, backup/restore e manutenzione.

== Licenza ==

Guardian funziona **solo** con una licenza valida.

- Modalità supportate:
  - **Token offline (incolla)**: incolli il token firmato
  - **WHMCS (auto-recupero)**: inserisci endpoint + License ID e Guardian scarica/aggiorna il token in automatico
- Il token è firmato (Ed25519): il plugin verifica la firma con una chiave pubblica incorporata.
- Lo script di generazione (chiave privata) va tenuto **separato** e non deve mai essere caricato su WordPress.
- In modalità WHMCS, Guardian usa:
  - richieste firmate HMAC (`ts/nonce/sig`)
  - un `install_id` per legare la licenza alla singola installazione
  - User-Agent `Guardian/<version> (+WordPress)` (utile con allowlist lato WHMCS)

== Installazione ==

1) Copia la cartella `guardian-ultimate/` in `wp-content/plugins/guardian-ultimate/`
2) Attiva il plugin da WP Admin.
3) (Consigliato) abilita il caricamento anticipato come MU-plugin:
   - copia `guardian-ultimate/mu-plugin/guardian-loader.php` in `wp-content/mu-plugins/guardian-loader.php`
   - in alternativa, su alcuni hosting Guardian prova a copiarlo automaticamente all'attivazione (se permessi OK).

== Come funziona ==

- Moduli (abilitati dal piano + selezionabili in Bacheca > Guardian Ultimate > Moduli):
  - **Core**: licenza, crash guard, safe-mode, scheduler
  - **Integrità**: snapshot, change tracking, diff, report install/upgrade
  - **Backup**: backup/restore per plugin/tema + **restore point incrementali** (dedup) + restore granulare (best-effort)
  - **Security**: (roadmap) feed vulnerabilità + policy auto-update/rollback
  - **Health**: (roadmap) link/performance/conflitti con remediation

Nota: in questa versione i moduli Security/Health sono predisposti a livello licenza/UI, ma le funzionalità avanzate sono in roadmap.

== Limitazioni importanti ==

- Modifiche fatte via FTP/SFTP, deploy, o script esterni: Guardian può rilevarle via snapshot manuale, ma non può garantire backup/rollback se non c’è un ZIP precedente.
- Auto-rollback su crash/fatal:
  - è molto più affidabile se Guardian è caricato come MU-plugin (vedi sopra)
  - non può garantire il ripristino in tutti i casi (core update, permessi filesystem, hosting restrittivi).
- Ripristino completo:
  - è "best-effort": sovrascrive/ricrea i file presenti nel backup, ma non elimina automaticamente quelli aggiunti dopo
  - per sicurezza, per default NON sovrascrive `wp-config.php` (opzione attivabile ma rischiosa).
- Snapshot completi su siti grandi possono essere lenti; per default `wp-content/uploads` è escluso (attivabile da impostazioni).

