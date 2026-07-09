<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/scambio_salto.php';
require_once __DIR__ . '/../includes/auth.php';
richiediLogin();

$pdo   = getDB();

// Turno primario: fisso sul turno di casa (admin/user niente switch qui). Comando
// non ha un turno di casa: segue l'attivo di sessione (le tab del cruscotto).
$TURNO = turnoCorrente() ?: turnoAttivo();

// Turni extra da affiancare in SOLA LETTURA (checkbox in UI), whitelisted contro i
// turni che l'utente può davvero vedere (esclude il primario).
$turniExtra = array_values(array_intersect(
    array_map(fn($t) => strtoupper(substr((string)$t, 0, 1)), (array)($_GET['extra'] ?? [])),
    array_diff(turniVisibili(), [$TURNO])
));
$turniQuery = array_merge([$TURNO], $turniExtra);
$phTurni    = implode(',', array_fill(0, count($turniQuery), '?'));

// ── Mese di riferimento ──────────────────────────────────────
$annoP = isset($_GET['anno']) ? (int)$_GET['anno'] : (int)date('Y');
$meseP = isset($_GET['mese']) ? (int)$_GET['mese'] : (int)date('m');
if ($meseP < 1)  { $meseP = 12; $annoP--; }
if ($meseP > 12) { $meseP = 1;  $annoP++; }
$meseStr = sprintf('%04d-%02d', $annoP, $meseP);

$mesePrev = $meseP - 1; $annoPrev = $annoP;
if ($mesePrev < 1) { $mesePrev = 12; $annoPrev--; }
$meseNext = $meseP + 1; $annoNext = $annoP;
if ($meseNext > 12) { $meseNext = 1; $annoNext++; }

$mesiNomi = ['','Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno',
             'Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];

$giorniNomi = ['','Lun','Mar','Mer','Gio','Ven','Sab','Dom'];

// ── Helpers assenza (sync foglio/cruscotto) ──────────────────
// Mirror della logica del bot (database.py): l'assenza tipo=1 marca il
// vigile assente sul foglio. accepted/pending → assenza presente,
// rejected → assenza rimossa. Tutto reversibile: l'assenza si ricrea
// dai dati della richiesta (vigile + data + turno).

require_once __DIR__ . '/../includes/ferie_assenze.php';

// ── AJAX ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $azione = $_POST['azione'] ?? '';

    // Blocco rapido di ruolo (gli user non scrivono mai). La guardia vera, ora che
    // questa pagina può mostrare più turni insieme, è il controllo per-riga qui sotto:
    // un turno aggiunto "in sola lettura" resta tale anche via richiesta diretta.
    if (isSoloLettura()) {
        echo json_encode(['ok' => false, 'errore' => 'Profilo in sola lettura.']);
        exit;
    }

    if ($azione === 'set_stato') {
        $stato = $_POST['stato'] ?? '';
        if (!in_array($stato, ['approved', 'rejected', 'pending'], true)) {
            echo json_encode(['ok' => false, 'errore' => 'Stato non valido']); exit;
        }
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        $ids = is_array($ids)
            ? array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0))
            : [];
        if (empty($ids)) {
            echo json_encode(['ok' => false, 'errore' => 'Nessuna richiesta selezionata']); exit;
        }

        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT r.id, r.vigile_id, r.data_richiesta, r.tipo_turno, r.stato, v.turno
             FROM bot_requests r JOIN vigili v ON v.id = r.vigile_id WHERE r.id IN ($ph)"
        );
        $stmt->execute($ids);
        $rows = array_values(array_filter($stmt->fetchAll(), fn($r) => puoModificareTurno($r['turno'])));
        if (empty($rows)) {
            echo json_encode(['ok' => false, 'errore' => 'Turno in sola lettura per il tuo profilo.']); exit;
        }

        $up = $pdo->prepare("UPDATE bot_requests SET stato=?, processed_at=? WHERE id=?");

        // ACCETTA = 'pending' (deciso ma NON ancora comunicato). L'approvazione vera
        // avviene solo all'invio della mail (finalizeFerie promuove pending→approved).
        // Se una notifica ferie:<id> è accodata ma non ancora partita, la togliamo
        // (torna "da inviare"); se è già 'sent' non si può disinviare.
        $obSel = $pdo->prepare("SELECT id, stato FROM bot_outbox WHERE ctx=?");
        $obDel = $pdo->prepare("DELETE FROM bot_outbox WHERE id=?");
        $giaNotificati = 0;
        $esiti = [];   // id => stato risultante (il client allinea il DOM su questo)

        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                // Accettare NON deve retrocedere una ferie già 'approved' (= già
                // comunicata via mail): resta approved. Gli altri casi vanno al target.
                $target = ($stato === 'pending' && $r['stato'] === 'approved')
                    ? 'approved' : $stato;
                $esiti[(int)$r['id']] = $target;

                $processedAt = $target === 'pending' ? null : date('Y-m-d H:i:s');
                $up->execute([$target, $processedAt, $r['id']]);
                feriaSyncAssenza($pdo, (int)$r['vigile_id'], $r['data_richiesta'], $r['tipo_turno'], $target);

                if ($target === 'pending') {
                    $obSel->execute(['ferie:' . (int)$r['id']]);
                    $ob = $obSel->fetch();
                    if ($ob) {
                        if ($ob['stato'] === 'sent') {
                            $giaNotificati++;          // vigile già avvisato: non si può disinviare
                        } else {
                            $obDel->execute([$ob['id']]);  // notifica non ancora partita → annullata
                        }
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'errore' => 'Errore DB']); exit;
        }

        echo json_encode([
            'ok' => true, 'aggiornati' => count($rows), 'stato' => $stato,
            'esiti' => $esiti, 'gia_notificati' => $giaNotificati,
        ]);
        exit;
    }

    // Cancellazione definitiva: rimuove la richiesta dal DB + l'assenza dal foglio
    if ($azione === 'elimina') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'errore' => 'ID non valido']); exit; }

        $st = $pdo->prepare(
            "SELECT r.vigile_id, r.data_richiesta, r.tipo_turno, v.turno
             FROM bot_requests r JOIN vigili v ON v.id = r.vigile_id WHERE r.id=?"
        );
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) { echo json_encode(['ok' => false, 'errore' => 'Richiesta inesistente']); exit; }
        if (!puoModificareTurno($r['turno'])) {
            echo json_encode(['ok' => false, 'errore' => 'Turno in sola lettura per il tuo profilo.']); exit;
        }

        $pdo->beginTransaction();
        try {
            // Toglie l'assenza dal foglio (DN → entrambi i turni)
            feriaSyncAssenza($pdo, (int)$r['vigile_id'], $r['data_richiesta'], $r['tipo_turno'], 'rejected');
            $pdo->prepare("DELETE FROM bot_requests WHERE id=?")->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'errore' => 'Errore DB']); exit;
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Scambi salto nati dal bot: approva / rifiuta dall'Agenda ──
    // Molte approvazioni avvengono al computer invece che dal bot. Replica la
    // stessa logica del bot (override + patch fogli) e avvisa i due vigili via
    // bot_outbox (Telegram + mail dal bot).
    if ($azione === 'scambio_set_stato') {
        $sid   = (int)($_POST['scambio_id'] ?? 0);
        $stato = $_POST['stato'] ?? '';
        if ($sid <= 0 || !in_array($stato, ['approved', 'rejected'], true)) {
            echo json_encode(['ok' => false, 'errore' => 'Parametri non validi']); exit;
        }

        $stT = $pdo->prepare(
            "SELECT a.turno FROM bot_scambi_salto s JOIN vigili a ON a.id = s.vigile_a_id WHERE s.id=?"
        );
        $stT->execute([$sid]);
        $turnoScambio = $stT->fetchColumn();
        if ($turnoScambio === false || !puoModificareTurno($turnoScambio)) {
            echo json_encode(['ok' => false, 'errore' => 'Turno in sola lettura per il tuo profilo.']); exit;
        }

        $pdo->beginTransaction();
        try {
            if ($stato === 'approved') {
                $sc = scambioApprovaEsistente($pdo, $sid);
                scambioEnqueueOutbox($pdo, (int)$sc['vigile_a_id'],
                    'scambio_approvato', "scambio:$sid:approvato");
            } else {
                $sc = scambioRifiuta($pdo, $sid);
                scambioEnqueueOutbox($pdo, (int)$sc['vigile_a_id'],
                    'scambio_rifiutato', "scambio:$sid:rifiutato");
            }
            $pdo->commit();
        } catch (ScambioConflitto $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'errore' => $e->getMessage()]); exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'errore' => $e->getMessage()]); exit;
        }

        echo json_encode(['ok' => true, 'stato' => $stato]); exit;
    }

    echo json_encode(['ok' => false, 'errore' => 'Azione non riconosciuta']);
    exit;
}

