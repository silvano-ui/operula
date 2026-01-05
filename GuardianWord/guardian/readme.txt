=== Guardian ===
Contributors: guardian
Tags: security, integrity, backup, rollback, monitor
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Guardian crea snapshot (hash) dell'installazione, rileva quali file sono cambiati dopo install/upgrade di plugin/temi, mostra un diff (quando possibile) e permette rollback best-effort.

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

1) Copia la cartella `guardian/` in `wp-content/plugins/guardian/`
2) Attiva il plugin da WP Admin.
3) (Consigliato) abilita il caricamento anticipato come MU-plugin:
   - copia `guardian/mu-plugin/guardian-loader.php` in `wp-content/mu-plugins/guardian-loader.php`
   - in alternativa, su alcuni hosting Guardian prova a copiarlo automaticamente all'attivazione (se permessi OK).

== Come funziona ==

- Durante installazioni/aggiornamenti via WP Admin (Upgrader) Guardian:
  - crea uno snapshot pre e post (hash sha256 + size + mtime) dell'installazione
  - crea un backup ZIP della directory del plugin/tema coinvolto (se esiste già)
  - opzionalmente crea un backup ZIP completo dell’installazione (molto pesante)
  - salva un report con lista file aggiunti/rimossi/modificati
- Dopo l’install/upgrade, per ~10 minuti l’operazione rimane in stato "armed": se avviene un fatal error, Guardian può provare auto-rollback (molto più affidabile con MU-loader).
- In Bacheca > Guardian:
  - vedi l’ultima operazione e le differenze
  - apri un diff per file (solo se il file era nel backup ZIP e sembra testuale)
  - fai rollback dell’ultima operazione (best-effort)

== Limitazioni importanti ==

- Modifiche fatte via FTP/SFTP, deploy, o script esterni: Guardian può rilevarle via snapshot manuale, ma non può garantire backup/rollback se non c’è un ZIP precedente.
- Auto-rollback su crash/fatal:
  - è molto più affidabile se Guardian è caricato come MU-plugin (vedi sopra)
  - non può garantire il ripristino in tutti i casi (core update, permessi filesystem, hosting restrittivi).
- Ripristino completo:
  - è "best-effort": sovrascrive/ricrea i file presenti nel backup, ma non elimina automaticamente quelli aggiunti dopo
  - per sicurezza, per default NON sovrascrive `wp-config.php` (opzione attivabile ma rischiosa).
- Snapshot completi su siti grandi possono essere lenti; per default `wp-content/uploads` è escluso (attivabile da impostazioni).

