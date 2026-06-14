# Foglio di Servizio generato dal DB — design

> Stato: **progettato, NON implementato.** Sposta la generazione del foglio/ODT dal
> bot al gestionale, basandola interamente sul DB. Il bot resta solo canale di
> richieste/approvazioni + mail.
> Data design: 2026-06-14. Decisioni chiave prese (vedi "Decisioni prese"):
> approvazione ferie alla generazione ODT (mail+telegram), anteprima 100% = ODT,
> righe fisse per mezzo come da modulo.

## Obiettivo

1. **Tutto basato su DB.** Il foglio (anteprima + ODT) nasce dalle assegnazioni reali
   in `vvf_turno_b`, non da template statici con nomi precompilati.
2. **Generazione nel gestionale (PHP)**, non nel bot.
3. **Bot ridotto a canale**: raccoglie richieste (ferie, scambio salto), le fa
   approvare e manda le mail. Non genera né legge il foglio; scrive solo le *richieste*.
4. **Anteprima identica all'ODT**: un solo motore, due output (HTML + ODT).

## Situazione attuale (da smontare)

```
gestionale/scarica_odt.php
   └─ curl  http://[ipv6-fly]:8080/odt?data=...   (DNS interno 6PN)
        └─ bot/odt_service.genera_foglio()
             └─ carica data/templates/B{n}.odt  (NOMI STATICI precompilati)
                rimuove i ferianti dalle celle mezzo, li sposta in FERIE
                colora i nomi per patente, forza due pagine
             └─ ritorna bytes .odt
```

Problemi:
- L'ODT **non riflette** le assegnazioni del gestionale (drag&drop): mostra il crew
  statico del template, non chi è davvero in servizio nel DB.
- La logica vive nel bot (Python), accoppiata ai template.
- Il gestionale dipende dal bot via DNS interno Fly per una cosa che è sua.

## Architettura target

### Principio: un motore, due output

```
foglio_id  (data + tipo_turno)
   │
   ▼
FoglioRenderer  (PHP, nel gestionale)
   ├─ legge DB:
   │    • assegnazioni per posizione  (chi è su ogni mezzo)
   │    • assenze per tipo            (FER / RC / MISS / PERM / MAL / INF)
   │    • capo_servizio / vice / furieri / funzionario / note
   │    • salto effettivo (salti_turno ± salto_override)  [per coerenza]
   │    • colori patente (vigili_patenti: 3/4 rosso, 2 blu)
   ├─ carica  modello.odt  (modulo ufficiale VUOTO, nel repo gestionale)
   ├─ mappa  codice-posizione → celle del modulo
   │    CENTR-OP, 1A, 2A, 3A, 4A, 5A, 1B, 2B-NBCR, 3B, 4B,
   │    1SMZ, 1FUN-AUTORADIO, 1SOP-AUTORIM,
   │    ML-1A, ML-1NAU, GA-1NAU, GE-1A, BL-1A, BS-1A, RP-1A,
   │    CH-1A, CH-1B, EL-1SMZ, AP-TEL, AP-1ROS, AP-1ASA, AP-1VI, AP-2VI
   └─ output:
        ├─ HTML  → stampa.php       (anteprima a video / stampa diretta)
        └─ ODT   → scarica_odt.php  (download .odt, zip ricompattato)
```

Anteprima e ODT escono dallo **stesso** `modello.odt` e dagli **stessi** dati →
identici per costruzione.

### Il modulo (geometria reale, da B4.odt)

