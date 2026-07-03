# Multi-turno + Amministrazione self-service — Piano di progetto

> Obiettivo di Lele (2026-07-03): rendere il gestionale **autosufficiente**.
> 1) Poter inserire gli altri tre turni (A, C, D), non solo B.
> 2) Sull'account superuser (ora `lelemele`, ruolo `comando`) far comparire in
>    Amministrazione la gestione degli **altri admin** con **priorità/permessi**
>    su cosa possono e non possono fare.
> 3) Arrivare a **non aver più bisogno di codice** se non per grosse modifiche
>    strutturali: nuovi turni / nuovi admin / nuovi parametri = roba da pannello.

Questo documento è la traccia condivisa tra sessioni. Aggiornare la sezione
**Stato / Changelog** in fondo a ogni avanzamento.

---

## 1. Stato attuale (fotografia 2026-07-03)

- **Calendario turni già multi-turno.** `includes/turni.php` (`calcolaDiurno`)
  calcola turno+salto per ogni giorno su tutti e 4 i turni (A/B/C/D), ancora
  `TURNI_ANCHOR = 2026-05-01` = B4 diurno / A4 notturno. La rotazione è verificata
  **solo su B** (docstring): il turno diurno ruota A→B→C→D ogni giorno, il notturno
  è il diurno del giorno prima, il salto (1–8) avanza +1 ogni 4 giorni → ciclo 32 gg.
- **Ma i dati sono mono-turno.** Nessuna colonna `turno` (A/B/C/D) da nessuna parte.
  Il turno è di fatto codificato nel **nome del database**: `vvf_turno_b`
  (`config/db.php`, `DB_NAME`). Tutti i vigili, i fogli, i salti (`salti_turno`
  codici `B1..B8`) sono implicitamente turno B.
