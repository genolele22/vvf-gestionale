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

$stili = stiliPatente();
// hex di riferimento quando l'admin non ha mai scelto una tinta propria (colonna
// 'Classico (attuale)' della vecchia palette, e default storici ODT/#182)
const HEX_ROSSO_DEFAULT      = '#c0392b';
const HEX_BLU_DEFAULT        = '#2471a3';
const HEX_STRAORD_DEFAULT    = '#FFFF66';
const HEX_FERIE_ESTIVA_DEFAULT  = '#AEE3E8';
const HEX_FERIE_UFFICIO_DEFAULT = '#AEE3E8';

/** Normalizza il POST di un colore libero: '' (non un hex valido → classico) | '#rrggbb'. */
function leggiColorePost(string $campo): string {
    $v = trim((string)($_POST[$campo] ?? ''));
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : '';
}

/** Colore di evidenziazione (#182): checkbox "spento" separata dall'input colore —
 *  l'input non va mai disabilitato lato HTML, altrimenti il browser non lo invia
 *  col form e uno stato "spento" salvato tornerebbe "classico" al salvataggio successivo. */
function leggiEvidPost(string $campo): string {
    if (isset($_POST["{$campo}_off"])) return 'none';
    return leggiColorePost($campo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stile = $_POST['stile'] ?? 'colore';
    if (!isset($stili[$stile])) $stile = 'colore';
    $rosso   = leggiColorePost('rosso');
    $blu     = leggiColorePost('blu');
    $straord = leggiEvidPost('straord');
    $estiva  = leggiEvidPost('ferie_estiva');
    $ufficio = leggiEvidPost('ferie_ufficio');
    try {
        setParam($pdo, "foglio_stile_patente_$TURNO", $stile, "Patenti sul foglio turno $TURNO: colore/numero/entrambi");
        setParam($pdo, "foglio_rosso_patente_$TURNO", $rosso, "Tinta rossa patenti 3ª/4ª turno $TURNO (vuoto = storica)");
        setParam($pdo, "foglio_blu_patente_$TURNO",   $blu,   "Tinta blu patente 2ª turno $TURNO (vuoto = storica)");
        setParam($pdo, "foglio_col_straord_$TURNO",       $straord, "Sfondo straordinario ODT turno $TURNO (vuoto=classico, none=spento)");
        setParam($pdo, "foglio_col_ferie_estiva_$TURNO",  $estiva,  "Sfondo ferie estive ODT turno $TURNO (vuoto=classico, none=spento)");
        setParam($pdo, "foglio_col_ferie_ufficio_$TURNO", $ufficio, "Sfondo ferie d'ufficio ODT turno $TURNO (vuoto=classico, none=spento)");
        $sucesso = "Stili & Colori del turno $TURNO salvati. Le patenti valgono subito sul foglio web e dal prossimo ODT scaricato; le evidenziazioni solo sull'ODT.";
    } catch (Throwable $e) {
        $errore = 'Errore salvataggio: ' . $e->getMessage();
    }
}

$cfg = validaStilePatente(leggiParametri($pdo, chiaviStilePatente($TURNO)), $TURNO);
$cfg = ['stile' => $cfg['stile'] ?? 'colore', 'rosso' => $cfg['rosso'] ?? '', 'blu' => $cfg['blu'] ?? ''];
$exRosso = $cfg['rosso'] !== '' ? $cfg['rosso'] : HEX_ROSSO_DEFAULT;

$evid = validaStileEvidenziazioni(leggiParametri($pdo, chiaviStileEvidenziazioni($TURNO)), $TURNO);
// per il form: valore grezzo del campo ('' | 'none' | '#hex') + hex da mostrare nel picker anche se spento
$evidForm = [];
foreach (['straord' => HEX_STRAORD_DEFAULT, 'estiva' => HEX_FERIE_ESTIVA_DEFAULT, 'ufficio' => HEX_FERIE_UFFICIO_DEFAULT] as $k => $def) {
    $raw = $evid[$k] ?? null;
    $evidForm[$k] = [
        'raw' => $raw ?? '',
        'spento' => $raw === 'none',
        'hex' => ($raw !== null && $raw !== 'none') ? $raw : $def,
    ];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Stili &amp; Colori turno <?= htmlspecialchars($TURNO) ?></title>
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
  .col-row { display:flex; align-items:center; gap:10px; padding:8px 10px; border:1px solid #e3e6e9; border-radius:8px; margin-bottom:8px; }
  .col-row input[type=color] { width:40px; height:32px; padding:0; border:1px solid #ccc; border-radius:6px; cursor:pointer; }
  .col-row .col-nome { flex:1; font-size:.85rem; }
  .col-row .col-spento { display:flex; align-items:center; gap:5px; font-size:.72rem; color:var(--grigio-md); font-weight:400; text-transform:none; margin:0; white-space:nowrap; }
  .col-row .col-spento input { width:auto; }
  .col-row.is-spento input[type=color] { opacity:.35; }
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
    <h2>🎨 Stili &amp; Colori — turno <?= htmlspecialchars($TURNO) ?></h2>
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
      <form method="POST" action="stile_patenti.php" class="par-form" id="stiliForm">
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
            <div class="col-row">
              <input type="color" name="rosso" value="<?= htmlspecialchars($exRosso) ?>">
              <span class="col-nome">Colore libero</span>
              <button type="button" class="btn btn-grigio btn-sm" onclick="ripristina(this, '<?= HEX_ROSSO_DEFAULT ?>')">↺ Classico</button>
            </div>
          </div>
          <div>
            <label>Blu (2ª)</label>
            <div class="col-row">
              <input type="color" name="blu" value="<?= htmlspecialchars($cfg['blu'] !== '' ? $cfg['blu'] : HEX_BLU_DEFAULT) ?>">
              <span class="col-nome">Colore libero</span>
              <button type="button" class="btn btn-grigio btn-sm" onclick="ripristina(this, '<?= HEX_BLU_DEFAULT ?>')">↺ Classico</button>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head">Evidenziazioni sull'ODT</div>
    <div style="padding:14px">
      <p class="hint" style="margin:0 0 12px">
        Colore di sfondo dietro al nome per straordinario, ferie estive e ferie d'ufficio —
        solo sul PDF/ODT scaricato, il foglio web non cambia. Spunta «nessuna evidenziazione»
        per non colorare quel caso (lo straordinario resta comunque in grassetto).
      </p>
      <?php foreach ([
          'straord'       => ['Straordinario',   HEX_STRAORD_DEFAULT],
          'ferie_estiva'  => ["Ferie estive",    HEX_FERIE_ESTIVA_DEFAULT],
          'ferie_ufficio' => ["Ferie d'ufficio", HEX_FERIE_UFFICIO_DEFAULT],
      ] as $campo => [$etichetta, $def]):
          $k = $campo === 'straord' ? 'straord' : ($campo === 'ferie_estiva' ? 'estiva' : 'ufficio');
          $f = $evidForm[$k];
      ?>
      <div>
        <label><?= htmlspecialchars($etichetta) ?></label>
        <div class="col-row<?= $f['spento'] ? ' is-spento' : '' ?>" data-evid-row>
          <input type="color" name="<?= $campo ?>" value="<?= htmlspecialchars($f['hex']) ?>" form="stiliForm">
          <span class="col-nome">Colore libero</span>
          <button type="button" class="btn btn-grigio btn-sm" onclick="ripristina(this, '<?= $def ?>')">↺ Classico</button>
          <label class="col-spento">
            <input type="checkbox" name="<?= $campo ?>_off" data-spegni value="1" <?= $f['spento'] ? 'checked' : '' ?> form="stiliForm">
            nessuna evidenziazione
          </label>
        </div>
      </div>
      <?php endforeach; ?>
      <div><button type="submit" form="stiliForm" class="btn btn-rosso">Salva turno <?= htmlspecialchars($TURNO) ?></button></div>
    </div>
  </div>
</main>
<script>
function ripristina(btn, hex) {
  btn.closest('.col-row').querySelector('input[type=color]').value = hex;
}
document.querySelectorAll('[data-evid-row]').forEach(row => {
  const chk = row.querySelector('[data-spegni]');
  chk.addEventListener('change', () => row.classList.toggle('is-spento', chk.checked));
});
</script>
<?php require __DIR__ . '/../includes/logbook_widget.php'; ?>
</body>
</html>
