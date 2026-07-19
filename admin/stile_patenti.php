<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/parametri_lib.php';
require_once __DIR__ . '/../includes/format.php';
richiediAdmin();   // ogni admin imposta il SUO turno (il comando sceglie col selettore)
$pdo   = getDB();
$TURNO = turnoAttivo();
$errore  = '';
$sucesso = '';
assicuraTabellaParametri($pdo);

$stili   = stiliPatente();
$palette = palettePatente();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stile = $_POST['stile'] ?? 'colore';
    if (!isset($stili[$stile])) $stile = 'colore';
    $rosso = $_POST['rosso'] ?? '';
    if (!isset($palette['rosso'][$rosso])) $rosso = '';
    $blu = $_POST['blu'] ?? '';
    if (!isset($palette['blu'][$blu])) $blu = '';
    try {
        setParam($pdo, "foglio_stile_patente_$TURNO", $stile, "Patenti sul foglio turno $TURNO: colore/numero/entrambi");
        setParam($pdo, "foglio_rosso_patente_$TURNO", $rosso, "Tinta rossa patenti 3ª/4ª turno $TURNO (vuoto = storica)");
        setParam($pdo, "foglio_blu_patente_$TURNO",   $blu,   "Tinta blu patente 2ª turno $TURNO (vuoto = storica)");
        $sucesso = "Stile patenti del turno $TURNO salvato. Vale subito sul foglio web e dal prossimo ODT scaricato.";
    } catch (Throwable $e) {
        $errore = 'Errore salvataggio: ' . $e->getMessage();
    }
}

$cfg = validaStilePatente(leggiParametri($pdo, chiaviStilePatente($TURNO)), $TURNO);
$cfg = ['stile' => $cfg['stile'] ?? 'colore', 'rosso' => $cfg['rosso'] ?? '', 'blu' => $cfg['blu'] ?? ''];
$exRosso = $cfg['rosso'] !== '' ? $cfg['rosso'] : '#c0392b';
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Stile patenti turno <?= htmlspecialchars($TURNO) ?></title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/vigili.css?v=<?= @filemtime(__DIR__.'/../assets/css/vigili.css') ?>">
<style>
  .par-form { display:flex; flex-direction:column; gap:12px; max-width:680px; }
  .par-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .par-form label { font-size:.72rem; font-weight:700; text-transform:uppercase; color:var(--grigio-md); display:block; margin-bottom:3px; }
  .hint { font-size:.75rem; color:var(--grigio-md); margin-top:2px; }
  .fmt-opt { display:flex; align-items:center; gap:10px; padding:8px 10px; border:1px solid #e3e6e9; border-radius:8px; margin-bottom:8px; cursor:pointer; }
  .fmt-opt input { width:auto; }
  .fmt-opt .ex { font-weight:700; margin-left:auto; }
  @media(max-width:640px){ .par-grid{ grid-template-columns:1fr; } }
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
    <div class="header-badge">TURNO <?= htmlspecialchars($TURNO) ?></div>
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
    <h2>🎨 Patenti sul Foglio/ODT — turno <?= htmlspecialchars($TURNO) ?></h2>
    <a href="index.php" class="btn btn-grigio">← Amministrazione</a>
  </div>

  <?php if ($sucesso): ?><div style="background:#e7f6ec;border:1px solid #b7e1c2;color:#1e6b34;border-radius:8px;padding:10px 14px;margin-bottom:14px"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
  <?php if ($errore):  ?><div style="background:#fdecea;border:1px solid #f5b8b2;color:#a12622;border-radius:8px;padding:10px 14px;margin-bottom:14px"><?= htmlspecialchars($errore) ?></div><?php endif; ?>

  <div class="card">
    <div class="card-head">Come indicare la patente più alta di ogni vigile</div>
    <div style="padding:14px">
      <p class="hint" style="margin:0 0 12px">
        Col <b>colore</b> del nome (rosso = 3ª/4ª, blu = 2ª), col <b>numero</b> accanto al
        nome (es. <i>Cs Rossi 3°</i>), o entrambi. Vale solo per il turno <b><?= htmlspecialchars($TURNO) ?></b>,
        subito sul foglio web e dal prossimo ODT scaricato. «Classico» mantiene le tinte usate finora.
      </p>
      <form method="POST" action="stile_patenti.php" class="par-form">
        <div>
          <label>Stile</label>
          <?php foreach ($stili as $val => $lbl):
              $ex = $val === 'colore' ? '<span style="color:'.$exRosso.'">Cs Rossi</span>'
                 : ($val === 'numero' ? '<span style="color:#2c3e50">Cs Rossi 3°</span>'
                                      : '<span style="color:'.$exRosso.'">Cs Rossi 3°</span>');
          ?>
          <label class="fmt-opt">
            <input type="radio" name="stile" value="<?= $val ?>" <?= $cfg['stile'] === $val ? 'checked' : '' ?>>
            <span><?= htmlspecialchars($lbl) ?></span>
            <span class="ex"><?= $ex ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <div class="par-grid">
          <div>
            <label>Rosso (3ª/4ª)</label>
            <?php foreach ($palette['rosso'] as $val => [$lbl, $hex]): ?>
            <label class="fmt-opt" style="margin-bottom:4px;padding:6px 8px">
              <input type="radio" name="rosso" value="<?= $val ?>" <?= $cfg['rosso'] === $val ? 'checked' : '' ?>>
              <span style="color:<?= $hex ?>;font-weight:700"><?= htmlspecialchars($lbl) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
          <div>
            <label>Blu (2ª)</label>
            <?php foreach ($palette['blu'] as $val => [$lbl, $hex]): ?>
            <label class="fmt-opt" style="margin-bottom:4px;padding:6px 8px">
              <input type="radio" name="blu" value="<?= $val ?>" <?= $cfg['blu'] === $val ? 'checked' : '' ?>>
              <span style="color:<?= $hex ?>;font-weight:700"><?= htmlspecialchars($lbl) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div><button type="submit" class="btn btn-rosso">Salva stile turno <?= htmlspecialchars($TURNO) ?></button></div>
      </form>
    </div>
  </div>
</main>
</body>
</html>
