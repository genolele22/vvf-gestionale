<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/turni.php';
require_once __DIR__ . '/../includes/FoglioRenderer.php';

$pdo = getDB();

// Capienza per posizione = slot del modello.odt (stessi spazi dell'ODT di riferimento).
// Cache statica: il modello si parsa una volta sola.
function capPos(int $posId): int {
    static $map = null;
    if ($map === null) {
        $map = [];
        $cap = FoglioRenderer::slotCapacities();   // codice → n. slot
        foreach (getDB()->query("SELECT id, codice FROM posizioni") as $p) {
            $map[(int)$p['id']] = $cap[$p['codice']] ?? 7;   // 7 = fallback se codice non nel modello
        }
    }
    return $map[$posId] ?? 7;
}

// ── Parametri URL ────────────────────────────────────────────
$dataParam = $_GET['data'] ?? date('Y-m-d');
$tipoParam = ($_GET['tipo'] ?? 'D') === 'N' ? 'N' : 'D';

try {
    $dtFoglio = new DateTime($dataParam);
} catch (Exception $e) {
    $dtFoglio = new DateTime('today');
}
$dataStr   = $dtFoglio->format('Y-m-d');
$dataLabel = $dtFoglio->format('d/m/Y');
$oraLabel  = $tipoParam === 'D' ? '08:00 → 20:00' : '20:00 → 08:00';

// ── Turno del giorno ─────────────────────────────────────────
$turnoGiorno  = getTurnoGiorno($dataStr);
$turnoAttivo  = $tipoParam === 'D' ? $turnoGiorno['diurno'] : $turnoGiorno['notte'];
$turnoRiposo  = $tipoParam === 'D' ? $turnoGiorno['notte']  : $turnoGiorno['diurno'];
$codSaltoRip  = 'B' . $turnoRiposo['salto'];

// ── Helper: chi riposa davvero su questo foglio ──────────────
// Default = vigili col salto_id del giorno. Poi applica gli scambi salto
// approvati (salto_override) per quella data/turno: il vigile "out" torna a
// lavorare, il vigile "in" va a riposo. Ritorna [vigile_id => true].
function resterEffettivi(PDO $pdo, string $dataStr, string $tipoParam, int $saltoRiposoId): array {
    $resters = [];
    $st = $pdo->prepare("SELECT id FROM vigili WHERE attivo=1 AND salto_id=?");
    $st->execute([$saltoRiposoId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $vid) {
        $resters[(int)$vid] = true;
    }
    $so = $pdo->prepare(
        "SELECT vigile_out_id, vigile_in_id FROM salto_override
         WHERE data=? AND tipo=? AND attivo=1"
    );
    $so->execute([$dataStr, $tipoParam]);
    foreach ($so->fetchAll() as $r) {
        unset($resters[(int)$r['vigile_out_id']]);
        $resters[(int)$r['vigile_in_id']] = true;
    }
    return $resters;
}

// ── Helper: pre-popola assegnazioni e salto per un foglio ────
// Regola: max = capienza ODT della posizione (capPos). Chi ha una posizione
// esplicita (template ODT / default) ci va; gli altri vengono SPALMATI sulle
// posizioni della loro sede, riempiendo fino a capienza in ordine.
// $resters = insieme effettivo dei riposanti (default ± scambi salto).
function prepopolaAssegnazioni(PDO $pdo, int $foglioId, int $saltoRiposoId, array $resters): void {
    $pdo->prepare("DELETE FROM assegnazioni WHERE foglio_id=?")->execute([$foglioId]);

    // Posizione da montaggio ODT per questo salto canonico
    $tmpl = [];
    $st = $pdo->prepare("SELECT vigile_id, posizione_id FROM posizione_template WHERE salto_canoni=?");
    $st->execute([$saltoRiposoId]);
    foreach ($st->fetchAll() as $r) {
        $tmpl[(int)$r['vigile_id']] = (int)$r['posizione_id'];
    }

    // Tutte le posizioni di ogni sede, ordinate; + mappa posizione→sede
    $posizioniDiSede = [];
    $sedeDiPos       = [];
    foreach ($pdo->query("SELECT id, sede_id FROM posizioni ORDER BY sede_id, ordine") as $pp) {
        $posizioniDiSede[(int)$pp['sede_id']][] = (int)$pp['id'];
        $sedeDiPos[(int)$pp['id']]              = (int)$pp['sede_id'];
    }

    $personale = $pdo->query(
        "SELECT v.id, v.sede_id, v.salto_id, v.posizione_default_id FROM vigili v WHERE v.attivo=1"
    )->fetchAll();

    // Vigili con assenza registrata per questo foglio → non vanno in servizio
    $assentiIds = [];
    $stAb = $pdo->prepare("SELECT vigile_id FROM assenze WHERE foglio_id=?");
    $stAb->execute([$foglioId]);
    foreach ($stAb->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        $assentiIds[(int)$aid] = true;
    }

    $nextAssId = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM assegnazioni")->fetchColumn();
    $stA       = $pdo->prepare("INSERT IGNORE INTO assegnazioni (id,foglio_id,posizione_id,vigile_id,ordine,in_straordinario) VALUES (?,?,?,?,?,0)");

    $conteggio = [];  // posId => n attuale
    $assegna = function(int $vid, int $posId) use (&$conteggio, &$nextAssId, $stA, $foglioId): void {
        $conteggio[$posId] = ($conteggio[$posId] ?? 0) + 1;
        $stA->execute([$nextAssId++, $foglioId, $posId, $vid, $conteggio[$posId]]);
    };

    // Prima posizione libera (sotto la sua capienza ODT) della sede, in ordine
    $primaLibera = function(int $sedeId) use (&$conteggio, $posizioniDiSede): ?int {
        foreach ($posizioniDiSede[$sedeId] ?? [] as $pid) {
            if (($conteggio[$pid] ?? 0) < capPos($pid)) return $pid;
        }
        return null;
    };

    // Pass 1: chi ha posizione esplicita (template/default) e c'è ancora posto
    $daSpalmare = [];
    foreach ($personale as $vp) {
        $vid     = (int)$vp['id'];
        $sedeId  = (int)$vp['sede_id'];
        if (isset($resters[$vid])) continue;
        if (isset($assentiIds[$vid])) continue;

        $posEsplicita = $tmpl[$vid]
            ?? ($vp['posizione_default_id'] ? (int)$vp['posizione_default_id'] : null);

        if ($posEsplicita && ($conteggio[$posEsplicita] ?? 0) < capPos($posEsplicita)) {
            $assegna($vid, $posEsplicita);
        } else {
            // Sede di destinazione: quella della posizione esplicita (se piena) o quella del vigile
            $daSpalmare[] = ['vid' => $vid,
                             'sede' => $posEsplicita ? ($sedeDiPos[$posEsplicita] ?? $sedeId) : $sedeId];
        }
    }

    // Pass 2: spalma i restanti sulle posizioni libere della loro sede
    foreach ($daSpalmare as $d) {
        $pid = $primaLibera($d['sede']);
        if ($pid) $assegna($d['vid'], $pid);
        // se tutte piene → resta nei disponibili (non assegnato)
    }
}

function prepopolaFoglio(PDO $pdo, int $foglioId, int $saltoRiposoId, array $resters): void {
    $pdo->prepare("DELETE FROM salto_servizio WHERE foglio_id=?")->execute([$foglioId]);

    $personale   = $pdo->query("SELECT v.id FROM vigili v WHERE v.attivo=1")->fetchAll();
    $nextSaltoId = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM salto_servizio")->fetchColumn();
    $stS         = $pdo->prepare("INSERT IGNORE INTO salto_servizio (id,foglio_id,vigile_id,richiamato) VALUES (?,?,?,0)");

    foreach ($personale as $vp) {
        if (isset($resters[(int)$vp['id']])) {
            $stS->execute([$nextSaltoId++, $foglioId, (int)$vp['id']]);
        }
    }

    prepopolaAssegnazioni($pdo, $foglioId, $saltoRiposoId, $resters);
}

// ── Recupera o crea il foglio ────────────────────────────────
$stmtF = $pdo->prepare(
    "SELECT * FROM fogli_servizio WHERE data_servizio=? AND tipo_turno=?"
);
$stmtF->execute([$dataStr, $tipoParam]);
$foglio = $stmtF->fetch();

$stSalto = $pdo->prepare("SELECT id FROM salti_turno WHERE codice=?");
$stSalto->execute([$codSaltoRip]);
$saltoRiposoId = (int)($stSalto->fetchColumn() ?: 1);

if (!$foglio) {
    $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM fogli_servizio")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO fogli_servizio (id,data_servizio,tipo_turno,salto_riposo_id,creato_da) VALUES (?,?,?,?,?)"
    )->execute([$nextId, $dataStr, $tipoParam, $saltoRiposoId, 'sistema']);
    $stmtF->execute([$dataStr, $tipoParam]);
    $foglio = $stmtF->fetch();
    $foglioId = (int)$foglio['id'];
    prepopolaFoglio($pdo, $foglioId, $saltoRiposoId, resterEffettivi($pdo, $dataStr, $tipoParam, $saltoRiposoId));
}
$foglioId = (int)$foglio['id'];

// ── Servizio precedente (per il tasto "Servizio precedente") ──
// Il più recente foglio prima di questo: stessa data ma turno D (per un N),
// altrimenti la data anteriore più vicina (D < N nello stesso giorno).
$stPrev = $pdo->prepare(
    "SELECT id, data_servizio, tipo_turno FROM fogli_servizio
      WHERE data_servizio < ? OR (data_servizio = ? AND tipo_turno < ?)
      ORDER BY data_servizio DESC, tipo_turno DESC
      LIMIT 1"
);
$stPrev->execute([$dataStr, $dataStr, $tipoParam]);
$foglioPrec = $stPrev->fetch();

// ── Stato blocco foglio (condiviso, lato server) ─────────────
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS fogli_lock (
        foglio_id  INT PRIMARY KEY,
        bloccato   TINYINT NOT NULL DEFAULT 0,
        updated_at DATETIME
     )"
);
$stLock = $pdo->prepare("SELECT bloccato FROM fogli_lock WHERE foglio_id=?");
$stLock->execute([$foglioId]);
$foglioBloccato = (bool)$stLock->fetchColumn();

