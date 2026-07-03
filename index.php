<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/turni.php';
require_once __DIR__ . '/includes/auth.php';
richiediLogin();   // soft: non blocca finché AUTH_ENFORCE è false

$TURNO = turnoAttivo();   // turno evidenziato nel cruscotto (A/B/C/D); default B

// ── Navigazione mese ────────────────────────────────────────
$oggi  = new DateTime('today');
$annoP = isset($_GET['anno']) ? (int)$_GET['anno'] : (int)$oggi->format('Y');
$meseP = isset($_GET['mese']) ? (int)$_GET['mese'] : (int)$oggi->format('m');

if ($meseP < 1)  { $meseP = 12; $annoP--; }
if ($meseP > 12) { $meseP = 1;  $annoP++; }

$mesePrev = $meseP - 1; $annoPrev = $annoP;
if ($mesePrev < 1)  { $mesePrev = 12; $annoPrev--; }
$meseNext = $meseP + 1; $annoNext = $annoP;
if ($meseNext > 12) { $meseNext = 1;  $annoNext++; }

$primoGiorno     = new DateTime(sprintf('%04d-%02d-01', $annoP, $meseP));
$giorniNelMese   = (int)(clone $primoGiorno)->modify('last day of this month')->format('d');
$inizioSettimana = (int)$primoGiorno->format('N'); // 1=Lun, 7=Dom

$mesiNomi = [
    '', 'Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno',
    'Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'
];
$oggiStr = $oggi->format('Y-m-d');

// Turni del mese
$turniMese = getTurniMese($annoP, $meseP);

// Fogli già compilati dal DB
$fogliCompilati = [];
try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        "SELECT data_servizio, tipo_turno
         FROM fogli_servizio
         WHERE turno=? AND YEAR(data_servizio)=? AND MONTH(data_servizio)=?"
    );
    $stmt->execute([$TURNO, $annoP, $meseP]);
    foreach ($stmt->fetchAll() as $row) {
        $fogliCompilati[$row['data_servizio']][$row['tipo_turno']] = true;
    }
} catch (Exception $e) {}

// Statistiche
$totVigili = 0;
$totFogli  = 0;
$fogliOggi = 0;
$saltoOggi = '';

try {
    $pdo = getDB();

    $vc = $pdo->prepare("SELECT COUNT(*) FROM vigili WHERE attivo=1 AND turno=?");
    $vc->execute([$TURNO]);
    $totVigili = (int)$vc->fetchColumn();

    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM fogli_servizio
         WHERE turno=? AND YEAR(data_servizio)=? AND MONTH(data_servizio)=?"
    );
    $s->execute([$TURNO, $annoP, $meseP]);
    $totFogli = (int)$s->fetchColumn();

    $s2 = $pdo->prepare(
        "SELECT COUNT(*) FROM fogli_servizio WHERE turno=? AND data_servizio=?"
    );
    $s2->execute([$TURNO, $oggiStr]);
    $fogliOggi = (int)$s2->fetchColumn();

} catch (Exception $e) {}

