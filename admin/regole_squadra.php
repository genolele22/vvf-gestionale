<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
richiediComando();   // policy di sistema (Centrale), non per-turno — come parametri.php
$pdo    = getDB();
$errore = '';
$sucesso = '';

// Tabella regole di composizione squadra. Creata al volo se manca.
$pdo->exec("CREATE TABLE IF NOT EXISTS regole_squadra (
    id              INT UNSIGNED NOT NULL,
    ordine          INT UNSIGNED NOT NULL DEFAULT 100,
    etichetta       VARCHAR(80)  NOT NULL,
    sede_id         INT UNSIGNED NOT NULL,
    qualifiche_ids  VARCHAR(60)  DEFAULT NULL,
    patenti_ids     VARCHAR(60)  DEFAULT NULL,
    abilitazione_id INT UNSIGNED DEFAULT NULL,
    posizioni_ids   VARCHAR(120) NOT NULL,
    attiva          TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';

    if ($azione === 'salva') {
        $id        = (int)($_POST['id'] ?? 0);
        $etichetta = trim($_POST['etichetta'] ?? '');
        $ordine    = (int)($_POST['ordine'] ?? 100);
        $sedeId    = (int)($_POST['sede_id'] ?? 0);
        $qualiIds  = implode(',', array_map('intval', $_POST['qualifiche'] ?? []));
        $patIds    = implode(',', array_map('intval', $_POST['patenti'] ?? []));
        $abilId    = (int)($_POST['abilitazione_id'] ?? 0) ?: null;
        $posIds    = implode(',', array_values(array_filter(array_map(
            fn($k) => (int)($_POST[$k] ?? 0),
            ['posizione_1', 'posizione_2', 'posizione_3', 'posizione_4']
        ))));
        $attiva    = isset($_POST['attiva']) ? 1 : 0;

        if ($etichetta === '') {
            $errore = 'L\'etichetta è obbligatoria.';
        } elseif ($sedeId <= 0) {
            $errore = 'Seleziona la sede.';
        } elseif ($posIds === '') {
            $errore = 'Seleziona almeno una posizione target.';
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare(
                        "UPDATE regole_squadra SET ordine=?, etichetta=?, sede_id=?, qualifiche_ids=?,
                                                    patenti_ids=?, abilitazione_id=?, posizioni_ids=?, attiva=?
                         WHERE id=?"
                    )->execute([$ordine, $etichetta, $sedeId, $qualiIds, $patIds, $abilId, $posIds, $attiva, $id]);
                    $sucesso = 'Regola aggiornata.';
                } else {
                    $newId = nextId($pdo, 'regole_squadra');
                    $pdo->prepare(
                        "INSERT INTO regole_squadra (id, ordine, etichetta, sede_id, qualifiche_ids,
                                                      patenti_ids, abilitazione_id, posizioni_ids, attiva)
                         VALUES (?,?,?,?,?,?,?,?,?)"
                    )->execute([$newId, $ordine, $etichetta, $sedeId, $qualiIds, $patIds, $abilId, $posIds, $attiva]);
                    $sucesso = 'Regola aggiunta.';
                }
            } catch (Throwable $e) {
                $errore = 'Errore salvataggio: ' . $e->getMessage();
            }
        }
    } elseif ($azione === 'elimina') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM regole_squadra WHERE id=?")->execute([$id]);
            $sucesso = 'Regola eliminata.';
        }
    }
}

// Liste per le tendine/checkbox
$sedi = $pdo->query("SELECT id, codice, nome FROM sedi ORDER BY ordine, codice")->fetchAll();
$qualifiche = $pdo->query("SELECT id, codice, nome FROM qualifiche ORDER BY id")->fetchAll();
$patenti = $pdo->query("SELECT id, tipo, nome FROM patenti ORDER BY tipo")->fetchAll();
$abilitazioni = $pdo->query("SELECT id, codice, nome FROM abilitazioni ORDER BY codice")->fetchAll();
$posizioni = $pdo->query(
    "SELECT p.id, p.codice, p.nome, s.codice AS sede_codice
     FROM posizioni p JOIN sedi s ON s.id = p.sede_id
     ORDER BY s.ordine, s.codice, p.ordine, p.codice"
)->fetchAll();

