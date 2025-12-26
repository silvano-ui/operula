
# OPERULA – OPERATIONAL LOG
SCRIPTA MANENT. SEMPER.

Questo file contiene TUTTE le decisioni operative rilevanti
prese durante lo sviluppo di Operula.

Se una decisione NON è qui, è come se NON fosse mai stata presa.

---

## [2025-12-26  – DAY 0]
TIPO: Inizializzazione progetto
AREA: Governance / Setup
AZIONE:
- Pulizia completa DNS operula.com
- Disattivazione Cloudflare proxy (DNS only)
- Rimozione wildcard DNS
- Reset completo repository GitHub
- Creazione nuova repo `operula`
- Caricamento documento ufficiale Operula v1.0

MOTIVAZIONE:
Ripartire da base pulita, coerente con Operula v1.0,
senza legacy, interferenze o ambiguità.

CONSEGUENZA ATTESA:
Ambiente disciplinato, controllabile, pronto allo sviluppo.

NOTE:
Server lasciato volutamente senza web server.
Sviluppo previsto SOLO in locale (Laragon).