// ── AJAX: salva intestazione ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';
    header('Content-Type: application/json');

    // Cambia lo stato di blocco condiviso del foglio
    if ($azione === 'set_blocco') {
        $val = (!empty($_POST['bloccato']) && $_POST['bloccato'] !== '0') ? 1 : 0;
        $pdo->prepare(
            "INSERT INTO fogli_lock (foglio_id, bloccato, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE bloccato=VALUES(bloccato), updated_at=NOW()"
        )->execute([$foglioId, $val]);
        echo json_encode(['ok' => true, 'bloccato' => (bool)$val]);
        exit;
    }

    // Guardia: se il foglio è bloccato, nessuna operazione di modifica passa
    $azioniModifica = ['salva_intestazione', 'assegna', 'rimuovi', 'assenza',
                       'rimuovi_assenza', 'metti_salto', 'richiama_salto', 'reset_foglio',
                       'ferie_ufficio', 'rimuovi_ufficio', 'scambia_salto', 'annulla_scambio'];
    if ($foglioBloccato && in_array($azione, $azioniModifica, true)) {
        echo json_encode(['ok' => false, 'bloccato' => true,
                          'errore' => 'Foglio bloccato. Sblocca per modificare.']);
        exit;
    }

    if ($azione === 'salva_intestazione') {
        $csId  = (int)($_POST['capo_servizio_id'] ?? 0) ?: null;
        $vcsId = (int)($_POST['vice_capo_id']     ?? 0) ?: null;
        $fun   = trim($_POST['funzionario']        ?? '');
        $note  = trim($_POST['note_generali']      ?? '');

        $pdo->prepare(
            "UPDATE fogli_servizio
             SET capo_servizio_id=?, vice_capo_id=?, funzionario=?, note_generali=?
             WHERE id=?"
        )->execute([$csId, $vcsId, $fun, $note, $foglioId]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: assegna vigile a posizione ─────────────────────
    if ($azione === 'assegna') {
        $vigileId    = (int)($_POST['vigile_id']     ?? 0);
        $posizioneId = (int)($_POST['posizione_id']  ?? 0);
        $straord     = (int)($_POST['straordinario'] ?? 0);

        // Limite = capienza ODT della posizione: controlla PRIMA di toccare i dati,
        // escludendo il vigile stesso (caso di ri-drop nella stessa squadra)
        if ($posizioneId > 0) {
            $cap = capPos($posizioneId);
            $stCap = $pdo->prepare(
                "SELECT COUNT(*) FROM assegnazioni
                 WHERE foglio_id=? AND posizione_id=? AND vigile_id<>?"
            );
            $stCap->execute([$foglioId, $posizioneId, $vigileId]);
            if ((int)$stCap->fetchColumn() >= $cap) {
                echo json_encode(['ok' => false, 'pieno' => true,
                                  'errore' => "Posizione al completo (max $cap)."]);
                exit;
            }
        }

        // Rimuove assegnazioni e salto precedenti dello stesso vigile
        $pdo->prepare(
            "DELETE FROM assegnazioni WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);
        $pdo->prepare(
            "DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        if ($posizioneId > 0) {
            $stOrd = $pdo->prepare(
                "SELECT COALESCE(MAX(ordine),0)+1
                 FROM assegnazioni WHERE foglio_id=? AND posizione_id=?"
            );
            $stOrd->execute([$foglioId, $posizioneId]);
            $ordine = (int)$stOrd->fetchColumn();

            $nextAssId = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM assegnazioni")->fetchColumn();
            $pdo->prepare(
                "INSERT INTO assegnazioni
                 (id, foglio_id, posizione_id, vigile_id, ordine, in_straordinario)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$nextAssId, $foglioId, $posizioneId, $vigileId, $ordine, $straord]);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: riordina i vigili dentro UNA posizione (per anzianità) ──
    // Riceve posizione_id e `ordine` = CSV di vigile_id nel nuovo ordine.
    // Aggiorna solo il campo `ordine` delle righe già assegnate a quella
    // posizione (nessun insert/delete): operazione idempotente e sicura.
    if ($azione === 'riordina') {
        $posizioneId = (int)($_POST['posizione_id'] ?? 0);
        $ordineCsv   = trim($_POST['ordine'] ?? '');
        if ($posizioneId <= 0 || $ordineCsv === '') {
            echo json_encode(['ok' => false, 'errore' => 'Dati mancanti.']); exit;
        }
        $ids = array_values(array_filter(array_map('intval', explode(',', $ordineCsv))));
        $up  = $pdo->prepare(
            "UPDATE assegnazioni SET ordine=?
             WHERE foglio_id=? AND posizione_id=? AND vigile_id=?"
        );
        foreach ($ids as $i => $vid) {
            $up->execute([$i + 1, $foglioId, $posizioneId, $vid]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: rimuovi assegnazione ────────────────────────────
    if ($azione === 'rimuovi') {
        $vigileId = (int)($_POST['vigile_id'] ?? 0);
        $pdo->prepare(
            "DELETE FROM assegnazioni WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);
        $pdo->prepare(
            "DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: aggiungi assenza ────────────────────────────────
    if ($azione === 'assenza') {
        $vigileId      = (int)($_POST['vigile_id']       ?? 0);
        $tipoAssenzaId = (int)($_POST['tipo_assenza_id'] ?? 0);
        $sedeDist      = trim($_POST['sede_distaccata']  ?? '');
        $notaAss       = trim($_POST['note']             ?? '');
        $dataDa        = trim($_POST['data_da']          ?? '') ?: null;
        $dataA         = trim($_POST['data_a']           ?? '') ?: null;
        $nrTurni       = (int)($_POST['nr_turni']        ?? 0) ?: null;

        // Rimuove da posizioni e salto
        $pdo->prepare(
            "DELETE FROM assegnazioni WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);
        $pdo->prepare(
            "DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        // Rimuove assenza precedente (stessa giornata)
        $pdo->prepare(
            "DELETE FROM assenze WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        $nextAssenzaId = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM assenze")->fetchColumn();
        $pdo->prepare(
            "INSERT INTO assenze
             (id, foglio_id, vigile_id, tipo_assenza_id,
              sede_distaccata, data_da, data_a, nr_turni, note)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([
            $nextAssenzaId, $foglioId, $vigileId, $tipoAssenzaId,
            $sedeDist ?: null, $dataDa, $dataA, $nrTurni, $notaAss ?: null
        ]);

        // Se è una FERIE (tipo=1) e c'era una richiesta respinta per questa data,
        // riportala ad approved → sulla pagina ferie torna flaggata "accetto".
        if ($tipoAssenzaId === 1) {
            $stReq = $pdo->prepare(
                "SELECT tipo_turno FROM bot_requests
                 WHERE vigile_id=? AND data_richiesta=? AND stato='rejected'
                 ORDER BY id DESC LIMIT 1"
            );
            $stReq->execute([$vigileId, $dataStr]);
            $reqTipo = $stReq->fetchColumn();

            if ($reqTipo !== false) {
                $pdo->prepare(
                    "UPDATE bot_requests SET stato='approved', processed_at=NOW()
                     WHERE vigile_id=? AND data_richiesta=? AND stato='rejected'"
                )->execute([$vigileId, $dataStr]);

                // Richiesta DN → ripristina la ferie anche sull'altro turno del giorno
                if ($reqTipo === 'DN') {
                    $tipoPaired = ($tipoParam === 'D') ? 'N' : 'D';
                    $stF = $pdo->prepare(
                        "SELECT id FROM fogli_servizio WHERE data_servizio=? AND tipo_turno=?"
                    );
                    $stF->execute([$dataStr, $tipoPaired]);
                    $fidPaired = $stF->fetchColumn();
                    if (!$fidPaired) {
                        $fidPaired = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM fogli_servizio")->fetchColumn();
                        $pdo->prepare(
                            "INSERT INTO fogli_servizio (id,data_servizio,tipo_turno,salto_riposo_id,creato_da)
                             VALUES (?,?,?,1,'ferie')"
                        )->execute([$fidPaired, $dataStr, $tipoPaired]);
                    }
                    $stChk = $pdo->prepare(
                        "SELECT id FROM assenze WHERE foglio_id=? AND vigile_id=? AND tipo_assenza_id=1"
                    );
                    $stChk->execute([$fidPaired, $vigileId]);
                    if (!$stChk->fetchColumn()) {
                        $nid = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM assenze")->fetchColumn();
                        $pdo->prepare(
                            "INSERT INTO assenze (id,foglio_id,vigile_id,tipo_assenza_id) VALUES (?,?,?,1)"
                        )->execute([$nid, $fidPaired, $vigileId]);
                    }
                }
            }
        }

        // Per le ferie: è "a mano" (d'ufficio) se non c'è una richiesta Telegram dietro.
        // Serve al client per mostrarla anche nel box "Ferie d'ufficio" (ridondanza).
        $isUfficio = false;
        if ($tipoAssenzaId === 1) {
            $stU = $pdo->prepare(
                "SELECT 1 FROM bot_requests
                 WHERE vigile_id=? AND data_richiesta=? AND stato IN ('pending','approved') LIMIT 1"
            );
            $stU->execute([$vigileId, $dataStr]);
            $isUfficio = !(bool) $stU->fetchColumn();
        }

        echo json_encode(['ok' => true, 'ufficio' => $isUfficio]);
        exit;
    }

    // ── AJAX: ferie d'ufficio (assenza ferie diretta, senza richiesta) ──
    if ($azione === 'ferie_ufficio') {
        $vigileId = (int)($_POST['vigile_id'] ?? 0);
        if ($vigileId <= 0) { echo json_encode(['ok' => false]); exit; }

        // Libera da posizioni/salto/assenze sul foglio corrente
        $pdo->prepare("DELETE FROM assegnazioni  WHERE foglio_id=? AND vigile_id=?")->execute([$foglioId, $vigileId]);
        $pdo->prepare("DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?")->execute([$foglioId, $vigileId]);
        $pdo->prepare("DELETE FROM assenze        WHERE foglio_id=? AND vigile_id=?")->execute([$foglioId, $vigileId]);

        // Crea assenza FERIE (tipo=1) — nessun bot_request
        $nid = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM assenze")->fetchColumn();
        $pdo->prepare(
            "INSERT INTO assenze (id, foglio_id, vigile_id, tipo_assenza_id) VALUES (?, ?, ?, 1)"
        )->execute([$nid, $foglioId, $vigileId]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: togli ferie d'ufficio ───────────────────────────
    if ($azione === 'rimuovi_ufficio') {
        $vigileId = (int)($_POST['vigile_id'] ?? 0);
        $pdo->prepare(
            "DELETE FROM assenze WHERE foglio_id=? AND vigile_id=? AND tipo_assenza_id=1"
        )->execute([$foglioId, $vigileId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: rimuovi assenza ─────────────────────────────────
    if ($azione === 'rimuovi_assenza') {
        $vigileId = (int)($_POST['vigile_id'] ?? 0);

        // È una ferie (tipo=1)?
        $stCheck = $pdo->prepare(
            "SELECT 1 FROM assenze WHERE foglio_id=? AND vigile_id=? AND tipo_assenza_id=1"
        );
        $stCheck->execute([$foglioId, $vigileId]);
        $isFeria = (bool) $stCheck->fetchColumn();

        // C'è una richiesta Telegram dietro? (ferie a mano = nessuna richiesta)
        $stReq = $pdo->prepare(
            "SELECT 1 FROM bot_requests
             WHERE vigile_id=? AND data_richiesta=? AND stato IN ('pending','approved') LIMIT 1"
        );
        $stReq->execute([$vigileId, $dataStr]);
        $eraRichiesta = (bool) $stReq->fetchColumn();

        $pdo->prepare(
            "DELETE FROM assenze WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        // Solo le ferie DA RICHIESTA vanno "respinte" (pulizia DN + stato rejected).
        // Le ferie a mano si tolgono e basta: la persona torna disponibile.
        if ($isFeria && $eraRichiesta) {
            $tipoPaired = ($tipoParam === 'D') ? 'N' : 'D';
            $pdo->prepare(
                "DELETE a FROM assenze a
                 JOIN fogli_servizio f ON f.id = a.foglio_id
                 WHERE a.vigile_id=? AND f.data_servizio=? AND f.tipo_turno=? AND a.tipo_assenza_id=1"
            )->execute([$vigileId, $dataStr, $tipoPaired]);

            $pdo->prepare(
                "UPDATE bot_requests SET stato='rejected', processed_at=NOW()
                 WHERE vigile_id=? AND data_richiesta=? AND stato IN ('pending','approved')"
            )->execute([$vigileId, $dataStr]);
        }

        echo json_encode(['ok' => true, 'era_feria' => $isFeria, 'era_richiesta' => $eraRichiesta]);
        exit;
    }

    // ── AJAX: metti in salto ──────────────────────────────────
    if ($azione === 'metti_salto') {
        $vigileId = (int)($_POST['vigile_id'] ?? 0);

        $pdo->prepare(
            "DELETE FROM assegnazioni WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);
        $pdo->prepare(
            "DELETE FROM assenze WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);
        $pdo->prepare(
            "DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        $nextSaltoId = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM salto_servizio")->fetchColumn();
        $pdo->prepare(
            "INSERT INTO salto_servizio (id, foglio_id, vigile_id, richiamato)
             VALUES (?,?,?,0)"
        )->execute([$nextSaltoId, $foglioId, $vigileId]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: richiama da salto (straordinario) ───────────────
    if ($azione === 'richiama_salto') {
        $vigileId = (int)($_POST['vigile_id'] ?? 0);
        $pdo->prepare(
            "UPDATE salto_servizio SET richiamato=1
             WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: cambio salto turno (bidirezionale, bypass Telegram) ──
    // Replica la logica del bot (approva_scambio): scrive bot_scambi_salto
    // 'approvato' + 4 righe salto_override (D+N di A, D+N di B) e patcha i
    // fogli esistenti. A = chi cede il salto (riposa su questo foglio),
    // B = controparte di un altro slot col riposo nello stesso blocco.
    if ($azione === 'scambia_salto') {
        $aId = (int)($_POST['vigile_a_id'] ?? 0);
        $bId = (int)($_POST['vigile_b_id'] ?? 0);
        if (!$aId || !$bId || $aId === $bId) {
            echo json_encode(['ok' => false, 'errore' => 'Selezione non valida.']); exit;
        }

        // Slot (1..8) dei due vigili
        $stV = $pdo->prepare(
            "SELECT v.id, v.attivo, CAST(SUBSTRING(st.codice,2) AS UNSIGNED) AS slot
             FROM vigili v JOIN salti_turno st ON st.id = v.salto_id
             WHERE v.id IN (?,?)"
        );
        $stV->execute([$aId, $bId]);
        $info = [];
        foreach ($stV->fetchAll() as $r) $info[(int)$r['id']] = $r;
        if (!isset($info[$aId], $info[$bId]) || !$info[$aId]['attivo'] || !$info[$bId]['attivo']) {
            echo json_encode(['ok' => false, 'errore' => 'Vigile inesistente o non attivo.']); exit;
        }
        $slotA = (int)$info[$aId]['slot'];
        $slotB = (int)$info[$bId]['slot'];

        if ($slotA !== saltoRiposoNum($dataStr, $tipoParam)) {
            echo json_encode(['ok' => false, 'errore' => 'Il primo vigile non è a riposo su questo foglio.']); exit;
        }
        if ($slotA === $slotB) {
            echo json_encode(['ok' => false, 'errore' => 'I due vigili sono dello stesso salto.']); exit;
        }

        $aOcc = slotDatesInBlocco($slotA, $dataStr);
        $bOcc = slotDatesInBlocco($slotB, $dataStr);
        if (!$aOcc || !$bOcc) {
            echo json_encode(['ok' => false, 'errore' => 'Controparte fuori dal blocco.']); exit;
        }
        if ($bOcc[0] <= date('Y-m-d')) {
            echo json_encode(['ok' => false, 'errore' => 'Il riposo della controparte è già passato.']); exit;
        }

        [$bloccoIni, $bloccoFin] = bloccoConfini($dataStr);

        // 4 righe override: (data, tipo, vigile_out, vigile_in)
        $rows = [
            [$aOcc[0], 'D', $aId, $bId],
            [$aOcc[1], 'N', $aId, $bId],
            [$bOcc[0], 'D', $bId, $aId],
            [$bOcc[1], 'N', $bId, $aId],
        ];

        // Guardia: nessuno scambio attivo già presente su queste righe coi due vigili
        $stDup = $pdo->prepare(
            "SELECT COUNT(*) FROM salto_override
             WHERE attivo=1 AND data=? AND tipo=?
               AND (vigile_in_id IN (?,?) OR vigile_out_id IN (?,?))"
        );
        foreach ($rows as [$d, $t, , ]) {
            $stDup->execute([$d, $t, $aId, $bId, $aId, $bId]);
            if ((int)$stDup->fetchColumn() > 0) {
                echo json_encode(['ok' => false,
                    'errore' => 'Esiste già uno scambio attivo per uno dei due vigili in questo blocco.']); exit;
            }
        }

        $pdo->beginTransaction();
        try {
            $sid = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM bot_scambi_salto")->fetchColumn();
            $pdo->prepare(
                "INSERT INTO bot_scambi_salto
                    (id, vigile_a_id, vigile_b_id, slot_a, slot_b,
                     blocco_inizio, blocco_fine, stato, creato_da, approvato_da)
                 VALUES (?,?,?,?,?,?,?, 'approvato', ?, ?)"
            )->execute([$sid, $aId, $bId, $slotA, $slotB, $bloccoIni, $bloccoFin, $aId, $aId]);

            $insOv  = $pdo->prepare(
                "INSERT INTO salto_override
                    (id, scambio_id, data, tipo, vigile_out_id, vigile_in_id, attivo)
                 VALUES (?,?,?,?,?,?,1)"
            );
            $selF   = $pdo->prepare("SELECT id FROM fogli_servizio WHERE data_servizio=? AND tipo_turno=?");
            $delSS  = $pdo->prepare("DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?");
            $delAss = $pdo->prepare("DELETE FROM assegnazioni  WHERE foglio_id=? AND vigile_id=?");
            $chkSS  = $pdo->prepare("SELECT 1 FROM salto_servizio WHERE foglio_id=? AND vigile_id=?");
            $insSS  = $pdo->prepare("INSERT INTO salto_servizio (id, foglio_id, vigile_id, richiamato) VALUES (?,?,?,0)");

            foreach ($rows as [$d, $t, $outId, $inId]) {
                $oid = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM salto_override")->fetchColumn();
                $insOv->execute([$oid, $sid, $d, $t, $outId, $inId]);

                // Patch foglio esistente: out torna a lavorare (esce dal salto),
                // in va a riposo (esce dalle assegnazioni, entra nel salto).
                $selF->execute([$d, $t]);
                $fid = $selF->fetchColumn();
                if ($fid) {
                    $delSS->execute([$fid, $outId]);
                    $delAss->execute([$fid, $inId]);
                    $chkSS->execute([$fid, $inId]);
                    if (!$chkSS->fetchColumn()) {
                        $nss = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM salto_servizio")->fetchColumn();
                        $insSS->execute([$nss, $fid, $inId]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'errore' => 'Errore DB: ' . $e->getMessage()]); exit;
        }

        echo json_encode(['ok' => true, 'scambio_id' => $sid]); exit;
    }

    // ── AJAX: annulla cambio salto turno (reversibile) ──────────
    // Inverte lo scambio: per ogni riga override attiva del scambio rimette
    // vigile_out in salto e toglie vigile_in dai fogli esistenti, poi
    // disattiva le righe override e marca lo scambio 'annullato'.
    if ($azione === 'annulla_scambio') {
        $sid = (int)($_POST['scambio_id'] ?? 0);
        if (!$sid) {
            echo json_encode(['ok' => false, 'errore' => 'Scambio non valido.']); exit;
        }
        $stO = $pdo->prepare(
            "SELECT id, data, tipo, vigile_out_id, vigile_in_id
             FROM salto_override WHERE scambio_id=? AND attivo=1"
        );
        $stO->execute([$sid]);
        $rows = $stO->fetchAll();
        if (!$rows) {
            echo json_encode(['ok' => false, 'errore' => 'Scambio già annullato o inesistente.']); exit;
        }

        $pdo->beginTransaction();
        try {
            $selF  = $pdo->prepare("SELECT id FROM fogli_servizio WHERE data_servizio=? AND tipo_turno=?");
            $delSS = $pdo->prepare("DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?");
            $chkSS = $pdo->prepare("SELECT 1 FROM salto_servizio WHERE foglio_id=? AND vigile_id=?");
            $insSS = $pdo->prepare("INSERT INTO salto_servizio (id, foglio_id, vigile_id, richiamato) VALUES (?,?,?,0)");
            $offO  = $pdo->prepare("UPDATE salto_override SET attivo=0 WHERE id=?");

            foreach ($rows as $r) {
                $selF->execute([$r['data'], $r['tipo']]);
                $fid = $selF->fetchColumn();
                if ($fid) {
                    // chi era entrato in salto esce; chi cedeva (rester canonico) rientra
                    $delSS->execute([$fid, (int)$r['vigile_in_id']]);
                    $chkSS->execute([$fid, (int)$r['vigile_out_id']]);
                    if (!$chkSS->fetchColumn()) {
                        $nss = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM salto_servizio")->fetchColumn();
                        $insSS->execute([$nss, $fid, (int)$r['vigile_out_id']]);
                    }
                }
                $offO->execute([(int)$r['id']]);
            }
            $pdo->prepare("UPDATE bot_scambi_salto SET stato='annullato' WHERE id=?")->execute([$sid]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'errore' => 'Errore DB: ' . $e->getMessage()]); exit;
        }

        echo json_encode(['ok' => true]); exit;
    }

    // ── AJAX: reset foglio ───────────────────────────────────
    // Riporta il foglio allo stato CANONICO, annullando ogni modifica manuale:
    //   - salti = salto_id ± scambi salto APPROVATI (resterEffettivi)
    //   - ferie = richieste fatte sul bot (le respinte tornano richieste)
    //   - assegnazioni = ripopolate da zero
    //   - assenze manuali (ferie d'ufficio, malattia, missione, permessi) = rimosse
    if ($azione === 'reset_foglio') {
        $tipiTurno = ($tipoParam === 'D') ? ['D', 'DN'] : ['N', 'DN'];
        $phTt      = implode(',', array_fill(0, count($tipiTurno), '?'));

        // 1. Le ferie RESPINTE su questo turno tornano richieste (annulla la
        //    decisione manuale della fureria): rejected → pending.
        $pdo->prepare(
            "UPDATE bot_requests SET stato='pending', processed_at=NULL
             WHERE data_richiesta=? AND stato='rejected' AND tipo_turno IN ($phTt)"
        )->execute(array_merge([$dataStr], $tipiTurno));

        // 2. Via TUTTE le assenze del foglio (anche le manuali): vengono
        //    ricostruite sotto solo quelle che derivano da una richiesta bot.
        $pdo->prepare("DELETE FROM assenze WHERE foglio_id=?")->execute([$foglioId]);

        // 3. Ricrea le ferie (tipo_assenza 1) da TUTTE le richieste bot attive
        //    (pending/approved) per questo turno.
        $stReq = $pdo->prepare(
            "SELECT DISTINCT vigile_id FROM bot_requests
             WHERE data_richiesta=? AND stato IN ('pending','approved') AND tipo_turno IN ($phTt)"
        );
        $stReq->execute(array_merge([$dataStr], $tipiTurno));
        $reqVigili = $stReq->fetchAll(PDO::FETCH_COLUMN);
        if ($reqVigili) {
            $nid = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM assenze")->fetchColumn();
            $insAss = $pdo->prepare(
                "INSERT INTO assenze (id, foglio_id, vigile_id, tipo_assenza_id) VALUES (?,?,?,1)"
            );
            foreach ($reqVigili as $vid) {
                $insAss->execute([$nid++, $foglioId, (int)$vid]);
            }
        }

        // 4. Ricostruisci salti + assegnazioni dallo stato canonico.
        //    prepopolaAssegnazioni salta chi è in ferie (assenze appena ricreate).
        prepopolaFoglio($pdo, $foglioId, $saltoRiposoId,
            resterEffettivi($pdo, $dataStr, $tipoParam, $saltoRiposoId));

        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'errore' => 'Azione non riconosciuta']);
    exit;
}

// ── Auto-popolamento fogli "nudi" creati dal bot ─────────────
// Quando un vigile chiede ferie, il bot crea il foglio (+ assenza) ma NON popola
// salti/assegnazioni: restavano vuoti finché la fureria non faceva un reset a mano.
// Alla prima APERTURA (GET), se il foglio non è mai stato popolato (0 assegnazioni
// e 0 salto_servizio) e nasce dal flusso ferie (bot/ferie), lo popoliamo ora.
// Le ferie sono preservate: prepopolaAssegnazioni salta chi è già assente.
if (!$foglioBloccato && in_array(($foglio['creato_da'] ?? ''), ['bot', 'ferie'], true)) {
    $stChkA = $pdo->prepare("SELECT COUNT(*) FROM assegnazioni  WHERE foglio_id=?");
    $stChkA->execute([$foglioId]);
    $stChkS = $pdo->prepare("SELECT COUNT(*) FROM salto_servizio WHERE foglio_id=?");
    $stChkS->execute([$foglioId]);
    if ((int)$stChkA->fetchColumn() === 0 && (int)$stChkS->fetchColumn() === 0) {
        prepopolaFoglio($pdo, $foglioId, $saltoRiposoId,
            resterEffettivi($pdo, $dataStr, $tipoParam, $saltoRiposoId));
    }
}

// ── Carica dati per la pagina ────────────────────────────────

// Sedi con le loro posizioni
$sedi = $pdo->query(
    "SELECT * FROM sedi ORDER BY ordine"
)->fetchAll();

$posizioni = $pdo->query(
    "SELECT p.*, s.codice AS sede_codice
     FROM posizioni p
     JOIN sedi s ON s.id = p.sede_id
     ORDER BY p.sede_id, p.ordine"
)->fetchAll();

// Raggruppa posizioni per sede
$posizioniPerSede = [];
foreach ($posizioni as $pos) {
    $posizioniPerSede[$pos['sede_id']][] = $pos;
}

// Tutto il personale attivo con qualifica e salto
$tuttoPersonale = $pdo->query(
    "SELECT v.*, q.codice AS qcodice,
            st.codice AS salto_codice, st.id AS salto_id_val,
            s.codice AS sede_codice, s.nome AS sede_nome,
            (
                SELECT MAX(p.tipo) FROM vigili_patenti vp
                JOIN patenti p ON p.id = vp.patente_id
                WHERE vp.vigile_id = v.id
            ) AS patente_max
     FROM vigili v
     JOIN qualifiche q   ON q.id  = v.qualifica_id
     JOIN salti_turno st ON st.id = v.salto_id
     JOIN sedi s         ON s.id  = v.sede_id
     WHERE v.attivo = 1
     ORDER BY q.id DESC, v.cognome ASC, v.disambiguatore ASC"
)->fetchAll();


// Assegnazioni esistenti per questo foglio
$assegnazioni = $pdo->prepare(
    "SELECT a.*, v.cognome, v.nome, v.disambiguatore,
            q.codice AS qcodice,
            st.codice AS salto_codice,
            s.codice AS sede_codice,
            s.nome AS sede_nome,
            (
                SELECT MAX(p.tipo) FROM vigili_patenti vp
                JOIN patenti p ON p.id = vp.patente_id
                WHERE vp.vigile_id = v.id
            ) AS patente_max
     FROM assegnazioni a
     JOIN vigili v       ON v.id  = a.vigile_id
     JOIN qualifiche q   ON q.id  = v.qualifica_id
     JOIN salti_turno st ON st.id = v.salto_id
     JOIN sedi s         ON s.id  = v.sede_id
     WHERE a.foglio_id = ?
     ORDER BY a.posizione_id, a.ordine"
);

$assegnazioni->execute([$foglioId]);
$assegnazioni = $assegnazioni->fetchAll();

// Raggruppa assegnazioni per posizione
$assPerPosizione = [];
foreach ($assegnazioni as $ass) {
    $assPerPosizione[$ass['posizione_id']][] = $ass;
}

// Vigili assegnati (set di id)
$vigiliAssegnati = array_unique(array_column($assegnazioni, 'vigile_id'));

// Assenze
$stmtAss = $pdo->prepare(
    "SELECT a.*, v.cognome, v.nome, v.disambiguatore,
            q.codice AS qcodice,
            ta.nome AS tipo_nome, ta.codice AS tipo_codice,
            s.codice AS sede_codice, s.nome AS sede_nome,
            (
                SELECT MAX(p.tipo) FROM vigili_patenti vp
                JOIN patenti p ON p.id = vp.patente_id
                WHERE vp.vigile_id = v.id
            ) AS patente_max
     FROM assenze a
     JOIN vigili v        ON v.id  = a.vigile_id
     JOIN qualifiche q    ON q.id  = v.qualifica_id
     JOIN tipo_assenza ta ON ta.id = a.tipo_assenza_id
     JOIN sedi s          ON s.id  = v.sede_id
     WHERE a.foglio_id = ?
     ORDER BY ta.id, v.cognome"
);

$stmtAss->execute([$foglioId]);
$assenze = $stmtAss->fetchAll();


// Raggruppa assenze per tipo
$assenzePerTipo = [];
foreach ($assenze as $ass) {
    $assenzePerTipo[$ass['tipo_codice']][] = $ass;
}

// Vigili assenti (set di id)
$vigiliAssenti = array_unique(array_column($assenze, 'vigile_id'));

// Vigili in salto
$stmtSalto = $pdo->prepare(
    "SELECT ss.*, v.cognome, v.nome, v.disambiguatore,
            q.codice AS qcodice, st.codice AS salto_codice
     FROM salto_servizio ss
     JOIN vigili v       ON v.id  = ss.vigile_id
     JOIN qualifiche q   ON q.id  = v.qualifica_id
     JOIN salti_turno st ON st.id = v.salto_id
     WHERE ss.foglio_id = ?
     ORDER BY v.cognome"
);
$stmtSalto->execute([$foglioId]);
$vigiliInSalto = $stmtSalto->fetchAll();

// Chi è in salto su questo foglio — calcolo ROBUSTO. NON dipende dal fatto che
// salto_servizio sia stato pre-popolato: quella tabella resta vuota per i fogli
// creati dal bot (richiesta ferie), per quelli mai pre-popolati e dopo un reset
// → prima i nomi sparivano dal riquadro salto al ricaricamento. Ora:
//   base = riposanti di diritto (canonico ± scambi salto) via resterEffettivi
//   + chi è stato messo in salto a mano (salto_servizio)
//   − chi è in ferie/assenza (se assente non è a riposo)
// STR (richiamato) = riposante assegnato a una posizione, o flag su salto_servizio.
$setAssegnati    = array_flip(array_map('intval', $vigiliAssegnati));
$setAssenti      = array_flip(array_map('intval', $vigiliAssenti));
$inSaltoSet      = [];
$saltoRichiamato = [];
foreach (array_keys(resterEffettivi($pdo, $dataStr, $tipoParam, $saltoRiposoId)) as $vid) {
    $vid = (int)$vid;
    if (isset($setAssenti[$vid])) continue;
    $inSaltoSet[$vid]      = true;
    $saltoRichiamato[$vid] = isset($setAssegnati[$vid]) ? 1 : 0;
}
foreach ($vigiliInSalto as $r) {
    $vid = (int)$r['vigile_id'];
    if (isset($setAssenti[$vid]) || isset($inSaltoSet[$vid])) continue;
    $inSaltoSet[$vid]      = true;
    $saltoRichiamato[$vid] = (int)$r['richiamato'] ? 1 : (isset($setAssegnati[$vid]) ? 1 : 0);
}
$idVigiliInSalto = array_keys($inSaltoSet);

// Scambi salto attivi per questa data/turno: chi ENTRA (prende il salto) e chi CEDE
// (torna in servizio). Servono solo per marcare le card con un badge 🔄.
$scambioIn = []; $scambioOut = [];
$stSc = $pdo->prepare(
    "SELECT vigile_in_id, vigile_out_id FROM salto_override
     WHERE data=? AND tipo=? AND attivo=1"
);
$stSc->execute([$dataStr, $tipoParam]);
foreach ($stSc->fetchAll() as $r) {
    $scambioIn[(int)$r['vigile_in_id']]   = true;
    $scambioOut[(int)$r['vigile_out_id']] = true;
}

// Vigili disponibili (non assegnati, non assenti, non in salto)
$vigiliOccupati = array_unique(array_merge(
    $vigiliAssegnati,
    $vigiliAssenti,
    $idVigiliInSalto
));

// Vigili con salto canonico di riposo oggi
$stSaltoCanon = $pdo->prepare("SELECT id FROM salti_turno WHERE codice=?");
$stSaltoCanon->execute([$codSaltoRip]);
$idSaltoRiposo = (int)($stSaltoCanon->fetchColumn() ?: 0);

// Vigili con ferie RESPINTA per questo turno → casella dedicata, fuori dall'organico
$tipiRespinte = ($tipoParam === 'D') ? ['D', 'DN'] : ['N', 'DN'];
$phResp = implode(',', array_fill(0, count($tipiRespinte), '?'));
$stResp = $pdo->prepare(
    "SELECT DISTINCT vigile_id FROM bot_requests
     WHERE data_richiesta=? AND stato='rejected' AND tipo_turno IN ($phResp)"
);
$stResp->execute(array_merge([$dataStr], $tipiRespinte));
$idFerieRespinte = array_map('intval', array_column($stResp->fetchAll(), 'vigile_id'));

// Vigili con ferie DA RICHIESTA bot (pending O approved) per questo turno.
// Solo le ferie SENZA richiesta attiva sono "d'ufficio" (a mano) → coerente con
// $isUfficio. Una ferie da richiesta sta SOLO in colonna Ferie, mai nel box ufficio
// (la ridondanza nel box vale solo per le ferie a mano).
$stApp = $pdo->prepare(
    "SELECT DISTINCT vigile_id FROM bot_requests
     WHERE data_richiesta=? AND stato IN ('pending','approved') AND tipo_turno IN ($phResp)"
);
$stApp->execute(array_merge([$dataStr], $tipiRespinte));
$idFerieRichiesta = array_map('intval', array_column($stApp->fetchAll(), 'vigile_id'));

// La colonna Ferie mostra tutte le FER ($ferieTutte); il box "Ferie d'ufficio"
// solo quelle SENZA richiesta bot attiva (= a mano).
$ferieTutte   = $assenzePerTipo['FER'] ?? [];
$ferieUfficio = array_values(array_filter($ferieTutte,
    fn($a) => !in_array((int)$a['vigile_id'], $idFerieRichiesta)));

// Select per capo servizio e vice (solo Cr e Cs)
$dirigenti = $pdo->query(
    "SELECT v.id, v.cognome, v.disambiguatore, q.codice AS qcodice
     FROM vigili v
     JOIN qualifiche q ON q.id = v.qualifica_id
     WHERE v.attivo=1 AND q.codice IN ('Cr','Cs')
     ORDER BY q.id DESC, v.cognome"
)->fetchAll();

// Tipi assenza (RC escluso: il riposo compensativo è il salto turno → gestito
// dal cambio salto nel riquadro Salto, non come assenza separata)
$tipiAssenza = $pdo->query(
    "SELECT * FROM tipo_assenza WHERE codice <> 'RC' ORDER BY id"
)->fetchAll();

// Sedi per select sede distaccata
$sediSelect = $pdo->query(
    "SELECT codice, nome FROM sedi ORDER BY ordine"
)->fetchAll();

// ── Cambio salto turno (inserimento dal gestionale, bypass Telegram) ──
// A = chi cede il salto (a riposo su questo foglio); B = controparte di un
// altro slot col riposo (data_D) ancora futuro, nello stesso blocco B1→B8.
$slotRiposoOggi  = saltoRiposoNum($dataStr, $tipoParam);
$oggiStr         = date('Y-m-d');
$contropartiSlot = [];   // slot => ['dataD','dataN']
for ($k = 1; $k <= 8; $k++) {
    if ($k === $slotRiposoOggi) continue;
    $sd = slotDatesInBlocco($k, $dataStr);
    if ($sd && $sd[0] > $oggiStr) {
        $contropartiSlot[$k] = ['dataD' => $sd[0], 'dataN' => $sd[1]];
    }
}
// Vigili attivi raggruppati per slot (per i due menu del form)
$vigiliPerSlot = [];
$stVPS = $pdo->query(
    "SELECT v.id, v.cognome, v.nome, v.disambiguatore, q.codice AS qcodice,
            CAST(SUBSTRING(st.codice,2) AS UNSIGNED) AS slot
     FROM vigili v
     JOIN salti_turno st ON st.id = v.salto_id
     JOIN qualifiche  q  ON q.id  = v.qualifica_id
     WHERE v.attivo=1
     ORDER BY v.cognome, v.nome"
);
foreach ($stVPS as $r) {
    $vigiliPerSlot[(int)$r['slot']][] = $r;
}
$cambioSaltoOk = !empty($contropartiSlot) && !empty($vigiliPerSlot[$slotRiposoOggi] ?? []);

// Scambi salto ATTIVI che toccano questo turno (per il tasto annulla)
$stSA = $pdo->prepare(
    "SELECT DISTINCT s.id, s.slot_a, s.slot_b,
            va.cognome AS a_cognome, vb.cognome AS b_cognome
     FROM salto_override o
     JOIN bot_scambi_salto s ON s.id = o.scambio_id
     JOIN vigili va ON va.id = s.vigile_a_id
     JOIN vigili vb ON vb.id = s.vigile_b_id
     WHERE o.data=? AND o.tipo=? AND o.attivo=1 AND s.stato='approvato'
     ORDER BY s.id"
);
$stSA->execute([$dataStr, $tipoParam]);
$scambiAttivi = $stSA->fetchAll();

// Quante ferie PENDING verrebbero approvate generando l'ODT (per la conferma).
// Stessa logica di finalize_ferie: vigili in FER sul foglio con richiesta pending
// per questa data e turno (N→N/DN, D→D/DN).
$tipiFerieAppr = $tipoParam === 'N' ? ['N', 'DN'] : ['D', 'DN'];
$phFA = implode(',', array_fill(0, count($tipiFerieAppr), '?'));
$stFA = $pdo->prepare(
    "SELECT COUNT(DISTINCT a.vigile_id)
     FROM assenze a
     JOIN bot_requests r ON r.vigile_id = a.vigile_id AND r.data_richiesta = ?
          AND r.stato = 'pending' AND r.tipo_turno IN ($phFA)
     WHERE a.foglio_id = ? AND a.tipo_assenza_id = 1"
);
$stFA->execute(array_merge([$dataStr], $tipiFerieAppr, [$foglioId]));
$nFerieDaApprovare = (int)$stFA->fetchColumn();

// Furieri del foglio
$furieri = $pdo->prepare(
    "SELECT v.cognome, v.disambiguatore, q.codice AS qcodice
     FROM foglio_furieri ff
     JOIN vigili v     ON v.id = ff.vigile_id
     JOIN qualifiche q ON q.id = v.qualifica_id
     WHERE ff.foglio_id = ?
     ORDER BY v.cognome"
);
$furieri->execute([$foglioId]);
$furieri = $furieri->fetchAll();

// Helper: etichetta vigile "CS Rossi 4"
function etichettaVigile(array $v): string {
    return ucfirst(strtolower($v['qcodice'] ?? ''))
         . ' ' . ucfirst(strtolower($v['cognome'] ?? ''))
         . ($v['disambiguatore'] ? ' ' . (int)$v['disambiguatore'] : '');
}
// Sigla sede per il badge (solo visualizzazione): Centrale → "C".
function siglaSede(?string $c): string {
    return $c === 'CENTR' ? 'C' : (string)$c;
}
function colorePatentePHP(?string $patente): string {
    switch ($patente) {
        case '4':
        case '3': return '#c0392b';
        case '2': return '#2471a3';
        default:  return '#2c3e50';
    }
}

?>

<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Foglio di Servizio <?= $dataLabel ?></title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/foglio.css?v=<?= @filemtime(__DIR__.'/../assets/css/foglio.css') ?>">
<script defer src="../assets/js/conferma.js?v=<?= @filemtime(__DIR__.'/../assets/js/conferma.js') ?>"></script>
</head>
<body>

<!-- HEADER -->
<header class="header">
  <div class="header-inner">
    <div class="header-logo">🚒</div>
    <div class="header-testi">
      <h1>Comando Provinciale VVF di Genova</h1>
      <p>Gestionale Foglio di Servizio &mdash; Turno B</p>
    </div>
    <div class="header-badge">TURNO&nbsp;B</div>
  </div>
</header>

<!-- NAVBAR -->
<nav class="navbar">
  <div class="navbar-inner">
    <a href="../index.php"        class="nav-btn">🏠 Cruscotto</a>
    <a href="nuovo.php"           class="nav-btn active">📋 Foglio</a>
    <a href="../vigili/lista.php" class="nav-btn">👥 Personale</a>
    <a href="../ferie/index.php"  class="nav-btn">🗓️ Agenda</a>
    <a href="../report/index.php" class="nav-btn">📊 Reportistica</a>
    <a href="../admin/index.php"  class="nav-btn">⚙️ Amministrazione</a>
    <a href="../logout.php"       class="nav-btn ml-auto">🚪 Esci</a>
  </div>
</nav>

<div class="foglio-page">

  <!-- ══ INTESTAZIONE FOGLIO ══════════════════════════════════ -->
  <div class="foglio-header">

    <div class="foglio-header-top">
      <div class="foglio-titolo">
        <h2>Foglio di Servizio del Soccorso</h2>
        <p>Dipartimento VVF del Soccorso Pubblico e Difesa Civile</p>
      </div>

      <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <!-- Badge turno -->
        <div class="foglio-turno-badge <?= $tipoParam==='D' ? 'diurno':'notturno' ?>">
          <span class="badge-tipo">
            <?= $tipoParam==='D' ? '☀️ Diurno':'🌙 Notturno' ?>
          </span>
          <span class="badge-ora"><?= $oraLabel ?></span>
        </div>

        <!-- Data e salto -->
        <div style="text-align:center">
          <div style="font-size:1.4rem;font-weight:800;color:var(--grigio-sc)">
            <?= $dataLabel ?>
          </div>
          <div style="font-size:.78rem;color:var(--grigio-md);margin-top:2px">
            Salto a riposo:
            <strong style="color:var(--rosso)"><?= htmlspecialchars($codSaltoRip) ?></strong>
            &nbsp;|&nbsp; In servizio:
            <strong><?= htmlspecialchars($turnoAttivo['turno'].$turnoAttivo['salto']) ?></strong>
          </div>
        </div>

        <!-- Pulsanti -->
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button id="btnBlocco" onclick="toggleBlocco()" class="btn btn-sm"></button>
          <button onclick="salvaIntestazioneAjax()"
                  class="btn btn-verde btn-sm">💾 Salva</button>
          <a href="stampa.php?id=<?= $foglioId ?>" target="_blank"
             class="btn btn-grigio btn-sm">🖨️ Stampa</a>
          <?php if ($foglioPrec):
              $precLabel = date('d/m/Y', strtotime($foglioPrec['data_servizio']))
                         . ' ' . ($foglioPrec['tipo_turno'] === 'N' ? 'Notturno' : 'Diurno'); ?>
          <button type="button" class="btn btn-grigio btn-sm"
                  onclick="window.open('stampa.php?id=<?= (int)$foglioPrec['id'] ?>','servPrec','width=1000,height=1000,scrollbars=yes,resizable=yes')"
                  title="Apre la stampa del servizio precedente (<?= htmlspecialchars($precLabel) ?>)">
            🕑 Servizio precedente
          </button>
          <?php endif; ?>
          <a href="scarica_odt.php?data=<?= $dataStr ?>&tipo=<?= $tipoParam ?>"
             class="btn btn-grigio btn-sm"
             data-nferie="<?= (int)$nFerieDaApprovare ?>"
             onclick="return scaricaOdt(this)">📄 Scarica .odt</a>
          <a href="../index.php" class="btn btn-grigio btn-sm">← Torna</a>
          <button onclick="apriModalReset()"
                  class="btn btn-sm" style="background:#c0392b;color:#fff">↺ Reset servizio</button>
        </div>

        <!-- Modale reset -->
        <div id="modalReset" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);
             z-index:9999;align-items:center;justify-content:center">
          <div style="background:#fff;border-radius:10px;padding:28px 32px;max-width:360px;
                      width:90%;box-shadow:0 8px 32px rgba(0,0,0,.25);text-align:center">
            <div style="font-size:1.4rem;font-weight:700;margin-bottom:8px">Reset servizio</div>
            <div style="color:#555;margin-bottom:24px">
                Tutte le assegnazioni verranno cancellate e ricostruite da zero.<br><br>
              <strong>Salti e ferie/assenze restano invariati.</strong><br><br>
              Continuare?
            </div>
            <div style="display:flex;gap:12px;justify-content:center">
              <button onclick="chiudiModalReset()"
                      class="btn btn-grigio btn-sm">Annulla</button>
              <button onclick="eseguiReset()"
                      class="btn btn-sm" style="background:#c0392b;color:#fff">Sì, reset</button>
            </div>
          </div>
        </div>
      </div>
    </div><!-- /.foglio-header-top -->

    <!-- Campi intestazione -->
    <form id="formIntestazione">
    <div class="foglio-header-fields">

      <!-- Furieri (sola lettura se già salvati) -->
      <div class="fh-field">
        <div class="fh-label">Furieri</div>
        <div class="fh-value" style="font-size:.78rem">
          <?php if (!empty($furieri)): ?>
            <?php foreach ($furieri as $f): ?>
              <span><?= htmlspecialchars(etichettaVigile($f)) ?></span><br>
            <?php endforeach; ?>
          <?php else: ?>
            <span style="color:#bbb">— non impostati —</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Capo Servizio -->
      <div class="fh-field">
        <div class="fh-label">Capo Servizio</div>
        <div class="fh-value fh-dropzone" id="dropCapoServizio"
             title="Trascina qui un vigile, oppure scegli dalla tendina">
          <select name="capo_servizio_id" id="csId">
            <option value="">— seleziona —</option>
            <?php foreach ($dirigenti as $d): ?>
              <option value="<?= $d['id'] ?>"
                <?= $foglio['capo_servizio_id']==$d['id'] ? 'selected':'' ?>>
                <?= htmlspecialchars(etichettaVigile($d)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Vice Capo Servizio -->
      <div class="fh-field">
        <div class="fh-label">Vice Capo Servizio</div>
        <div class="fh-value fh-dropzone" id="dropViceCapo"
             title="Trascina qui un vigile, oppure scegli dalla tendina">
          <select name="vice_capo_id" id="vcsId">
            <option value="">— seleziona —</option>
            <?php foreach ($dirigenti as $d): ?>
              <option value="<?= $d['id'] ?>"
                <?= $foglio['vice_capo_id']==$d['id'] ? 'selected':'' ?>>
                <?= htmlspecialchars(etichettaVigile($d)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Funzionario -->
      <div class="fh-field">
        <div class="fh-label">Funzionario</div>
        <div class="fh-value">
          <input type="text" name="funzionario" id="funzionario"
                 placeholder="Nome funzionario…"
                 value="<?= htmlspecialchars($foglio['funzionario'] ?? '') ?>">
        </div>
      </div>

      <!-- Note generali -->
      <div class="fh-field" style="grid-column:1/-1">
        <div class="fh-label">Note generali</div>
        <div class="fh-value">
          <input type="text" name="note_generali" id="noteGenerali"
                 placeholder="Note sul turno…"
                 value="<?= htmlspecialchars($foglio['note_generali'] ?? '') ?>">
        </div>
      </div>

    </div>
    </form>

  </div><!-- /.foglio-header -->

  <div id="msgBox"></div>

  <!-- ══ LAYOUT PRINCIPALE ════════════════════════════════════ -->
  <div class="foglio-layout">

    <!-- ── COLONNA SINISTRA (organico + ferie respinte) ────── -->
    <div class="col-sinistra">

    <!-- ── PANNELLO ORGANICO ──────────────────────────────── -->
    <div class="organico-panel">
      <div class="organico-head">
        <span>👥 Organico</span>
        <span id="contatore-liberi" style="font-size:.75rem;opacity:.8"></span>
      </div>
      <input type="text" class="organico-cerca"
             id="cercaOrganico"
             placeholder="🔍 Trova vigile nel foglio…"
             oninput="filtraOrganico(this.value)">
      <div class="organico-list" id="organicoList">
  <?php foreach ($tuttoPersonale as $v):
    $vid          = $v['id'];
    $isSaltoCanon = ((int)$v['salto_id'] === $idSaltoRiposo);
    if ($isSaltoCanon) continue;

    $isAssegnato = in_array($vid, $vigiliAssegnati);
    $isAssente   = in_array($vid, $vigiliAssenti);
    $isInSalto   = in_array($vid, $idVigiliInSalto);
    $occupato    = $isAssegnato || $isAssente || $isInSalto;
    // Il vigile con ferie respinta RESTA in organico (disponibile, trascinabile):
    // la casella "Ferie respinte" è solo un promemoria parallelo, non lavorabile.
    $classeCard  = 'persona-card' . ($occupato ? ' assente' : '');
    $label       = etichettaVigile($v);
    $colore      = colorePatentePHP($v['patente_max'] ?? null);

    // Badge sede: solo per distaccamenti, calcolato QUI dentro il foreach
    $mostraSede  = (!empty($v['sede_nome']) && $v['sede_nome'] !== 'CENTRALE');
?>
    <div class="<?= $classeCard ?>"
         id="pers-<?= $vid ?>"
         data-id="<?= $vid ?>"
         data-nome="<?= htmlspecialchars($label) ?>"
         data-cognome="<?= htmlspecialchars($v['cognome']) ?>"
         data-qualifica="<?= htmlspecialchars($v['qcodice']) ?>"
         data-salto="<?= htmlspecialchars($v['salto_codice']) ?>"
         data-salto-id="<?= (int)$v['salto_id'] ?>"
         data-salto-canon="0"
         <?= $occupato ? '' : 'draggable="true"' ?>>

        <span class="qual-dot <?= htmlspecialchars($v['qcodice']) ?>"></span>

        <span class="persona-nome"
              style="color:<?= $colore ?>">
            <?= htmlspecialchars($label) ?>
            <?php if ($isAssegnato): ?>
                <small style="color:var(--grigio-md)">in servizio</small>
            <?php elseif ($isAssente): ?>
                <small style="color:var(--grigio-md)">assente</small>
            <?php elseif ($isInSalto): ?>
                <small style="color:var(--grigio-md)">in salto</small>
            <?php endif; ?>
            <?php if (isset($scambioOut[$vid])): ?>
                <span class="scambio-badge"
                      style="font-size:.65rem;color:#0a58ca;font-weight:700;margin-left:4px"
                      title="Ha ceduto il salto per scambio turno">🔄 ha ceduto</span>
            <?php endif; ?>
        </span>

        <?php if ($mostraSede): ?>
            <span class="persona-salto">
                <?= htmlspecialchars(siglaSede($v['sede_codice'])) ?>
            </span>
        <?php endif; ?>

    </div>
<?php endforeach; ?>

</div><!-- /.organico-list -->

      <!-- Legenda organico -->
      <div style="padding:8px 10px;border-top:1px solid #e8e8e8;
                  font-size:.68rem;color:var(--grigio-md);line-height:1.8">
        <span style="background:var(--giallo-bg);border:1px solid var(--giallo);
                     padding:1px 5px;border-radius:3px">
          Salto <?= htmlspecialchars($codSaltoRip) ?>
        </span> = riposo canonico
        <br>
        <span class="qual-dot Cr"
              style="display:inline-block;width:8px;height:8px;border-radius:50%;
                     background:var(--rosso)"></span> Cr &nbsp;
        <span class="qual-dot Cs"
              style="display:inline-block;width:8px;height:8px;border-radius:50%;
                     background:var(--blu)"></span> Cs &nbsp;
        <span class="qual-dot Vp"
              style="display:inline-block;width:8px;height:8px;border-radius:50%;
                     background:var(--verde)"></span> Vp
      </div>

    </div><!-- /.organico-panel -->

    <!-- ── PANNELLO FERIE RESPINTE ─────────────────────────── -->
    <?php
      // Promemoria: TUTTI i vigili con ferie respinta per questo turno (anche se
      // poi assegnati in servizio o altrove). Escono solo se la ferie torna approvata
      // (allora lo stato non è più 'rejected' e spariscono da $idFerieRespinte).
      $respinti = array_filter($tuttoPersonale, function ($v) use (
          $idSaltoRiposo, $idFerieRespinte
      ) {
          if ((int)$v['salto_id'] === $idSaltoRiposo) return false;
          return in_array($v['id'], $idFerieRespinte);
      });
    ?>
    <div class="ferie-respinte-panel">
      <div class="ferie-respinte-head">
        <span>🚫 Ferie respinte</span>
        <span id="ferieRespinteCount" style="font-size:.75rem;opacity:.85"><?= count($respinti) ?></span>
      </div>
      <div class="ferie-respinte-list" id="ferieRespinteList">
      <?php if (empty($respinti)): ?>
        <div class="ferie-respinte-vuoto">Nessuna ferie respinta per questo turno.</div>
      <?php else: ?>
        <?php foreach ($respinti as $v):
          $vid    = $v['id'];
          $label  = etichettaVigile($v);
          $colore = colorePatentePHP($v['patente_max'] ?? null);
          $mostraSede = (!empty($v['sede_nome']) && $v['sede_nome'] !== 'CENTRALE');
        ?>
        <div class="persona-card"
             id="resp-<?= $vid ?>"
             data-id="<?= $vid ?>"
             style="cursor:default">
            <span class="qual-dot <?= htmlspecialchars($v['qcodice']) ?>"></span>
            <span class="persona-nome" style="color:<?= $colore ?>">
                <?= htmlspecialchars($label) ?>
                <small style="color:var(--rosso)">ferie respinta</small>
            </span>
            <?php if ($mostraSede): ?>
                <span class="persona-salto"><?= htmlspecialchars(siglaSede($v['sede_codice'])) ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
      </div>
    </div><!-- /.ferie-respinte-panel -->

    <!-- ── PANNELLO FERIE D'UFFICIO (drop zone) ─────────────── -->
    <div class="ferie-ufficio-panel" data-drop-zone="colFerieUfficio">
      <div class="ferie-ufficio-head">
        <span>🏛️ Ferie d'ufficio</span>
        <span id="ferieUfficioCount" style="font-size:.75rem;opacity:.85"><?= count($ferieUfficio) ?></span>
      </div>
      <div class="ferie-ufficio-list" id="colFerieUfficio">
        <?php if (empty($ferieUfficio)): ?>
          <div class="ferie-ufficio-vuoto" id="ferieUfficioVuoto">Trascina qui un vigile per dare ferie d'ufficio.</div>
        <?php endif; ?>
        <?php foreach ($ferieUfficio as $a):
          $colore = colorePatentePHP($a['patente_max'] ?? null);
        ?>
        <div class="assente-row" data-vigile-id="<?= $a['vigile_id'] ?>"
             draggable="true" style="cursor:grab">
            <span class="qual-dot <?= htmlspecialchars($a['qcodice']) ?>"></span>
            <span class="assente-nome" style="color:<?= $colore ?>">
                <?= htmlspecialchars(etichettaVigile($a)) ?>
                <?php if (!empty($a['sede_nome']) && $a['sede_nome'] !== 'CENTRALE'): ?>
                    <span class="persona-salto"><?= htmlspecialchars(siglaSede($a['sede_codice'])) ?></span>
                <?php endif; ?>
            </span>
            <button class="assente-del"
                    onclick="rimuoviFerieUfficio(<?= $a['vigile_id'] ?>)"
                    title="Togli ferie d'ufficio">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
    </div><!-- /.ferie-ufficio-panel -->

    </div><!-- /.col-sinistra -->

    <!-- ── GRIGLIA POSIZIONI (destra) ─────────────────────── -->
    <div class="griglia-wrapper" id="grigliaPosizioni">

      <?php
      // Layout a fasce piene (come ODT): Centrale → Distaccamenti (fascia UNICA)
      // → Aeroporto. I distaccamenti non hanno più un box ciascuno: tutte le loro
      // squadre stanno in un'unica fascia densa (la sigla nel codice posizione
      // identifica già il distaccamento, es. ML-1A). Recupera spazio verticale.
      $posCentrale = []; $posDistacc = []; $posAeroporto = [];
      foreach ($sedi as $sede) {
          $p = $posizioniPerSede[$sede['id']] ?? [];
          if (!$p) continue;
          if      ($sede['codice'] === 'CENTR') $posCentrale  = array_merge($posCentrale,  $p);
          elseif  ($sede['codice'] === 'AP')    $posAeroporto = array_merge($posAeroporto, $p);
          else                                  $posDistacc   = array_merge($posDistacc,   $p);
      }
      // Ordine esplicito Centrale (griglia 7 colonne):
      //   riga 1 = Operativa + serie A + SMZ; riga 2 = serie B + funzionale/autorimessa.
      // Indipendente dal campo `ordine` del DB (che mischiava A e B).
      $ordineCentrale = ['CENTR-OP','1A','2A','3A','4A','5A','1SMZ',
                         '1B','2B-NBCR','3B','4B','1FUN-AUTORADIO','1SOP-AUTORIM'];
      usort($posCentrale, function ($a, $b) use ($ordineCentrale) {
          $ia = array_search($a['codice'], $ordineCentrale, true);
          $ib = array_search($b['codice'], $ordineCentrale, true);
          if ($ia === false) $ia = PHP_INT_MAX;
          if ($ib === false) $ib = PHP_INT_MAX;
          return $ia <=> $ib;
      });
      // Distaccamenti su 2 righe da 5: in fondo (seconda riga) Busalla (BS),
      // Chiavari (CH), Rapallo (RP) ed EL-1SMZ; sopra restano ML/GA/GE/BL.
      // Ordinamento stabile via [rango, indice] (gli altri restano in ordine).
      $distInFondo = ['BS', 'CH', 'RP', 'EL'];
      $i = 0;
      foreach ($posDistacc as &$pd) {
          $inFondo = in_array(substr($pd['codice'], 0, 2), $distInFondo, true) ? 1 : 0;
          $pd['_ord'] = [$inFondo, $i++];
      }
      unset($pd);
      usort($posDistacc, fn($a, $b) => $a['_ord'] <=> $b['_ord']);

      $blocchiGriglia = [
          ['nome' => 'Centrale',      'class' => ' sede-full sede-centrale',      'pos' => $posCentrale,  'head' => true],
          ['nome' => 'Distaccamenti', 'class' => ' sede-full sede-distaccamenti', 'pos' => $posDistacc,   'head' => false],
          ['nome' => 'Aeroporto',     'class' => ' sede-full sede-aeroporto',     'pos' => $posAeroporto, 'head' => true],
      ];
      foreach ($blocchiGriglia as $blocco):
        $posSede = $blocco['pos'];
        if (empty($posSede)) continue;
      ?>
      <div class="sede-block<?= $blocco['class'] ?>">
        <?php if ($blocco['head']): ?>
        <div class="sede-head">
          🏠 <?= htmlspecialchars($blocco['nome']) ?>
          <span style="font-size:.72rem;opacity:.7;margin-left:auto">
            <?= count($posSede) ?> posizioni
          </span>
        </div>
        <?php endif; ?>
        <div class="sede-body">
          <?php foreach ($posSede as $pos):
            $assQui = $assPerPosizione[$pos['id']] ?? [];
            // Tipo per colore testata
            $codPos = strtolower($pos['codice']);
            $tipoHead = 'tipo-a';
            if (str_contains($codPos,'nbcr'))    $tipoHead = 'tipo-nbcr';
            elseif (str_contains($codPos,'nau'))  $tipoHead = 'tipo-nau';
            elseif (str_contains($codPos,'smz'))  $tipoHead = 'tipo-smz';
            elseif (str_contains($codPos,'fun') || str_contains($codPos,'sop')) $tipoHead = 'tipo-fun';
            elseif (str_contains($codPos,'ap-'))  $tipoHead = 'tipo-ap';
            elseif (str_contains($codPos,'el-'))  $tipoHead = 'tipo-el';
            elseif (str_contains($codPos,'op'))   $tipoHead = 'tipo-op';
            elseif (str_contains($codPos,'b'))    $tipoHead = 'tipo-b';
          ?>
          <div class="pos-card"
               id="pos-<?= $pos['id'] ?>"
               data-pos-id="<?= $pos['id'] ?>"
               data-sede="<?= htmlspecialchars($pos['sede_codice'] ?? '') ?>"
               ondragover="event.preventDefault();this.classList.add('drag-over')"
               ondragleave="this.classList.remove('drag-over')"
               ondrop="onDropPosizione(event,<?= $pos['id'] ?>)">

            <div class="pos-head <?= $tipoHead ?>">
              <?= htmlspecialchars($pos['codice']) ?>
            </div>

            <div class="pos-body" id="body-<?= $pos['id'] ?>" data-cap="<?= capPos((int)$pos['id']) ?>">
              <?php foreach ($assQui as $ass):
    $colore = colorePatentePHP($ass['patente_max'] ?? null);
    // Sigla sede SOLO se il vigile è in servizio fuori dalla propria sede:
    // sede del vigile ($ass['sede_codice']) ≠ sede della posizione ($pos['sede_codice']).
    // Es. vigile di Multedo in posizione Multedo → niente sigla; in Centrale → "ML".
    // Stessa logica dell'ODT (FoglioRenderer: sigla solo se vig_sede ≠ pos_sede).
    $mostraSede = (!empty($ass['sede_codice']) && $ass['sede_codice'] !== ($pos['sede_codice'] ?? null));
    $nomeCompleto = ucfirst(strtolower($ass['qcodice'])).' '.ucfirst(strtolower($ass['cognome'])).
                    ($ass['disambiguatore'] ? ' '.$ass['disambiguatore'] : '');
?>
    <div class="ass-card"
         id="ass-<?= $ass['vigile_id'] ?>"
         data-vigile-id="<?= $ass['vigile_id'] ?>"
         data-pos-id="<?= $pos['id'] ?>"
         draggable="true">

        <span class="qual-dot <?= htmlspecialchars($ass['qcodice']) ?>"></span>

        <span class="ass-nome" style="color:<?= $colore ?>"
              title="<?= htmlspecialchars($nomeCompleto) ?>">
            <span class="ass-nome-txt"><?= htmlspecialchars($nomeCompleto) ?></span>
            <?php if ($mostraSede): ?>
                <span class="persona-salto"><?= htmlspecialchars(siglaSede($ass['sede_codice'])) ?></span>
            <?php endif; ?>
            <?php if (isset($scambioOut[(int)$ass['vigile_id']])): ?>
                <span class="scambio-badge"
                      style="font-size:.6rem;color:#0a58ca;font-weight:700"
                      title="Ha ceduto il salto per scambio turno">🔄</span>
            <?php endif; ?>
            <?php if ($ass['in_straordinario']): ?>
                <span style="font-size:.6rem;color:var(--giallo);
                             font-weight:700">STR</span>
            <?php endif; ?>
        </span>

        <button class="remove-btn"
                onclick="rimuoviDaPosizione(<?= $ass['vigile_id'] ?>)"
                title="Rimuovi">✕</button>
    </div>
<?php endforeach; ?>
              <?php // Slot vuoti fino alla capienza ODT della posizione (stessi spazi del modulo)
              for ($i = count($assQui); $i < capPos((int)$pos['id']); $i++): ?>
                <div class="slot-empty"></div>
              <?php endfor; ?>
            </div>

          </div><!-- /.pos-card -->
          <?php endforeach; ?>
        </div><!-- /.sede-body -->
      </div><!-- /.sede-block -->
      <?php endforeach; ?>

    </div><!-- /.griglia-wrapper -->

  </div><!-- /.foglio-layout -->

  <!-- ══ SEZIONE ASSENTI ══════════════════════════════════════ -->
  <div class="assenti-section">
    <div class="assenti-head">
      <span>👤 Personale Assente / Salto Turno</span>
      <button onclick="toggleAssentiForm()"
              class="btn btn-sm btn-grigio">➕ Aggiungi assenza</button>
    </div>

    <!-- Form aggiungi assenza (nascosto di default) -->
    <div id="assenzaForm"
         style="display:none;padding:16px;border-bottom:1px solid #e8e8e8;
                background:#fafafa">
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">

        <div style="display:flex;flex-direction:column;gap:4px;min-width:160px;flex:1">
          <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;
                        color:var(--grigio-md)">Vigile</label>
          <select id="assVigile" style="padding:7px 10px;border:1px solid #d5d8dc;
                  border-radius:5px;font-size:.85rem">
            <option value="">— seleziona —</option>
            <?php foreach ($tuttoPersonale as $v): ?>
              <option value="<?= $v['id'] ?>">
                <?= htmlspecialchars(etichettaVigile($v)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="display:flex;flex-direction:column;gap:4px;min-width:140px">
          <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;
                        color:var(--grigio-md)">Tipo assenza</label>
          <select id="assTipo" style="padding:7px 10px;border:1px solid #d5d8dc;
                  border-radius:5px;font-size:.85rem">
            <?php foreach ($tipiAssenza as $ta): ?>
              <option value="<?= $ta['id'] ?>"
                      data-codice="<?= htmlspecialchars($ta['codice']) ?>">
                <?= htmlspecialchars($ta['nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="campoSedeDist"
             style="display:none;flex-direction:column;gap:4px;min-width:120px">
          <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;
                        color:var(--grigio-md)">Sede distaccata</label>
          <select id="assSede" style="padding:7px 10px;border:1px solid #d5d8dc;
                  border-radius:5px;font-size:.85rem">
            <option value="">— nessuna —</option>
            <?php foreach ($sediSelect as $sd): ?>
              <option value="<?= htmlspecialchars($sd['codice']) ?>">
                <?= htmlspecialchars($sd['nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="campoFerie"
             style="display:none;align-items:flex-end;gap:8px;flex-wrap:wrap">
          <div style="display:flex;flex-direction:column;gap:4px">
            <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;
                          color:var(--grigio-md)">Dal</label>
            <input type="date" id="assDa"
                   style="padding:7px 10px;border:1px solid #d5d8dc;
                          border-radius:5px;font-size:.85rem">
          </div>
          <div style="display:flex;flex-direction:column;gap:4px">
            <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;
                          color:var(--grigio-md)">Al</label>
            <input type="date" id="assA"
                   style="padding:7px 10px;border:1px solid #d5d8dc;
                          border-radius:5px;font-size:.85rem">
          </div>
          <div style="display:flex;flex-direction:column;gap:4px;max-width:80px">
            <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;
                          color:var(--grigio-md)">N° turni</label>
            <input type="number" id="assNrTurni" min="1" max="99"
                   style="padding:7px 10px;border:1px solid #d5d8dc;
                          border-radius:5px;font-size:.85rem">
          </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:4px;min-width:140px;flex:1">
          <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;
                        color:var(--grigio-md)">Note</label>
          <input type="text" id="assNote" placeholder="Note opzionali…"
                 style="padding:7px 10px;border:1px solid #d5d8dc;
                        border-radius:5px;font-size:.85rem">
        </div>

        <div style="display:flex;gap:8px;align-items:flex-end">
          <button onclick="salvaAssenza()" class="btn btn-rosso btn-sm">
            ✔ Conferma
          </button>
          <button onclick="toggleAssentiForm()" class="btn btn-grigio btn-sm">
            ✕
          </button>
        </div>

      </div>
    </div>

    <!-- Griglia assenti per tipo -->
    <div class="assenti-grid" id="grigliaSalto">

     <!-- Salto turno (riposo canonico) — pre-popolato dal PHP -->
<div class="assenti-col">
  <span class="assenti-col-head"
        style="background:var(--giallo);color:#7d5a00">
    😴 Salto <?= htmlspecialchars($codSaltoRip) ?>
  </span>

  <div id="colSalto">

    <?php
    foreach ($tuttoPersonale as $v):
      $vid          = $v['id'];
      if (!in_array($vid, $idVigiliInSalto)) continue;   // verità: salto_servizio (riflette gli scambi)
      $isAssegnatoStr = !empty($saltoRichiamato[$vid]);   // STR = richiamato (straordinario)
      $label        = etichettaVigile($v);
    ?>
      <div class="assente-row"
     id="salto-<?= $vid ?>"
     data-vigile-id="<?= $vid ?>"
     draggable="true"
     style="cursor:grab">
    <span class="qual-dot <?= htmlspecialchars($v['qcodice']) ?>"></span>
    <span class="assente-nome"
          style="color:<?= colorePatentePHP($v['patente_max'] ?? null) ?>">
        <?= htmlspecialchars($label) ?>
        <?php if ($v['sede_nome'] !== 'CENTRALE'): ?>
            <span class="persona-salto">
                <?= htmlspecialchars(siglaSede($v['sede_codice'])) ?>
            </span>
        <?php endif; ?>
        <?php if (isset($scambioIn[$vid])): ?>
            <span class="scambio-badge"
                  style="font-size:.65rem;color:#0a58ca;font-weight:700;margin-left:4px"
                  title="A riposo per scambio salto turno">🔄 scambio</span>
        <?php endif; ?>
        <?php if ($isAssegnatoStr): ?>
            <span class="str-badge"
                  style="font-size:.65rem;color:var(--giallo);
                         font-weight:700;margin-left:4px">★ STR</span>
        <?php endif; ?>
    </span>
    <?php if (!$isAssegnatoStr): ?>
        <span class="drag-icon-salto"
              style="font-size:.75rem;color:var(--grigio-md);
                     margin-left:auto"
              title="Trascina su posizione o assenza">⇄</span>
    <?php endif; ?>
</div>
    <?php endforeach; ?>

    <div class="assenti-drop" id="dropSalto" style="font-size:.7rem">
      ⇄ Trascina qui da organico per mettere in salto
    </div>

    <!-- Cambio salto turno (inserimento manuale, bypass Telegram) -->
    <div style="margin-top:8px;border-top:1px dashed #e0c200;padding-top:8px">
      <button type="button" class="btn btn-sm btn-grigio" style="width:100%"
              onclick="toggleCambioSalto()">🔄 Cambia salto…</button>

      <?php if (!empty($scambiAttivi)): ?>
      <div style="margin-top:8px;display:flex;flex-direction:column;gap:4px">
        <div style="font-size:.66rem;font-weight:700;text-transform:uppercase;
                    color:var(--grigio-md)">Scambi attivi su questo turno</div>
        <?php foreach ($scambiAttivi as $sc): ?>
          <div style="display:flex;align-items:center;gap:6px;font-size:.74rem;
                      background:#fff7d6;border:1px solid #e0c200;border-radius:5px;padding:4px 6px">
            <span style="flex:1">🔄 B<?= (int)$sc['slot_a'] ?> <?= htmlspecialchars(ucfirst(strtolower($sc['a_cognome']))) ?>
                  ⇄ B<?= (int)$sc['slot_b'] ?> <?= htmlspecialchars(ucfirst(strtolower($sc['b_cognome']))) ?></span>
            <button type="button" class="btn btn-grigio btn-sm" style="padding:2px 6px"
                    onclick="annullaScambio(<?= (int)$sc['id'] ?>)"
                    title="Annulla questo scambio">✖️</button>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <script>window.CS_SLOT_A = <?= (int)$slotRiposoOggi ?>;</script>

      <div id="cambioSaltoForm" style="display:none;flex-direction:column;gap:6px;margin-top:8px">
        <?php if (!$cambioSaltoOk): ?>
          <div style="font-size:.7rem;color:var(--grigio-md)">
            Nessuna controparte disponibile nel blocco (riposi già passati o
            nessun vigile dell'altro salto).
          </div>
        <?php else: ?>
          <label style="font-size:.68rem;font-weight:700;text-transform:uppercase;
                        color:var(--grigio-md)">Cede il salto (B<?= (int)$slotRiposoOggi ?>)</label>
          <select id="csVigileA" style="padding:6px;border:1px solid #d5d8dc;
                  border-radius:5px;font-size:.8rem">
            <option value="">— seleziona —</option>
            <?php foreach ($vigiliPerSlot[$slotRiposoOggi] as $v): ?>
              <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars(etichettaVigile($v)) ?></option>
            <?php endforeach; ?>
          </select>

          <label style="font-size:.68rem;font-weight:700;text-transform:uppercase;
                        color:var(--grigio-md)">Entra in salto (riposa al suo posto)</label>
          <select id="csVigileB" style="padding:6px;border:1px solid #d5d8dc;
                  border-radius:5px;font-size:.8rem">
            <option value="">— seleziona —</option>
            <?php foreach ($contropartiSlot as $k => $occ): ?>
              <optgroup label="B<?= (int)$k ?> — riposo <?= date('d/m', strtotime($occ['dataD'])) ?>">
                <?php foreach ($vigiliPerSlot[$k] ?? [] as $v): ?>
                  <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars(etichettaVigile($v)) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>

          <button type="button" class="btn btn-rosso btn-sm"
                  onclick="salvaCambioSalto()">✔ Esegui scambio</button>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>


     <!-- Ferie -->
<div class="assenti-col" data-drop-zone="colFerie">
  <span class="assenti-col-head ac-ferie">🏖️ Ferie</span>
  <div id="colFerie">
          <?php /* Mostra TUTTE le ferie (da Telegram + a mano): le ferie a mano
                   si vedono sia qui sia nel box "Ferie d'ufficio" (ridondanza voluta). */ ?>
          <?php foreach ($ferieTutte as $a):
    $colore = colorePatentePHP($a['patente_max'] ?? null);
?>
    <div class="assente-row" data-vigile-id="<?= $a['vigile_id'] ?>">
        <span class="qual-dot <?= htmlspecialchars($a['qcodice']) ?>"></span>
        <span class="assente-nome" style="color:<?= $colore ?>">
            <?= htmlspecialchars(etichettaVigile($a)) ?>
            <?php if (!empty($a['sede_nome']) && $a['sede_nome'] !== 'CENTRALE'): ?>
                <span class="persona-salto">
                    <?= htmlspecialchars(siglaSede($a['sede_codice'])) ?>
                </span>
            <?php endif; ?>
        </span>
        <span class="assente-info">
            <?= $a['nr_turni'] ? $a['nr_turni'].'T' : '' ?>
            <?= $a['data_da'] ? date('d/m', strtotime($a['data_da'])) : '' ?>
            <?= $a['data_a']  ? '→'.date('d/m', strtotime($a['data_a'])) : '' ?>
        </span>
        <button class="assente-del"
                onclick="rimuoviDaAssenza(<?= $a['vigile_id'] ?>)"
                title="Rimuovi">✕</button>
    </div>
<?php endforeach; ?>

        </div>
      </div>

          <!-- Missione / Permesso -->
      <!-- Missione / Permesso -->
<div class="assenti-col" data-drop-zone="colMissione">
  <span class="assenti-col-head ac-missione">✈️ Missione / Permesso</span>
  <div id="colMissione">
          <?php foreach (array_merge(
              $assenzePerTipo['MISS'] ?? [],
              $assenzePerTipo['PERM'] ?? []
          ) as $a): ?>
            <div class="assente-row">
              <span class="qual-dot <?= htmlspecialchars($a['qcodice']) ?>"></span>
              <span class="assente-nome">
                <?= htmlspecialchars(etichettaVigile($a)) ?>
              </span>
              <span class="assente-info">
                <?= htmlspecialchars($a['tipo_codice']) ?>
              </span>
              <button class="assente-del"
                      onclick="rimuoviDaAssenza(<?= $a['vigile_id'] ?>)"
                      title="Rimuovi">✕</button>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Malattia / Infortunio -->
      <div class="assenti-col" data-drop-zone="colMalattia">
  <span class="assenti-col-head ac-malattia">🤒 Malattia / Infortunio</span>
  <div id="colMalattia">
          <?php foreach (array_merge(
              $assenzePerTipo['MAL'] ?? [],
              $assenzePerTipo['INF'] ?? []
          ) as $a): ?>
            <div class="assente-row">
              <span class="qual-dot <?= htmlspecialchars($a['qcodice']) ?>"></span>
              <span class="assente-nome">
                <?= htmlspecialchars(etichettaVigile($a)) ?>
              </span>
              <span class="assente-info">
                <?= htmlspecialchars($a['tipo_codice']) ?>
              </span>
              <button class="assente-del"
                      onclick="rimuoviDaAssenza(<?= $a['vigile_id'] ?>)"
                      title="Rimuovi">✕</button>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div><!-- /.assenti-grid -->
  </div><!-- /.assenti-section -->

</div><!-- /.foglio-page -->

<!-- ══ DATI JSON PER JAVASCRIPT ═════════════════════════════ -->
<script>
const FOGLIO_ID  = <?= $foglioId ?>;
const FOGLIO_URL = 'nuovo.php?data=<?= $dataStr ?>&tipo=<?= $tipoParam ?>';

const PERSONALE = {
<?php foreach ($tuttoPersonale as $v): ?>
  <?= $v['id'] ?>: {
    id:          <?= $v['id'] ?>,
    nome:        <?= json_encode(etichettaVigile($v)) ?>,
    cognome:     <?= json_encode($v['cognome']) ?>,
    qcodice:     <?= json_encode($v['qcodice']) ?>,
    salto:       <?= json_encode($v['salto_codice']) ?>,
    saltoId:     <?= (int)$v['salto_id'] ?>,
    saltoCanon:  <?= ((int)$v['salto_id'] === $idSaltoRiposo) ? 'true':'false' ?>,
    sede:        <?= json_encode($v['sede_codice']) ?>,
    sedeCentrale:<?= ($v['sede_nome'] === 'CENTRALE') ? 'true':'false' ?>,
    patente:     <?= json_encode($v['patente_max'] ?? '') ?>
  },
<?php endforeach; ?>
};


const TIPI_ASSENZA = {
<?php foreach ($tipiAssenza as $ta):
    $mapCol = [
        'FER'  => 'colFerie',
        'MISS' => 'colMissione',
        'PERM' => 'colMissione',
        'MAL'  => 'colMalattia',
        'INF'  => 'colMalattia',
    ];
?>
  '<?= $ta['codice'] ?>': {
      id:    <?= (int)$ta['id'] ?>,
      colId: '<?= $mapCol[$ta['codice']] ?? 'colMalattia' ?>'
  },
<?php endforeach; ?>
};

const ID_SALTO_RIPOSO = <?= $idSaltoRiposo ?>;

// ── Stato drag ───────────────────────────────────────────────
let _dragId     = null;  // vigile_id corrente
let _dragSource = null;  // 'organico' | 'posizione' | 'salto'

// ════════════════════════════════════════════════════════════
// UTILITY
// ════════════════════════════════════════════════════════════
function showMsg(txt, tipo = 'ok') {
    const b = document.getElementById('msgBox');
    b.innerHTML = `<div class="alert alert-${tipo}">${txt}</div>`;
    setTimeout(() => b.innerHTML = '', 3500);
}

function aggiornaContatore() {
    const tutti    = document.querySelectorAll('#organicoList .persona-card').length;
    const occupati = document.querySelectorAll('#organicoList .persona-card.assente').length;
    const el = document.getElementById('contatore-liberi');
    if (el) el.textContent = (tutti - occupati) + ' disponibili';
}

async function ajax(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));
    const r = await fetch(FOGLIO_URL, { method:'POST', body:fd });
    const json = await r.json();
    // Se il server segnala blocco (es. bloccato da un altro utente), allinea la UI
    if (json && json.bloccato === true && !BLOCCATO) {
        BLOCCATO = true;
        applicaStatoBlocco();
        showMsg('🔒 Foglio bloccato.', 'err');
    }
    return json;
}

// ════════════════════════════════════════════════════════════
// BLOCCO / SBLOCCO FOGLIO (stato condiviso sul server)
// ════════════════════════════════════════════════════════════
let BLOCCATO = <?= $foglioBloccato ? 'true' : 'false' ?>;

function applicaStatoBlocco() {
    const btn = document.getElementById('btnBlocco');
    if (BLOCCATO) {
        document.body.classList.add('cruscotto-bloccato');
        if (btn) { btn.innerHTML = '🔓 Sblocca'; btn.className = 'btn btn-sm btn-verde'; btn.style.cssText = ''; }
    } else {
        document.body.classList.remove('cruscotto-bloccato');
        if (btn) { btn.innerHTML = '🔒 Blocca'; btn.className = 'btn btn-sm'; btn.style.cssText = 'background:#c0392b;color:#fff'; }
    }
}

function toggleBlocco() {
    if (BLOCCATO) {
        chiediConferma({
            titolo:  'Sblocca foglio',
            testo:   'Sbloccare il foglio?<br>Le modifiche torneranno possibili per tutti.',
            okLabel: '🔓 Sblocca',
            okStyle: 'background:var(--verde);color:#fff',
            onOk:    () => eseguiBlocco(false)
        });
    } else {
        eseguiBlocco(true);
    }
}

async function eseguiBlocco(nuovo) {
    const res = await ajax({ azione: 'set_blocco', bloccato: nuovo ? 1 : 0 });
    if (!res.ok) { showMsg('⚠️ Errore.', 'err'); return; }
    BLOCCATO = res.bloccato;
    applicaStatoBlocco();
    showMsg(BLOCCATO ? '🔒 Foglio bloccato.' : '🔓 Foglio sbloccato.');
}

function setOccupato(id, occupato, label) {
    const c = document.getElementById('pers-' + id);
    if (!c) return;
    if (occupato) {
        c.classList.add('assente');
        c.removeAttribute('draggable');
        let s = c.querySelector('small');
        if (!s) { s = document.createElement('small'); c.querySelector('.persona-nome').appendChild(s); }
        s.textContent = label || 'in servizio';
    } else {
        c.classList.remove('assente');
        c.setAttribute('draggable','true');
        const s = c.querySelector('small');
        if (s) s.remove();
    }
    aggiornaContatore();
    updateRespinteCount();
}

// Mantiene gli slot di una posizione = capienza ODT (data-cap): card + placeholder vuoti
function sincronizzaSlot(body) {
    if (!body) return;
    const cap = parseInt(body.dataset.cap || '7');
    body.querySelectorAll('.slot-empty').forEach(s => s.remove());
    const n = body.querySelectorAll('.ass-card').length;
    for (let i = n; i < cap; i++) {
        body.insertAdjacentHTML('beforeend', '<div class="slot-empty"></div>');
    }
}

// Riordino dentro una posizione: trova la card sopra cui inserire quella
// trascinata, in base alla Y del cursore (la card il cui centro sta sotto Y).
// Ritorna null se va in fondo.
function getDragAfterElement(body, y) {
    const cards = [...body.querySelectorAll('.ass-card:not(.dragging)')];
    let closest = { offset: Number.NEGATIVE_INFINITY, element: null };
    for (const card of cards) {
        const box = card.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
            closest = { offset, element: card };
        }
    }
    return closest.element;
}

// ── Placeholder di riordino: il "gap" che mostra dove cadrà la card ──
let _ph = null;
function ensurePlaceholder() {
    if (!_ph) { _ph = document.createElement('div'); _ph.className = 'reorder-ph'; }
    return _ph;
}
function removePlaceholder() {
    _ph?.parentElement?.removeChild(_ph);
    document.querySelectorAll('.pos-card.reorder-target')
        .forEach(el => el.classList.remove('reorder-target'));
    // Ripristina la card trascinata eventualmente nascosta durante il riordino
    document.querySelectorAll('.ass-card.dragging')
        .forEach(c => { c.style.display = ''; });
}
// Durante il dragover: se sto riordinando nella STESSA squadra, apri il gap
// nel punto di inserimento (e nascondo la card trascinata, così resta un solo
// spazio chiaro); altrimenti togli il placeholder.
function gestisciPlaceholderRiordino(target, y) {
    if (_dragSource === 'posizione' && target && target.classList?.contains('pos-card')) {
        const dragged = document.getElementById('ass-' + _dragId);
        if (dragged && parseInt(dragged.dataset.posId) === parseInt(target.dataset.posId)) {
            const body  = document.getElementById('body-' + target.dataset.posId);
            dragged.style.display = 'none';       // libera lo slot originale
            const after = getDragAfterElement(body, y);
            const ph    = ensurePlaceholder();
            if (after) {
                if (ph.nextSibling !== after) body.insertBefore(ph, after);
            } else {
                const firstEmpty = body.querySelector('.slot-empty');
                if (firstEmpty) { if (ph !== firstEmpty.previousSibling) body.insertBefore(ph, firstEmpty); }
                else if (body.lastElementChild !== ph) body.appendChild(ph);
            }
            target.classList.add('reorder-target');
            return;
        }
    }
    removePlaceholder();
}

// Rimuove dal DOM da qualsiasi posto (senza toccare la riga salto canonico)
function rimuoviDOM(id) {
    const p = PERSONALE[id];

    // Da posizione
    const ac = document.getElementById('ass-' + id);
    if (ac) { const body = ac.parentElement; ac.remove(); sincronizzaSlot(body); }

    // Da riga salto canonico: solo badge, non la riga
    const sr = document.getElementById('salto-' + id);
    if (sr) {
        if (p && p.saltoCanon) {
            sr.querySelector('.str-badge')?.remove();
            sr.querySelector('.abil-badge')?.remove();
            if (!sr.querySelector('.drag-icon-salto')) {
                sr.insertAdjacentHTML('beforeend',
                    `<span class="drag-icon-salto"
                           style="font-size:.75rem;color:var(--grigio-md);
                                  margin-left:auto;cursor:grab"
                           title="Trascina su posizione o assenza">⇄</span>`);
            }
            sr.setAttribute('draggable','true');
            sr.style.cursor = 'grab';
        } else {
            sr.remove();
        }
    }

    // Da colonne assenze
    document.querySelectorAll('.assente-row[data-vigile-id="' + id + '"]')
        .forEach(r => {
            // Non rimuovere la riga del salto canonico
            if (r.id !== 'salto-' + id) r.remove();
        });
}


// Restituisce il colore CSS in base alla patente massima
function colorePatente(patente) {
    switch (patente) {
        case '4':
        case '3': return '#c0392b';  // rosso
        case '2': return '#2471a3';  // blu
        case '1':
        default:  return '#2c3e50';  // nero/grigio scuro
    }
}



// Sigla sede per il badge (solo visualizzazione): Centrale → "C".
function siglaSede(c) { return c === 'CENTR' ? 'C' : (c || ''); }

// ════════════════════════════════════════════════════════════
// COSTRUTTORI HTML
// ════════════════════════════════════════════════════════════
function buildAssCard(p, posId, straord) {
    // Sigla sede SOLO se il vigile lavora fuori dalla propria sede:
    // sede del vigile (p.sede) ≠ sede della posizione (data-sede della pos-card).
    const posSede   = document.getElementById('pos-' + posId)?.dataset.sede || '';
    const sedeBadge = (p.sede && p.sede !== posSede)
        ? `<span class="persona-salto">${siglaSede(p.sede)}</span>` : '';
    const strBadge = straord
        ? `<span style="font-size:.6rem;color:var(--giallo);
                        font-weight:700;margin-left:3px">STR</span>` : '';
    const colore = colorePatente(p.patente);

    return `<div class="ass-card"
                 id="ass-${p.id}"
                 data-vigile-id="${p.id}"
                 data-pos-id="${posId}"
                 draggable="true">
              <span class="qual-dot ${p.qcodice}"></span>
              <span class="ass-nome" style="color:${colore}" title="${p.nome}">
                <span class="ass-nome-txt">${p.nome}</span>${sedeBadge}${strBadge}
              </span>
              <button class="remove-btn"
                      onclick="rimuoviDaPosizione(${p.id})"
                      title="Rimuovi">✕</button>
            </div>`;
}


function buildAssenteRow(p, tipoCodice) {
    const sedeBadge = (!p.sedeCentrale && p.sede)
        ? `<span class="persona-salto">${siglaSede(p.sede)}</span>` : '';
    const colore = colorePatente(p.patente);

    return `<div class="assente-row"
                 data-vigile-id="${p.id}">
              <span class="qual-dot ${p.qcodice}"></span>
              <span class="assente-nome"
                    style="color:${colore}">
                ${p.nome}${sedeBadge}
              </span>
              <span class="assente-info"
                    style="font-size:.65rem;color:var(--grigio-md)">
                ${tipoCodice}
              </span>
              <button class="assente-del"
                      onclick="rimuoviDaAssenza(${p.id})"
                      title="Rimetti">✕</button>
            </div>`;
}



// ════════════════════════════════════════════════════════════
// DRAG START — listener unico delegato su tutto il documento
// ════════════════════════════════════════════════════════════
document.addEventListener('dragstart', function(e) {
    if (BLOCCATO) { e.preventDefault(); return; }
    // 1. Da organico (il box "Ferie respinte" è sola lettura, non si trascina)
    const persCard = e.target.closest('#organicoList .persona-card');
    if (persCard && !persCard.classList.contains('assente')) {
        _dragId     = parseInt(persCard.dataset.id);
        _dragSource = 'organico';
        persCard.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', _dragId);
        return;
    }

    // 2. Da ass-card in posizione
    const assCard = e.target.closest('.ass-card');
    if (assCard) {
        _dragId     = parseInt(assCard.dataset.vigileId);
        _dragSource = 'posizione';
        assCard.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', _dragId);
        return;
    }

    // 3. Da riga salto canonico
    const saltoRow = e.target.closest('#colSalto .assente-row[draggable="true"]');
    if (saltoRow) {
        _dragId     = parseInt(saltoRow.dataset.vigileId);
        _dragSource = 'salto';
        saltoRow.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', _dragId);
        return;
    }

    // 4. Da ferie d'ufficio
    const ufficioRow = e.target.closest('#colFerieUfficio .assente-row[draggable="true"]');
    if (ufficioRow) {
        _dragId     = parseInt(ufficioRow.dataset.vigileId);
        _dragSource = 'ufficio';
        ufficioRow.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', _dragId);
        return;
    }

    // Nessuna sorgente valida
    e.preventDefault();
});

document.addEventListener('dragend', function() {
    document.querySelectorAll('.dragging').forEach(el => el.classList.remove('dragging'));
    document.querySelectorAll('.drop-target').forEach(el => el.classList.remove('drop-target'));
    removePlaceholder();
    _stopAutoScroll();
    _dragId     = null;
    _dragSource = null;
});

// ════════════════════════════════════════════════════════════
// AUTO-SCROLL durante il drag: avvicinando il puntatore al bordo
// alto/basso della finestra la pagina scorre, così si raggiungono
// anche le caselle in fondo senza lasciare il trascinamento.
// ════════════════════════════════════════════════════════════
let _autoScrollDir = 0, _autoScrollTimer = null;
function _autoScrollTick() {
    if (_autoScrollDir !== 0) window.scrollBy(0, _autoScrollDir * 14);
}
function _setAutoScroll(clientY) {
    const m = 110;                               // zona sensibile ai bordi (px)
    if (clientY > window.innerHeight - m)      _autoScrollDir = 1;
    else if (clientY < m)                      _autoScrollDir = -1;
    else                                       _autoScrollDir = 0;
    if (_autoScrollDir !== 0 && !_autoScrollTimer)
        _autoScrollTimer = setInterval(_autoScrollTick, 16);
}
function _stopAutoScroll() {
    if (_autoScrollTimer) { clearInterval(_autoScrollTimer); _autoScrollTimer = null; }
    _autoScrollDir = 0;
}

// ════════════════════════════════════════════════════════════
// ZONE DI DROP — listener delegato su tutto il documento
// ════════════════════════════════════════════════════════════
document.addEventListener('dragover', function(e) {
    _setAutoScroll(e.clientY);                    // scorri vicino ai bordi
    const target = getDropTarget(e.target);
    if (target) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        // Evidenzia solo la zona corrente
        document.querySelectorAll('.drop-target')
            .forEach(el => { if (el !== target) el.classList.remove('drop-target'); });
        target.classList.add('drop-target');
    }
    // Riordino stessa squadra: apri/sposta il gap di inserimento
    gestisciPlaceholderRiordino(target, e.clientY);
});

document.addEventListener('dragleave', function(e) {
    const target = getDropTarget(e.target);
    if (target && !target.contains(e.relatedTarget)) {
        target.classList.remove('drop-target');
    }
});

document.addEventListener('drop', async function(e) {
    _stopAutoScroll();
    const target = getDropTarget(e.target);
    if (!target) return;

    e.preventDefault();
    target.classList.remove('drop-target');

    const vigileId = _dragId;
    const source   = _dragSource;
    _dragId        = null;
    _dragSource    = null;

    if (!vigileId) return;
    const p = PERSONALE[vigileId];
    if (!p) return;

    // ── Drag-out da ferie d'ufficio → rimetti in organico ────
    if (source === 'ufficio') {
        if (target.id === 'organicoList') {
            await rimuoviFerieUfficio(vigileId);
        }
        return;
    }

    // ── Drop su Capo Servizio / Vice Capo (barra intestazione) ─
    if (target.id === 'dropCapoServizio' || target.id === 'dropViceCapo') {
        const isCapo = (target.id === 'dropCapoServizio');
        const sel    = document.getElementById(isCapo ? 'csId' : 'vcsId');
        // La tendina contiene solo Cr/Cs: se manca, aggiungi l'opzione al volo
        if (!sel.querySelector(`option[value="${vigileId}"]`)) {
            const opt = document.createElement('option');
            opt.value = vigileId;
            opt.textContent = p.nome;
            sel.appendChild(opt);
        }
        sel.value = String(vigileId);
        await salvaIntestazioneAjax();
        showMsg((isCapo ? '👤 Capo Servizio: ' : '👤 Vice Capo: ') + p.nome);
        return;
    }

    // ── Drop su posizione ────────────────────────────────────
    if (target.classList.contains('pos-card')) {
        const posId   = parseInt(target.dataset.posId);

        // Riordino DENTRO la stessa squadra (per anzianità): se trascino una
        // card già in questa posizione, non riassegno — la sposto e salvo l'ordine.
        const draggedCard = document.getElementById('ass-' + vigileId);
        if (source === 'posizione' && draggedCard
                && parseInt(draggedCard.dataset.posId) === posId) {
            const body = document.getElementById('body-' + posId);
            // Atterra esattamente dove c'è il gap mostrato; se non c'è, ricalcola.
            if (_ph && _ph.parentElement === body) {
                body.insertBefore(draggedCard, _ph);
            } else {
                const after = getDragAfterElement(body, e.clientY);
                if (after) body.insertBefore(draggedCard, after);
                else       body.appendChild(draggedCard);
            }
            removePlaceholder();
            sincronizzaSlot(body);
            const ordini = [...body.querySelectorAll('.ass-card')]
                .map(c => c.dataset.vigileId).join(',');
            const res = await ajax({ azione: 'riordina', posizione_id: posId, ordine: ordini });
            if (!res.ok) { showMsg('⚠️ Errore riordino.', 'err'); return; }
            showMsg('↕️ Ordine aggiornato.');
            return;
        }

        const straord = (source === 'salto' || p.saltoCanon) ? 1 : 0;

        const res = await ajax({
            azione: 'assegna', vigile_id: vigileId,
            posizione_id: posId, straordinario: straord
        });
        if (!res.ok) {
            showMsg(res.pieno ? '🚫 ' + (res.errore || 'Posizione al completo.') + ' ' + p.nome + ' resta tra i disponibili.' : '⚠️ Errore.', 'err');
            return;
        }

        rimuoviDOM(vigileId);

        const body = document.getElementById('body-' + posId);
        if (body) { body.insertAdjacentHTML('beforeend', buildAssCard(p, posId, straord)); sincronizzaSlot(body); }

        // Aggiorna riga salto se STR
        if (p.saltoCanon) {
            const sr = document.getElementById('salto-' + vigileId);
            if (sr) {
                sr.removeAttribute('draggable');
                sr.style.cursor = 'default';
                sr.querySelector('.drag-icon-salto')?.remove();
                const nome = sr.querySelector('.assente-nome');
                if (nome && !nome.querySelector('.str-badge')) {
                    nome.insertAdjacentHTML('beforeend',
                        `<span class="str-badge"
                               style="font-size:.65rem;color:var(--giallo);
                                      font-weight:700;margin-left:4px">★ STR</span>`);
                }
            }
        }

        if (!p.saltoCanon) setOccupato(vigileId, true, 'in servizio');
        showMsg('✅ ' + p.nome + (straord ? ' (STR)':'' ) + ' assegnato.');
        return;
    }

    // ── Drop su zona salto ───────────────────────────────────
    if (target.id === 'colSalto') {
        await azioneSalto(vigileId);
        return;
    }

    // ── Drop su box Ferie d'ufficio ──────────────────────────
    if (target.id === 'colFerieUfficio') {
        const res = await ajax({ azione: 'ferie_ufficio', vigile_id: vigileId });
        if (!res.ok) { showMsg('⚠️ Errore.','err'); return; }

        rimuoviDOM(vigileId);
        document.getElementById('ferieUfficioVuoto')?.remove();
        target.insertAdjacentHTML('beforeend', buildUfficioRow(p));
        // Ridondanza: la ferie a mano si vede anche nella colonna Ferie
        document.getElementById('colFerie')
            ?.insertAdjacentHTML('beforeend', buildAssenteRow(p, 'FER'));
        if (!p.saltoCanon) setOccupato(vigileId, true, 'ferie ufficio');
        updateUfficioCount();
        showMsg('🏛️ ' + p.nome + ' → ferie d\'ufficio (anche in Ferie).');
        return;
    }

    // ── Drop su colonna assenza ──────────────────────────────
    const colId = target.id; // colFerie | colMissione | colMalattia
    // Codice tipo per quella colonna (colMissione→MISS, colMalattia→MAL: il primo
    // che mappa la colonna, come da comportamento storico del drop).
    const tipoCodice = Object.keys(TIPI_ASSENZA).find(
        k => TIPI_ASSENZA[k].colId === colId
    );
    if (!tipoCodice) return;
    await azioneAssenza(vigileId, tipoCodice);
});

// ════════════════════════════════════════════════════════════
// AZIONI VIGILE (riusate da drag&drop e dal menu tasto destro)
// ════════════════════════════════════════════════════════════
async function azioneSalto(vigileId) {
    const p = PERSONALE[vigileId];
    if (!p) return;
    if (p.saltoCanon) { showMsg('ℹ️ ' + p.nome + ' è già a riposo (salto).'); return; }
    const res = await ajax({ azione: 'metti_salto', vigile_id: vigileId });
    if (!res.ok) { showMsg('⚠️ Errore.', 'err'); return; }

    rimuoviDOM(vigileId);
    document.getElementById('colSalto')?.insertAdjacentHTML('beforeend',
        `<div class="assente-row"
              id="salto-${vigileId}"
              data-vigile-id="${vigileId}"
              draggable="true"
              style="cursor:grab">
           <span class="qual-dot ${p.qcodice}"></span>
           <span class="assente-nome">
             ${p.nome}
             ${(!p.sedeCentrale && p.sede) ? `<span class="persona-salto">${siglaSede(p.sede)}</span>` : ''}
           </span>
           <button class="assente-del"
                   onclick="rimuoviDaZonaSalto(${vigileId})"
                   title="Rimetti">✕</button>
         </div>`
    );
    setOccupato(vigileId, true, 'in salto');
    showMsg('😴 ' + p.nome + ' → salto.');
}

async function azioneAssenza(vigileId, tipoCodice) {
    const p    = PERSONALE[vigileId];
    const tipo = TIPI_ASSENZA[tipoCodice];
    if (!p || !tipo) return;
    const colId = tipo.colId;

    const res = await ajax({
        azione:          'assenza',
        vigile_id:       vigileId,
        tipo_assenza_id: tipo.id,
        sede_distaccata: '', data_da: '', data_a: '', nr_turni: '', note: ''
    });
    if (!res.ok) { showMsg('⚠️ Errore.', 'err'); return; }

    rimuoviDOM(vigileId);

    // Aggiorna riga salto se il vigile era a riposo di diritto (salto canonico)
    if (p.saltoCanon) {
        const sr = document.getElementById('salto-' + vigileId);
        if (sr) {
            sr.removeAttribute('draggable');
            sr.style.cursor = 'default';
            sr.querySelector('.drag-icon-salto')?.remove();
            const nome = sr.querySelector('.assente-nome');
            if (nome && !nome.querySelector('.abil-badge')) {
                nome.insertAdjacentHTML('beforeend',
                    `<span class="abil-badge"
                           style="font-size:.65rem;color:#e67e22;
                                  font-weight:700;margin-left:4px">
                       ${tipoCodice}
                     </span>`);
            }
        }
    }

    document.getElementById(colId)
        ?.insertAdjacentHTML('beforeend', buildAssenteRow(p, tipoCodice));

    // Ridondanza ferie a mano: se è una ferie senza richiesta Telegram,
    // mostrala anche nel box "Ferie d'ufficio".
    if (colId === 'colFerie' && res.ufficio) {
        document.getElementById('ferieUfficioVuoto')?.remove();
        document.getElementById('colFerieUfficio')
            ?.insertAdjacentHTML('beforeend', buildUfficioRow(p));
        updateUfficioCount();
    }

    // Riapprovata una ferie respinta → esce dal box "Ferie respinte"
    if (colId === 'colFerie') rimuoviCardRespinta(vigileId);

    if (!p.saltoCanon) setOccupato(vigileId, true, 'assente');
    showMsg('📋 ' + p.nome + ' → ' + tipoCodice + '.');
}

// ════════════════════════════════════════════════════════════
// MENU TASTO DESTRO — azioni rapide su un vigile (Ferie/Permesso/
// Missione/Salto). Riusa azioneAssenza/azioneSalto.
// ════════════════════════════════════════════════════════════
let _ctxVigileId = null;

function buildCtxMenu() {
    if (document.getElementById('ctxMenu')) return;
    const m = document.createElement('div');
    m.id = 'ctxMenu';
    m.className = 'ctx-menu';
    m.style.display = 'none';
    m.innerHTML =
        `<div class="ctx-nome" id="ctxNome"></div>
         <button type="button" data-act="FER">🏖️ Ferie</button>
         <button type="button" data-act="PERM">📄 Permesso</button>
         <button type="button" data-act="MISS">✈️ Missione</button>
         <button type="button" data-act="SALTO">😴 Salto</button>`;
    document.body.appendChild(m);
    m.addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-act]');
        if (!btn) return;
        const act = btn.dataset.act;
        const vid = _ctxVigileId;
        nascondiCtxMenu();
        if (vid == null) return;
        if (act === 'SALTO') await azioneSalto(vid);
        else                 await azioneAssenza(vid, act);
    });
}

function mostraCtxMenu(x, y, vigileId) {
    buildCtxMenu();
    _ctxVigileId = vigileId;
    const p = PERSONALE[vigileId];
    const m = document.getElementById('ctxMenu');
    const nome = document.getElementById('ctxNome');
    if (nome) nome.textContent = p ? p.nome : '';
    m.style.display = 'block';
    // Tieni il menu dentro la finestra
    const mw = m.offsetWidth, mh = m.offsetHeight;
    m.style.left = Math.min(x, window.innerWidth  - mw - 8) + 'px';
    m.style.top  = Math.min(y, window.innerHeight - mh - 8) + 'px';
}

function nascondiCtxMenu() {
    const m = document.getElementById('ctxMenu');
    if (m) m.style.display = 'none';
    _ctxVigileId = null;
}

document.addEventListener('contextmenu', function(e) {
    if (BLOCCATO) return;  // foglio bloccato da altri → niente azioni
    // Solo card vigile lavorabili: disponibili in organico e assegnati in posizione.
    // (Il box "Ferie respinte" è sola lettura → escluso.)
    const card = e.target.closest('#organicoList .persona-card, .ass-card');
    if (!card) return;     // fuori da una card → menu nativo del browser
    const vid = parseInt(card.dataset.id || card.dataset.vigileId);
    if (!vid || !PERSONALE[vid]) return;
    e.preventDefault();
    mostraCtxMenu(e.clientX, e.clientY, vid);
});

document.addEventListener('click', nascondiCtxMenu);
document.addEventListener('scroll', nascondiCtxMenu, true);
window.addEventListener('resize', nascondiCtxMenu);
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') nascondiCtxMenu(); });

// ════════════════════════════════════════════════════════════
// FUNZIONE CHIAVE: identifica la zona di drop dall'elemento
// sotto il cursore — risale il DOM finché trova una zona valida
// ════════════════════════════════════════════════════════════
function getDropTarget(el) {
    while (el && el !== document.body) {
        // Organico (per drag-out da ferie ufficio)
        if (el.id === 'organicoList' || el.closest?.('#organicoList')) {
            return document.getElementById('organicoList');
        }
        // Posizione/squadra
        if (el.classList && el.classList.contains('pos-card')) return el;
        // Zona salto — intercetta sia il div interno che il contenitore
        if (el.id === 'colSalto') return el;
        if (el.closest && el.closest('#colSalto')) {
            return document.getElementById('colSalto');
        }
        // Capo Servizio / Vice Capo (barra intestazione)
        if (el.id === 'dropCapoServizio' || el.id === 'dropViceCapo') return el;
        if (el.closest && el.closest('#dropCapoServizio')) {
            return document.getElementById('dropCapoServizio');
        }
        if (el.closest && el.closest('#dropViceCapo')) {
            return document.getElementById('dropViceCapo');
        }
        // Colonne assenze — intercetta qualsiasi figlio
        // usando data-drop-zone sul contenitore .assenti-col
        const colParent = el.closest && el.closest('[data-drop-zone]');
        if (colParent) {
            const colId = colParent.dataset.dropZone;
            return document.getElementById(colId);
        }
        el = el.parentElement;
    }
    return null;
}

// ════════════════════════════════════════════════════════════
// RIMOZIONI
// ════════════════════════════════════════════════════════════
async function rimuoviDaPosizione(vigileId) {
    const res = await ajax({ azione: 'rimuovi', vigile_id: vigileId });
    if (!res.ok) { showMsg('⚠️ Errore.','err'); return; }
    rimuoviDOM(vigileId);
    const p = PERSONALE[vigileId];
    if (p && !p.saltoCanon) setOccupato(vigileId, false);
    showMsg('↩️ Rimosso dalla posizione.');
}

async function rimuoviDaAssenza(vigileId) {
    const res = await ajax({ azione: 'rimuovi_assenza', vigile_id: vigileId });
    if (!res.ok) { showMsg('⚠️ Errore.','err'); return; }
    rimuoviDOM(vigileId);          // toglie la card sia da Ferie sia da Ferie d'ufficio
    updateUfficioCount();
    const p = PERSONALE[vigileId];
    if (res.era_richiesta) {
        // Solo le ferie DA RICHIESTA Telegram diventano "respinte"
        spostaInFerieRespinte(vigileId);
        showMsg('🚫 ' + (p ? p.nome : 'Vigile') + ' → ferie respinta.');
    } else {
        // Ferie a mano: si toglie e basta, la persona torna disponibile
        if (p && !p.saltoCanon) setOccupato(vigileId, false);
        showMsg('↩️ Rimesso disponibile.');
    }
}

// Ferie respinta (deny da foglio): il vigile torna DISPONIBILE in organico
// (card normale, trascinabile) e compare un promemoria sola-lettura nel box.
function spostaInFerieRespinte(vigileId) {
    const p = PERSONALE[vigileId];
    if (p && !p.saltoCanon) setOccupato(vigileId, false);
    aggiungiCardRespinta(vigileId);
}

// Aggiunge la card promemoria (non trascinabile) nel box "Ferie respinte".
function aggiungiCardRespinta(vigileId) {
    const list = document.getElementById('ferieRespinteList');
    const p    = PERSONALE[vigileId];
    if (!list || !p) return;
    if (document.getElementById('resp-' + vigileId)) return; // già presente
    list.querySelector('.ferie-respinte-vuoto')?.remove();
    const sedeBadge = (!p.sedeCentrale && p.sede)
        ? `<span class="persona-salto">${siglaSede(p.sede)}</span>` : '';
    list.insertAdjacentHTML('beforeend',
        `<div class="persona-card" id="resp-${p.id}" data-id="${p.id}" style="cursor:default">
            <span class="qual-dot ${p.qcodice}"></span>
            <span class="persona-nome" style="color:${colorePatente(p.patente)}">
                ${p.nome}<small style="color:var(--rosso)">ferie respinta</small>
            </span>${sedeBadge}
         </div>`);
    updateRespinteCount();
}

// Rimuove la card promemoria dal box (quando la ferie torna approvata).
function rimuoviCardRespinta(vigileId) {
    document.getElementById('resp-' + vigileId)?.remove();
    const list = document.getElementById('ferieRespinteList');
    if (list && !list.querySelector('.persona-card')
            && !list.querySelector('.ferie-respinte-vuoto')) {
        list.insertAdjacentHTML('beforeend',
            '<div class="ferie-respinte-vuoto">Nessuna ferie respinta per questo turno.</div>');
    }
    updateRespinteCount();
}

function updateRespinteCount() {
    const list = document.getElementById('ferieRespinteList');
    if (!list) return;
    const n = list.querySelectorAll('.persona-card:not(.assente)').length;
    const badge = document.getElementById('ferieRespinteCount');
    if (badge) badge.textContent = n;
}

// ── Ferie d'ufficio ──────────────────────────────────────────
function buildUfficioRow(p) {
    const sedeBadge = (!p.sedeCentrale && p.sede)
        ? `<span class="persona-salto">${siglaSede(p.sede)}</span>` : '';
    const colore = colorePatente(p.patente);
    return `<div class="assente-row" data-vigile-id="${p.id}"
                 draggable="true" style="cursor:grab">
              <span class="qual-dot ${p.qcodice}"></span>
              <span class="assente-nome" style="color:${colore}">${p.nome}${sedeBadge}</span>
              <button class="assente-del" onclick="rimuoviFerieUfficio(${p.id})"
                      title="Togli ferie d'ufficio">✕</button>
            </div>`;
}

function updateUfficioCount() {
    const list = document.getElementById('colFerieUfficio');
    if (!list) return;
    const badge = document.getElementById('ferieUfficioCount');
    if (badge) badge.textContent = list.querySelectorAll('.assente-row').length;
}

async function rimuoviFerieUfficio(vigileId) {
    const res = await ajax({ azione: 'rimuovi_ufficio', vigile_id: vigileId });
    if (!res.ok) { showMsg('⚠️ Errore.','err'); return; }
    rimuoviDOM(vigileId);
    const p = PERSONALE[vigileId];
    if (p && !p.saltoCanon) setOccupato(vigileId, false);
    updateUfficioCount();
    showMsg('↩️ Ferie d\'ufficio rimossa.');
}

async function rimuoviDaZonaSalto(vigileId) {
    const res = await ajax({ azione: 'rimuovi', vigile_id: vigileId });
    if (!res.ok) { showMsg('⚠️ Errore.','err'); return; }
    rimuoviDOM(vigileId);
    const p = PERSONALE[vigileId];
    if (p && !p.saltoCanon) setOccupato(vigileId, false);
    showMsg('↩️ Rimesso in organico.');
}

// ════════════════════════════════════════════════════════════
// FORM ASSENZA MANUALE
// ════════════════════════════════════════════════════════════
function toggleAssentiForm() {
    const f = document.getElementById('assenzaForm');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}

document.getElementById('assTipo').addEventListener('change', function() {
    const codice = this.options[this.selectedIndex].dataset.codice;
    document.getElementById('campoSedeDist').style.display =
        codice === 'RC'  ? 'flex' : 'none';
    document.getElementById('campoFerie').style.display =
        codice === 'FER' ? 'flex' : 'none';
});

async function salvaAssenza() {
    if (BLOCCATO) { showMsg('🔒 Foglio bloccato.', 'err'); return; }
    const vigileId      = parseInt(document.getElementById('assVigile').value);
    const tipoAssenzaId = parseInt(document.getElementById('assTipo').value);
    if (!vigileId) { showMsg('⚠️ Seleziona un vigile.','err'); return; }

    const res = await ajax({
        azione:          'assenza',
        vigile_id:       vigileId,
        tipo_assenza_id: tipoAssenzaId,
        sede_distaccata: document.getElementById('assSede').value,
        data_da:         document.getElementById('assDa').value,
        data_a:          document.getElementById('assA').value,
        nr_turni:        document.getElementById('assNrTurni').value,
        note:            document.getElementById('assNote').value,
    });

    if (res.ok) {
        rimuoviDOM(vigileId);
        const p = PERSONALE[vigileId];
        if (p) {
            // Trova la colonna corretta dal tipo assenza selezionato
            const tipoSel = document.getElementById('assTipo');
            const tipoCodice = tipoSel.options[tipoSel.selectedIndex]?.dataset?.codice || '';
            const colId = (TIPI_ASSENZA[tipoCodice] || {}).colId;
            if (colId) {
                document.getElementById(colId)
                    ?.insertAdjacentHTML('beforeend', buildAssenteRow(p, tipoCodice));
            }
            // Ridondanza ferie a mano: anche nel box "Ferie d'ufficio"
            if (colId === 'colFerie' && res.ufficio) {
                document.getElementById('ferieUfficioVuoto')?.remove();
                document.getElementById('colFerieUfficio')
                    ?.insertAdjacentHTML('beforeend', buildUfficioRow(p));
                updateUfficioCount();
            }
            if (!p.saltoCanon) setOccupato(vigileId, true, 'assente');
        }
        toggleAssentiForm();
        showMsg('✅ Assenza registrata.');
    } else {
        showMsg('⚠️ Errore.','err');
    }
}

// ════════════════════════════════════════════════════════════
// SALVA INTESTAZIONE
// ════════════════════════════════════════════════════════════
function apriModalReset() {
    if (BLOCCATO) { showMsg('🔒 Foglio bloccato.', 'err'); return; }
    const m = document.getElementById('modalReset');
    m.style.display = 'flex';
}
function chiudiModalReset() {
    document.getElementById('modalReset').style.display = 'none';
}
async function eseguiReset() {
    chiudiModalReset();
    showMsg('Reset in corso…', 'ok');
    const res = await ajax({ azione: 'reset_foglio' });
    if (res.ok) {
        location.reload();
    } else {
        showMsg('⚠️ Errore durante il reset.', 'err');
    }
}

// ════════════════════════════════════════════════════════════
// CAMBIO SALTO TURNO (inserimento manuale dal foglio)
// ════════════════════════════════════════════════════════════
function toggleCambioSalto() {
    const f = document.getElementById('cambioSaltoForm');
    if (!f) return;
    f.style.display = (f.style.display === 'none' || !f.style.display) ? 'flex' : 'none';
}

async function salvaCambioSalto() {
    if (BLOCCATO) { showMsg('🔒 Foglio bloccato.', 'err'); return; }
    const selA = document.getElementById('csVigileA');
    const selB = document.getElementById('csVigileB');
    const a = parseInt(selA?.value);
    const b = parseInt(selB?.value);
    if (!a || !b) { showMsg('⚠️ Seleziona entrambi i vigili.', 'err'); return; }
    if (a === b) { showMsg('⚠️ Sono lo stesso vigile.', 'err'); return; }

    // Riepilogo per la conferma
    const nomeA = selA.options[selA.selectedIndex]?.text || '';
    const optB  = selB.options[selB.selectedIndex];
    const nomeB = optB?.text || '';
    const grpB  = optB?.parentNode?.label || '';   // es. "B6 — riposo 12/07"
    chiediConferma({
        titolo:  'Conferma scambio salto',
        testo:   `Stai scambiando il salto:<br><br>` +
                 `• <b>${nomeA}</b> (B${window.CS_SLOT_A || '?'}) cede il riposo e torna in servizio<br>` +
                 `• <b>${nomeB}</b> (${grpB}) va a riposo al suo posto<br><br>` +
                 `E viceversa sull'altra data del blocco. Confermi?`,
        okLabel: '🔄 Esegui scambio',
        okStyle: 'background:var(--rosso);color:#fff',
        onOk: async () => {
            const res = await ajax({ azione: 'scambia_salto', vigile_a_id: a, vigile_b_id: b });
            if (res.ok) {
                showMsg('✅ Salto scambiato.');
                setTimeout(() => location.reload(), 500);
            } else {
                showMsg('⚠️ ' + (res.errore || 'Errore.'), 'err');
            }
        }
    });
}

async function annullaScambio(scambioId) {
    if (BLOCCATO) { showMsg('🔒 Foglio bloccato.', 'err'); return; }
    chiediConferma({
        titolo:  'Annulla scambio salto',
        testo:   'Annulli questo scambio? I due vigili tornano alla situazione di partenza ' +
                 'su tutte le date interessate.',
        okLabel: '✖️ Annulla scambio',
        okStyle: 'background:var(--rosso);color:#fff',
        onOk: async () => {
            const res = await ajax({ azione: 'annulla_scambio', scambio_id: scambioId });
            if (res.ok) {
                showMsg('✅ Scambio annullato.');
                setTimeout(() => location.reload(), 500);
            } else {
                showMsg('⚠️ ' + (res.errore || 'Errore.'), 'err');
            }
        }
    });
}

// Scarica ODT = chiude il servizio: se ci sono ferie pending da approvare, chiede conferma.
function scaricaOdt(a) {
    const n = parseInt(a.dataset.nferie || '0');
    if (n > 0) {
        chiediConferma({
            titolo:  'Genera ODT e approva ferie',
            testo:   `Generando l'ODT <b>approvi ${n} fer${n === 1 ? 'ia' : 'ie'}</b> di questo turno e ` +
                     `<b>notifichi i vigili</b> (Telegram + mail). L'operazione è registrata.<br><br>Procedere?`,
            okLabel: '📄 Genera e approva',
            okStyle: 'background:var(--rosso);color:#fff',
            onOk:    () => { window.location.href = a.href; },
        });
        return false;   // blocca il download: parte solo dopo conferma
    }
    return true;        // nessuna ferie pending → download diretto
}

async function salvaIntestazioneAjax() {
    if (BLOCCATO) { showMsg('🔒 Foglio bloccato.', 'err'); return; }
    const res = await ajax({
        azione:           'salva_intestazione',
        capo_servizio_id: document.getElementById('csId').value,
        vice_capo_id:     document.getElementById('vcsId').value,
        funzionario:      document.getElementById('funzionario').value,
        note_generali:    document.getElementById('noteGenerali').value,
    });
    showMsg(res.ok ? '✅ Intestazione salvata.' : '⚠️ Errore.', res.ok ? 'ok':'err');
}

// ════════════════════════════════════════════════════════════
// FILTRO ORGANICO
// ════════════════════════════════════════════════════════════
function filtraOrganico(testo) {
    // Ricerca su TUTTO il foglio: evidenzia dove si trova il vigile (assegnato,
    // assente o tra i disponibili) e oscura il resto. Scorre al primo risultato.
    const t = testo.toLowerCase().trim();
    const cards = document.querySelectorAll(
        '.ass-card[data-vigile-id], .assente-row[data-vigile-id], #organicoList .persona-card'
    );
    let primoFoglio = null, primoQualsiasi = null;
    cards.forEach(card => {
        const id = card.dataset.vigileId || card.dataset.id;
        const p  = id ? PERSONALE[id] : null;
        const cognome = ((p && p.cognome) ? p.cognome : (card.dataset.cognome || '')).toLowerCase();
        const match = t !== '' && cognome.startsWith(t);
        card.classList.toggle('trovato', match);
        card.classList.toggle('dimmed', t !== '' && !match);
        if (match) {
            if (!primoQualsiasi) primoQualsiasi = card;
            if (!primoFoglio && !card.classList.contains('persona-card')) primoFoglio = card;
        }
    });
    const target = primoFoglio || primoQualsiasi;
    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ════════════════════════════════════════════════════════════
// AGGIUNGE STILE DROP-TARGET AL CSS DINAMICAMENTE
// ════════════════════════════════════════════════════════════
const styleEl = document.createElement('style');
styleEl.textContent = `
    .pos-card.drop-target {
        border-color: var(--rosso) !important;
        background: #fff0ee !important;
        box-shadow: 0 0 0 2px rgba(192,57,43,.25) !important;
    }
    #colSalto.drop-target,
    #colFerie.drop-target,
    #colMissione.drop-target,
    #colMalattia.drop-target {
        background: #fff0ee !important;
        outline: 2px dashed var(--rosso) !important;
        border-radius: 4px;
    }
    .fh-dropzone { border-radius: 6px; transition: background .12s, outline .12s; }
    .fh-dropzone.drop-target {
        background: #fff0ee !important;
        outline: 2px dashed var(--rosso) !important;
    }
`;
document.head.appendChild(styleEl);

// ════════════════════════════════════════════════════════════
// INIT
// ════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function() {
    aggiornaContatore();
    applicaStatoBlocco();

    // Rimuovi tutti gli handler inline dalle pos-card
    // (ora gestiti dal listener delegato sul documento)
    document.querySelectorAll('.pos-card').forEach(card => {
        card.removeAttribute('ondragover');
        card.removeAttribute('ondragleave');
        card.removeAttribute('ondrop');
    });
});
</script>




</body>
</html>