$sedeMap = [];
foreach ($sedi as $s) $sedeMap[(int)$s['id']] = $s['codice'] . ' · ' . $s['nome'];
$posLabel = fn(array $p): string => $p['sede_codice'] . ' · ' . $p['codice'] . ($p['nome'] ? ' (' . $p['nome'] . ')' : '');

function csvToInts(?string $csv): array {
    return array_map('intval', array_filter(explode(',', (string)$csv), fn($v) => trim($v) !== ''));
}

$rigaEdit = null;
if (isset($_GET['modifica'])) {
    $st = $pdo->prepare("SELECT * FROM regole_squadra WHERE id=?");
    $st->execute([(int)$_GET['modifica']]);
    $rigaEdit = $st->fetch() ?: null;
}
$editQuali = $rigaEdit ? csvToInts($rigaEdit['qualifiche_ids']) : [];
$editPat   = $rigaEdit ? csvToInts($rigaEdit['patenti_ids']) : [];
$editPos   = $rigaEdit ? csvToInts($rigaEdit['posizioni_ids']) : [];
$sedeCentraleId = 0;
foreach ($sedi as $s) if ($s['codice'] === 'C') $sedeCentraleId = (int)$s['id'];

// Regole esistenti con etichette leggibili
$righe = $pdo->query("SELECT * FROM regole_squadra ORDER BY ordine, id")->fetchAll();
$qualCod = []; foreach ($qualifiche as $q) $qualCod[(int)$q['id']] = $q['codice'];
$patTipo = []; foreach ($patenti as $p) $patTipo[(int)$p['id']] = $p['tipo'];
$abilCod = []; foreach ($abilitazioni as $a) $abilCod[(int)$a['id']] = $a['codice'];
$posCod  = []; foreach ($posizioni as $p) $posCod[(int)$p['id']] = $p['codice'];
$etichettaCsv = function (array $ids, array $map): string {
    if (!$ids) return 'tutte';
    return implode(', ', array_map(fn($id) => $map[$id] ?? '#' . $id, $ids));
};
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Criteri squadra</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/vigili.css?v=<?= @filemtime(__DIR__.'/../assets/css/vigili.css') ?>">
<script defer src="../assets/js/conferma.js?v=<?= @filemtime(__DIR__.'/../assets/js/conferma.js') ?>"></script>
<style>
  .rs-form { display:flex; flex-direction:column; gap:14px; max-width:760px; }
  .rs-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .rs-form label.fl { font-size:.72rem; font-weight:700; text-transform:uppercase; color:var(--grigio-md); display:block; margin-bottom:4px; }
  .rs-form input[type=text], .rs-form input[type=number], .rs-form select {
    width:100%; padding:8px 10px; border:1px solid #d5d8dc; border-radius:6px; font-size:.85rem; font-family:inherit; }
  .chk-group { display:flex; flex-wrap:wrap; gap:10px; border:1px solid #e3e6e9; border-radius:8px; padding:10px 12px; }
  .chk-group label { display:flex; align-items:center; gap:5px; font-size:.85rem; font-weight:400; text-transform:none; }
  .chk-group input { width:auto; }
  .chk-hint { font-size:.74rem; color:var(--grigio-md); margin-top:3px; }
  .pos-slots { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px; }
  code.kk { background:#f1f3f5; padding:1px 6px; border-radius:4px; font-size:.8rem; }
  .badge-off { background:#f1f3f5; color:var(--grigio-md); border-radius:5px; padding:2px 8px; font-size:.75rem; font-weight:600; }
  .badge-on { background:#eafaf1; color:#1e8449; border-radius:5px; padding:2px 8px; font-size:.75rem; font-weight:600; }
</style>
</head>
<body>
<header class="header">
  <div class="header-inner">
    <div class="header-logo">🚒</div>
    <div class="header-testi">
      <h1>Comando Provinciale VVF di Genova</h1>
      <p>Gestionale Foglio di Servizio</p>
    </div>
    <div class="header-badge">SISTEMA</div>
  </div>
</header>
<nav class="navbar">
  <div class="navbar-inner">
    <a href="../index.php"        class="nav-btn">🚒 Home</a>
    <a href="../foglio/nuovo.php" class="nav-btn">📋 Nuovo Foglio</a>
    <a href="../vigili/lista.php" class="nav-btn">👥 Personale</a>
    <a href="../ferie/index.php"  class="nav-btn">🗓️ Agenda</a>
    <a href="index.php"           class="nav-btn active">⚙️ Amministrazione</a>
  </div>
</nav>
<main class="main">
  <div class="page-title">
    <h2>🧩 Criteri riempimento squadre</h2>
    <a href="index.php" class="btn btn-grigio">← Amministrazione</a>
  </div>

  <p style="color:var(--grigio-md);margin:0 0 12px;font-size:.85rem">
    Regole automatiche applicate alla creazione di un nuovo foglio (o dopo reset) per
    riempire le posizioni della Centrale: chi (per grado/patente/abilitazione) va in
    quale posizione. Si applicano <b>nell'ordine</b> indicato, dopo le
    <a href="assegnazioni_fisse.php">assegnazioni fisse</a> e dopo l'automatismo 1A/2A
    legato al riposo (quello resta fisso, non è qui). Chi è già stato assegnato da una
    regola precedente non viene ripreso dalle successive.
  </p>

  <div class="card">
    <div class="card-head"><?= $rigaEdit ? '✏️ Modifica regola' : '➕ Nuova regola' ?></div>
    <div style="padding:14px">
      <form method="POST" action="regole_squadra.php" class="rs-form">
        <input type="hidden" name="azione" value="salva">
        <input type="hidden" name="id" value="<?= $rigaEdit['id'] ?? 0 ?>">

        <div class="rs-grid">
          <div>
            <label class="fl">Etichetta</label>
            <input type="text" name="etichetta" value="<?= htmlspecialchars($rigaEdit['etichetta'] ?? '') ?>"
                   placeholder="es. Operatore Centrale (SO)">
          </div>
          <div>
            <label class="fl">Ordine (priorità, crescente)</label>
            <input type="number" name="ordine" value="<?= (int)($rigaEdit['ordine'] ?? 100) ?>">
          </div>
        </div>

        <div>
          <label class="fl">Sede (pool di partenza)</label>
          <select name="sede_id">
            <?php foreach ($sedi as $s): $sid = (int)$s['id'];
                  $sel = $rigaEdit ? (int)$rigaEdit['sede_id'] === $sid : $sid === $sedeCentraleId; ?>
              <option value="<?= $sid ?>" <?= $sel ? 'selected' : '' ?>><?= htmlspecialchars($s['codice'] . ' · ' . $s['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="fl">Qualifiche ammesse</label>
          <div class="chk-group">
            <?php foreach ($qualifiche as $q): ?>
              <label><input type="checkbox" name="qualifiche[]" value="<?= (int)$q['id'] ?>"
                     <?= in_array((int)$q['id'], $editQuali, true) ? 'checked' : '' ?>>
                     <?= htmlspecialchars($q['codice']) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="chk-hint">Nessuna selezionata = tutte le qualifiche.</div>
        </div>

        <div>
          <label class="fl">Patenti ammesse</label>
          <div class="chk-group">
            <?php foreach ($patenti as $p): ?>
              <label><input type="checkbox" name="patenti[]" value="<?= (int)$p['id'] ?>"
                     <?= in_array((int)$p['id'], $editPat, true) ? 'checked' : '' ?>>
                     <?= htmlspecialchars($p['tipo']) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="chk-hint">Nessuna selezionata = tutte le patenti (anche nessuna posseduta).</div>
        </div>

        <div>
          <label class="fl">Abilitazione richiesta</label>
          <select name="abilitazione_id">
            <option value="0">— nessuna —</option>
            <?php foreach ($abilitazioni as $a): $sel = $rigaEdit && (int)($rigaEdit['abilitazione_id'] ?? 0) === (int)$a['id']; ?>
              <option value="<?= (int)$a['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= htmlspecialchars($a['codice'] . ' — ' . $a['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="fl">Posizioni target, in ordine di riempimento (trabocco alla successiva a capienza piena)</label>
          <div class="pos-slots">
            <?php for ($slot = 1; $slot <= 4; $slot++): $curVal = $editPos[$slot - 1] ?? 0; ?>
              <select name="posizione_<?= $slot ?>">
                <option value="0"><?= $slot === 1 ? '— seleziona —' : '— (non usata) —' ?></option>
                <?php foreach ($posizioni as $p): $sel = $curVal === (int)$p['id']; ?>
                  <option value="<?= (int)$p['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= htmlspecialchars($posLabel($p)) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endfor; ?>
          </div>
        </div>

        <div>
          <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;font-weight:400">
            <input type="checkbox" name="attiva" style="width:auto"
                   <?= ($rigaEdit === null || !empty($rigaEdit['attiva'])) ? 'checked' : '' ?>>
            Regola attiva
          </label>
        </div>

        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-rosso"><?= $rigaEdit ? 'Salva modifiche' : 'Aggiungi regola' ?></button>
          <?php if ($rigaEdit): ?><a href="regole_squadra.php" class="btn btn-grigio">Annulla</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="tabella-wrap" style="margin-top:16px">
    <table>
      <thead>
        <tr>
          <th>Ord.</th><th>Etichetta</th><th>Sede</th><th>Qualifiche</th><th>Patenti</th>
          <th>Abilitazione</th><th>Posizioni</th><th>Stato</th><th style="text-align:center">Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($righe)): ?>
          <tr><td colspan="9" style="text-align:center;padding:24px;color:var(--grigio-md)">Nessuna regola.</td></tr>
        <?php endif; ?>
        <?php foreach ($righe as $r): ?>
          <tr>
            <td><?= (int)$r['ordine'] ?></td>
            <td><strong><?= htmlspecialchars($r['etichetta']) ?></strong></td>
            <td><?= htmlspecialchars($sedeMap[(int)$r['sede_id']] ?? '#' . (int)$r['sede_id']) ?></td>
            <td><?= htmlspecialchars($etichettaCsv(csvToInts($r['qualifiche_ids']), $qualCod)) ?></td>
            <td><?= htmlspecialchars($etichettaCsv(csvToInts($r['patenti_ids']), $patTipo)) ?></td>
            <td><?= $r['abilitazione_id'] ? '<code class="kk">' . htmlspecialchars($abilCod[(int)$r['abilitazione_id']] ?? '#' . (int)$r['abilitazione_id']) . '</code>' : '—' ?></td>
            <td><?= htmlspecialchars($etichettaCsv(csvToInts($r['posizioni_ids']), $posCod)) ?></td>
            <td><?= $r['attiva'] ? '<span class="badge-on">attiva</span>' : '<span class="badge-off">disattiva</span>' ?></td>
            <td>
              <div class="azioni" style="justify-content:center">
                <a href="regole_squadra.php?modifica=<?= (int)$r['id'] ?>" class="btn btn-grigio btn-sm">✏️ Modifica</a>
                <form method="POST" action="regole_squadra.php"
                      onsubmit="return confermaSubmit(this, 'Eliminare questa regola?', {titolo:'Elimina regola', okLabel:'🗑️ Elimina'})">
                  <input type="hidden" name="azione" value="elimina">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="btn btn-sm" style="background:var(--rosso);color:#fff">🗑️</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
</body>
</html>