- **L'auth invece è già predisposta al multi-turno**: `utenti(id, username,
  password, nome, ruolo ENUM('comando','admin','user'), turno CHAR(1) NULL,
  attivo)`, con `puoVedereTurno()` / `puoModificareTurno()` in `includes/auth.php`.
  L'autenticazione è avanti; il modello dati no.
- **Il bot** (`vvf-ferie-bot`) è anch'esso mono-turno: usa lo stesso DB
  `vvf_turno_b`, i vigili sono B, e la **fureria/mail** (`FURERIA_EMAIL`) è unica.

### Punti dove "B" è cablato (da parametrizzare in Fase 1)
- `foglio/nuovo.php:60` `$codSaltoRip = 'B' . $turnoRiposo['salto']`
- `foglio/nuovo.php:133` `'B' . $prevNum`
- `includes/FoglioRenderer.php:62` `$this->codSaltoRip = 'B' . $rip['salto']`
- `includes/turni.php`: filtri `=== 'B'` in `servizioAdiacenteB`, `prossimoSlotTurnoB`,
  `getSaltoLabel`, `isSaltoRiposo` (la rotazione no, solo il "qual è il nostro turno").
- `ferie/index.php:579-581`, `admin/ferie_simulate.php:31-32`.

---

## 2. Decisione architetturale

**Opzione scelta: B — database UNICO, il turno diventa una COLONNA.**

Alternativa scartata (A): un DB per turno (`vvf_turno_a/b/c/d`) + un DB condiviso
per gli account. Riusa quasi tutto il codice ma richiede 4 DB e bot replicato/
turno-aware → aggiungere un turno resta lavoro di infrastruttura.

**Perché B.** L'obiettivo è "non aver più bisogno di codice". Con B, aggiungere un
turno = **inserire i suoi vigili dall'admin** (dati), non provisioning. Con A serve
sempre un intervento tecnico. B centra l'obiettivo; A no.

**Rischio della B** ("una query che dimentica il filtro turno mischia i turni")
mitigato dal fatto che quasi tutti i dati pendono da `foglio_id` (foglio già scelto
per turno) o da `vigile_id`: il filtro si concentra su **due tabelle-àncora**,
`vigili` e `fogli_servizio`. Vedi §10 Rischi.

**Momento giusto = adesso**: beta con ~4/117 vigili → migrazione dati indolore.

---

## 3. Modello dati target

Convenzione: `turno` = A/B/C/D (il gruppo). Da non confondere con
`fogli_servizio.tipo_turno` = D/N (diurno/notturno).

| Tabella | Cambiamento | Note |
|---|---|---|
| `vigili` | `+ turno CHAR(1) NOT NULL DEFAULT 'B'` | ogni vigile appartiene a un turno |
| `fogli_servizio` | `+ turno CHAR(1) NOT NULL DEFAULT 'B'` | un foglio è (turno, data, tipo_turno). Chiave logica passa da (data, tipo_turno) a (turno, data, tipo_turno) — vedi Fase 1 |
| `salti_turno` | `+ turno CHAR(1)` (riempito da `codice`) e righe `A1..A8, C1..C8, D1..D8` | 8 salti per turno = 32 righe totali. **Le righe A/C/D si aggiungono in Fase 3**, non in Fase 0 |
| `assenze`, `assegnazioni`, `salto_servizio`, `fogli_lock`, `bot_outbox` | nessuna colonna | ereditano il turno via `foglio_id` |
| `bot_requests` | nessuna colonna | il turno deriva dal `vigile_id` (`vigili.turno`); l'agenda filtra via JOIN |
| `utenti` | invariata in Fase 0 | matrice permessi in Fase 2 (§6) |
| `funzionari` | da decidere: globale o per-turno | probabilmente per-turno (`+ turno`) |
| `salto_override` (scambi salto) | via foglio/vigile | il turno deriva dai vigili coinvolti |

**Fase 0 tocca solo colonne additive** (righe 1–3 colonna "turno", niente righe
A/C/D salti), tutto `= 'B'` → zero comportamento diverso.

---

## 4. Calendario turni (parametrizzazione)

La rotazione è già multi-turno. Serve:
- Sostituire i filtri `=== 'B'` con il **turno attivo** `$TURNO` (parametro).
- `$codSaltoRip = $TURNO . $num` invece di `'B' . $num`.
- Le funzioni `servizioAdiacenteB`/`prossimoSlotTurnoB`/`getSaltoLabel`/
  `isSaltoRiposo` prendono il turno come argomento (o si aggiunge una variante
  generica `...PerTurno($t, ...)`).

> ⚠️ **Gate di validazione (Fase 1, con Lele):** la rotazione è verificata solo su
> B. Prima di popolare A/C/D confermare con Lele (che conosce il calendario reale)
> che diurno/notturno/salto di A, C, D coincidano con la realtà su qualche data di
> prova. Se un turno ha un'ancora diversa, il modello va esteso.

---

## 5. Contesto turno (selettore / lock)

Ogni pagina operativa lavora su **un turno attivo** `$TURNO_ATTIVO`, ricavato così:
- `comando` → può scegliere il turno (selettore in navbar, memorizzato in
  sessione o passato in URL `?turno=A`); default = un turno a scelta.
- `admin` / `user` → agganciato al/ai turno/i concessi dalla matrice permessi (§6);
  niente selettore verso turni non concessi.

Ogni query su `vigili` e `fogli_servizio` filtra per `$TURNO_ATTIVO`. Le query
figlie (via `foglio_id`) sono già scoperte.

---

## 6. Permessi / Amministrazione admin (#90, richiesta centrale)

Modello di Moli (#90): per ogni utente, scegliere **turno per turno** cosa può fare
in **lettura** e in **scrittura** (caselle multiple A/B/C/D).

**Target:**
- `comando` resta super-ruolo speciale: tutti i turni in R/W + gestione utenti.
  Non è rappresentato nella matrice (o = all-write implicito).
- Tutti gli altri: righe in una nuova tabella
  `utenti_turni(utente_id, turno CHAR(1), livello ENUM('lettura','scrittura'),
  PRIMARY KEY(utente_id, turno))`.
  Es.: un "admin di B" = riga (B, scrittura); un "user di B" = riga (B, lettura);
  un utente che scrive su B e legge A/C = tre righe.
- `includes/auth.php`: `puoVedereTurno($t)` / `puoModificareTurno($t)` leggono dalla
  matrice invece che da `utenti.turno` singolo. `utenti.ruolo` resta per distinguere
  `comando` (super) dal resto; `admin`/`user` diventano quasi etichette (il vero
  potere è nella matrice).
- `admin/utenti.php`: UI con, per ogni utente, una griglia
  `A B C D` × (lettura / scrittura) a caselle. Creazione/modifica admin, reset
  password (già presente).

> Da rifinire con Lele in Fase 2: se serve un livello "nessun accesso" esplicito,
> se `user`/`admin` come etichette vanno mantenute, e la UI esatta della griglia.

---

## 7. Parametri configurabili (#92)

Nuova tabella `parametri(chiave VARCHAR PK, valore TEXT, scope
ENUM('globale','turno'), turno CHAR(1) NULL, segreto TINYINT DEFAULT 0)` + pagina
`admin/parametri.php` (esiste già uno scheletro).

Candidati (elenco di Moli, #92):
- **Mail bot**: indirizzo mittente e destinatario fureria, per turno.
- **Parametri di posta**: IMAP/SMTP host, porte, password (⚠️ **segreti → cifrati**
  con lo stesso `crypto` del bot; mai in chiaro, mai web-esposti).
- **Criteri formattazione** nomi nel gestionale e nell'ODT.
- **Criterio di riempimento squadre** (compositore di default): in parte
  algoritmico (`prepopolaFoglio`) → si parametrizzano le *manopole* (fasce, ordine
  sedi, specialisti), il cuore algoritmico resta codice. Sotto-progetto a sé.

---

## 8. Bot multi-turno (pezzo più grosso, NON blocca)

Oggi il bot è solo B (fureria e mail incluse). Renderlo multi-turno:
- Ogni vigile ha `turno` → instradare ferie/scambi al **giusto turno**.
- **Fureria per turno**: ogni turno ha la sua fureria/casella; la mail di richiesta
  va alla fureria del turno del richiedente. Oggi `FURERIA_EMAIL` è unica.
- Calendario salti già per-turno lato calcolo.

**Non blocca il gestionale:** A/C/D partono **gestiti a mano** dal gestionale
(fogli, ferie d'ufficio via agenda, ODT). Il bot li aggancia dopo. → Bot = Fase 5.

---

## 9. Password self-service (#98)

In gran parte già presente: `cambia_password.php` (ognuno la propria) + reset da
`admin/utenti.php` (comando). Da verificare/chiudere i buchi in Fase 6
(es. adminX/userX cambiano la propria in autonomia, lelemele cambia tutte — già c'è).

---

## 10. Piano a fasi

Ogni fase è un traguardo usabile. Dipendenze in ordine.

- **Fase 0 — Modello dati** *(invisibile)*: colonne `turno` su `vigili`,
  `fogli_servizio`, `salti_turno`, tutto `= 'B'`. Nessun cambiamento di
  comportamento. **NON** aggiunge ancora righe salti/vigili A/C/D.
- **Fase 1 — App turno-aware**: parametrizzare `turni.php` + `$codSaltoRip` + i
  filtri `=== 'B'`; introdurre `$TURNO_ATTIVO` (selettore comando / lock admin);
  filtrare `vigili` e `fogli_servizio` per turno; chiave foglio → (turno,data,tipo).
  **Gate di validazione rotazione A/C/D con Lele.** Fine: comando apre il turno A
  (vuoto) e vede il suo gestionale.
- **Fase 2 — Permessi self-service (#90)**: tabella `utenti_turni` + UI in
  `admin/utenti.php` + `auth.php` legge la matrice. Fine: comando crea admin per
  turno con R/W. *(La richiesta centrale di Lele.)*
- **Fase 3 — Inserimento turni (#: "i 3 turni")**: righe salti A/C/D, anagrafica
  `vigili` con campo turno, assegnazione salti. Fine: A/C/D operativi a mano.
- **Fase 4 — Parametri (#92)**: `parametri` + `admin/parametri.php`, togliere
  l'hardcoding (mail, IMAP cifrati, formattazione, manopole compositore).
- **Fase 5 — Bot multi-turno**: instradamento + fureria per turno.
- **Fase 6 — Password self-service (#98)**: verifica/chiusura.

Mappatura richieste di Lele → fasi: "inserire i 3 turni" = 0+1+3; "comando gestisce
gli admin con priorità" = 2; "niente più codice" = tutto + 4.

---

## 11. Rischi e mitigazioni

- **Query senza filtro turno → contaminazione tra turni** (correttezza + sicurezza).
  Mitigazione: il turno si concentra su `vigili` e `fogli_servizio` (i figli seguono
  via `foglio_id`); audit sistematico in Fase 1; helper centralizzato per la
  selezione foglio/organico; test cross-turno (un admin di A non deve vedere B in
  scrittura).
- **Rotazione A/C/D non verificata**: gate di validazione con Lele prima di popolare
  (Fase 1). Nessun turno reale finché non confermato.
- **Segreti in `parametri`** (IMAP/SMTP): cifrare (`crypto`), mai web-esposti, mai
  in log. La root del repo è web-esposta → attenzione (vedi §12).
- **Bot mono-fureria**: il gestionale va multi-turno prima; il bot resta B finché la
  Fase 5 non è pronta. A/C/D a mano nel frattempo.
- **TiDB**: niente AUTO_INCREMENT implicito → ogni INSERT nuovo usa `nextId()`
  (`config/db.php`). DDL `ADD COLUMN` è online e sicura.

---

## 12. Note operative

- **Deploy gestionale**: `fly deploy --build-only --push -a vvf-gestionale` →
  `fly machine update 1850375f715dd8 --app vvf-gestionale --image <tag> --yes`
  (sandbox off per il builder). Macchina unica `1850375f715dd8`.
- **DB**: TiDB Cloud `vvf_turno_b` (resta il nome anche da DB unico; eventuale
  rename è cosmetico e non necessario). Query read-only di verifica dal repo bot:
  `PYTHONPATH=. ./venv/bin/python` (usa `database._connect()`).
- **Web-esposizione**: la root del repo è document root. Mai lasciarci segreti/dump.
  Questo `docs/` va escluso dall'immagine (`.dockerignore` → `/docs`).
- **Login**: superuser = `lelemele` (ruolo `comando`), vedi memoria
  `project_vvf_login`.

---

## 13. Stato / Changelog

- **2026-07-03** — Documento creato. Decisione: Opzione B (DB unico + colonna
  turno). Pre-flight Fase 0 fatto (nessun INSERT posizionale su vigili/fogli lato
  gestionale **e bot**, nessun conflitto nome `turno`, salti_turno letto via JOIN su
  vigili → turno-scoped da sé, salti codici `B1..B8`).
- **2026-07-03 — ✅ FASE 0 APPLICATA e verificata** (DDL su TiDB `vvf_turno_b`):
  - `ALTER TABLE vigili ADD COLUMN turno CHAR(1) NOT NULL DEFAULT 'B'` → 118 righe = B
  - `ALTER TABLE fogli_servizio ADD COLUMN turno CHAR(1) NOT NULL DEFAULT 'B'` → 78 = B
  - `ALTER TABLE salti_turno ADD COLUMN turno CHAR(1)` + `UPDATE ... turno=LEFT(codice,1)`
    → B1..B8 = B
  - Smoke test: gestionale 302 su tutte le pagine, bot `started`+polling. Nessun
    cambiamento di comportamento (le colonne non le legge ancora nessuno).
  - **Prossimo: Fase 1** (app turno-aware + selettore/lock + gate validazione
    rotazione A/C/D con Lele).
- **2026-07-03 — ✅ FASE 1 APPLICATA e deployata** (app turno-aware):
  - `auth.php`: `turnoAttivo()` (da `?turno=`/sessione/default), `puoModificareTurnoAttivo()`,
    `soloLetturaAttivo()`, `turniVisibili()`, `vietaSeSolaLetturaTurno()`, `selettoreTurnoHtml()`
    (tendina navbar, mostra i turni visibili, 👁 = sola lettura).
  - `turni.php`: `servizioAdiacenteB`/`prossimoSlotTurnoB`/`getSaltoLabel`/`isSaltoRiposo`
    ora prendono `$turno='B'` (default retrocompat).
  - `foglio/nuovo.php`: `$TURNO=turnoAttivo()`; `$codSaltoRip=$TURNO.$num`; identità foglio
    → (turno,data,tipo) in TUTTI i lookup/insert (principale, appaiato DN, copia-diurno);
    filtro `v.turno` sulle query che enumerano tutti i vigili (pool compositore 153, prepopola
    268, organico 1245, vigiliPerSlot 1473, capi_pool, furieri_fissi); `$foglioPrec` per turno;
    frecce #88 per turno; sola-lettura → `soloLetturaAttivo()` (guard POST + flag UI).
  - `FoglioRenderer.php`: `codSaltoRip` usa `$f['turno']`.
  - `ferie/index.php`: `$TURNO`; agenda + scambi filtrati per `v.turno`/`a.turno`.
  - `vigili/lista.php`: lista anagrafica filtrata per `v.turno`.
  - Selettore turno inserito nelle navbar di nuovo/ferie/vigili/index.
  - `.dockerignore /docs` → doc non più web-esposta (verificato 404).
  - **Auto-scoping**: le query per `salto_id` sono già turno-scoped (un salto = un turno);
    filtro esplicito solo dove si enumerano tutti i vigili.
  - Comportamento su B invariato (tutti gli utenti attuali → turnoAttivo()='B', dati tutti B).
  - **DA VERIFICARE (Lele, login)**: B identico; tendina turni per lelemele/adminb; adminb su
    A/C/D = sola lettura; userb nessuna tendina.
- **2026-07-03 — fix + cruscotto + GATE**:
  - **FIX vincolo**: `fogli_servizio.uq_foglio` era UNIQUE su (data_servizio, tipo_turno)
    → cambiare turno su una data con foglio B dava `Duplicate entry` all'INSERT. Ricreato su
    **(turno, data_servizio, tipo_turno)** (DDL su TiDB; nessun duplicato preesistente).
  - **Cruscotto turno-aware** (`index.php`): il calendario evidenzia/rende cliccabile il turno
    ATTIVO (non più B fisso); header/badge/legenda/titolo dinamici; stat (vigili, fogli) filtrate
    per turno; link pill con `&turno=`.
  - **✅ GATE ROTAZIONE PASSATO (confermato da Lele):** diurni ruotano A→B→C→D (il giorno
    prima di B1 = A1, dopo = C1 poi D1), salto +1 dopo il giro, notte = diurno del giorno prima,
    stessi orari per tutti. = ESATTAMENTE `turni.php` (ancora 2026-05-01=B4). **Via libera a
    popolare A/C/D.**
- **2026-07-03 — ✅ FASE 1b: Amministrazione turno-aware** (richiesta Lele: "l'admin non
  segue il cambio turno; i 4 turni devono essere indipendenti, quello fatto per B rifattibile
  per gli altri"):
  - **Modello confermato:** le tabelle-regole di composizione (`capi_pool`, `furieri_fissi`,
    `assegnazioni_fisse`) NON hanno colonna turno — puntano a `vigile_id` → il turno è **derivato
    dal vigile** (auto-indipendenti). Globali/condivise: sedi, posizioni, qualifiche, patenti,
    abilitazioni, funzionari.
  - **Selettore turno aggiunto in TUTTE le navbar admin** (index, ruoli, assegnazioni_fisse,
    anagrafiche, ferie_simulate, parametri, utenti).
  - **Filtri per turno** (via JOIN `vigili.turno`): `admin/index.php` conteggi capi/furieri/
    assegnazioni; `admin/ruoli.php` pool capi/furieri + dropdown candidati; `admin/assegnazioni_fisse.php`
    dropdown vigili + lista; `admin/ferie_simulate.php` (PHP closure + query vigili + JS `TURNO_ATTIVO`).
  - Global pages (anagrafiche/utenti/parametri) hanno solo il selettore (dati condivisi).
  - **Deploy builder Fly lento/timeout ×2**, poi in background — verificare che sia andato.
  - NON ancora fatto: badge header "TURNO B" cablato in alcune admin (cosmetico); validazione
    che un POST non aggiunga un vigile di turno diverso al pool (dropdown già filtrato).
- **2026-07-03 — ✅ FASE 2: Permessi self-service (#90)** live:
  - **Tabella `utenti_turni(utente_id, turno, livello ENUM('lettura','scrittura'), PK(utente_id,turno))`.**
    Migrati: adminb → B=scrittura + A/C/D=lettura; userb → B=lettura; comando (lelemele) → nessuna
    riga (super, tutto R/W).
  - **`auth.php`**: `grantsTurni()` legge la matrice (comando short-circuit = tutto scrittura);
    `puoVedereTurno` = esiste riga; `puoModificareTurno` = livello scrittura. `turnoAttivo()` con
    fallback robusto (turno di casa se visibile, altrimenti primo visibile).
  - **`admin/utenti.php`**: la vecchia select "Turno" sostituita da **griglia A/B/C/D → —/👁 Lettura/
    ✏️ Scrittura**; salva → riscrive `utenti_turni` in transazione + `utenti.turno` = 1° turno di
    scrittura (turno "di casa", default al login). Tabella mostra i permessi. Ruolo comando/admin/user:
    comando=super, admin=può entrare in Amministrazione, user=no. Il `ruolo` NON è più la fonte
    dei permessi di turno (lo è la griglia).
  - **Enforcement**: write-guard turno-aware su tutte le pagine che scrivono per-turno —
    foglio (già Fase 1), ferie/index (JSON), vigili/lista, admin/ruoli, admin/assegnazioni_fisse,
    admin/ferie_simulate → `soloLetturaAttivo()`/`vietaSeSolaLetturaTurno()`.
  - **DA TESTARE (Lele)**: comando → Utenti → crea un admin con permessi misti (es. scrittura A,
    lettura B); login come lui → vede solo A/B, modifica solo A. adminb/userb come prima.
  - **Prossimo: Fase 3 (dati A/C/D — anagrafica col turno + salti A1..D8) / Fase 4 (parametri).**
    Pagine NON ancora turno-aware (solo B oggi, da fare in Fase 3): admin/ferie_simulate.php,
    admin/anagrafiche.php, scarica_odt.php, e l'INSERT nuovo vigile (deve nascere col turno).