Il modulo è una tabella `25 colonne × 86 righe` che, di fatto, è una griglia di
**5 colonne uguali** (~3.6 cm l'una). Ogni colonna è una **pila di mezzi**
(intestazione grigia + nomi). Mappatura colonne → mezzi:

**Sede Centrale**
| col 1 | col 2 | col 3 | col 4 | col 5 |
|---|---|---|---|---|
| Centrale operativa | 1A | 2A | 3A/Squadretta | 4A |
| 1SMZ | 1B | 2B/NBCR | 1FUN/Autoradio | 5A |
| | 3B | 4B | 1SOP/Autorimessa | |

**Distaccamenti & Aeroporto**
| col 1 | col 2 | col 3 | col 4 | col 5 |
|---|---|---|---|---|
| Multedo ML-1A | Genova Est GE-1A | Busalla BS-1A | Chiavari CH-1A | Aeroporto AP-TEL |
| Nautica GA-1NAU | Bolzaneto BL-1A | Rapallo RP-1A | Chiavari CH-1B | AP-1ROS |
| Multedo ML-1NAU | | | Reparto Volo EL-1SMZ | AP-1ASA / AP-1VI / AP-2VI |

**Personale assente** (sotto la barra `PERSONALE ASSENTE`)
- FERIE: `Cognome | Turni | Da | A`  (da `assenze` tipo FER)
- RIPOSO COMPENSATIVO: `Cognome | Variazioni`  (tipo RC)
- MISSIONE o PERMESSO  (tipi MISS + PERM)
- MALATTIA o INFORTUNIO  (tipi MAL + INF)

Intestazione: Dipartimento / Comando / "Foglio di Servizio", riga Furieri,
riga Capo Servizio · Vice · Funzionario, eventuali Note.

### Generazione ODT: skeleton-fill

1. `modello.odt` = un B*.odt con **tutte le celle-nome svuotate**, salvato una volta
   nel repo gestionale. Il modulo è identico per tutti i gruppi (cambiano solo i
   nomi, che comunque scartiamo) → **un solo modello**.
2. PHP apre lo zip, parsa `content.xml`.
3. Per ogni mezzo: trova le celle-nome (per colonna/sezione) e inietta i nomi dal DB,
   ognuno in uno `<text:span>` col colore patente.
4. Sezioni FERIE/RC/MISSIONE/MALATTIA: riempie le righe dal DB.
5. Intestazione: Furieri, Capo/Vice/Funzionario, data, turno.
6. Ricompatta lo zip (mimetype primo e STORED, come fa oggi `odt_service`) → bytes.

**Righe per mezzo — DECISO: righe fisse come da modulo, niente clonazione.**
Si rispetta la capienza del modulo (rifarsi all'ODT in visione). Il `modello.odt` vuoto
definisce, mezzo per mezzo, quante righe-nome esistono; il renderer le riempie dall'alto,
lascia vuote le eccedenti, e tronca l'eventuale eccesso (raro). Capienza di riferimento
estratta dal B4 (da rivedere creando il modello: i mezzi a 0 nel B4 vanno portati a un
minimo sensato):

| mezzo | slot | mezzo | slot | mezzo | slot |
|---|---|---|---|---|---|
| Centrale operativa | 5 | 5A | 8 | CH-1A | 6 |
| 1A | 4 | 3B | 1 | CH-1B | 3 |
| 2A | 4 | 4B | 1 | GA-1NAU | 3 |
| 3A/Squadretta | 6 | 1SOP/Autorimessa | 2 | BL-1A | 5 |
| 4A | 6 | ML-1A | 7 | RP-1A | 4 |
| 1SMZ | 6 | GE-1A | 5 | EL-1SMZ | 0→min |
| 1B | 1 | BS-1A | 6 | ML-1NAU | 0→min |
| 2B/NBCR | 2 | Telefonista | 1 | AP-1ROS/1ASA/1VI/2VI | 2/3/2/4 |
| 1FUN/Autoradio | 2 | | | | |

Nota: i conteggi B4 riflettono il crew di quel giorno, non un vero massimo. In fase di
creazione del `modello.odt` si fissano le righe definitive per mezzo (uniformando dove
serve, p.es. min 4–7 sui mezzi principali).

## Bot ridotto a canale

Resta: handler Telegram (`pompiere`, `fureria`, `scambio`) + `email_service.py`.
**Si rimuove:** `odt_service.py`, `data/templates/B*.odt`, l'HTTP server `/odt` in `bot.py`.

### Flusso ferie — approvazione alla generazione ODT
L'approvazione ferie **non è un atto separato**: avviene quando il **servizio è completo**,
cioè quando la fureria **genera l'ODT** del foglio. Generare l'ODT = chiudere il servizio
= approvare le ferie che vi compaiono.
```
vigile (Telegram) → richiesta ferie
   → bot scrive bot_requests (DB, stato = pending) + mail "richiesta ricevuta"
   → la ferie resta PENDING; nel gestionale è una richiesta da evadere sul foglio
fureria compila il foglio nel gestionale e GENERA l'ODT (servizio completo)
   → il gestionale APPROVA le ferie pending di quel foglio (bot_requests → approved)
   → comunica l'approvazione su DUE canali:
        • MAIL     (al vigile / agli interessati)
        • TELEGRAM (il gestionale notifica il bot, che invia il messaggio)
→ foglio/ODT riflettono il DB. Coerente per costruzione.
```
Nota: oggi le ferie nascono `approved` (commit `insert_request: ferie nasce approved`).
Con questo design **tornano a nascere `pending`** e si approvano alla generazione ODT.

**Da confermare** (ferie multi-giorno): una richiesta copre `data_da→data_a` / `nr_turni`.
L'approvazione alla generazione ODT è per-foglio (per data+turno). Opzioni: (a) approva
tutta la richiesta alla prima generazione utile; (b) approva per-giorno man mano che i
fogli si generano. Default proposto: **(a)**.

### Flusso scambio salto (già implementato, si tiene)
```
A propone → B conferma → fureria approva (Telegram)
   → bot scrive salto_override (DB) + mail a entrambi
→ foglio/ODT riflettono lo scambio dal DB.
```

Il bot **non tocca il foglio**: scrive solo richieste/override. Il foglio è costruito
dal gestionale leggendo il DB.

## Cosa cambia, file per file

| Elemento | Oggi | Domani |
|---|---|---|
| Motore foglio/ODT | `bot/odt_service.py` | `gestionale/includes/FoglioRenderer.php` |
| Template modulo | `bot/data/templates/B*.odt` | `gestionale/templates/modello.odt` (unico) |
| `gestionale/foglio/scarica_odt.php` | proxy curl al bot | `FoglioRenderer::odt($foglioId)` |
| `gestionale/foglio/stampa.php` | replica HTML a 5 colonne | `FoglioRenderer::html($foglioId)` (stesso modello) |
| `bot/bot.py` | avvia anche HTTP `/odt` | solo Telegram |
| Mail | bot (`email_service`) | bot (invariato) |

## Decisioni prese (2026-06-14)
1. **Approvazione ferie = alla generazione ODT** (servizio completo), comunicata su
   **mail + telegram**. La ferie nasce `pending` e viene approvata quando la fureria
   genera il foglio. Il gestionale fa l'approvazione + mail e notifica il bot per il
   messaggio Telegram. (Multi-giorno: default = approva tutta la richiesta alla prima
   generazione utile — da confermare.)
2. **Anteprima = ODT al 100%**: `stampa.php` viene riscritto per usare lo stesso
   `modello.odt` del download. La replica HTML a 5 colonne attuale è provvisoria.
3. **Righe per mezzo = fisse, come da modulo** (rifarsi all'ODT in visione), niente
   clonazione. Capienza definita nel `modello.odt`, riferimento B4 (tabella sopra).

### Resta da confermare
- Ferie multi-giorno: approvazione (a) tutta-alla-prima-generazione vs (b) per-giorno.
- Canale Telegram→gestionale: come il gestionale fa inviare il messaggio al bot
  (endpoint HTTP sul bot, oppure il bot fa polling di una coda nel DB).

## Piano di implementazione (fasi)
1. **Modello**: creare `gestionale/templates/modello.odt` (B4 con celle-nome svuotate),
   fissando le righe definitive per mezzo (capienza, vedi tabella).
2. **Renderer ODT**: `FoglioRenderer::odt()` con skeleton-fill dal DB (righe fisse);
   collaudo confronto con un foglio reale.
3. **scarica_odt.php**: puntare al renderer, rimuovere il proxy/curl/DNS.
4. **Anteprima**: `stampa.php` → `FoglioRenderer::html()` (stesso modello → 100% ODT).
5. **Approvazione ferie alla generazione**: ferie → `pending`; alla generazione ODT,
   approva le pending del foglio + mail + notifica Telegram al bot.
6. **Pulizia bot**: rimuovere `/odt`, `odt_service.py`, i template; il bot resta solo
   Telegram + mail (+ endpoint per ricevere le notifiche da inoltrare, se scelto).
7. **Verifica**: foglio reale → anteprima === ODT === modulo dirigenza; ferie approvate
   solo a servizio completo, con mail+telegram.
