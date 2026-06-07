<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/turni.php';

$pdo = getDB();

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

// ── Helper: pre-popola assegnazioni e salto per un foglio ────
function prepopolaAssegnazioni(PDO $pdo, int $foglioId, int $saltoRiposoId): void {
    $pdo->prepare("DELETE FROM assegnazioni WHERE foglio_id=?")->execute([$foglioId]);

    // Posizione da montaggio ODT per questo salto canonico
    $tmpl = [];
    $st = $pdo->prepare("SELECT vigile_id, posizione_id FROM posizione_template WHERE salto_canoni=?");
    $st->execute([$saltoRiposoId]);
    foreach ($st->fetchAll() as $r) {
        $tmpl[(int)$r['vigile_id']] = (int)$r['posizione_id'];
    }

    // Fallback: posizione principale per sede
    $posPerSede = [];
    foreach ($pdo->query(
        "SELECT p.id, p.sede_id
         FROM posizioni p
         INNER JOIN (SELECT sede_id, MIN(ordine) min_ordine FROM posizioni GROUP BY sede_id) pm
         ON p.sede_id=pm.sede_id AND p.ordine=pm.min_ordine"
    )->fetchAll() as $pp) {
        $posPerSede[(int)$pp['sede_id']] = (int)$pp['id'];
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
    $ordine    = [];
    $stA       = $pdo->prepare("INSERT IGNORE INTO assegnazioni (id,foglio_id,posizione_id,vigile_id,ordine,in_straordinario) VALUES (?,?,?,?,?,0)");

    foreach ($personale as $vp) {
        $vid     = (int)$vp['id'];
        $sedeId  = (int)$vp['sede_id'];
        $saltoId = (int)$vp['salto_id'];
        if ($saltoId === $saltoRiposoId) continue;
        if (isset($assentiIds[$vid])) continue;

        // Priorità: 1) montaggio ODT  2) posizione_default_id  3) posizione principale sede
        $posId = $tmpl[$vid]
            ?? ($vp['posizione_default_id'] ? (int)$vp['posizione_default_id'] : null)
            ?? ($posPerSede[$sedeId] ?? null);

        if ($posId) {
            $ordine[$posId] = ($ordine[$posId] ?? 0) + 1;
            $stA->execute([$nextAssId++, $foglioId, $posId, $vid, $ordine[$posId]]);
        }
    }
}

function prepopolaFoglio(PDO $pdo, int $foglioId, int $saltoRiposoId): void {
    $pdo->prepare("DELETE FROM salto_servizio WHERE foglio_id=?")->execute([$foglioId]);

    $personale   = $pdo->query("SELECT v.id, v.salto_id FROM vigili v WHERE v.attivo=1")->fetchAll();
    $nextSaltoId = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM salto_servizio")->fetchColumn();
    $stS         = $pdo->prepare("INSERT IGNORE INTO salto_servizio (id,foglio_id,vigile_id,richiamato) VALUES (?,?,?,0)");

    foreach ($personale as $vp) {
        if ((int)$vp['salto_id'] === $saltoRiposoId) {
            $stS->execute([$nextSaltoId++, $foglioId, (int)$vp['id']]);
        }
    }

    prepopolaAssegnazioni($pdo, $foglioId, $saltoRiposoId);
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
    prepopolaFoglio($pdo, $foglioId, $saltoRiposoId);
}
$foglioId = (int)$foglio['id'];

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
                       'ferie_ufficio', 'rimuovi_ufficio'];
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

        // Limite 7 vigili per squadra: controlla PRIMA di toccare i dati,
        // escludendo il vigile stesso (caso di ri-drop nella stessa squadra)
        if ($posizioneId > 0) {
            $stCap = $pdo->prepare(
                "SELECT COUNT(*) FROM assegnazioni
                 WHERE foglio_id=? AND posizione_id=? AND vigile_id<>?"
            );
            $stCap->execute([$foglioId, $posizioneId, $vigileId]);
            if ((int)$stCap->fetchColumn() >= 7) {
                echo json_encode(['ok' => false, 'pieno' => true,
                                  'errore' => 'Squadra al completo (max 7).']);
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

        echo json_encode(['ok' => true]);
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

        // Controlla se è una ferie Telegram (tipo=1) prima di eliminare
        $stCheck = $pdo->prepare(
            "SELECT 1 FROM assenze WHERE foglio_id=? AND vigile_id=? AND tipo_assenza_id=1"
        );
        $stCheck->execute([$foglioId, $vigileId]);
        $isFeria = (bool) $stCheck->fetchColumn();

        $pdo->prepare(
            "DELETE FROM assenze WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        if ($isFeria) {
            // Per richieste DN: pulisce l'assenza sull'altro turno dello stesso giorno
            $tipoPaired = ($tipoParam === 'D') ? 'N' : 'D';
            $pdo->prepare(
                "DELETE a FROM assenze a
                 JOIN fogli_servizio f ON f.id = a.foglio_id
                 WHERE a.vigile_id=? AND f.data_servizio=? AND f.tipo_turno=? AND a.tipo_assenza_id=1"
            )->execute([$vigileId, $dataStr, $tipoPaired]);

            // Marca come rifiutata la richiesta Telegram corrispondente
            $pdo->prepare(
                "UPDATE bot_requests SET stato='rejected', processed_at=NOW()
                 WHERE vigile_id=? AND data_richiesta=? AND stato IN ('pending','approved')"
            )->execute([$vigileId, $dataStr]);
        }

        echo json_encode(['ok' => true, 'era_feria' => $isFeria]);
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

    // ── AJAX: reset foglio ───────────────────────────────────
    if ($azione === 'reset_foglio') {
        prepopolaAssegnazioni($pdo, $foglioId, $saltoRiposoId);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'errore' => 'Azione non riconosciuta']);
    exit;
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
$idVigiliInSalto = array_unique(array_column($vigiliInSalto, 'vigile_id'));

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

// Vigili con ferie APPROVATA da richiesta per questo turno (= ferie "da flusso")
$stApp = $pdo->prepare(
    "SELECT DISTINCT vigile_id FROM bot_requests
     WHERE data_richiesta=? AND stato='approved' AND tipo_turno IN ($phResp)"
);
$stApp->execute(array_merge([$dataStr], $tipiRespinte));
$idFerieRichiesta = array_map('intval', array_column($stApp->fetchAll(), 'vigile_id'));

// Splitta le assenze FER: da richiesta (colonna Ferie) vs d'ufficio (box dedicato)
$ferieTutte     = $assenzePerTipo['FER'] ?? [];
$ferieRichiesta = array_values(array_filter($ferieTutte,
    fn($a) => in_array((int)$a['vigile_id'], $idFerieRichiesta)));
$ferieUfficio   = array_values(array_filter($ferieTutte,
    fn($a) => !in_array((int)$a['vigile_id'], $idFerieRichiesta)));

// Select per capo servizio e vice (solo Cr e Cs)
$dirigenti = $pdo->query(
    "SELECT v.id, v.cognome, v.disambiguatore, q.codice AS qcodice
     FROM vigili v
     JOIN qualifiche q ON q.id = v.qualifica_id
     WHERE v.attivo=1 AND q.codice IN ('Cr','Cs')
     ORDER BY q.id DESC, v.cognome"
)->fetchAll();

// Tipi assenza
$tipiAssenza = $pdo->query(
    "SELECT * FROM tipo_assenza ORDER BY id"
)->fetchAll();

// Sedi per select sede distaccata (RC)
$sediSelect = $pdo->query(
    "SELECT codice, nome FROM sedi ORDER BY ordine"
)->fetchAll();

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
function colorePatentePHP(?string $patente): string {
    switch ($patente) {
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
    <a href="../ferie/index.php"  class="nav-btn">🏖️ Ferie</a>
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
          <a href="scarica_odt.php?data=<?= $dataStr ?>"
             class="btn btn-grigio btn-sm">📄 Scarica .odt</a>
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
        <div class="fh-value">
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
        <div class="fh-value">
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
             placeholder="🔍 Cerca cognome…"
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
    // Ferie respinta + vigile libero → va nella casella "Ferie respinte", non in organico
    if (!$occupato && in_array($vid, $idFerieRespinte)) continue;
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
        </span>

        <?php if ($mostraSede): ?>
            <span class="persona-salto">
                <?= htmlspecialchars($v['sede_codice']) ?>
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
      // Vigili liberi con ferie respinta per questo turno
      $respinti = array_filter($tuttoPersonale, function ($v) use (
          $idSaltoRiposo, $vigiliAssegnati, $vigiliAssenti, $idVigiliInSalto, $idFerieRespinte
      ) {
          $vid = $v['id'];
          if ((int)$v['salto_id'] === $idSaltoRiposo) return false;
          $occ = in_array($vid, $vigiliAssegnati) || in_array($vid, $vigiliAssenti) || in_array($vid, $idVigiliInSalto);
          return !$occ && in_array($vid, $idFerieRespinte);
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
             id="pers-<?= $vid ?>"
             data-id="<?= $vid ?>"
             data-nome="<?= htmlspecialchars($label) ?>"
             data-qualifica="<?= htmlspecialchars($v['qcodice']) ?>"
             data-salto="<?= htmlspecialchars($v['salto_codice']) ?>"
             data-salto-id="<?= (int)$v['salto_id'] ?>"
             data-salto-canon="0"
             draggable="true">
            <span class="qual-dot <?= htmlspecialchars($v['qcodice']) ?>"></span>
            <span class="persona-nome" style="color:<?= $colore ?>">
                <?= htmlspecialchars($label) ?>
                <small style="color:var(--rosso)">ferie respinta</small>
            </span>
            <?php if ($mostraSede): ?>
                <span class="persona-salto"><?= htmlspecialchars($v['sede_codice']) ?></span>
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
                    <span class="persona-salto"><?= htmlspecialchars($a['sede_codice']) ?></span>
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
      // Ordine: Centrale (full-width) → distaccamenti → Aeroporto (full-width)
      $sediCentrale      = array_filter($sedi, fn($s) => $s['codice'] === 'CENTR');
      $sediAeroporto     = array_filter($sedi, fn($s) => $s['codice'] === 'AP');
      $sediDistaccamenti = array_filter($sedi, fn($s) => !in_array($s['codice'], ['CENTR','AP']));
      $sediOrdinati      = array_merge(
          array_values($sediCentrale),
          array_values($sediDistaccamenti),
          array_values($sediAeroporto)
      );
      foreach ($sediOrdinati as $sede):
        $posSede  = $posizioniPerSede[$sede['id']] ?? [];
        if (empty($posSede)) continue;
        $sedeFull  = in_array($sede['codice'], ['CENTR','AP']);
        $sedeClass = '';
        if ($sede['codice'] === 'CENTR') $sedeClass = ' sede-full sede-centrale';
        elseif ($sede['codice'] === 'AP') $sedeClass = ' sede-full sede-aeroporto';
      ?>
      <div class="sede-block<?= $sedeClass ?>">
        <div class="sede-head">
          🏠 <?= htmlspecialchars($sede['nome']) ?>
          <span style="font-size:.72rem;opacity:.7;margin-left:auto">
            <?= count($posSede) ?> posizioni
          </span>
        </div>
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
            // CENTR-OP occupa 2 colonne: chiude la riga A a 7 e manda 1B in seconda riga
            $posWide = ($pos['codice'] === 'CENTR-OP') ? ' pos-wide' : '';
          ?>
          <div class="pos-card<?= $posWide ?>"
               id="pos-<?= $pos['id'] ?>"
               data-pos-id="<?= $pos['id'] ?>"
               ondragover="event.preventDefault();this.classList.add('drag-over')"
               ondragleave="this.classList.remove('drag-over')"
               ondrop="onDropPosizione(event,<?= $pos['id'] ?>)">

            <div class="pos-head <?= $tipoHead ?>">
              <?= htmlspecialchars($pos['codice']) ?>
            </div>

            <div class="pos-body" id="body-<?= $pos['id'] ?>">
              <?php foreach ($assQui as $ass):
    $colore = colorePatentePHP($ass['patente_max'] ?? null);
    $mostraSede = (!empty($ass['sede_nome']) && $ass['sede_nome'] !== 'CENTRALE');
?>
    <div class="ass-card"
         id="ass-<?= $ass['vigile_id'] ?>"
         data-vigile-id="<?= $ass['vigile_id'] ?>"
         data-pos-id="<?= $pos['id'] ?>"
         draggable="true">

        <span class="qual-dot <?= htmlspecialchars($ass['qcodice']) ?>"></span>

        <span style="color:<?= $colore ?>;font-weight:600;
                     display:flex;align-items:center;gap:4px;flex-wrap:wrap">
            <?= htmlspecialchars(
                ucfirst(strtolower($ass['qcodice'])).' '.ucfirst(strtolower($ass['cognome'])).
                ($ass['disambiguatore'] ? ' '.$ass['disambiguatore'] : '')
            ) ?>
            <?php if ($mostraSede): ?>
                <span class="persona-salto">
                    <?= htmlspecialchars($ass['sede_codice']) ?>
                </span>
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
      if ((int)$v['salto_id'] !== $idSaltoRiposo) continue;
      $vid          = $v['id'];
      $isAssegnatoStr = in_array($vid, $vigiliAssegnati);
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
                <?= htmlspecialchars($v['sede_codice']) ?>
            </span>
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

  </div>
</div>


     <!-- Ferie -->
<div class="assenti-col" data-drop-zone="colFerie">
  <span class="assenti-col-head ac-ferie">🏖️ Ferie</span>
  <div id="colFerie">
          <?php foreach ($ferieRichiesta as $a):
    $colore = colorePatentePHP($a['patente_max'] ?? null);
?>
    <div class="assente-row" data-vigile-id="<?= $a['vigile_id'] ?>">
        <span class="qual-dot <?= htmlspecialchars($a['qcodice']) ?>"></span>
        <span class="assente-nome" style="color:<?= $colore ?>">
            <?= htmlspecialchars(etichettaVigile($a)) ?>
            <?php if (!empty($a['sede_nome']) && $a['sede_nome'] !== 'CENTRALE'): ?>
                <span class="persona-salto">
                    <?= htmlspecialchars($a['sede_codice']) ?>
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

      <!-- Riposo Compensativo -->
      <!-- Riposo Compensativo -->
<div class="assenti-col" data-drop-zone="colRC">
  <span class="assenti-col-head ac-rc">🔄 Riposo Compensativo</span>
  <div id="colRC">
          <?php foreach ($assenzePerTipo['RC'] ?? [] as $a): ?>
            <div class="assente-row">
              <span class="qual-dot <?= htmlspecialchars($a['qcodice']) ?>"></span>
              <span class="assente-nome">
                <?= htmlspecialchars(etichettaVigile($a)) ?>
              </span>
              <span class="assente-info">
                <?= $a['sede_distaccata'] ? htmlspecialchars($a['sede_distaccata']) : '' ?>
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
        'RC'   => 'colRC',
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
        const inRespinte = c.closest('#ferieRespinteList');
        const s = c.querySelector('small');
        if (inRespinte) {
            // resta nella casella respinte: mantiene l'etichetta rossa
            const lbl = s || (() => { const e = document.createElement('small'); c.querySelector('.persona-nome').appendChild(e); return e; })();
            lbl.style.color = 'var(--rosso)';
            lbl.textContent = 'ferie respinta';
        } else if (s) {
            s.remove();
        }
    }
    aggiornaContatore();
    updateRespinteCount();
}

// Rimuove dal DOM da qualsiasi posto (senza toccare la riga salto canonico)
function rimuoviDOM(id) {
    const p = PERSONALE[id];

    // Da posizione
    const ac = document.getElementById('ass-' + id);
    if (ac) ac.remove();

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
        case '3': return '#c0392b';  // rosso
        case '2': return '#2471a3';  // blu
        case '1':
        default:  return '#2c3e50';  // nero/grigio scuro
    }
}



// ════════════════════════════════════════════════════════════
// COSTRUTTORI HTML
// ════════════════════════════════════════════════════════════
function buildAssCard(p, posId, straord) {
    const sedeBadge = (!p.sedeCentrale && p.sede)
        ? `<span class="persona-salto">${p.sede}</span>` : '';
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
              <span style="color:${colore};font-weight:600;
                           display:flex;align-items:center;
                           gap:4px;flex-wrap:wrap">
                ${p.nome}${sedeBadge}${strBadge}
              </span>
              <button class="remove-btn"
                      onclick="rimuoviDaPosizione(${p.id})"
                      title="Rimuovi">✕</button>
            </div>`;
}


function buildAssenteRow(p, tipoCodice) {
    const sedeBadge = (!p.sedeCentrale && p.sede)
        ? `<span class="persona-salto">${p.sede}</span>` : '';
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
    // 1. Da organico o da ferie respinte
    const persCard = e.target.closest('#organicoList .persona-card, #ferieRespinteList .persona-card');
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
    _dragId     = null;
    _dragSource = null;
});

// ════════════════════════════════════════════════════════════
// ZONE DI DROP — listener delegato su tutto il documento
// ════════════════════════════════════════════════════════════
document.addEventListener('dragover', function(e) {
    const target = getDropTarget(e.target);
    if (target) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        // Evidenzia solo la zona corrente
        document.querySelectorAll('.drop-target')
            .forEach(el => { if (el !== target) el.classList.remove('drop-target'); });
        target.classList.add('drop-target');
    }
});

document.addEventListener('dragleave', function(e) {
    const target = getDropTarget(e.target);
    if (target && !target.contains(e.relatedTarget)) {
        target.classList.remove('drop-target');
    }
});

document.addEventListener('drop', async function(e) {
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

    // ── Drop su posizione ────────────────────────────────────
    if (target.classList.contains('pos-card')) {
        const posId   = parseInt(target.dataset.posId);
        const straord = (source === 'salto' || p.saltoCanon) ? 1 : 0;

        const res = await ajax({
            azione: 'assegna', vigile_id: vigileId,
            posizione_id: posId, straordinario: straord
        });
        if (!res.ok) {
            showMsg(res.pieno ? '🚫 Squadra al completo (max 7). ' + p.nome + ' resta tra i disponibili.' : '⚠️ Errore.', 'err');
            return;
        }

        rimuoviDOM(vigileId);

        const body = document.getElementById('body-' + posId);
        if (body) body.insertAdjacentHTML('beforeend', buildAssCard(p, posId, straord));

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
        if (p.saltoCanon) return; // già lì
        const res = await ajax({ azione: 'metti_salto', vigile_id: vigileId });
        if (!res.ok) { showMsg('⚠️ Errore.','err'); return; }

        rimuoviDOM(vigileId);

        target.insertAdjacentHTML('beforeend',
            `<div class="assente-row"
                  id="salto-${vigileId}"
                  data-vigile-id="${vigileId}"
                  draggable="true"
                  style="cursor:grab">
               <span class="qual-dot ${p.qcodice}"></span>
               <span class="assente-nome">
                 ${p.nome}
                 ${(!p.sedeCentrale && p.sede) ? `<span class="persona-salto">${p.sede}</span>` : ''}
               </span>
               <button class="assente-del"
                       onclick="rimuoviDaZonaSalto(${vigileId})"
                       title="Rimetti">✕</button>
             </div>`
        );

        setOccupato(vigileId, true, 'in salto');
        showMsg('😴 ' + p.nome + ' → salto.');
        return;
    }

    // ── Drop su box Ferie d'ufficio ──────────────────────────
    if (target.id === 'colFerieUfficio') {
        const res = await ajax({ azione: 'ferie_ufficio', vigile_id: vigileId });
        if (!res.ok) { showMsg('⚠️ Errore.','err'); return; }

        rimuoviDOM(vigileId);
        document.getElementById('ferieUfficioVuoto')?.remove();
        target.insertAdjacentHTML('beforeend', buildUfficioRow(p));
        if (!p.saltoCanon) setOccupato(vigileId, true, 'ferie ufficio');
        updateUfficioCount();
        showMsg('🏛️ ' + p.nome + ' → ferie d\'ufficio.');
        return;
    }

    // ── Drop su colonna assenza ──────────────────────────────
    const colId = target.id; // colFerie | colRC | colMissione | colMalattia
    const tipoEntry = Object.values(TIPI_ASSENZA).find(t => t.colId === colId);
    if (!tipoEntry) return;

    // Trova il codice del tipo assenza
    const tipoCodice = Object.keys(TIPI_ASSENZA).find(
        k => TIPI_ASSENZA[k].colId === colId
    );

    const res = await ajax({
        azione:          'assenza',
        vigile_id:       vigileId,
        tipo_assenza_id: tipoEntry.id,
        sede_distaccata: '', data_da: '', data_a: '', nr_turni: '', note: ''
    });
    if (!res.ok) { showMsg('⚠️ Errore.','err'); return; }

    rimuoviDOM(vigileId);

    // Aggiorna riga salto se viene dal salto canonico
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

    if (!p.saltoCanon) setOccupato(vigileId, true, 'assente');
    showMsg('📋 ' + p.nome + ' → ' + tipoCodice + '.');
});

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
    rimuoviDOM(vigileId);
    const p = PERSONALE[vigileId];
    if (res.era_feria) {
        // Ferie tolta dal foglio = ferie respinta → finisce nella casella dedicata
        spostaInFerieRespinte(vigileId);
        showMsg('🚫 ' + (p ? p.nome : 'Vigile') + ' → ferie respinta.');
    } else {
        if (p && !p.saltoCanon) setOccupato(vigileId, false);
        showMsg('↩️ Rimesso disponibile.');
    }
}

// Sposta la card del vigile nella casella "Ferie respinte"
function spostaInFerieRespinte(vigileId) {
    const c    = document.getElementById('pers-' + vigileId);
    const list = document.getElementById('ferieRespinteList');
    if (!c || !list) return;
    c.classList.remove('assente');
    c.setAttribute('draggable', 'true');
    let s = c.querySelector('small');
    if (!s) {
        s = document.createElement('small');
        c.querySelector('.persona-nome').appendChild(s);
    }
    s.style.color  = 'var(--rosso)';
    s.textContent  = 'ferie respinta';
    list.querySelector('.ferie-respinte-vuoto')?.remove();
    list.appendChild(c);
    aggiornaContatore();
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
        ? `<span class="persona-salto">${p.sede}</span>` : '';
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
    const t = testo.toLowerCase().trim();
    document.querySelectorAll('#organicoList .persona-card').forEach(card => {
        card.style.display =
            (!t || card.dataset.nome.toLowerCase().includes(t)) ? '' : 'none';
    });
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
    #colRC.drop-target,
    #colMissione.drop-target,
    #colMalattia.drop-target {
        background: #fff0ee !important;
        outline: 2px dashed var(--rosso) !important;
        border-radius: 4px;
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