// ── Helpers ──────────────────────────────────────────────────
function etichettaVigile(array $v): string {
    $q = ucfirst(strtolower($v['qcodice'] ?? ''));
    $c = ucfirst(strtolower($v['cognome'] ?? ''));
    $d = !empty($v['disambiguatore']) ? ' ' . (int)$v['disambiguatore'] : '';
    return "$q $c$d";
}

require_once __DIR__ . '/../includes/ferie_blocchi.php';

// ── Carica richieste del mese ────────────────────────────────
$stmt = $pdo->prepare("
    SELECT r.id, r.vigile_id, r.data_richiesta, r.tipo_turno, r.stato,
           v.nome, v.cognome, v.disambiguatore, v.email, v.turno,
           q.codice AS qcodice,
           s.nome   AS sede_nome,
           s.codice AS sede_codice
    FROM bot_requests r
    JOIN vigili v     ON v.id = r.vigile_id
    JOIN qualifiche q ON q.id = v.qualifica_id
    JOIN sedi s       ON s.id = v.sede_id
    WHERE DATE_FORMAT(r.data_richiesta, '%Y-%m') = ? AND v.turno IN ($phTurni)
    ORDER BY v.cognome, v.disambiguatore, r.data_richiesta
");
$stmt->execute(array_merge([$meseStr], $turniQuery));
$tutteRichieste = $stmt->fetchAll();

// ── Stato COMUNICAZIONE per singola richiesta (badge per-turno, richiesta #93) ──
// La "comunicazione" NON è lo stato della richiesta (pending/approved/rejected):
// è se esiste una riga bot_outbox 'sent' per l'esito corrente del turno. Chiavi
// ctx: 'ferie:<id>' (accettata), 'ferie_neg:<id>' (negata). Così il segnale è
// indipendente dallo stato, che viene riscritto a ogni modifica dell'assegnazione.
$outboxReq = [];   // [reqId => ['ok'=>stato, 'neg'=>stato]]
$reqIds = array_map('intval', array_column($tutteRichieste, 'id'));
if ($reqIds) {
    $ctxList = [];
    foreach ($reqIds as $rid) { $ctxList[] = 'ferie:' . $rid; $ctxList[] = 'ferie_neg:' . $rid; }
    $phc = implode(',', array_fill(0, count($ctxList), '?'));
    $obq = $pdo->prepare("SELECT ctx, stato FROM bot_outbox WHERE ctx IN ($phc)");
    $obq->execute($ctxList);
    foreach ($obq->fetchAll() as $o) {
        [$pref, $rid] = explode(':', $o['ctx'], 2);
        $outboxReq[(int)$rid][$pref === 'ferie_neg' ? 'neg' : 'ok'] = $o['stato'];
    }
}
// Ritorna [classe, etichetta] del badge comunicazione di un turno.
function comunicazioneTurno(array $r, array $outboxReq): array {
    $kind = $r['stato'] === 'rejected' ? 'neg' : 'ok';
    if (($outboxReq[(int)$r['id']][$kind] ?? null) === 'sent') return ['comunicata', '✉️ comunicata'];
    return ['dainviare', '📨 da inviare'];
}

// Raggruppa per vigile → blocchi
$perVigile = [];
foreach ($tutteRichieste as $r) {
    $perVigile[$r['vigile_id']][] = $r;
}

// Costruisce tutti i blocchi e li raggruppa per data di inizio
$perData = [];
foreach ($perVigile as $vid => $req) {
    $meta   = $req[0];
    $blocchi = blocchiContigui($req);
    foreach ($blocchi as $block) {
        $dataInizio = $block[0]['data_richiesta'];
        $perData[$dataInizio][] = [
            'meta'  => $meta,
            'block' => $block,
        ];
    }
}
ksort($perData); // ordina per data crescente

// Ordina i vigili dentro ogni data per cognome
foreach ($perData as &$gruppo) {
    usort($gruppo, fn($a, $b) => $a['meta']['cognome'] <=> $b['meta']['cognome']);
}
unset($gruppo);

// ── Scambi salto approvati del mese → per data (insieme alle ferie) ──
// Ogni scambio compare sotto la/le sue date di riposo (override tipo D) che
// cadono nel mese: in quella data il "vigile_in" riposa al posto della controparte.
$scById = [];
$stSc = $pdo->prepare("
        SELECT s.id, s.slot_a, s.slot_b, s.vigile_a_id, s.vigile_b_id, a.turno,
               a.cognome AS a_cog, b.cognome AS b_cog
        FROM bot_scambi_salto s
        JOIN vigili a ON a.id = s.vigile_a_id
        JOIN vigili b ON b.id = s.vigile_b_id
        WHERE s.stato = 'approvato' AND a.turno IN ($phTurni)
    ");
$stSc->execute($turniQuery);
foreach ($stSc->fetchAll() as $s) {
    $scById[(int)$s['id']] = $s;
}
$scambiPerData = [];
if ($scById) {
    $phSc = implode(',', array_fill(0, count($scById), '?'));
    $ovQ  = $pdo->prepare("
        SELECT scambio_id, data, vigile_in_id
        FROM salto_override
        WHERE tipo='D' AND attivo=1 AND scambio_id IN ($phSc)
          AND DATE_FORMAT(data, '%Y-%m') = ?
        ORDER BY data
    ");
    $ovQ->execute(array_merge(array_keys($scById), [$meseStr]));
    foreach ($ovQ->fetchAll() as $ov) {
        $s      = $scById[(int)$ov['scambio_id']];
        $restaA = ((int)$ov['vigile_in_id'] === (int)$s['vigile_a_id']);
        $scambiPerData[$ov['data']][] = [
            'resta'  => $restaA ? $s['a_cog'] : $s['b_cog'],
            'altro'  => $restaA ? $s['b_cog'] : $s['a_cog'],
            'slot_a' => (int)$s['slot_a'],
            'slot_b' => (int)$s['slot_b'],
            'turno'  => $s['turno'],
        ];
    }
}

// ── Scambi salto DA APPROVARE (nati dal bot: proposto/confermato) ──
// Non filtrati per mese: il furiere deve vederli tutti per agire, a prescindere
// dal mese in vista. Le date di riposo si ricavano dai due slot + il blocco.
$scambiPending = [];
$stScP = $pdo->prepare("
        SELECT s.id, s.slot_a, s.slot_b, s.blocco_inizio, s.stato,
               s.vigile_a_id, s.vigile_b_id, a.turno,
               a.cognome AS a_cog, a.disambiguatore AS a_dis, qa.codice AS a_q,
               b.cognome AS b_cog, b.disambiguatore AS b_dis, qb.codice AS b_q
        FROM bot_scambi_salto s
        JOIN vigili a     ON a.id = s.vigile_a_id
        JOIN vigili b     ON b.id = s.vigile_b_id
        JOIN qualifiche qa ON qa.id = a.qualifica_id
        JOIN qualifiche qb ON qb.id = b.qualifica_id
        WHERE s.stato IN ('proposto', 'confermato') AND a.turno IN ($phTurni)
        ORDER BY s.blocco_inizio, s.id
    ");
$stScP->execute($turniQuery);
foreach ($stScP->fetchAll() as $s) {
    $etic = fn($q, $c, $d) => ucfirst(strtolower($q)) . ' ' . ucfirst(strtolower($c))
                            . ($d ? ' ' . (int)$d : '');
    $s['a_label'] = $etic($s['a_q'], $s['a_cog'], $s['a_dis']);
    $s['b_label'] = $etic($s['b_q'], $s['b_cog'], $s['b_dis']);
    $s['a_occ']   = slotDatesInBlocco((int)$s['slot_a'], $s['blocco_inizio']);
    $s['b_occ']   = slotDatesInBlocco((int)$s['slot_b'], $s['blocco_inizio']);
    $scambiPending[] = $s;
}

// Date da renderizzare = unione ferie + scambi, in ordine cronologico
$tutteLeDate = array_unique(array_merge(array_keys($perData), array_keys($scambiPerData)));
sort($tutteLeDate);

// Statistiche solo sul turno PRIMARIO: quelle degli extra sono lì per consultazione,
// non vanno confuse coi contatori "tuoi".
$richiestePrimarie = array_filter($tutteRichieste, fn($r) => $r['turno'] === $TURNO);
$totPending  = count(array_filter($richiestePrimarie, fn($r) => $r['stato'] === 'pending'));
$totApproved = count(array_filter($richiestePrimarie, fn($r) => $r['stato'] === 'approved'));
$totRejected = count(array_filter($richiestePrimarie, fn($r) => $r['stato'] === 'rejected'));
$totVigili   = count(array_unique(array_column($richiestePrimarie, 'vigile_id')));
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Agenda <?= $mesiNomi[$meseP] ?> <?= $annoP ?></title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<script defer src="../assets/js/conferma.js?v=<?= @filemtime(__DIR__.'/../assets/js/conferma.js') ?>"></script>
<style>
.ferie-page { max-width: 1100px; margin: 24px auto; padding: 0 16px 40px; }

/* ── Stat bar ── */
.stat-bar {
    display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px;
}
.stat-pill {
    background: var(--bianco); border-radius: 8px; box-shadow: var(--shadow);
    padding: 10px 20px; display: flex; flex-direction: column; align-items: center;
    min-width: 110px;
}
.stat-pill .n  { font-size: 1.6rem; font-weight: 800; line-height: 1; }
.stat-pill .lbl{ font-size: .68rem; font-weight: 700; text-transform: uppercase;
                 letter-spacing: .5px; color: var(--grigio-md); margin-top: 2px; }
.stat-pill.pending  .n { color: #b7950b; }
.stat-pill.approved .n { color: var(--verde); }
.stat-pill.rejected .n { color: var(--rosso); }
.stat-pill.vigili   .n { color: var(--rosso); }

/* ── Navigazione mese ── */
.mese-nav {
    background: var(--bianco); border-radius: var(--radius); box-shadow: var(--shadow);
    padding: 12px 20px; margin-bottom: 16px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.mese-nav h2 { font-size: 1rem; font-weight: 700; text-transform: uppercase;
               letter-spacing: .5px; color: var(--grigio-sc); }
.mese-nav a  { text-decoration: none; }

/* ── Vigile card ── */
.vigile-card {
    background: var(--bianco); border-radius: var(--radius); box-shadow: var(--shadow);
    margin-bottom: 10px; overflow: hidden;
}

/* ── Blocco dentro la card ── */
.blocco-row {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px; border-top: 1px solid #f0f0f0;
    cursor: pointer; transition: background .15s;
}
.blocco-row:first-child { border-top: none; }
.blocco-row:hover { background: #f8f9fa; }

.blocco-row .toggle-icon { font-size: .75rem; color: var(--grigio-md); width: 14px; flex-shrink: 0; }
.blocco-nome { font-weight: 700; font-size: .88rem; color: var(--grigio-sc); min-width: 130px; }
.blocco-sede { font-size: .65rem; font-weight: 700; background: var(--grigio-sc);
               color: #fff; border-radius: 3px; padding: 1px 6px; flex-shrink: 0; }
.blocco-periodo { font-size: .85rem; font-weight: 600; color: var(--grigio-sc);
                  min-width: 80px; }
.blocco-turni { font-size: .75rem; color: var(--grigio-md); min-width: 55px; }
.blocco-spacer { flex: 1; }

.stato-badge {
    font-size: .65rem; font-weight: 700; border-radius: 4px;
    padding: 3px 8px; text-transform: uppercase; letter-spacing: .4px; flex-shrink: 0;
}
.stato-pending  { background: #fef9e7; color: #b7950b; border: 1px solid #f9e79f; }
.stato-approved { background: var(--verde-bg); color: var(--verde); border: 1px solid #a9dfbf; }
.stato-misto    { background: #eaf4fb; color: var(--blu); border: 1px solid #aed6f1; }
.stato-rejected { background: #fdf2f2; color: var(--rosso); border: 1px solid #f5b7b1; }

/* ── Azioni rapide sul blocco ── */
.blocco-azioni { display: flex; gap: 6px; flex-shrink: 0; }
.btn-mini {
    border: 1px solid transparent; border-radius: 5px; cursor: pointer;
    font-size: .7rem; font-weight: 700; padding: 4px 9px; transition: filter .12s, background .12s;
}
.btn-mini.accetta { background: var(--verde-bg); color: var(--verde); border-color: #a9dfbf; }
.btn-mini.respingi{ background: #fdf2f2; color: var(--rosso); border-color: #f5b7b1; }
.btn-mini:hover { filter: brightness(.95); }

/* ── Tendina singoli turni ── */
.turni-detail {
    display: none;
    background: #fafafa;
    border-top: 1px solid #ececec;
    padding: 6px 16px 10px 42px;
}
.turni-detail.open { display: block; }

.turno-riga {
    display: flex; align-items: center; gap: 10px;
    padding: 6px 0; border-bottom: 1px solid #f0f0f0; font-size: .8rem;
}
.turno-riga:last-child { border-bottom: none; }
.turno-riga[data-stato="rejected"] { opacity: .6; }

/* Badge comunicazione per-turno (comunicata / da inviare) */
.com-badge { font-size: .72rem; font-weight: 600; padding: 1px 7px; border-radius: 10px;
             white-space: nowrap; margin-right: 8px; }
.com-comunicata { background: var(--verde-bg); color: var(--verde); border: 1px solid #a9dfbf; }
.com-dainviare  { background: #fff3e0; color: #b56a00; border: 1px solid #f5c896; }

.turno-data { font-weight: 600; color: var(--grigio-sc); width: 60px; }
.turno-dow  { color: var(--grigio-md); width: 28px; }
.turno-tipo { font-size: .72rem; font-weight: 700; width: 90px; }
.turno-tipo.D  { color: #7d5a00; }
.turno-tipo.N  { color: #1a4d72; }
.turno-tipo.DN { color: #6c3483; }
.turno-spacer { flex: 1; }

/* ── Doppia spunta accetto / respingo ── */
.scelta {
    display: flex; gap: 14px; align-items: center; flex-shrink: 0;
}
.scelta label {
    display: inline-flex; align-items: center; gap: 4px; cursor: pointer;
    font-size: .72rem; font-weight: 700; user-select: none;
}
.scelta .lbl-si { color: var(--verde); }
.scelta .lbl-no { color: var(--rosso); }
.chk-si { width: 16px; height: 16px; cursor: pointer; accent-color: var(--verde); }
.chk-no { width: 16px; height: 16px; cursor: pointer; accent-color: var(--rosso); }

.btn-elimina {
    background: none; border: none; cursor: pointer; flex-shrink: 0;
    color: #ccc; font-size: .9rem; line-height: 1; padding: 0 2px; margin-left: 10px;
    transition: color .15s;
}
.btn-elimina:hover { color: var(--rosso); }

.alert { padding: 12px 18px; border-radius: 6px; margin-bottom: 16px;
         font-size: .875rem; font-weight: 500; }
.alert-ok  { background: var(--verde-bg); color: var(--verde); border: 1px solid #a9dfbf; }
.alert-err { background: #fdf2f2; color: var(--rosso); border: 1px solid #f5b7b1; }

/* ── Turni extra (sola lettura) ── */
.extra-form { background: var(--bianco); border-radius: var(--radius); box-shadow: var(--shadow);
              padding: 10px 20px; margin-bottom: 16px; display: flex; align-items: center;
              gap: 16px; flex-wrap: wrap; font-size: .82rem; }
.extra-form .lbl { font-weight: 700; color: var(--grigio-md); }
.extra-form label.opt { display: inline-flex; align-items: center; gap: 5px; cursor: pointer; }
.turno-tag { font-size: .65rem; font-weight: 800; padding: 1px 7px; border-radius: 4px;
             background: #eaf4fb; color: var(--blu); border: 1px solid #aed6f1; margin-right: 6px; }
.ro-badge { font-size: .68rem; font-weight: 700; color: var(--grigio-md); white-space: nowrap; }
</style>
</head>
<body>

<header class="header">
  <div class="header-inner">
    <div class="header-logo">🚒</div>
    <div class="header-testi">
      <h1>Comando Provinciale VVF di Genova</h1>
      <p>Gestionale — Agenda Turno <?= htmlspecialchars($TURNO) ?><?= $turniExtra ? ' + ' . htmlspecialchars(implode(', ', $turniExtra)) . ' (sola lettura)' : '' ?></p>
    </div>
    <div class="header-badge">TURNO&nbsp;<?= htmlspecialchars($TURNO) ?></div>
  </div>
</header>

<nav class="navbar">
  <div class="navbar-inner">
    <a href="../index.php"          class="nav-btn">🏠 Cruscotto</a>
    <a href="../foglio/nuovo.php"   class="nav-btn">📋 Foglio</a>
    <a href="../vigili/lista.php"   class="nav-btn">👥 Personale</a>
    <a href="index.php"             class="nav-btn active">🗓️ Agenda</a>
    <a href="../report/index.php"   class="nav-btn">📊 Reportistica</a>
    <a href="../admin/index.php"    class="nav-btn">⚙️ Amministrazione</a>
    <span style="margin-left:auto"><?= turnoComandoHtml() ?></span>
    <a href="../logout.php"         class="nav-btn">🚪 Esci</a>
  </div>
</nav>

<div class="ferie-page">

  <div id="msgBox"></div>

  <!-- Navigazione mese -->
  <div class="mese-nav">
    <a href="?anno=<?= $annoPrev ?>&mese=<?= $mesePrev ?>" class="btn btn-grigio btn-sm">◀</a>
    <h2>🗓️ Agenda — <?= $mesiNomi[$meseP] ?> <?= $annoP ?></h2>
    <a href="?anno=<?= $annoNext ?>&mese=<?= $meseNext ?>" class="btn btn-grigio btn-sm">▶</a>
  </div>

  <!-- Turni extra in sola lettura: affianca le richieste di altri turni visibili -->
  <?php $turniAltri = array_diff(turniVisibili(), [$TURNO]);
  if ($turniAltri): ?>
  <form method="GET" class="extra-form">
    <input type="hidden" name="anno" value="<?= $annoP ?>">
    <input type="hidden" name="mese" value="<?= $meseP ?>">
    <span class="lbl">👁 Mostra anche (sola lettura):</span>
    <?php foreach ($turniAltri as $t): ?>
      <label class="opt">
        <input type="checkbox" name="extra[]" value="<?= htmlspecialchars($t) ?>"
               <?= in_array($t, $turniExtra, true) ? 'checked' : '' ?>
               onchange="this.form.submit()">
        Turno <?= htmlspecialchars($t) ?>
      </label>
    <?php endforeach; ?>
  </form>
  <?php endif; ?>

  <!-- Stat bar -->
  <div class="stat-bar">
    <div class="stat-pill vigili">
      <span class="n"><?= $totVigili ?></span>
      <span class="lbl">Vigili</span>
    </div>
    <div class="stat-pill approved">
      <span class="n" id="statApproved"><?= $totApproved ?></span>
      <span class="lbl">Accettati</span>
    </div>
    <div class="stat-pill rejected">
      <span class="n" id="statRejected"><?= $totRejected ?></span>
      <span class="lbl">Rifiutati</span>
    </div>
  </div>

  <?php
  // Formatta un'occorrenza di riposo (data_D, data_N) → "12/06 ☀️ + 13/06 🌙"
  $fmtOcc = function (?array $occ): string {
      if (!$occ) return '—';
      $d = (new DateTime($occ[0]))->format('d/m');
      $n = (new DateTime($occ[1]))->format('d/m');
      return "$d ☀️ + $n 🌙";
  };
  ?>
  <?php if ($scambiPending): ?>
  <!-- Scambi salto in attesa di approvazione (da bot) -->
  <div class="data-section" id="scambiDaApprovare">
    <div class="data-head" style="display:flex;align-items:baseline;gap:10px;padding:6px 4px 6px 0;margin-bottom:6px;border-bottom:2px solid #0a58ca;">
      <span class="data-label" style="font-size:.95rem;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#0a58ca;">🔄 Scambi salto da approvare</span>
      <span class="data-count" style="font-size:.72rem;color:var(--grigio-md);font-weight:600;"><?= count($scambiPending) ?> in attesa</span>
    </div>
    <div class="vigile-card">
      <?php foreach ($scambiPending as $s): ?>
      <div class="blocco-row" id="scambio-<?= (int)$s['id'] ?>" style="cursor:default;flex-wrap:wrap;">
        <span class="toggle-icon">🔄</span>
        <?php if ($turniExtra): ?><span class="turno-tag">Turno <?= htmlspecialchars($s['turno']) ?></span><?php endif; ?>
        <span class="blocco-nome"><?= htmlspecialchars($s['a_label']) ?> <span style="color:var(--grigio-md);">(<?= htmlspecialchars($s['turno']) ?><?= (int)$s['slot_a'] ?>)</span> ⇄ <?= htmlspecialchars($s['b_label']) ?> <span style="color:var(--grigio-md);">(<?= htmlspecialchars($s['turno']) ?><?= (int)$s['slot_b'] ?>)</span></span>
        <span class="blocco-spacer"></span>
        <span style="font-size:.74rem;color:var(--grigio-md);white-space:nowrap;">
          <?= htmlspecialchars($s['a_label']) ?> → riposa <?= $fmtOcc($s['b_occ']) ?>
          &nbsp;·&nbsp;
          <?= htmlspecialchars($s['b_label']) ?> → riposa <?= $fmtOcc($s['a_occ']) ?>
        </span>
        <span class="stato-badge stato-pending" style="margin-left:8px;"><?= $s['stato'] === 'proposto' ? 'in attesa conferma' : 'da approvare' ?></span>
        <?php if ($s['turno'] === $TURNO): ?>
        <div class="blocco-azioni" onclick="event.stopPropagation()">
          <button class="btn-mini accetta"
                  onclick='approvaScambio(<?= (int)$s['id'] ?>)'
                  title="Approva lo scambio (scrive il foglio e avvisa i due vigili)">✓ approva</button>
          <button class="btn-mini respingi"
                  onclick='rifiutaScambio(<?= (int)$s['id'] ?>)'
                  title="Rifiuta lo scambio e avvisa i due vigili">✗ rifiuta</button>
        </div>
        <?php else: ?>
        <span class="ro-badge" style="margin-left:8px;">👁 sola lettura</span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (empty($tutteLeDate) && empty($scambiPending)): ?>
    <div class="alert alert-ok">Nessuna richiesta di ferie o scambio salto per <?= $mesiNomi[$meseP] ?> <?= $annoP ?>.</div>
  <?php endif; ?>

  <!-- Sezioni per data: scambi salto + ferie insieme -->
  <?php foreach ($tutteLeDate as $dataInizio):
    $gruppo     = $perData[$dataInizio] ?? [];
    $scambi     = $scambiPerData[$dataInizio] ?? [];
    $dtInizio   = new DateTime($dataInizio);
    $dataHeader = $giorniNomi[(int)$dtInizio->format('N')] . ' '
                . $dtInizio->format('d') . ' '
                . $mesiNomi[(int)$dtInizio->format('n')];
    $conteggio  = [];
    if ($gruppo) $conteggio[] = count($gruppo) . ' vigil' . (count($gruppo) === 1 ? 'e' : 'i') . ' in ferie';
    if ($scambi) $conteggio[] = count($scambi) . (count($scambi) === 1 ? ' scambio' : ' scambi') . ' salto';
    // Quel giorno il turno PRIMARIO è in servizio diurno (☀️) o notturno (🌙):
    // mostro un'icona sola, col salto a riposo del foglio corrispondente (l'ancora
    // resta il turno primario anche in vista multi-turno).
    $tgB = getTurnoGiorno($dataInizio);
    if ($tgB['diurno']['turno'] === $TURNO) {
        $saltoTipo = 'D'; $saltoIco = '☀️';
    } elseif ($tgB['notte']['turno'] === $TURNO) {
        $saltoTipo = 'N'; $saltoIco = '🌙';
    } else {
        $saltoTipo = 'D'; $saltoIco = '';
    }
    $saltoNum  = saltoRiposoNum($dataInizio, $saltoTipo);
    $urlFoglio = '../foglio/nuovo.php?data=' . urlencode($dataInizio) . '&tipo=' . $saltoTipo
               . '&turno=' . $TURNO;
  ?>
  <div class="data-section" id="giorno-<?= htmlspecialchars($dataInizio) ?>" data-giorno="<?= htmlspecialchars($dataInizio) ?>">

    <!-- Intestazione data: data cliccabile (apre il foglio) + salto a riposo a destra -->
    <div class="data-head" style="display:flex;align-items:baseline;gap:10px;padding:6px 4px 6px 0;margin-bottom:6px;border-bottom:2px solid var(--rosso);">
      <a class="data-label" href="<?= $urlFoglio ?>"
         title="Apri il foglio di servizio di questo giorno"
         style="font-size:.95rem;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--rosso);text-decoration:none;">📋 <?= $dataHeader ?></a>
      <span class="data-count" style="font-size:.72rem;color:var(--grigio-md);font-weight:600;"><?= implode(' · ', $conteggio) ?></span>
      <a href="<?= $urlFoglio ?>" title="Apri il foglio di servizio di questo giorno"
         style="margin-left:auto;font-size:.8rem;font-weight:700;color:var(--grigio-sc);text-decoration:none;white-space:nowrap;"><?= $saltoIco ?> <?= htmlspecialchars($TURNO) ?><?= $saltoNum ?></a>
    </div>

    <?php if ($scambi): ?>
    <!-- Scambi salto di questa data -->
    <div class="vigile-card" style="margin-bottom:8px;">
      <?php foreach ($scambi as $sc): ?>
      <div class="blocco-row" style="cursor:default;">
        <span class="toggle-icon">🔄</span>
        <?php if ($turniExtra): ?><span class="turno-tag">Turno <?= htmlspecialchars($sc['turno']) ?></span><?php endif; ?>
        <span class="blocco-nome" style="color:#0a58ca;"><?= htmlspecialchars($sc['resta']) ?> riposa</span>
        <span class="blocco-spacer"></span>
        <span style="font-size:.78rem;color:var(--grigio-md);">scambio salto con <?= htmlspecialchars($sc['altro']) ?> (<?= htmlspecialchars($sc['turno']) ?><?= $sc['slot_a'] ?>⇄<?= htmlspecialchars($sc['turno']) ?><?= $sc['slot_b'] ?>)</span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($gruppo): ?>
    <div class="vigile-card">
    <?php foreach ($gruppo as $gi => $item):
      $meta       = $item['meta'];
      $block      = $item['block'];
      $label      = etichettaVigile($meta);
      $isCentrale = ($meta['sede_nome'] === 'CENTRALE');
      $periodo    = periodLabel($block);
      $turni      = turniLabel($block);
      $stato      = statoBlock($block);
      $detailId   = 'detail-' . $meta['vigile_id'] . '-' . md5($dataInizio);
      $allIds     = array_column($block, 'id');
      $editabile  = ($meta['turno'] === $TURNO);
    ?>
    <!-- Riga vigile -->
    <div class="blocco-row" id="row-<?= $detailId ?>"
         onclick="toggleDetail('<?= $detailId ?>')">
      <span class="toggle-icon" id="icon-<?= $detailId ?>">▶</span>
      <?php if ($turniExtra): ?><span class="turno-tag">Turno <?= htmlspecialchars($meta['turno']) ?></span><?php endif; ?>
      <span class="blocco-nome"><?= htmlspecialchars($label) ?></span>
      <?php if (!$isCentrale): ?>
        <span class="blocco-sede"><?= htmlspecialchars($meta['sede_codice']) ?></span>
      <?php endif; ?>
      <span class="blocco-periodo"><?= $periodo ?></span>
      <span class="blocco-turni"><?= $turni ?> turni</span>
      <span class="blocco-spacer"></span>
      <span class="stato-badge stato-<?= $stato ?>" id="badge-<?= $detailId ?>"><?= $stato ?></span>
      <?php if ($editabile): ?>
      <div class="blocco-azioni" onclick="event.stopPropagation()">
        <button class="btn-mini accetta"
                onclick='setStato(<?= htmlspecialchars(json_encode($allIds)) ?>, "pending")'
                title="Accetta tutto il periodo (resta in attesa fino all'invio della mail)">✓ tutti</button>
        <button class="btn-mini respingi"
                onclick='setStato(<?= htmlspecialchars(json_encode($allIds)) ?>, "rejected")'
                title="Respingi tutto il periodo">✗ tutti</button>
      </div>
      <?php else: ?>
      <span class="ro-badge">👁 sola lettura</span>
      <?php endif; ?>
    </div>

    <!-- Tendina singoli turni -->
    <div class="turni-detail" id="<?= $detailId ?>" data-block="<?= $detailId ?>">
      <?php foreach ($block as $r):
        $d   = new DateTime($r['data_richiesta']);
        $dow = $giorniNomi[(int)$d->format('N')];
      ?>
      <?php
        [$comCls, $comLbl] = comunicazioneTurno($r, $outboxReq);
        $sok  = (($outboxReq[(int)$r['id']]['ok']  ?? null) === 'sent') ? 1 : 0;
        $sneg = (($outboxReq[(int)$r['id']]['neg'] ?? null) === 'sent') ? 1 : 0;
      ?>
      <div class="turno-riga" data-id="<?= $r['id'] ?>" data-stato="<?= $r['stato'] ?>" data-block="<?= $detailId ?>"
           data-sok="<?= $sok ?>" data-sneg="<?= $sneg ?>">
        <span class="turno-data"><?= $d->format('d/m') ?></span>
        <span class="turno-dow"><?= $dow ?></span>
        <span class="turno-tipo <?= $r['tipo_turno'] ?>">
          <?= match($r['tipo_turno']) {
              'D'  => '☀️ Diurno',
              'N'  => '🌙 Notturno',
              'DN' => '🌅 Giornata',
              default => $r['tipo_turno'],
          } ?>
        </span>
        <span class="turno-spacer"></span>
        <span class="com-badge com-<?= $comCls ?>" id="com-<?= $r['id'] ?>"><?= $comLbl ?></span>
        <?php if ($editabile): ?>
        <div class="scelta">
          <label class="lbl-si">
            <input type="checkbox" class="chk-si" <?= $r['stato'] !== 'rejected' ? 'checked' : '' ?>
                   onchange="onScelta(this, 'pending')">accetto
          </label>
          <label class="lbl-no">
            <input type="checkbox" class="chk-no" <?= $r['stato'] === 'rejected' ? 'checked' : '' ?>
                   onchange="onScelta(this, 'rejected')">respingo
          </label>
        </div>
        <button class="btn-elimina" title="Elimina definitivamente la richiesta"
                onclick="eliminaTurno(<?= $r['id'] ?>, this)">🗑️</button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <?php endforeach; ?>
    </div><!-- /.vigile-card -->
    <?php endif; ?>

  </div><!-- /.data-section -->
  <?php endforeach; ?>

</div><!-- /.ferie-page -->

<script>
const STATO_LABEL = { pending: 'attesa', approved: 'accettata', rejected: 'rifiutata', misto: 'misto' };

// ── Accordion ────────────────────────────────────────────────
function toggleDetail(id) {
    const el   = document.getElementById(id);
    const icon = document.getElementById('icon-' + id);
    const open = el.classList.toggle('open');
    if (icon) icon.textContent = open ? '▼' : '▶';
}

function showMsg(html, cls) {
    const box = document.getElementById('msgBox');
    box.innerHTML = `<div class="alert alert-${cls}">${html}</div>`;
    setTimeout(() => { box.innerHTML = ''; }, 2500);
}

// ── Spunta singola accetto/respingo (binaria: una sempre attiva) ───
function onScelta(chk, target) {
    const riga = chk.closest('.turno-riga');
    const id   = parseInt(riga.dataset.id);
    // Non si può lasciare la richiesta senza scelta: ri-flagga e basta
    if (!chk.checked) { chk.checked = true; return; }
    setStato([id], target);
}

// ── Applica uno stato a una lista di richieste ───────────────
async function setStato(ids, stato) {
    if (!ids || ids.length === 0) return;
    const fd = new FormData();
    fd.append('azione', 'set_stato');
    fd.append('stato', stato);
    fd.append('ids', JSON.stringify(ids));

    let res;
    try {
        res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
    } catch (e) {
        showMsg('⚠️ Errore di rete', 'err');
        return;
    }
    if (!res.ok) {
        showMsg('⚠️ ' + (res.errore || 'Errore'), 'err');
        sincronizzaDOM(); // ripristina le spunte allo stato reale
        return;
    }

    // Aggiorna subito le spunte/badge (feedback immediato), poi ricarica: i blocchi
    // (quali turni stanno nella stessa card, periodo/nr_turni mostrati) li calcola
    // solo PHP con blocchiContigui() al render — un turno che cambia stato può
    // dover uscire dal blocco corrente o unirsi a un altro, cosa che l'update in
    // pagina da solo non può rappresentare (restava "accorpato"/"disaccorpato"
    // finché non si premeva F5).
    const esiti = res.esiti || {};
    ids.forEach(id => {
        const riga = document.querySelector(`.turno-riga[data-id="${id}"]`);
        if (riga) riga.dataset.stato = esiti[id] || stato;
    });
    sincronizzaDOM();
    const extra = (stato === 'pending' && res.gia_notificati > 0)
        ? ` — ⚠️ ${res.gia_notificati} già notificato/i: avvisa il vigile a voce`
        : '';
    showMsg(`✅ ${res.aggiornati} turno/i → ${STATO_LABEL[stato]}${extra}`, 'ok');
    sessionStorage.setItem('agendaScrollY', window.scrollY);
    setTimeout(() => location.reload(), 700);
}

// Badge comunicazione di un turno, calcolato dallo stato + dai flag sent per esito
// (data-sok = 'ferie:<id>' inviata, data-sneg = 'ferie_neg:<id>' inviata).
function comBadgeTurno(riga) {
    const st   = riga.dataset.stato;
    const sent = st === 'rejected' ? riga.dataset.sneg === '1' : riga.dataset.sok === '1';
    if (sent) return ['comunicata', '✉️ comunicata'];
    return ['dainviare', '📨 da inviare'];
}

// ── Riallinea spunte, badge blocco e contatori al DOM ────────
function sincronizzaDOM() {
    // spunte di ogni riga
    document.querySelectorAll('.turno-riga').forEach(riga => {
        const st = riga.dataset.stato;
        const si = riga.querySelector('.chk-si');
        const no = riga.querySelector('.chk-no');
        if (si) si.checked = (st !== 'rejected');
        if (no) no.checked = (st === 'rejected');
        // badge comunicazione per-turno
        const com = document.getElementById('com-' + riga.dataset.id);
        if (com) { const [cls, lbl] = comBadgeTurno(riga); com.className = 'com-badge com-' + cls; com.textContent = lbl; }
    });

    // badge di blocco
    document.querySelectorAll('.turni-detail').forEach(det => {
        const bid    = det.dataset.block;
        const stati  = [...det.querySelectorAll('.turno-riga')].map(r => r.dataset.stato);
        const unici  = [...new Set(stati)];
        const stato  = unici.length === 1 ? unici[0] : 'misto';
        const badge  = document.getElementById('badge-' + bid);
        if (badge) {
            badge.className = 'stato-badge stato-' + stato;
            badge.textContent = stato;
        }
    });

    // contatori
    const tutte = [...document.querySelectorAll('.turno-riga')];
    const conta = s => tutte.filter(r => r.dataset.stato === s).length;
    document.getElementById('statApproved').textContent = conta('approved');
    document.getElementById('statRejected').textContent = conta('rejected');
}

// ── Cancellazione definitiva (con conferma) ──────────────────
function eliminaTurno(id, btn) {
    chiediConferma({
        titolo:  'Elimina richiesta',
        testo:   'Eliminare definitivamente questa richiesta?<br>Verrà rimossa dal database e l\'assenza tolta dal foglio.<br><strong>L\'operazione non è reversibile.</strong>',
        okLabel: '🗑️ Elimina',
        okStyle: 'background:var(--rosso);color:#fff',
        onOk:    () => eseguiEliminaTurno(id, btn)
    });
}

async function eseguiEliminaTurno(id, btn) {
    const fd = new FormData();
    fd.append('azione', 'elimina');
    fd.append('id', id);

    let res;
    try {
        res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
    } catch (e) {
        showMsg('⚠️ Errore di rete', 'err');
        return;
    }
    if (!res.ok) {
        showMsg('⚠️ ' + (res.errore || 'Errore'), 'err');
        return;
    }

    // Rimuove la riga; se il blocco resta vuoto, toglie l'intera card
    const riga  = btn.closest('.turno-riga');
    const det   = riga.closest('.turni-detail');
    riga.remove();
    if (det && det.querySelectorAll('.turno-riga').length === 0) {
        const card = det.closest('.vigile-card');
        const row  = document.getElementById('row-' + det.dataset.block);
        if (row)  row.remove();
        det.remove();
    }
    sincronizzaDOM();
    showMsg('🗑️ Richiesta eliminata.', 'ok');
}

// ── Scambi salto: approva / rifiuta (richieste nate dal bot) ──
function approvaScambio(sid) {
    chiediConferma({
        titolo:  'Approva scambio salto',
        testo:   'Approvi questo scambio?<br>Verrà scritto sul foglio di servizio ' +
                 'e i due vigili saranno avvisati (Telegram + mail).',
        okLabel: '✓ Approva',
        okStyle: 'background:#198754;color:#fff',
        onOk:    () => eseguiScambio(sid, 'approved'),
    });
}

function rifiutaScambio(sid) {
    chiediConferma({
        titolo:  'Rifiuta scambio salto',
        testo:   'Rifiuti questo scambio?<br>I due vigili saranno avvisati e ' +
                 'resteranno sul loro riposo originale.',
        okLabel: '✗ Rifiuta',
        okStyle: 'background:var(--rosso);color:#fff',
        onOk:    () => eseguiScambio(sid, 'rejected'),
    });
}

async function eseguiScambio(sid, stato) {
    const fd = new FormData();
    fd.append('azione', 'scambio_set_stato');
    fd.append('scambio_id', sid);
    fd.append('stato', stato);

    let res;
    try {
        res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
    } catch (e) {
        showMsg('⚠️ Errore di rete', 'err');
        return;
    }
    if (!res.ok) {
        showMsg('⚠️ ' + (res.errore || 'Errore'), 'err');
        return;
    }
    // Ricarico: l'approvato deve comparire nell'elenco per data e sparire dai pending
    showMsg(stato === 'approved' ? '✅ Scambio approvato.' : '✅ Scambio rifiutato.', 'ok');
    setTimeout(() => location.reload(), 700);
}

sincronizzaDOM();

// ── Auto-refresh: aggiorna la pagina ogni 60s per vedere richieste/scambi
// nuovi o cambiati da un'altra sessione (bot o altro operatore), senza dover
// ricaricare a mano. Salta il giro se un dettaglio è aperto (non richiuderlo
// sotto le mani mentre lo si sta consultando) e mantiene lo scroll tra un
// refresh e l'altro.
if (sessionStorage.getItem('agendaScrollY') !== null) {
    window.scrollTo(0, parseInt(sessionStorage.getItem('agendaScrollY'), 10));
    sessionStorage.removeItem('agendaScrollY');
}
setInterval(() => {
    if (document.querySelector('.turni-detail.open')) return;
    sessionStorage.setItem('agendaScrollY', window.scrollY);
    location.reload();
}, 60000);

// #87 — apertura da "clic sulla data" del foglio: ?goto=YYYY-MM-DD → scrolla in
// cima alla sezione di quel giorno; se quel giorno non ha ferie, va alla prima
// sezione a partire da quella data (così si atterra comunque nel punto giusto).
(function () {
    const goto = new URLSearchParams(location.search).get('goto');
    if (!goto) return;
    let target = document.getElementById('giorno-' + goto);
    if (!target) {
        const sezioni = [...document.querySelectorAll('.data-section[data-giorno]')];
        target = sezioni.find(s => s.dataset.giorno >= goto) || sezioni[sezioni.length - 1];
    }
    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
})();
</script>

</body>
</html>
