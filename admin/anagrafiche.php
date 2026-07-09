<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
richiediAdmin();
$pdo     = getDB();
$errore  = '';
$sucesso = '';

// ── Definizione delle tabelle gestibili ──────────────────────
// Ogni colonna: [etichetta, tipo].  tipo: 'text' | 'int' | 'fk_sedi'
$TABELLE = [
    'sedi'         => ['label' => 'Sedi', 'icona' => '🏠',
        'cols' => ['codice' => ['Codice','text'], 'nome' => ['Nome','text'], 'ordine' => ['Ordine','int']]],
    'posizioni'    => ['label' => 'Mezzi / Posizioni', 'icona' => '🚒',
        'cols' => ['sede_id' => ['Sede','fk_sedi'], 'codice' => ['Codice','text'], 'nome' => ['Nome','text'], 'ordine' => ['Ordine','int']]],
    'qualifiche'   => ['label' => 'Qualifiche', 'icona' => '🎖️',
        'cols' => ['codice' => ['Codice','text'], 'nome' => ['Nome','text']]],
    'salti_turno'  => ['label' => 'Salti turno', 'icona' => '😴',
        'cols' => ['codice' => ['Codice','text'], 'turno' => ['Turno','turno']]],
    'tipo_assenza' => ['label' => 'Tipi assenza', 'icona' => '📋',
        'cols' => ['codice' => ['Codice','text'], 'nome' => ['Nome','text']]],
    'patenti'      => ['label' => 'Patenti', 'icona' => '🪪',
        'cols' => ['tipo' => ['Tipo','text'], 'nome' => ['Nome','text']]],
    'abilitazioni' => ['label' => 'Abilitazioni', 'icona' => '✅',
        'cols' => ['codice' => ['Codice','text'], 'nome' => ['Nome','text']]],
    'funzionari'   => ['label' => 'Funzionari', 'icona' => '👔',
        'cols' => ['nome' => ['Nome','text']]],
];

// Tab corrente
$tab = $_GET['tab'] ?? 'sedi';
if (!isset($TABELLE[$tab])) $tab = 'sedi';
$def  = $TABELLE[$tab];
$cols = $def['cols'];

// Sedi per le tendine fk_sedi
$sediOpt = $pdo->query("SELECT id, codice, nome FROM sedi ORDER BY ordine, codice")->fetchAll();

// ── AZIONI POST (sempre sulla tabella del tab) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';
    $tabP   = $_POST['tab'] ?? '';
    if (!isset($TABELLE[$tabP])) {
        $errore = 'Tabella non valida.';
    } else {
        $tab  = $tabP;
        $def  = $TABELLE[$tab];
        $cols = $def['cols'];

        // Raccoglie e valida i valori delle colonne
        $vals = [];
        foreach ($cols as $c => [$lbl, $tipo]) {
            $raw = trim($_POST[$c] ?? '');
            if ($tipo === 'int' || $tipo === 'fk_sedi') {
                $vals[$c] = ($raw === '') ? 0 : (int)$raw;
            } elseif ($tipo === 'turno') {
                $vals[$c] = strtoupper(substr($raw, 0, 1)) ?: 'B';
            } else {
                $vals[$c] = $raw;
            }
        }

        // ── Genera in blocco i salti 1..8 di un turno (solo Comando) ──
        // Serve quando si aggiunge un turno nuovo: crea A1..A8 (o C/D) con lo
        // stesso schema di B1..B8 invece di 8 inserimenti manuali uno a uno.
        if ($azione === 'genera_salti' && $tab === 'salti_turno') {
            if (!isComando()) {
                $errore = 'Solo il Comando può generare i salti di un turno.';
            } else {
                $turnoGen = strtoupper(substr(trim($_POST['turno_gen'] ?? ''), 0, 1));
                if (!in_array($turnoGen, ['A', 'B', 'C', 'D'], true)) {
                    $errore = 'Turno non valido.';
                } else {
                    $creati = 0; $saltati = 0;
                    for ($n = 1; $n <= 8; $n++) {
                        $codiceGen = $turnoGen . $n;
                        $chk = $pdo->prepare("SELECT 1 FROM salti_turno WHERE codice=?");
                        $chk->execute([$codiceGen]);
                        if ($chk->fetchColumn()) { $saltati++; continue; }
                        $newId = nextId($pdo, 'salti_turno');
                        $pdo->prepare("INSERT INTO salti_turno (id, codice, turno) VALUES (?,?,?)")
                            ->execute([$newId, $codiceGen, $turnoGen]);
                        $creati++;
                    }
                    $sucesso = "Turno $turnoGen: $creati salti creati" . ($saltati ? ", $saltati già esistenti (saltati)" : '') . '.';
                }
            }
        } elseif ($azione === 'salva') {
            $id = (int)($_POST['id'] ?? 0);
            // Validazione minima: il primo campo testo non vuoto
            $primo = array_key_first($cols);
            if ($vals[$primo] === '' || $vals[$primo] === 0) {
                $errore = $cols[$primo][0] . ' è obbligatorio.';
            } else {
                try {
                    if ($id > 0) {
                        $set = implode(', ', array_map(fn($c) => "`$c`=?", array_keys($cols)));
                        $sql = "UPDATE `$tab` SET $set WHERE id=?";
                        $pdo->prepare($sql)->execute([...array_values($vals), $id]);
                        $sucesso = 'Voce aggiornata.';
                    } else {
                        $newId = nextId($pdo, $tab);   // $tab whitelisted ($TABELLE)
                        $campi = '`id`, ' . implode(', ', array_map(fn($c) => "`$c`", array_keys($cols)));
                        $ph    = implode(', ', array_fill(0, count($cols) + 1, '?'));
                        $pdo->prepare("INSERT INTO `$tab` ($campi) VALUES ($ph)")
                            ->execute([$newId, ...array_values($vals)]);
                        $sucesso = 'Voce aggiunta.';
                    }
                } catch (Throwable $e) {
                    $errore = 'Errore salvataggio: ' . $e->getMessage();
                }
            }
        } elseif ($azione === 'elimina') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $pdo->prepare("DELETE FROM `$tab` WHERE id=?")->execute([$id]);
                    $sucesso = 'Voce eliminata.';
                } catch (Throwable $e) {
                    // Tipicamente vincolo FK: la voce è usata da vigili/fogli.
                    $errore = 'Impossibile eliminare: la voce è in uso (referenziata da vigili o fogli).';
                }
            }
        }
    }
}

