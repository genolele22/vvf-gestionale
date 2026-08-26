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
// #220: questi due controlli nascono SPENTI (default = comportamento storico), gli
// hex qui sotto servono solo come punto di partenza del selettore.
const HEX_FUORISEDE_DEFAULT  = '#FFD9B3';
const HEX_QUAL_DEFAULT = ['Cr' => '#c0392b', 'Cs' => '#2471a3', 'Vp' => '#2c3e50'];

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

/** Checkbox booleana → '1'/'0' (mai '': il default vale solo per chi non ha mai salvato). */
function leggiBoolPost(string $campo): string {
    return isset($_POST[$campo]) ? '1' : '0';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stile = $_POST['stile'] ?? 'colore';
    if (!isset($stili[$stile])) $stile = 'colore';
    $rosso   = leggiColorePost('rosso');
    $blu     = leggiColorePost('blu');
    $straord = leggiEvidPost('straord');
    $estiva  = leggiEvidPost('ferie_estiva');
    $ufficio = leggiEvidPost('ferie_ufficio');
    $fuoriS  = leggiEvidPost('fuorisede');
    try {
        setParam($pdo, "foglio_stile_patente_$TURNO", $stile, "Patenti sul foglio turno $TURNO: colore/numero/entrambi");
        setParam($pdo, "foglio_rosso_patente_$TURNO", $rosso, "Tinta rossa patenti 3ª/4ª turno $TURNO (vuoto = storica)");
        setParam($pdo, "foglio_blu_patente_$TURNO",   $blu,   "Tinta blu patente 2ª turno $TURNO (vuoto = storica)");
        setParam($pdo, "foglio_col_straord_$TURNO",       $straord, "Sfondo straordinario ODT turno $TURNO (vuoto=classico, none=spento)");
        setParam($pdo, "foglio_col_ferie_estiva_$TURNO",  $estiva,  "Sfondo ferie estive ODT turno $TURNO (vuoto=classico, none=spento)");
        setParam($pdo, "foglio_col_ferie_ufficio_$TURNO", $ufficio, "Sfondo ferie d'ufficio ODT turno $TURNO (vuoto=classico, none=spento)");
        // #220 — colori dei nomi sull'ODT + stili di testo straordinario/fuori sede
        setParam($pdo, "foglio_colori_odt_$TURNO",      leggiBoolPost('colori_odt'),      "ODT turno $TURNO: usa gli stessi colori del gestionale (0 = nomi neri)");
        setParam($pdo, "foglio_str_grassetto_$TURNO",   leggiBoolPost('str_grassetto'),   "ODT turno $TURNO: straordinario in grassetto");
        setParam($pdo, "foglio_str_corsivo_$TURNO",     leggiBoolPost('str_corsivo'),     "ODT turno $TURNO: straordinario in corsivo");
        setParam($pdo, "foglio_col_fuorisede_$TURNO",   $fuoriS,                          "Sfondo fuori sede ODT turno $TURNO (none/vuoto=nessuno)");
        setParam($pdo, "foglio_fs_grassetto_$TURNO",    leggiBoolPost('fs_grassetto'),    "ODT turno $TURNO: fuori sede in grassetto");
        setParam($pdo, "foglio_fs_corsivo_$TURNO",      leggiBoolPost('fs_corsivo'),      "ODT turno $TURNO: fuori sede in corsivo");
        setParam($pdo, "foglio_fs_sottolineato_$TURNO", leggiBoolPost('fs_sottolineato'), "ODT turno $TURNO: fuori sede sottolineato");
        // #220 — colore per qualifica. Si salva SOLO in stile 'numero': fuori da lì il
        // riquadro è disabilitato (quindi il browser non manda nulla) e riscrivere le
        // chiavi col POST vuoto cancellerebbe le scelte dell'admin. Il conflitto
        // patente/qualifica è comunque chiuso lato server in colorePatentePHP() /
        // FoglioRenderer::nameStyle(): fuori dallo stile 'numero' quei colori sono
        // inerti anche se qualcuno forzasse il POST.
        if ($stile === 'numero') {
            foreach (qualificheColorabili() as $q => $lbl) {
                setParam($pdo, 'foglio_col_qual_' . strtolower($q) . "_$TURNO", leggiEvidPost('qual_' . strtolower($q)),
                    "Colore nome $lbl turno $TURNO (none/vuoto = nessun colore)");
            }
        }
        $sucesso = "Stili & Colori del turno $TURNO salvati. Le patenti valgono subito sul foglio web e dal prossimo ODT scaricato; le evidenziazioni solo sull'ODT."
                 . ($stile !== 'numero' ? " Il colore per qualifica è disattivato: vale solo con lo stile «solo numero»." : '');
    } catch (Throwable $e) {
        $errore = 'Errore salvataggio: ' . $e->getMessage();
    }
}

