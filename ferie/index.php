<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();

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

function feriaGetOrCreateFoglio(PDO $pdo, string $data, string $tipo): int {
    $st = $pdo->prepare("SELECT id FROM fogli_servizio WHERE data_servizio=? AND tipo_turno=?");
    $st->execute([$data, $tipo]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $next = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM fogli_servizio")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO fogli_servizio (id, data_servizio, tipo_turno, salto_riposo_id, creato_da)
         VALUES (?, ?, ?, 1, 'ferie')"
    )->execute([$next, $data, $tipo]);
    return $next;
}

function feriaInsertAssenza(PDO $pdo, int $vigileId, int $foglioId): void {
    $st = $pdo->prepare(
        "SELECT id FROM assenze WHERE foglio_id=? AND vigile_id=? AND tipo_assenza_id=1"
    );
    $st->execute([$foglioId, $vigileId]);
    if ($st->fetchColumn()) return;
    $next = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM assenze")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO assenze (id, foglio_id, vigile_id, tipo_assenza_id) VALUES (?, ?, ?, 1)"
    )->execute([$next, $foglioId, $vigileId]);
}

function feriaDeleteAssenza(PDO $pdo, int $vigileId, string $data, string $tipo): void {
    $pdo->prepare(
        "DELETE a FROM assenze a
         JOIN fogli_servizio f ON f.id = a.foglio_id
         WHERE a.vigile_id=? AND f.data_servizio=? AND f.tipo_turno=? AND a.tipo_assenza_id=1"
    )->execute([$vigileId, $data, $tipo]);
}

function feriaSyncAssenza(PDO $pdo, int $vigileId, string $data, string $tipoTurno, string $stato): void {
    $tipi = ($tipoTurno === 'DN') ? ['D', 'N'] : [$tipoTurno];
    foreach ($tipi as $t) {
        if ($stato === 'rejected') {
            feriaDeleteAssenza($pdo, $vigileId, $data, $t);
        } else { // approved | pending → vigile assente sul foglio
            $foglioId = feriaGetOrCreateFoglio($pdo, $data, $t);
            feriaInsertAssenza($pdo, $vigileId, $foglioId);
        }
    }
}

// ── AJAX ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $azione = $_POST['azione'] ?? '';

    if ($azione === 'set_stato') {
        $stato = $_POST['stato'] ?? '';
        if (!in_array($stato, ['approved', 'rejected'], true)) {
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
            "SELECT id, vigile_id, data_richiesta, tipo_turno FROM bot_requests WHERE id IN ($ph)"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();

        $processedAt = date('Y-m-d H:i:s');
        $up = $pdo->prepare("UPDATE bot_requests SET stato=?, processed_at=? WHERE id=?");

        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                $up->execute([$stato, $processedAt, $r['id']]);
                feriaSyncAssenza($pdo, (int)$r['vigile_id'], $r['data_richiesta'], $r['tipo_turno'], $stato);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'errore' => 'Errore DB']); exit;
        }

        echo json_encode(['ok' => true, 'aggiornati' => count($rows), 'stato' => $stato]);
        exit;
    }

    // Cancellazione definitiva: rimuove la richiesta dal DB + l'assenza dal foglio
    if ($azione === 'elimina') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'errore' => 'ID non valido']); exit; }

        $st = $pdo->prepare("SELECT vigile_id, data_richiesta, tipo_turno FROM bot_requests WHERE id=?");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) { echo json_encode(['ok' => false, 'errore' => 'Richiesta inesistente']); exit; }

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

function blocchiContigui(array $richieste): array {
    if (empty($richieste)) return [];
    $blocks = [];
    $current = [$richieste[0]];
    for ($i = 1; $i < count($richieste); $i++) {
        $prev = new DateTime(end($current)['data_richiesta']);
        $curr = new DateTime($richieste[$i]['data_richiesta']);
        if ((int)$curr->diff($prev)->days <= 3) {
            $current[] = $richieste[$i];
        } else {
            $blocks[] = $current;
            $current  = [$richieste[$i]];
        }
    }
    $blocks[] = $current;
    return $blocks;
}

