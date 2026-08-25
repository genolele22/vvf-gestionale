<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
richiediAdmin();
require_once __DIR__ . '/../includes/turni.php';          // getTurnoGiorno()
require_once __DIR__ . '/../includes/ferie_assenze.php';  // feriaSyncAssenza(), permessoOrarioSync()
require_once __DIR__ . '/../includes/bot_requests_schema.php';
require_once __DIR__ . '/../includes/scambio_salto.php';  // scambioConflittiRighe()
$TURNO = turnoAmministrazione();   // fisso sul turno di casa (admin/user niente switch qui)

$pdo = getDB();
assicuraSchemaRichiesteAssenza($pdo);   // idempotente: garantisce tipo_assenza_id/ora_da/ora_a

// ── AJAX: crea le voci ferie (come richieste BOT, stato pending) ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!puoModificareTurno($TURNO)) { echo json_encode(['ok' => false, 'errore' => 'Turno in sola lettura.']); exit; }
    $azione = $_POST['azione'] ?? '';

    if ($azione === 'crea_voci') {
        $vigili = json_decode($_POST['vigili'] ?? '[]', true);
        $voci   = json_decode($_POST['voci']   ?? '[]', true);   // [{data:'Y-m-d', tipo:'D'|'N'}]
        $vigili = is_array($vigili)
            ? array_values(array_filter(array_map('intval', $vigili), fn($i) => $i > 0)) : [];
        $voci = is_array($voci) ? $voci : [];

        // Tipo richiesta: 'ferie' (default, comportamento storico) o 'permesso'
        // (giornaliero o orario). tipo_assenza_id: 1=FER, 4=PERM.
        $tipoRichiesta = ($_POST['tipoRichiesta'] ?? 'ferie') === 'permesso' ? 'permesso' : 'ferie';
        $tipoAssenzaId = $tipoRichiesta === 'permesso' ? 4 : 1;
        $isOrario      = $tipoRichiesta === 'permesso' && ($_POST['modalita'] ?? '') === 'orario';

        // #141: visto "ferie estiva" applicato a tutte le voci create in questo giro
        // (solo GIU-SET) — ha senso solo per le ferie vere, non per i permessi.
        $estiva = $tipoRichiesta === 'ferie' && !empty($_POST['estiva']);

        // Permesso orario: un solo orario per l'intero batch (l'UI, in modalità
        // orario, limita comunque la selezione a un giorno solo per volta).
        $oraDa = null; $oraA = null;
        if ($isOrario) {
            $oraDa = (string)($_POST['oraDa'] ?? '');
            $oraA  = (string)($_POST['oraA']  ?? '');
            if (!preg_match('/^([01]\d|2[0-3]):00$/', $oraDa) || !preg_match('/^([01]\d|2[0-3]):00$/', $oraA)) {
                echo json_encode(['ok' => false, 'errore' => 'Orario non valido.']);
                exit;
            }
        }

        if (!$vigili || !$voci) {
            echo json_encode(['ok' => false, 'errore' => 'Seleziona almeno un vigile e un giorno.']);
            exit;
        }

        // Slot reale del turno attivo per una data: 'D', 'N' o null (non in servizio).
        $slotTurnoB = function (string $data) use ($TURNO): ?string {
            $tg = getTurnoGiorno($data);
            if (($tg['diurno']['turno'] ?? '') === $TURNO) return 'D';
            if (($tg['notte']['turno']  ?? '') === $TURNO) return 'N';
            return null;
        };

        // #205 (logbook): il turno può essere in servizio quel giorno mentre il
        // SINGOLO vigile è comunque sul proprio salto (riposo compensativo) —
        // salto_id è a rotazione interna al turno, diverso da vigile a vigile.
        // Ferie/permesso non hanno senso su un giorno in cui il vigile non
        // sarebbe comunque in servizio: qui si blocca, niente solo avviso
        // (diverso dallo scambio salto, che è un'eccezione negoziabile — questa
        // è la rotazione di base). Missione/malattia/infortunio ne restano
        // fuori: hanno precedenza sul salto per regola di dominio già esistente.
        $saltoIdVigile = [];
        $nomiVigili    = [];
        if ($vigili) {
            $ph = implode(',', array_fill(0, count($vigili), '?'));
            $stV = $pdo->prepare("SELECT id, salto_id, cognome, nome, disambiguatore FROM vigili WHERE id IN ($ph)");
            $stV->execute($vigili);
            foreach ($stV->fetchAll() as $r) {
                $saltoIdVigile[(int)$r['id']] = (int)$r['salto_id'];
                $lbl = strtoupper($r['cognome']) . ' ' . $r['nome'];
                if (!empty($r['disambiguatore'])) $lbl .= ' (' . $r['disambiguatore'] . ')';
                $nomiVigili[(int)$r['id']] = $lbl;
            }
        }
        $saltoIdGiornoCache = [];
        $saltoIdGiorno = function (string $data, string $tipo) use ($pdo, $TURNO, &$saltoIdGiornoCache): ?int {
            $key = "$data:$tipo";
            if (array_key_exists($key, $saltoIdGiornoCache)) return $saltoIdGiornoCache[$key];
            $num = saltoRiposoNum($data, $tipo);
            $id  = (int)($pdo->query(
                "SELECT id FROM salti_turno WHERE codice=" . $pdo->quote($TURNO . $num)
            )->fetchColumn() ?: 0);
            return $saltoIdGiornoCache[$key] = ($id ?: null);
        };

        // #225: il vigile può già risultare impegnato sul foglio in un altro
        // riquadro (Missione/Permesso, Malattia/Infortunio) inserito a mano
        // dal foglio stesso — senza passare da bot_requests, quindi invisibile
        // al controllo di duplicato più sotto (chiave univoca vigile+data+turno
        // su bot_requests). Va controllato direttamente su `assenze`, altrimenti
        // si crea la doppia presenza: stessa persona assente e in servizio.
        $LABEL_TIPO_ASSENZA = [1 => 'ferie', 3 => 'missione', 4 => 'permesso', 5 => 'malattia', 6 => 'infortunio'];
        $foglioIdCache = [];
        $foglioIdEsistente = function (string $data, string $tipo) use ($pdo, $TURNO, &$foglioIdCache): ?int {
            $key = "$data:$tipo";
            if (array_key_exists($key, $foglioIdCache)) return $foglioIdCache[$key];
            $st = $pdo->prepare("SELECT id FROM fogli_servizio WHERE turno=? AND data_servizio=? AND tipo_turno=?");
            $st->execute([$TURNO, $data, $tipo]);
            $id = $st->fetchColumn();
            return $foglioIdCache[$key] = ($id !== false ? (int)$id : null);
        };
        $stAssenzaEsistente = $pdo->prepare("SELECT tipo_assenza_id FROM assenze WHERE foglio_id=? AND vigile_id=?");

        // Finestra oraria valida per uno slot — mirror dei bottoni "a ore piene"
        // del bot (08-20 diurno, 20-08 notturno). Sequenza ordinata per poter
        // confrontare "prima/dopo" senza fare matematica sulle date (il
        // notturno attraversa la mezzanotte).
        $sequenzaOre = function (string $slot): array {
            return $slot === 'D'
                ? array_map(fn($h) => sprintf('%02d:00', $h), range(8, 20))
                : array_merge(array_map(fn($h) => sprintf('%02d:00', $h), range(20, 23)),
                              array_map(fn($h) => sprintf('%02d:00', $h), range(0, 8)));
        };
        $oraValidaPerSlot = function (string $slot) use ($oraDa, $oraA, $sequenzaOre): bool {
            $seq = $sequenzaOre($slot);
            $iDa = array_search($oraDa, $seq, true);
            $iA  = array_search($oraA,  $seq, true);
            return $iDa !== false && $iA !== false && $iA > $iDa;
        };

        $insReq = $pdo->prepare(
            "INSERT IGNORE INTO bot_requests
                (vigile_id, data_richiesta, tipo_turno, tipo_assenza_id, stato, processed_at, ferie_estiva, ora_da, ora_a)
             VALUES (?, ?, ?, ?, 'pending', NULL, ?, ?, ?)"
        );
        // Riga già presente (uq_req su vigile+data+turno, indipendente dal tipo):
        // serve a decidere se sincronizzare (stesso tipo) o limitarsi a contare il
        // duplicato senza toccare un'assenza di un tipo diverso (es. quel giorno
        // ha già una ferie e si prova a caricarci sopra un permesso).
        $selEsistente = $pdo->prepare(
            "SELECT id, tipo_assenza_id, ora_da FROM bot_requests WHERE vigile_id=? AND data_richiesta=? AND tipo_turno=?"
        );

        $creati = 0; $duplicati = 0; $scartati = 0; $scartatiSalto = 0;
        $conflittiAltriTipi = [];   // messaggi per il popup di sovrapposizione (#225)
        $righeSincronizzate = [];   // [vigile_id, data, tipo] — per l'avviso scambio salto
        $pdo->beginTransaction();
        try {
            foreach ($voci as $v) {
                $data = (string)($v['data'] ?? '');
                $tipo = (string)($v['tipo'] ?? '');
                // valida data e coerenza col ciclo turno B
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !in_array($tipo, ['D', 'N'], true)) {
                    $scartati++; continue;
                }
                if ($slotTurnoB($data) !== $tipo) { $scartati++; continue; }
                if ($isOrario && !$oraValidaPerSlot($tipo)) { $scartati++; continue; }

                $mese     = (int)date('n', strtotime($data));
                $isEstiva = $estiva && in_array($mese, [6, 7, 8, 9], true) ? 1 : 0;
                $saltoGiorno = $saltoIdGiorno($data, $tipo);

                foreach ($vigili as $vid) {
                    // #205: il turno lavora quel giorno, ma QUESTO vigile è sul
                    // proprio salto (riposo compensativo) — non avrebbe comunque
                    // servizio da coprire con la ferie/permesso. Blocco, non avviso.
                    if ($saltoGiorno !== null && ($saltoIdVigile[$vid] ?? null) === $saltoGiorno) {
                        $scartatiSalto++;
                        continue;
                    }

                    // #225: blocca (non solo avviso) se il vigile è già presente sul
                    // foglio con un tipo di assenza diverso (Missione/Permesso,
                    // Malattia/Infortunio) — la ferie/permesso non va creata.
                    $foglioIdEsist = $foglioIdEsistente($data, $tipo);
                    if ($foglioIdEsist !== null) {
                        $stAssenzaEsistente->execute([$foglioIdEsist, $vid]);
                        $tipoConflitto = null;
                        foreach ($stAssenzaEsistente->fetchAll() as $rowEsist) {
                            if ((int)$rowEsist['tipo_assenza_id'] !== $tipoAssenzaId) {
                                $tipoConflitto = (int)$rowEsist['tipo_assenza_id'];
                                break;
                            }
                        }
                        if ($tipoConflitto !== null) {
                            $conflittiAltriTipi[] = ($nomiVigili[$vid] ?? "#$vid") . ' — già in '
                                . ($LABEL_TIPO_ASSENZA[$tipoConflitto] ?? 'altra assenza')
                                . " il $data ($tipo)";
                            continue;
                        }
                    }

                    $insReq->execute([$vid, $data, $tipo, $tipoAssenzaId, $isEstiva, $oraDa, $oraA]);
                    $creatoOra = $insReq->rowCount() > 0;
                    $creatoOra ? $creati++ : $duplicati++;

                    $selEsistente->execute([$vid, $data, $tipo]);
                    $esist = $selEsistente->fetch();
                    if (!$esist) continue;   // difensivo, non dovrebbe succedere
                    if (!$creatoOra) {
                        if ((int)$esist['tipo_assenza_id'] !== $tipoAssenzaId) continue;
                        // stesso tipo_assenza_id ma sottotipo diverso (orario vs
                        // giornaliero): non confondere l'uno con l'altro.
                        $eraOrario = $esist['ora_da'] !== null;
                        if ($isOrario !== $eraOrario) continue;
                    }
                    $reqId = (int)$esist['id'];

                    if ($isOrario) {
                        // Permesso orario: mai su `assenze` (il vigile resta assegnato
                        // al turno), solo annotazione in permessi_orari.
                        permessoOrarioSync($pdo, $vid, $reqId, $data, $tipo, $oraDa, $oraA, null, 'pending');
                    } else {
                        // assenza sul foglio giusto (crea il foglio se non esiste): pending
                        feriaSyncAssenza($pdo, $vid, $data, $tipo, 'pending', $tipoAssenzaId);
                    }
                    $righeSincronizzate[] = [$vid, $data, $tipo];
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'errore' => 'Errore DB: ' . $e->getMessage()]);
            exit;
        }

        // Solo rilevazione (mai un blocco): ferie/permesso + scambio salto
        // attivo sullo stesso turno, stesso principio già in uso nel foglio
        // (#188) — anche per il permesso, su richiesta esplicita di Lele
        // (niente più blocco duro, solo l'avviso).
        $labelAvviso = $tipoRichiesta === 'ferie' ? 'ferie' : 'permesso';
        $avvisi = $righeSincronizzate ? scambioConflittiRighe($pdo, $righeSincronizzate, $labelAvviso) : [];

        echo json_encode([
            'ok' => true,
            'creati' => $creati, 'duplicati' => $duplicati, 'scartati' => $scartati,
            'scartatiSalto' => $scartatiSalto, 'conflitti' => $conflittiAltriTipi, 'avvisi' => $avvisi,
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'errore' => 'Azione sconosciuta']);
    exit;
}