// Riga in modifica (GET ?modifica=ID)
$rigaEdit = null;
if (isset($_GET['modifica'])) {
    $st = $pdo->prepare("SELECT * FROM `$tab` WHERE id=?");
    $st->execute([(int)$_GET['modifica']]);
    $rigaEdit = $st->fetch() ?: null;
}

// Righe della tabella corrente
$orderBy = isset($cols['ordine']) ? 'ordine, id' : (isset($cols['turno']) ? 'turno, id' : 'id');
$righe = $pdo->query("SELECT * FROM `$tab` ORDER BY $orderBy")->fetchAll();

// Mappa sedi id→etichetta per la colonna fk
$sediMap = [];
foreach ($sediOpt as $s) $sediMap[(int)$s['id']] = $s['codice'] . ' · ' . $s['nome'];

function valoreColonna(string $tipo, $v, array $sediMap): string {
    if ($tipo === 'fk_sedi') return htmlspecialchars($sediMap[(int)$v] ?? ('#' . (int)$v));
    return htmlspecialchars((string)$v);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Anagrafiche di sistema</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/vigili.css?v=<?= @filemtime(__DIR__.'/../assets/css/vigili.css') ?>">
<script defer src="../assets/js/conferma.js?v=<?= @filemtime(__DIR__.'/../assets/js/conferma.js') ?>"></script>
<style>
  .tabs { display:flex; flex-wrap:wrap; gap:6px; margin:8px 0 16px; }
  .tab  { padding:6px 14px; border-radius:7px; background:var(--bianco); border:1px solid #d8dce0;
          text-decoration:none; color:var(--grigio-sc); font-size:.85rem; font-weight:600; }
  .tab.active { background:var(--rosso); color:#fff; border-color:var(--rosso); }
  .anag-form { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
  .anag-form .ff { display:flex; flex-direction:column; gap:3px; }
  .anag-form label { font-size:.72rem; font-weight:700; text-transform:uppercase; color:var(--grigio-md); }
  .anag-form input, .anag-form select { padding:7px 9px; border:1px solid #d5d8dc; border-radius:6px; font-size:.85rem; }
</style>
</head>
<body>
<header class="header">
  <div class="header-inner">
    <div class="header-logo">🚒</div>
    <div class="header-testi">
      <h1>Comando Provinciale VVF di Genova</h1>
      <p>Gestionale Foglio di Servizio &mdash; Turno <?= htmlspecialchars(turnoCorrente() ?: turnoAttivo()) ?></p>
    </div>
    <div class="header-badge">TURNO&nbsp;<?= htmlspecialchars(turnoCorrente() ?: turnoAttivo()) ?></div>
  </div>
</header>
<nav class="navbar">
  <div class="navbar-inner">
    <a href="../index.php"        class="nav-btn">🏠 Cruscotto</a>
    <a href="../foglio/nuovo.php" class="nav-btn">📋 Nuovo Foglio</a>
    <a href="../vigili/lista.php" class="nav-btn">👥 Personale</a>
    <a href="../ferie/index.php"  class="nav-btn">🗓️ Agenda</a>
    <a href="index.php"           class="nav-btn active">⚙️ Amministrazione</a>
  </div>
</nav>
<main class="main">
  <div class="page-title">
    <h2>🗂️ Anagrafiche di sistema</h2>
    <a href="index.php" class="btn btn-grigio">← Amministrazione</a>
  </div>

  <?php if ($sucesso): ?><div class="alert alert-ok">✅ <?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
  <?php if ($errore):  ?><div class="alert alert-err">⚠️ <?= htmlspecialchars($errore) ?></div><?php endif; ?>

  <div class="tabs">
    <?php foreach ($TABELLE as $k => $t): ?>
      <a href="anagrafiche.php?tab=<?= $k ?>" class="tab <?= $k === $tab ? 'active' : '' ?>">
        <?= $t['icona'] ?> <?= htmlspecialchars($t['label']) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Genera in blocco i salti 1..8 di un turno (solo Comando, solo tab Salti turno) -->
  <?php if ($tab === 'salti_turno' && isComando()): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-head">⚡ Genera salti per un turno</div>
    <div style="padding:14px">
      <form method="POST" action="anagrafiche.php?tab=salti_turno" class="anag-form">
        <input type="hidden" name="azione" value="genera_salti">
        <input type="hidden" name="tab" value="salti_turno">
        <div class="ff">
          <label>Turno</label>
          <select name="turno_gen">
            <?php foreach (['A', 'B', 'C', 'D'] as $t): ?>
              <option value="<?= $t ?>">Turno <?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ff">
          <button type="submit" class="btn btn-rosso">Genera 8 salti (1..8)</button>
        </div>
        <div class="ff" style="font-size:.78rem;color:var(--grigio-md);align-self:center">
          Crea i codici mancanti (es. A1..A8): quelli già esistenti non si toccano.
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Form aggiungi / modifica -->
  <div class="card">
    <div class="card-head"><?= $rigaEdit ? '✏️ Modifica' : '➕ Aggiungi' ?> — <?= htmlspecialchars($def['label']) ?></div>
    <div style="padding:14px">
      <form method="POST" action="anagrafiche.php?tab=<?= $tab ?>" class="anag-form">
        <input type="hidden" name="azione" value="salva">
        <input type="hidden" name="tab" value="<?= $tab ?>">
        <input type="hidden" name="id" value="<?= $rigaEdit['id'] ?? 0 ?>">
        <?php foreach ($cols as $c => [$lbl, $tipo]):
            $val = $rigaEdit[$c] ?? ''; ?>
          <div class="ff">
            <label><?= htmlspecialchars($lbl) ?></label>
            <?php if ($tipo === 'fk_sedi'): ?>
              <select name="<?= $c ?>">
                <?php foreach ($sediOpt as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= (int)$val === (int)$s['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['codice'] . ' · ' . $s['nome']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($tipo === 'turno'): ?>
              <select name="<?= $c ?>">
                <?php foreach (['A', 'B', 'C', 'D'] as $t): ?>
                  <option value="<?= $t ?>" <?= (string)$val === $t ? 'selected' : '' ?>>Turno <?= $t ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($tipo === 'int'): ?>
              <input type="number" name="<?= $c ?>" value="<?= htmlspecialchars((string)$val) ?>" style="width:90px">
            <?php else: ?>
              <input type="text" name="<?= $c ?>" value="<?= htmlspecialchars((string)$val) ?>">
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div class="ff">
          <button type="submit" class="btn btn-rosso"><?= $rigaEdit ? 'Salva modifiche' : 'Aggiungi' ?></button>
        </div>
        <?php if ($rigaEdit): ?>
          <div class="ff"><a href="anagrafiche.php?tab=<?= $tab ?>" class="btn btn-grigio">Annulla</a></div>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Tabella voci -->
  <div class="tabella-wrap" style="margin-top:16px">
    <table>
      <thead>
        <tr>
          <?php foreach ($cols as $c => [$lbl, $tipo]): ?><th><?= htmlspecialchars($lbl) ?></th><?php endforeach; ?>
          <th style="text-align:center">Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($righe)): ?>
          <tr><td colspan="<?= count($cols) + 1 ?>" style="text-align:center;padding:24px;color:var(--grigio-md)">Nessuna voce.</td></tr>
        <?php endif; ?>
        <?php foreach ($righe as $r): ?>
          <tr>
            <?php foreach ($cols as $c => [$lbl, $tipo]): ?>
              <td><?= valoreColonna($tipo, $r[$c] ?? '', $sediMap) ?></td>
            <?php endforeach; ?>
            <td>
              <div class="azioni">
                <a href="anagrafiche.php?tab=<?= $tab ?>&modifica=<?= (int)$r['id'] ?>" class="btn btn-grigio btn-sm">✏️ Modifica</a>
                <form method="POST" action="anagrafiche.php?tab=<?= $tab ?>"
                      onsubmit="return confermaSubmit(this, 'Eliminare questa voce? Se è usata da vigili o fogli l\'operazione verrà bloccata.', {titolo:'Elimina voce', okLabel:'🗑️ Elimina'})">
                  <input type="hidden" name="azione" value="elimina">
                  <input type="hidden" name="tab" value="<?= $tab ?>">
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
