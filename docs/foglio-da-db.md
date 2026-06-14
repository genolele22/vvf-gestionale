# Foglio di Servizio generato dal DB — design

> Stato: **progettato, NON implementato.** Sposta la generazione del foglio/ODT dal
> bot al gestionale, basandola interamente sul DB. Il bot resta solo canale di
> richieste/approvazioni + mail.
> Data design: 2026-06-14.

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

**Nodo righe variabili**: il template ha slot fissi per mezzo, il DB può avere 1–7
nomi. Soluzione consigliata: **clonare/eliminare** `<table:table-row>` per adattare
il numero di righe al conteggio reale (mantiene il modulo pulito). Alternativa più
semplice: tetto fisso di righe per mezzo (rischio troncamento se un mezzo supera lo slot).

## Bot ridotto a canale

Resta: handler Telegram (`pompiere`, `fureria`, `scambio`) + `email_service.py`.
**Si rimuove:** `odt_service.py`, `data/templates/B*.odt`, l'HTTP server `/odt` in `bot.py`.

### Flusso ferie
```
vigile (Telegram) → richiesta
   → bot scrive bot_requests (DB, pending) + manda mail (richiesta)
fureria approva
   → bot aggiorna bot_requests (approved) + manda mail (conferma)
→ il gestionale, leggendo il DB, mostra la ferie nel foglio/ODT. Automatico.
```

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

## Decisioni aperte
1. **Approvazione ferie**: solo via bot (Telegram) o anche dal gestionale web?
   Se anche web → la mail di conferma la manda il gestionale o gira la palla al bot?
2. **Anteprima = ODT al 100%**: riscrivere `stampa.php` perché usi `modello.odt`
   (consigliato, coerenza totale) oppure tenere la replica HTML attuale (più leggera).
3. **Righe variabili ODT**: clonare righe (modulo pulito, più codice) vs tetto fisso.

## Piano di implementazione (fasi)
1. **Modello**: creare `gestionale/templates/modello.odt` (B4 con celle-nome svuotate).
2. **Renderer ODT**: `FoglioRenderer::odt()` con skeleton-fill dal DB; collaudo confronto
   con un foglio reale.
3. **scarica_odt.php**: puntare al renderer, rimuovere il proxy/curl/DNS.
4. **Anteprima**: `stampa.php` → renderer HTML (se si sceglie la coerenza totale).
5. **Pulizia bot**: rimuovere `/odt`, `odt_service.py`, i template; verificare che il
   bot resti solo Telegram + mail.
6. **Verifica**: foglio reale → anteprima === ODT === modulo dirigenza.