$par = leggiParametri($pdo, array_merge(
    chiaviStilePatente($TURNO), chiaviStileEvidenziazioni($TURNO),
    chiaviStileQualifica($TURNO), chiaviStileOdt($TURNO)
));
$cfg = validaStilePatente($par, $TURNO);
$cfg = ['stile' => $cfg['stile'] ?? 'colore', 'rosso' => $cfg['rosso'] ?? '', 'blu' => $cfg['blu'] ?? ''];
$exRosso = $cfg['rosso'] !== '' ? $cfg['rosso'] : HEX_ROSSO_DEFAULT;
$soloNumero = $cfg['stile'] === 'numero';   // #220: condizione del riquadro Qualifica

$evid = validaStileEvidenziazioni($par, $TURNO);
$odt  = validaStileOdt($par, $TURNO);
$evid['fuorisede'] = $odt['fs_col_raw'] === '' ? null : $odt['fs_col_raw'];
$qual = validaStileQualifica($par, $TURNO);
// per il form: valore grezzo del campo ('' | 'none' | '#hex') + hex da mostrare nel picker anche se spento
$evidForm = [];
foreach (['straord' => HEX_STRAORD_DEFAULT, 'estiva' => HEX_FERIE_ESTIVA_DEFAULT,
          'ufficio' => HEX_FERIE_UFFICIO_DEFAULT, 'fuorisede' => HEX_FUORISEDE_DEFAULT] as $k => $def) {
    $raw = $evid[$k] ?? null;
    $evidForm[$k] = [
        'raw' => $raw ?? '',
        // fuori sede: mai impostato = nessuna evidenziazione (default storico), non
        // "classico" — a differenza di straordinario/ferie che una tinta storica ce l'hanno
        'spento' => $raw === 'none' || ($k === 'fuorisede' && $raw === null),
        'hex' => ($raw !== null && $raw !== 'none') ? $raw : $def,
    ];
}
$qualForm = [];
foreach (qualificheColorabili() as $q => $lbl) {
    $raw = $qual[$q] ?? null;
    $qualForm[$q] = [
        'spento' => ($raw === null || $raw === 'none'),   // default: nessun colore
        'hex' => ($raw !== null && $raw !== 'none') ? $raw : HEX_QUAL_DEFAULT[$q],
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
  /* #220: riga delle spunte di stile testo (grassetto/corsivo/sottolineato) sotto al colore */
  .stile-txt { display:flex; align-items:center; gap:16px; padding:2px 10px 10px; margin:-6px 0 8px; }
  .stile-txt label { display:flex; align-items:center; gap:5px; font-size:.72rem; font-weight:400;
                     text-transform:none; color:var(--grigio-md); margin:0; }
  .stile-txt input { width:auto; }
  .stile-txt .b { font-weight:700; } .stile-txt .i { font-style:italic; } .stile-txt .u { text-decoration:underline; }
  /* riquadro disabilitato dal conflitto patente/qualifica */
  .box-off { opacity:.45; pointer-events:none; }
  .box-off-nota { font-size:.75rem; color:#a12622; background:#fdecea; border:1px solid #f5b8b2;
                  border-radius:8px; padding:8px 10px; margin:0 0 12px; }
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
        <?php /* #220: unico interruttore per i colori dei NOMI sull'ODT (patente o
                 qualifica, sono alternativi). Spento = ODT coi nomi tutti neri, che è
                 l'unica alternativa ammessa; il foglio web resta colorato.
                 Le evidenziazioni di sfondo hanno i loro controlli, qui sotto. */ ?>
        <div>
          <label>File ODT</label>
          <div class="col-row">
            <label class="col-spento" style="flex:1">
              <input type="checkbox" name="colori_odt" value="1" <?= $odt['colori_odt'] ? 'checked' : '' ?>>
              usa gli stessi colori su ODT
            </label>
          </div>
          <p class="hint" style="margin:-4px 0 0">
            Se la togli, sull'ODT i nomi escono tutti in nero (il foglio a schermo resta colorato).
          </p>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head">Evidenziazioni sull'ODT</div>
    <div style="padding:14px">
      <p class="hint" style="margin:0 0 12px">
        Colore di sfondo dietro al nome per straordinario, ferie estive, ferie d'ufficio e
        personale fuori sede — solo sul PDF/ODT scaricato, il foglio web non cambia.
        Spunta «nessuna evidenziazione» per non colorare quel caso; lo stile del testo
        (grassetto/corsivo/sottolineato) si sceglie a parte dove indicato.
      </p>
      <?php foreach ([
          'straord'       => ['Straordinario',   HEX_STRAORD_DEFAULT],
          'ferie_estiva'  => ["Ferie estive",    HEX_FERIE_ESTIVA_DEFAULT],
          'ferie_ufficio' => ["Ferie d'ufficio", HEX_FERIE_UFFICIO_DEFAULT],
          'fuorisede'     => ["Personale fuori sede", HEX_FUORISEDE_DEFAULT],
      ] as $campo => [$etichetta, $def]):
          $k = ['straord' => 'straord', 'ferie_estiva' => 'estiva',
                'ferie_ufficio' => 'ufficio', 'fuorisede' => 'fuorisede'][$campo];
          $f = $evidForm[$k];
          // #220: solo straordinario e fuori sede hanno anche lo stile del testo
          $txt = ['straord'   => [['str_grassetto', 'Grassetto', 'b', $odt['str_bold']],
                                  ['str_corsivo',   'Corsivo',   'i', $odt['str_italic']]],
                  'fuorisede' => [['fs_grassetto',    'Grassetto',    'b', $odt['fs_bold']],
                                  ['fs_corsivo',      'Corsivo',      'i', $odt['fs_italic']],
                                  ['fs_sottolineato', 'Sottolineato', 'u', $odt['fs_under']]]][$campo] ?? [];
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
        <?php if ($txt): ?>
        <div class="stile-txt">
          <span style="font-size:.72rem;color:var(--grigio-md)">Stile del testo:</span>
          <?php foreach ($txt as [$nome, $lbl, $cls, $on]): ?>
          <label><input type="checkbox" name="<?= $nome ?>" value="1" <?= $on ? 'checked' : '' ?> form="stiliForm">
            <span class="<?= $cls ?>"><?= $lbl ?></span></label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php /* #220 — Colore Qualifica. Vive solo con lo stile «solo numero»: se le patenti
           sono rese col colore, lo stesso nome avrebbe due colori possibili (patente e
           qualifica) e vincerebbe l'ultimo arrivato. Qui il riquadro è disabilitato e
           spiegato; la regola vale comunque anche lato server (colorePatentePHP() e
           FoglioRenderer::nameStyle()), l'interfaccia da sola si aggira. */ ?>
  <div class="card">
    <div class="card-head">Colore Qualifica</div>
    <div style="padding:14px">
      <div class="box-off-nota" id="qualNota" style="<?= $soloNumero ? 'display:none' : '' ?>">
        Disponibile solo con lo stile patente <b>«Solo numero»</b>: con le patenti a colori
        lo stesso nome avrebbe due colori in conflitto. Cambia lo stile qui sopra e salva.
      </div>
      <p class="hint" style="margin:0 0 12px">
        Colore del nome per qualifica, uguale sul foglio a schermo e sull'ODT
        (sull'ODT solo se è attiva «usa gli stessi colori su ODT», altrimenti nero).
      </p>
      <div id="qualBox" class="<?= $soloNumero ? '' : 'box-off' ?>">
        <?php foreach (qualificheColorabili() as $q => $lbl): $f = $qualForm[$q]; ?>
        <div>
          <label><?= htmlspecialchars($lbl) ?></label>
          <div class="col-row<?= $f['spento'] ? ' is-spento' : '' ?>" data-evid-row>
            <input type="color" name="qual_<?= strtolower($q) ?>" value="<?= htmlspecialchars($f['hex']) ?>"
                   form="stiliForm" <?= $soloNumero ? '' : 'disabled' ?>>
            <span class="col-nome">Colore libero</span>
            <label class="col-spento">
              <input type="checkbox" name="qual_<?= strtolower($q) ?>_off" data-spegni value="1"
                     <?= $f['spento'] ? 'checked' : '' ?> form="stiliForm" <?= $soloNumero ? '' : 'disabled' ?>>
              nessun colore
            </label>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:6px"><button type="submit" form="stiliForm" class="btn btn-rosso">Salva turno <?= htmlspecialchars($TURNO) ?></button></div>
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
// #220: il riquadro Colore Qualifica segue lo stile patente scelto, subito, senza
// aspettare il salvataggio — i campi restano `disabled` finché non è «solo numero»
// così il browser non li invia: il POST li salva solo in quello stile (vedi sopra).
function aggiornaQualifica() {
  const soloNumero = document.querySelector('input[name=stile]:checked')?.value === 'numero';
  document.getElementById('qualBox').classList.toggle('box-off', !soloNumero);
  document.getElementById('qualNota').style.display = soloNumero ? 'none' : '';
  document.querySelectorAll('#qualBox input').forEach(i => i.disabled = !soloNumero);
}
document.querySelectorAll('input[name=stile]').forEach(r => r.addEventListener('change', aggiornaQualifica));
aggiornaQualifica();
</script>
<?php require __DIR__ . '/../includes/logbook_widget.php'; ?>
</body>
</html>