// Salto B in servizio oggi (diurno)
$turnoOggi = getTurnoGiorno($oggiStr);
$saltoOggi = $turnoOggi['diurno']['turno'] . $turnoOggi['diurno']['salto']
           . ' / '
           . $turnoOggi['notte']['turno']  . $turnoOggi['notte']['salto'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF Genova – Gestionale Turno <?= htmlspecialchars($TURNO) ?></title>
<link rel="stylesheet" href="assets/css/stile.css?v=<?= @filemtime(__DIR__.'/assets/css/stile.css') ?>">
</head>

<body>

<!-- ══ HEADER ════════════════════════════════════════════════ -->
<header class="header">
  <div class="header-inner">
    <div class="header-logo">🚒</div>
    <div class="header-testi">
      <h1>Comando Provinciale VVF di Genova</h1>
      <p>Gestionale Foglio di Servizio &mdash; Turno <?= htmlspecialchars($TURNO) ?></p>
    </div>
    <div class="header-badge">TURNO&nbsp;<?= htmlspecialchars($TURNO) ?></div>
    <?php if (isLoggato()): $uc = utenteCorrente(); ?>
      <div style="margin-left:14px;text-align:right;font-size:.78rem;line-height:1.3">
        <div style="font-weight:700"><?= htmlspecialchars($uc['nome'] ?: $uc['username']) ?></div>
        <div style="opacity:.85">
          <?= htmlspecialchars(ucfirst($uc['ruolo'])) ?><?= $uc['turno'] ? ' · turno ' . htmlspecialchars($uc['turno']) : '' ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</header>

<!-- ══ NAVBAR ═════════════════════════════════════════════════ -->
<nav class="navbar">
  <div class="navbar-inner">
    <a href="index.php"        class="nav-btn active">🏠 Cruscotto</a>
    <a href="foglio/nuovo.php" class="nav-btn">📋 Nuovo Foglio</a>
    <a href="vigili/lista.php" class="nav-btn">👥 Personale</a>
    <a href="ferie/index.php"  class="nav-btn">🗓️ Agenda</a>
    <a href="report/index.php" class="nav-btn">📊 Reportistica</a>
    <?php if (isAdmin()): ?>
    <a href="admin/index.php"  class="nav-btn">⚙️ Amministrazione</a>
    <?php endif; ?>
    <!-- TEMP sviluppo: rimuovere a fine beta -->
    <a href="logbook/index.php" class="nav-btn">📓 Logbook</a>
    <a href="cambia_password.php" class="nav-btn ml-auto">🔑 Password</a>
    <span style="margin-left:auto"><?= selettoreTurnoHtml() ?></span>
    <a href="logout.php"       class="nav-btn">🚪 Esci</a>
  </div>
</nav>

<!-- ══ MAIN ══════════════════════════════════════════════════ -->
<main class="main">

  <!-- STAT CARDS -->
  <div class="stat-grid">

    <div class="stat-card">
      <div class="stat-icon">👨‍🚒</div>
      <div class="stat-info">
        <span>Vigili attivi</span>
        <strong><?= $totVigili ?: '—' ?></strong>
      </div>
    </div>

    <div class="stat-card blu">
      <div class="stat-icon">📋</div>
      <div class="stat-info">
        <span>Fogli del mese</span>
        <strong><?= $totFogli ?></strong>
      </div>
    </div>

    <div class="stat-card giallo">
      <div class="stat-icon">🔄</div>
      <div class="stat-info">
        <span>In servizio oggi</span>
        <strong style="font-size:1.1rem"><?= htmlspecialchars($saltoOggi) ?></strong>
      </div>
    </div>

    <div class="stat-card verde">
      <div class="stat-icon">📅</div>
      <div class="stat-info">
        <span>Fogli compilati oggi</span>
        <strong><?= $fogliOggi ?></strong>
      </div>
    </div>

  </div>

  <!-- CALENDARIO -->
  <div class="cal-wrapper">

    <!-- Testata navigazione -->
    <div class="cal-head">
      <a class="cal-nav-btn"
         href="?anno=<?= $annoPrev ?>&mese=<?= $mesePrev ?>">&#8592; Prec.</a>
      <h2><?= $mesiNomi[$meseP] ?> <?= $annoP ?></h2>
      <a class="cal-nav-btn"
         href="?anno=<?= $annoNext ?>&mese=<?= $meseNext ?>">Succ. &#8594;</a>
    </div>

    <!-- Griglia -->
    <div class="cal-grid">

      <!-- Intestazioni giorni della settimana -->
      <?php
      $dows = ['Lun','Mar','Mer','Gio','Ven','Sab','Dom'];
      foreach ($dows as $i => $d):
      ?>
        <div class="cal-dow <?= $i === 6 ? 'dom' : '' ?>"><?= $d ?></div>
      <?php endforeach; ?>

      <!-- Celle vuote iniziali -->
      <?php for ($v = 1; $v < $inizioSettimana; $v++): ?>
        <div class="cal-cell vuota"></div>
      <?php endfor; ?>

      <!-- Giorni del mese -->
      <?php for ($g = 1; $g <= $giorniNelMese; $g++):
    $dataStr = sprintf('%04d-%02d-%02d', $annoP, $meseP, $g);
    $dt      = new DateTime($dataStr);
    $dowNum  = (int)$dt->format('N');
    $isDom   = ($dowNum === 7);
    $isOggi  = ($dataStr === $oggiStr);
    $turnoG  = $turniMese[$dataStr] ?? null;

    // Evidenzia la cella SOLO se il turno ATTIVO è in servizio
    $turnoAttivoInServizio = $turnoG && (
        $turnoG['D']['turno'] === $TURNO ||
        $turnoG['N']['turno'] === $TURNO
    );

    $classeCell = 'cal-cell';
    if ($isOggi)                     $classeCell .= ' oggi-cell';
    elseif ($turnoAttivoInServizio)  $classeCell .= ' turno-b';
?>
    <div class="<?= $classeCell ?>">

        <!-- Numero giorno -->
        <div class="cal-num">
            <?php if ($isOggi): ?>
                <span class="num-bubble"><?= $g ?></span>
            <?php elseif ($isDom): ?>
                <span class="num-dom"><?= $g ?></span>
            <?php else: ?>
                <span><?= $g ?></span>
            <?php endif; ?>
        </div>

        <?php if ($turnoG):
            $d = $turnoG['D'];
            $n = $turnoG['N'];
            $isBd = ($d['turno'] === $TURNO);
            $iBn  = ($n['turno'] === $TURNO);
            $compilatoD = !empty($fogliCompilati[$dataStr]['D']);
            $compilatoN = !empty($fogliCompilati[$dataStr]['N']);
        ?>

            <!-- DIURNO -->
            <?php if ($isBd): ?>
                <?php
                // Turno B diurno: pill colorata e cliccabile
                $classd = 'pill diurno' . ($compilatoD ? ' compilato' : '');
                $linkD  = 'foglio/' . ($compilatoD ? 'visualizza' : 'nuovo')
                        . '.php?data=' . $dataStr . '&tipo=D&turno=' . $TURNO;
                ?>
                <a href="<?= htmlspecialchars($linkD) ?>" class="<?= $classd ?>">
                    <span class="pill-label">
                        <?= $compilatoD ? '✅' : '🌅' ?>
                        <span class="pill-turno">
                            <?= htmlspecialchars($d['turno'] . $d['salto']) ?>
                        </span>
                        <span class="pill-ora">08:00→20:00</span>
                    </span>
                    <span class="pill-badge-b"><?= htmlspecialchars($TURNO) ?></span>
                </a>
            <?php else: ?>
                <!-- Altri turni diurni: solo testo discreto, non cliccabile -->
                <div class="pill-altri">
                    🌅 <?= htmlspecialchars($d['turno'] . $d['salto']) ?>
                </div>
            <?php endif; ?>

            <!-- NOTTURNO -->
            <?php if ($iBn): ?>
                <?php
                // Turno B notturno: pill colorata e cliccabile
                $classn = 'pill notte' . ($compilatoN ? ' compilato' : '');
                $linkN  = 'foglio/' . ($compilatoN ? 'visualizza' : 'nuovo')
                        . '.php?data=' . $dataStr . '&tipo=N&turno=' . $TURNO;
                ?>
                <a href="<?= htmlspecialchars($linkN) ?>" class="<?= $classn ?>">
                    <span class="pill-label">
                        <?= $compilatoN ? '✅' : '🌙' ?>
                        <span class="pill-turno">
                            <?= htmlspecialchars($n['turno'] . $n['salto']) ?>
                        </span>
                        <span class="pill-ora">20:00→08:00</span>
                    </span>
                    <span class="pill-badge-b"><?= htmlspecialchars($TURNO) ?></span>
                </a>
            <?php else: ?>
                <!-- Altri turni notturni: solo testo discreto, non cliccabile -->
                <div class="pill-altri">
                    🌙 <?= htmlspecialchars($n['turno'] . $n['salto']) ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>
<?php endfor; ?>


    </div><!-- /.cal-grid -->

    <!-- Legenda -->
    <div class="cal-legenda">
      <div class="leg">
        <div class="leg-box"
             style="background:var(--giallo-bg);border-left:3px solid var(--giallo)">
        </div>
        Diurno (08:00–20:00)
      </div>
      <div class="leg">
        <div class="leg-box"
             style="background:var(--blu-bg);border-left:3px solid var(--blu)">
        </div>
        Notturno (20:00–08:00)
      </div>
      <div class="leg">
        <div class="leg-box"
             style="background:var(--verde-bg);border-left:3px solid var(--verde)">
        </div>
        Foglio compilato
      </div>
      <div class="leg">
        <div class="leg-box" style="background:#f0f7ff;border:1px solid #aac"></div>
        Turno <?= htmlspecialchars($TURNO) ?> in servizio
      </div>
      <div class="leg">
        <span class="pill-badge-b"><?= htmlspecialchars($TURNO) ?></span>
        &nbsp;= Turno <?= htmlspecialchars($TURNO) ?> attivo su quel turno
      </div>
    </div>

  </div><!-- /.cal-wrapper -->

</main>
</body>
</html>