// ── Vigili attivi per il dropdown ────────────────────────────────────────────
$vigili = $pdo->query(
    "SELECT id, cognome, nome, disambiguatore
       FROM vigili WHERE attivo = 1 AND turno = '$TURNO'
   ORDER BY cognome ASC, nome ASC, disambiguatore ASC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Ferie e permessi simulati (BETA)</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<script src="../assets/js/conferma.js?v=<?= @filemtime(__DIR__.'/../assets/js/conferma.js') ?>"></script>
<style>
  .fs-beta { display:inline-block; background:#b9770e; color:#fff; font-size:.66rem; font-weight:800;
             letter-spacing:.5px; padding:2px 8px; border-radius:6px; vertical-align:middle; margin-left:8px; }
  .fs-wrap { display:grid; grid-template-columns:280px 1fr; gap:18px; align-items:start; }
  @media (max-width:900px){ .fs-wrap{ grid-template-columns:1fr; } }
  .fs-box { background:#fff; border:1px solid #e4e7ea; border-radius:10px; padding:16px; box-shadow:var(--shadow); }
  .fs-box h3 { margin:0 0 10px; font-size:.95rem; color:var(--rosso); text-transform:uppercase; letter-spacing:.5px; }
  .fs-search { width:100%; padding:7px 10px; border:1px solid #ccc; border-radius:6px; margin-bottom:8px; font-size:.9rem; }
  .fs-vigili { max-height:380px; overflow:auto; border:1px solid #eee; border-radius:6px; }
  .fs-vrow { display:flex; align-items:center; gap:8px; padding:6px 10px; border-bottom:1px solid #f1f1f1; cursor:pointer; font-size:.88rem; }
  .fs-vrow:hover { background:#f8f9fa; }
  .fs-vrow input { width:16px; height:16px; }
  .fs-vactions { display:flex; gap:8px; margin-bottom:8px; }
  .fs-cal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
  .fs-cal-head h4 { margin:0; font-size:1.05rem; }
  .fs-nav { background:var(--rosso); color:#fff; border:none; border-radius:6px; padding:6px 12px; cursor:pointer; font-size:1rem; }
  .fs-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
  .fs-dow { text-align:center; font-size:.7rem; font-weight:700; color:var(--grigio-md); text-transform:uppercase; padding:4px 0; }
  .fs-cell { min-height:54px; border:1px solid #e8e8e8; border-radius:6px; padding:4px 6px; background:#fafafa; position:relative; }
  .fs-cell .gn { font-size:.8rem; font-weight:700; color:var(--grigio-sc); }
  .fs-cell.off { background:#f3f3f3; color:#bbb; }
  .fs-cell.off .gn { color:#bbb; }
  .fs-cell.slot { cursor:pointer; }
  .fs-cell.slot:hover { border-color:var(--rosso); }
  .fs-cell .ico { display:block; font-size:1.1rem; line-height:1; margin-top:4px; }
  .fs-cell.sel { background:#fde8e6; border-color:var(--rosso); box-shadow:0 0 0 2px rgba(192,57,43,.25); }
  .fs-cell.sel::after { content:'✓'; position:absolute; top:2px; right:5px; color:var(--rosso); font-weight:800; font-size:.8rem; }
  .fs-foot { margin-top:14px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
  .fs-count { font-size:.85rem; color:var(--grigio-md); font-weight:600; }
  .fs-estiva { display:flex; align-items:center; gap:6px; font-size:.85rem; font-weight:600;
               color:var(--grigio-sc); cursor:pointer; margin-left:auto; }
  .fs-estiva input { width:16px; height:16px; cursor:pointer; }
  .fs-go { background:#1e7e34; color:#fff; border:none; border-radius:8px; padding:10px 22px; font-size:.95rem; font-weight:700; cursor:pointer; }
  .fs-go:disabled { opacity:.5; cursor:not-allowed; }
  .fs-msg { margin-top:12px; padding:10px 14px; border-radius:8px; font-size:.9rem; display:none; }
  .fs-msg.ok { background:#e6f4ea; color:#1e7e34; display:block; }
  .fs-msg.err { background:#fdecea; color:#c0392b; display:block; }
  .fs-hint { font-size:.78rem; color:var(--grigio-md); margin:0 0 10px; line-height:1.4; }
  .fs-tipo { margin-bottom:18px; }
  .fs-chips { display:flex; flex-wrap:wrap; gap:8px; }
  .fs-chips.fs-sub { margin-top:10px; }
  .fs-chip { border:1px solid #ccc; background:#fff; border-radius:20px; padding:6px 14px;
             font-size:.85rem; font-weight:600; cursor:pointer; color:var(--grigio-sc); }
  .fs-chip:hover { border-color:var(--rosso); }
  .fs-chip.active { background:var(--rosso); border-color:var(--rosso); color:#fff; }
  .fs-orario { margin-top:12px; }
  .fs-ora-row { display:flex; align-items:center; gap:10px; margin-bottom:8px; flex-wrap:wrap; }
  .fs-ora-lbl { font-size:.8rem; font-weight:700; color:var(--grigio-md); min-width:44px; }
  .fs-chips .fs-chip.ora { padding:5px 10px; font-size:.8rem; border-radius:6px; }
  .fs-chips .fs-chip.ora.active { background:#1e7e34; border-color:#1e7e34; }
  .fs-chips .fs-chip.ora:disabled { opacity:.35; cursor:not-allowed; }
</style>
</head>
<body>
<header class="header">
  <div class="header-inner">
    <div class="header-logo">🚒</div>
    <div class="header-testi">
      <h1>Comando Provinciale VVF di Genova</h1>
      <p>Gestionale Foglio di Servizio &mdash; Turno <?= htmlspecialchars($TURNO) ?></p>
    </div>
    <div class="header-badge">TURNO&nbsp;<?= htmlspecialchars($TURNO) ?></div>
  </div>
</header>
<nav class="navbar">
  <div class="navbar-inner">
    <a href="../index.php"        class="nav-btn">🚒 Home</a>
    <a href="../foglio/nuovo.php" class="nav-btn">📋 Foglio</a>
    <a href="../vigili/lista.php" class="nav-btn">👥 Personale</a>
    <a href="../ferie/index.php"  class="nav-btn">🗓️ Agenda</a>
    <a href="../report/index.php" class="nav-btn">📊 Reportistica</a>
    <a href="index.php"           class="nav-btn active">⚙️ Amministrazione</a>
    <a href="../logbook/index.php" class="nav-btn">📓 Logbook</a>
    <a href="../cambia_password.php" class="nav-btn ml-auto">🔑 Password</a>
    <?= turnoComandoHtml() ?>
    <a href="../logout.php"       class="nav-btn">🚪 Esci</a>
  </div>
</nav>
<main class="main">
  <div class="page-title">
    <h2>🏖️ Ferie e permessi <span class="fs-beta">BETA</span></h2>
  </div>
  <p class="fs-hint">
    Strumento interno per caricare ferie e permessi (estivi/agenda cartacea) come se fossero
    arrivati dal bot: crea la voce in <strong>Agenda</strong> (stato <em>pending</em>) e l'assenza
    — o, per il permesso orario, l'annotazione — sul <strong>foglio del giorno giusto</strong>.
    Nessuna mail inviata.<br>
    Ogni giorno mostra lo slot reale del turno <?= htmlspecialchars($TURNO) ?>: ☀️ diurno, 🌙 notturno.
    I giorni in cui il turno non è in servizio non sono selezionabili. Un vigile può avere una
    sola voce (ferie o permesso) per giorno/turno.
  </p>

  <div class="fs-box fs-tipo">
    <h3>Tipo</h3>
    <div class="fs-chips" id="chipsTipo">
      <button type="button" class="fs-chip active" data-tipo="ferie">🏖️ Ferie</button>
      <button type="button" class="fs-chip" data-tipo="permesso">📝 Permesso</button>
    </div>
    <div class="fs-chips fs-sub" id="chipsModalita" style="display:none;">
      <button type="button" class="fs-chip active" data-modalita="giornaliero">📅 Giornaliero</button>
      <button type="button" class="fs-chip" data-modalita="orario">🕐 Orario</button>
    </div>
    <div class="fs-orario" id="orarioPicker" style="display:none;">
      <p class="fs-hint" style="margin:8px 0 6px;">
        Permesso orario: un giorno alla volta — selezionandone un altro nel calendario sostituisce
        quello scelto.
      </p>
      <div class="fs-ora-row"><span class="fs-ora-lbl">Dalle</span><div class="fs-chips" id="chipsOraDa"></div></div>
      <div class="fs-ora-row"><span class="fs-ora-lbl">Alle</span><div class="fs-chips" id="chipsOraA"></div></div>
    </div>
  </div>

  <div class="fs-wrap">

    <!-- ── Vigili ── -->
    <div class="fs-box">
      <h3>Vigili</h3>
      <input type="text" class="fs-search" id="vSearch" placeholder="Cerca per cognome…">
      <div class="fs-vactions">
        <button type="button" class="btn btn-sm btn-grigio" onclick="selVigili(true)">Tutti (visibili)</button>
        <button type="button" class="btn btn-sm btn-grigio" onclick="selVigili(false)">Nessuno</button>
      </div>
      <div class="fs-vigili" id="vList">
        <?php foreach ($vigili as $v):
            $lbl = strtoupper($v['cognome']) . ' ' . $v['nome'];
            if (!empty($v['disambiguatore'])) $lbl .= ' (' . $v['disambiguatore'] . ')';
        ?>
        <label class="fs-vrow" data-nome="<?= htmlspecialchars(strtolower($lbl)) ?>">
          <input type="checkbox" class="vchk" value="<?= (int)$v['id'] ?>">
          <span><?= htmlspecialchars($lbl) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ── Calendario ── -->
    <div class="fs-box">
      <div class="fs-cal-head">
        <button type="button" class="fs-nav" onclick="cambiaMese(-1)">‹</button>
        <h4 id="calTitolo"></h4>
        <button type="button" class="fs-nav" onclick="cambiaMese(1)">›</button>
      </div>
      <div class="fs-grid" id="calGrid"></div>

      <div class="fs-foot">
        <button type="button" class="fs-go" id="btnCrea" onclick="creaVoci()">Crea voci ferie</button>
        <span class="fs-count" id="contatori">0 vigili · 0 giorni</span>
        <label class="fs-estiva" title="Contrassegna come ferie estive (solo giugno–settembre)">
          <input type="checkbox" id="chkEstiva"> 🏖️ Ferie estiva
        </label>
      </div>
      <div class="fs-msg" id="msg"></div>
    </div>

  </div>
</main>

<script>
// ── Ciclo turni (porta di includes/turni.php) ────────────────────────────────
// Ancora 2026-05-01 = giorno 0, diurno turno B (indice 1 in A,B,C,D).
const ANCHOR = Date.UTC(2026, 4, 1);   // 2026-05-01
const ANCHOR_TURNO = 1;                // B
function giorniDallAncora(y, m, d) {   // m: 1-12
  return Math.round((Date.UTC(y, m - 1, d) - ANCHOR) / 86400000);
}
function turnoDiurno(giorni) {         // lettera del diurno (A,B,C,D)
  const idx = (((ANCHOR_TURNO + giorni) % 4) + 4) % 4;
  return ['A', 'B', 'C', 'D'][idx];
}
const TURNO_ATTIVO = '<?= $TURNO ?>';
// Slot del turno attivo in una data: 'D', 'N' o null (non in servizio)
function slotTurnoB(y, m, d) {
  const g = giorniDallAncora(y, m, d);
  if (turnoDiurno(g)     === TURNO_ATTIVO) return 'D';   // diurno
  if (turnoDiurno(g - 1) === TURNO_ATTIVO) return 'N';   // notturno = diurno del giorno prima
  return null;
}

const MESI = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno',
              'Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
const DOW  = ['Lun','Mar','Mer','Gio','Ven','Sab','Dom'];

let viewY, viewM;                       // mese visualizzato (m: 1-12)
const selezione = new Set();            // "Y-m-d:tipo"

let TIPO = 'ferie';                     // 'ferie' | 'permesso'
let MODALITA = 'giornaliero';           // 'giornaliero' | 'orario' (solo se TIPO='permesso')
let oraDaSel = null, oraASel = null;

function isOrarioAttivo() { return TIPO === 'permesso' && MODALITA === 'orario'; }

// Finestra oraria per slot — mirror dei bottoni "a ore piene" del bot:
// 08-20 diurno, 20-08 notturno (attraversa la mezzanotte).
function sequenzaOre(slot) {
  if (slot === 'D') return Array.from({length:13}, (_,i) => String(8+i).padStart(2,'0') + ':00');
  const seq = [];
  for (let h = 20; h <= 23; h++) seq.push(String(h).padStart(2,'0') + ':00');
  for (let h = 0;  h <= 8;  h++) seq.push(String(h).padStart(2,'0') + ':00');
  return seq;
}

function renderOraChips(slot) {
  const seq  = sequenzaOre(slot);
  const daBox = document.getElementById('chipsOraDa');
  const aBox  = document.getElementById('chipsOraA');
  daBox.innerHTML = ''; aBox.innerHTML = '';
  const iDa = seq.indexOf(oraDaSel);
  seq.forEach(h => {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'fs-chip ora' + (h === oraDaSel ? ' active' : '');
    b.textContent = h;
    b.onclick = () => { oraDaSel = h; oraASel = null; renderOraChips(slot); aggiornaContatori(); };
    daBox.appendChild(b);
  });
  seq.forEach((h, idx) => {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'fs-chip ora' + (h === oraASel ? ' active' : '');
    b.textContent = h;
    b.disabled = iDa === -1 || idx <= iDa;
    b.onclick = () => { oraASel = h; renderOraChips(slot); aggiornaContatori(); };
    aBox.appendChild(b);
  });
}

function svuotaOraChips() {
  document.getElementById('chipsOraDa').innerHTML = '';
  document.getElementById('chipsOraA').innerHTML = '';
  oraDaSel = null; oraASel = null;
}

document.getElementById('chipsTipo').addEventListener('click', e => {
  const btn = e.target.closest('.fs-chip'); if (!btn) return;
  TIPO = btn.dataset.tipo;
  document.querySelectorAll('#chipsTipo .fs-chip').forEach(c => c.classList.toggle('active', c === btn));
  document.getElementById('chipsModalita').style.display = TIPO === 'permesso' ? 'flex' : 'none';
  document.querySelector('.fs-estiva').style.display = TIPO === 'ferie' ? 'flex' : 'none';
  if (TIPO === 'ferie') {
    MODALITA = 'giornaliero';
    document.querySelectorAll('#chipsModalita .fs-chip').forEach(c => c.classList.toggle('active', c.dataset.modalita === 'giornaliero'));
  }
  document.getElementById('orarioPicker').style.display = isOrarioAttivo() ? 'block' : 'none';
  aggiornaEtichettaBtn();
  selezione.clear(); svuotaOraChips(); renderCal(); aggiornaContatori();
});
document.getElementById('chipsModalita').addEventListener('click', e => {
  const btn = e.target.closest('.fs-chip'); if (!btn) return;
  MODALITA = btn.dataset.modalita;
  document.querySelectorAll('#chipsModalita .fs-chip').forEach(c => c.classList.toggle('active', c === btn));
  document.getElementById('orarioPicker').style.display = isOrarioAttivo() ? 'block' : 'none';
  aggiornaEtichettaBtn();
  selezione.clear(); svuotaOraChips(); renderCal(); aggiornaContatori();
});

// Stesso principio del foglio (#188): solo avviso, mai un blocco — vale sia
// per ferie che per permesso.
function mostraAvvisiScambioSalto(avvisi) {
  if (!avvisi || !avvisi.length) return;
  chiediConferma({
    titolo:  '⚠️ Scambio salto attivo',
    testo:   'Attenzione — questi vigili risultano assenti ma anche in uno ' +
             'scambio salto attivo per lo stesso turno:<br><br>' +
             avvisi.map(a => '• ' + a).join('<br>') +
             '<br><br>Puoi sistemare il foglio o lasciarlo così.',
    okLabel: 'Ho capito',
    okStyle: 'background:#b7950b;color:#fff',
  });
}

// #225: sovrapposizione con Missione/Permesso o Malattia/Infortunio già
// presenti sul foglio — qui NON è stato creato nulla, va detto chiaramente.
// Il modale è un singleton (assets/js/conferma.js): se c'è anche l'avviso
// scambio salto, va incatenato con onOk invece che chiamato subito dopo,
// altrimenti il secondo popup sovrascrive il primo prima che si veda.
function mostraEsitiPostCrea(conflitti, avvisi) {
  if (conflitti && conflitti.length) {
    chiediConferma({
      titolo:  '⛔ Non creata: sovrapposizione',
      testo:   'Queste voci NON sono state create perché il vigile risulta già ' +
               'impegnato in un\'altra assenza sul foglio di quel giorno:<br><br>' +
               conflitti.map(c => '• ' + c).join('<br>') +
               '<br><br>Sistema prima la situazione esistente, poi riprova.',
      okLabel: 'Ho capito',
      okStyle: 'background:#c0392b;color:#fff',
      onOk:    () => mostraAvvisiScambioSalto(avvisi),
    });
  } else {
    mostraAvvisiScambioSalto(avvisi);
  }
}

function aggiornaEtichettaBtn() {
  document.getElementById('btnCrea').textContent =
    TIPO === 'ferie' ? 'Crea voci ferie' : 'Crea voci permesso';
}

function pad(n){ return String(n).padStart(2,'0'); }

function renderCal() {
  document.getElementById('calTitolo').textContent = MESI[viewM-1] + ' ' + viewY;
  const grid = document.getElementById('calGrid');
  grid.innerHTML = '';
  DOW.forEach(d => {
    const h = document.createElement('div'); h.className = 'fs-dow'; h.textContent = d; grid.appendChild(h);
  });
  // primo giorno (Lun=1..Dom=7)
  const primo = new Date(Date.UTC(viewY, viewM-1, 1)).getUTCDay(); // 0=Dom
  const offset = (primo === 0 ? 6 : primo - 1);
  for (let i = 0; i < offset; i++) {
    const e = document.createElement('div'); grid.appendChild(e);
  }
  const ultimo = new Date(Date.UTC(viewY, viewM, 0)).getUTCDate();
  for (let g = 1; g <= ultimo; g++) {
    const slot = slotTurnoB(viewY, viewM, g);
    const cell = document.createElement('div');
    cell.className = 'fs-cell' + (slot ? ' slot' : ' off');
    cell.innerHTML = '<span class="gn">' + g + '</span>';
    if (slot) {
      const key = `${viewY}-${pad(viewM)}-${pad(g)}:${slot}`;
      cell.innerHTML += '<span class="ico">' + (slot === 'D' ? '☀️' : '🌙') + '</span>';
      if (selezione.has(key)) cell.classList.add('sel');
      cell.onclick = () => {
        if (isOrarioAttivo()) {
          // Un giorno alla volta: click su un altro giorno sostituisce la selezione.
          const giaSelezionato = selezione.has(key);
          selezione.clear();
          svuotaOraChips();
          if (!giaSelezionato) { selezione.add(key); renderOraChips(slot); }
          renderCal();
          aggiornaContatori();
          return;
        }
        if (selezione.has(key)) { selezione.delete(key); cell.classList.remove('sel'); }
        else { selezione.add(key); cell.classList.add('sel'); }
        aggiornaContatori();
      };
    }
    grid.appendChild(cell);
  }
}

function cambiaMese(delta) {
  viewM += delta;
  if (viewM < 1)  { viewM = 12; viewY--; }
  if (viewM > 12) { viewM = 1;  viewY++; }
  renderCal();
}

function vigiliSelezionati() {
  return [...document.querySelectorAll('.vchk:checked')].map(c => parseInt(c.value));
}
function aggiornaContatori() {
  const nv = vigiliSelezionati().length;
  const ng = selezione.size;
  document.getElementById('contatori').textContent =
    `${nv} vigil${nv===1?'e':'i'} · ${ng} giorn${ng===1?'o':'i'}`;
  const oraOk = !isOrarioAttivo() || (oraDaSel && oraASel);
  document.getElementById('btnCrea').disabled = (nv === 0 || ng === 0 || !oraOk);
}

function selVigili(on) {
  document.querySelectorAll('.fs-vrow').forEach(r => {
    if (r.style.display === 'none') return;       // solo i visibili dal filtro
    r.querySelector('.vchk').checked = on;
  });
  aggiornaContatori();
}

document.getElementById('vSearch').addEventListener('input', e => {
  const t = e.target.value.toLowerCase().trim();
  document.querySelectorAll('.fs-vrow').forEach(r => {
    r.style.display = (!t || r.dataset.nome.includes(t)) ? '' : 'none';
  });
});
document.getElementById('vList').addEventListener('change', aggiornaContatori);

async function creaVoci() {
  const vigili = vigiliSelezionati();
  const voci = [...selezione].map(k => {
    const [data, tipo] = k.split(':');
    return { data, tipo };
  });
  const btn = document.getElementById('btnCrea');
  btn.disabled = true;
  const fd = new FormData();
  fd.append('azione', 'crea_voci');
  fd.append('vigili', JSON.stringify(vigili));
  fd.append('voci',   JSON.stringify(voci));
  fd.append('tipoRichiesta', TIPO);
  fd.append('modalita', MODALITA);
  if (TIPO === 'ferie') {
    fd.append('estiva', document.getElementById('chkEstiva').checked ? '1' : '');
  }
  if (isOrarioAttivo()) {
    fd.append('oraDa', oraDaSel || '');
    fd.append('oraA',  oraASel  || '');
  }
  const msg = document.getElementById('msg');
  try {
    const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
    if (res.ok) {
      msg.className = 'fs-msg ok';
      msg.textContent = `✅ Create ${res.creati} voci`
        + (res.duplicati ? ` · ${res.duplicati} già presenti` : '')
        + (res.scartati ? ` · ${res.scartati} scartate` : '')
        + (res.scartatiSalto ? ` · ${res.scartatiSalto} scartate (vigile sul proprio salto)` : '')
        + (res.conflitti && res.conflitti.length ? ` · ${res.conflitti.length} scartate (sovrapposizione)` : '') + '.';
      selezione.clear();
      svuotaOraChips();
      renderCal();
      // #141: reset dei visti dopo la creazione, per non dimenticarne uno attivo
      // e ricrearci ferie non richieste al giro successivo.
      document.querySelectorAll('.vchk').forEach(c => c.checked = false);
      document.getElementById('chkEstiva').checked = false;
      mostraEsitiPostCrea(res.conflitti, res.avvisi);
    } else {
      msg.className = 'fs-msg err';
      msg.textContent = '⚠️ ' + (res.errore || 'Errore.');
    }
  } catch (err) {
    msg.className = 'fs-msg err';
    msg.textContent = '⚠️ Errore di rete.';
  }
  aggiornaContatori();
}

// init sul mese corrente
const oggi = new Date();
viewY = oggi.getFullYear();
viewM = oggi.getMonth() + 1;
renderCal();
aggiornaContatori();
</script>
<?php require __DIR__ . '/../includes/logbook_widget.php'; ?>
</body>
</html>