function periodLabel(array $block): string {
    $da = new DateTime($block[0]['data_richiesta']);
    $a  = new DateTime(end($block)['data_richiesta']);
    if ($da->format('Y-m-d') === $a->format('Y-m-d')) {
        return $da->format('d/m');
    }
    return $da->format('d') . '–' . $a->format('d/m');
}

function turniLabel(array $block): int {
    $n = 0;
    foreach ($block as $r) {
        $n += ($r['tipo_turno'] === 'DN') ? 2 : 1;
    }
    return $n;
}

function statoBlock(array $block): string {
    $stati = array_unique(array_column($block, 'stato'));
    if (count($stati) === 1) return $stati[0];
    return 'misto';
}

// ── Carica richieste del mese ────────────────────────────────
$stmt = $pdo->prepare("
    SELECT r.id, r.vigile_id, r.data_richiesta, r.tipo_turno, r.stato,
           v.nome, v.cognome, v.disambiguatore, v.email,
           q.codice AS qcodice,
           s.nome   AS sede_nome,
           s.codice AS sede_codice
    FROM bot_requests r
    JOIN vigili v     ON v.id = r.vigile_id
    JOIN qualifiche q ON q.id = v.qualifica_id
    JOIN sedi s       ON s.id = v.sede_id
    WHERE DATE_FORMAT(r.data_richiesta, '%Y-%m') = ?
    ORDER BY v.cognome, v.disambiguatore, r.data_richiesta
");
$stmt->execute([$meseStr]);
$tutteRichieste = $stmt->fetchAll();

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

$totPending  = count(array_filter($tutteRichieste, fn($r) => $r['stato'] === 'pending'));
$totApproved = count(array_filter($tutteRichieste, fn($r) => $r['stato'] === 'approved'));
$totRejected = count(array_filter($tutteRichieste, fn($r) => $r['stato'] === 'rejected'));
$totVigili   = count($perVigile);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Gestione Ferie <?= $mesiNomi[$meseP] ?> <?= $annoP ?></title>
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
</style>
</head>
<body>

<header class="header">
  <div class="header-inner">
    <div class="header-logo">🚒</div>
    <div class="header-testi">
      <h1>Comando Provinciale VVF di Genova</h1>
      <p>Gestionale — Gestione Ferie Turno B</p>
    </div>
    <div class="header-badge">TURNO B</div>
  </div>
</header>

<nav class="navbar">
  <div class="navbar-inner">
    <a href="../index.php"          class="nav-btn">🏠 Cruscotto</a>
    <a href="../foglio/nuovo.php"   class="nav-btn">📋 Foglio</a>
    <a href="../vigili/lista.php"   class="nav-btn">👥 Personale</a>
    <a href="index.php"             class="nav-btn active">🏖️ Ferie</a>
    <a href="../report/index.php"   class="nav-btn">📊 Reportistica</a>
    <a href="../admin/index.php"    class="nav-btn">⚙️ Amministrazione</a>
    <a href="../logout.php"         class="nav-btn ml-auto">🚪 Esci</a>
  </div>
</nav>

<div class="ferie-page">

  <div id="msgBox"></div>

  <!-- Navigazione mese -->
  <div class="mese-nav">
    <a href="?anno=<?= $annoPrev ?>&mese=<?= $mesePrev ?>" class="btn btn-grigio btn-sm">◀</a>
    <h2>🏖️ Ferie — <?= $mesiNomi[$meseP] ?> <?= $annoP ?></h2>
    <a href="?anno=<?= $annoNext ?>&mese=<?= $meseNext ?>" class="btn btn-grigio btn-sm">▶</a>
  </div>

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

  <?php if (empty($perData)): ?>
    <div class="alert alert-ok">Nessuna richiesta di ferie per <?= $mesiNomi[$meseP] ?> <?= $annoP ?>.</div>
  <?php endif; ?>

  <!-- Sezioni per data di inizio -->
  <?php foreach ($perData as $dataInizio => $gruppo):
    $dtInizio   = new DateTime($dataInizio);
    $dataHeader = $giorniNomi[(int)$dtInizio->format('N')] . ' '
                . $dtInizio->format('d') . ' '
                . $mesiNomi[(int)$dtInizio->format('n')];
  ?>
  <div class="data-section">

    <!-- Intestazione data -->
    <div class="data-head" style="display:flex;align-items:baseline;gap:10px;padding:6px 4px 6px 0;margin-bottom:6px;border-bottom:2px solid var(--rosso);">
      <span class="data-label" style="font-size:.95rem;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--grigio-sc);"><?= $dataHeader ?></span>
      <span class="data-count" style="font-size:.72rem;color:var(--grigio-md);font-weight:600;"><?= count($gruppo) ?> vigil<?= count($gruppo) === 1 ? 'e' : 'i' ?></span>
    </div>

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
    ?>
    <!-- Riga vigile -->
    <div class="blocco-row" id="row-<?= $detailId ?>"
         onclick="toggleDetail('<?= $detailId ?>')">
      <span class="toggle-icon" id="icon-<?= $detailId ?>">▶</span>
      <span class="blocco-nome"><?= htmlspecialchars($label) ?></span>
      <?php if (!$isCentrale): ?>
        <span class="blocco-sede"><?= htmlspecialchars($meta['sede_codice']) ?></span>
      <?php endif; ?>
      <span class="blocco-periodo"><?= $periodo ?></span>
      <span class="blocco-turni"><?= $turni ?> turni</span>
      <span class="blocco-spacer"></span>
      <span class="stato-badge stato-<?= $stato ?>" id="badge-<?= $detailId ?>"><?= $stato ?></span>
      <div class="blocco-azioni" onclick="event.stopPropagation()">
        <button class="btn-mini accetta"
                onclick='setStato(<?= htmlspecialchars(json_encode($allIds)) ?>, "approved")'
                title="Accetta tutto il periodo">✓ tutti</button>
        <button class="btn-mini respingi"
                onclick='setStato(<?= htmlspecialchars(json_encode($allIds)) ?>, "rejected")'
                title="Respingi tutto il periodo">✗ tutti</button>
      </div>
    </div>

    <!-- Tendina singoli turni -->
    <div class="turni-detail" id="<?= $detailId ?>" data-block="<?= $detailId ?>">
      <?php foreach ($block as $r):
        $d   = new DateTime($r['data_richiesta']);
        $dow = $giorniNomi[(int)$d->format('N')];
      ?>
      <div class="turno-riga" data-id="<?= $r['id'] ?>" data-stato="<?= $r['stato'] ?>" data-block="<?= $detailId ?>">
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
        <div class="scelta">
          <label class="lbl-si">
            <input type="checkbox" class="chk-si" <?= $r['stato'] !== 'rejected' ? 'checked' : '' ?>
                   onchange="onScelta(this, 'approved')">accetto
          </label>
          <label class="lbl-no">
            <input type="checkbox" class="chk-no" <?= $r['stato'] === 'rejected' ? 'checked' : '' ?>
                   onchange="onScelta(this, 'rejected')">respingo
          </label>
        </div>
        <button class="btn-elimina" title="Elimina definitivamente la richiesta"
                onclick="eliminaTurno(<?= $r['id'] ?>, this)">🗑️</button>
      </div>
      <?php endforeach; ?>
    </div>

    <?php endforeach; ?>
    </div><!-- /.vigile-card -->

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

    // Aggiorna lo stato in pagina senza reload
    ids.forEach(id => {
        const riga = document.querySelector(`.turno-riga[data-id="${id}"]`);
        if (riga) riga.dataset.stato = stato;
    });
    sincronizzaDOM();
    showMsg(`✅ ${res.aggiornati} turno/i → ${STATO_LABEL[stato]}`, 'ok');
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

sincronizzaDOM();
</script>

</body>
</html>
