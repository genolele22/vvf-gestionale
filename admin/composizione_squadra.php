<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/composizione_squadra.php';
richiediAdmin();   // per-turno (#163: "ogni turno i propri valori"), come regole_squadra
$pdo    = getDB();
$TURNO  = turnoAmministrazione();   // fisso sul turno di casa (admin/user niente switch qui)
$errore = '';
$sucesso = '';

assicuraSchemaComposizioneSquadra($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vietaSeSolaLetturaTurno($TURNO);
    $azione = $_POST['azione'] ?? '';

    if ($azione === 'salva') {
        $posizioniIds = array_map('intval', $_POST['posizione_id'] ?? []);
        $esistenti = composizionePerTurno($pdo, $TURNO);

        $insId = null;
        $ins = $pdo->prepare(
            "INSERT INTO posizione_composizione
                (id, posizione_id, turno, min_capo, min_autista34, min_autista2, min_altri, abilitazione_id, min_abilitazione)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $upd = $pdo->prepare(
            "UPDATE posizione_composizione
                SET min_capo=?, min_autista34=?, min_autista2=?, min_altri=?, abilitazione_id=?, min_abilitazione=?
              WHERE id=?"
        );

        foreach ($posizioniIds as $posId) {
            if ($posId <= 0) continue;
            $minCapo  = (int)($_POST['min_capo'][$posId]        ?? 0);
            $minAut34 = (int)($_POST['min_autista34'][$posId]   ?? 0);
            $minAut2  = (int)($_POST['min_autista2'][$posId]    ?? 0);
            $minAltri = (int)($_POST['min_altri'][$posId]       ?? 0);
            $abilRaw  = (int)($_POST['abilitazione_id'][$posId] ?? 0);
            $abilId   = $abilRaw > 0 ? $abilRaw : null;
            $minAbil  = (int)($_POST['min_abilitazione'][$posId] ?? 0);

            if (isset($esistenti[$posId])) {
                $upd->execute([$minCapo, $minAut34, $minAut2, $minAltri, $abilId, $minAbil, $esistenti[$posId]['id']]);
            } elseif ($minCapo || $minAut34 || $minAut2 || $minAltri || $abilId || $minAbil) {
                // niente riga per una posizione senza alcun requisito: evita di
                // riempire la tabella di righe tutte a zero.
                $insId = $insId ?? nextId($pdo, 'posizione_composizione');
                $ins->execute([$insId++, $posId, $TURNO, $minCapo, $minAut34, $minAut2, $minAltri, $abilId, $minAbil]);
            }
        }
        $sucesso = 'Composizione salvata.';
    }
}

$posizioni = $pdo->query(
    "SELECT p.id, p.codice, p.nome, s.codice AS sede_codice
     FROM posizioni p JOIN sedi s ON s.id = p.sede_id
     ORDER BY s.ordine, s.codice, p.ordine, p.codice"
)->fetchAll();
$abilitazioni = $pdo->query("SELECT id, codice, nome FROM abilitazioni ORDER BY ordine, id")->fetchAll();
$comp = composizionePerTurno($pdo, $TURNO);
$vuota = composizioneVuota();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Composizione squadra</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/vigili.css?v=<?= @filemtime(__DIR__.'/../assets/css/vigili.css') ?>">
<style>
  .comp-hint { font-size: .82rem; color: var(--grigio-md); margin-bottom: 14px; line-height: 1.5; }
  .comp-table input[type="number"] { width: 56px; padding: 5px 6px; border: 1px solid #d5d8dc; border-radius: 5px; text-align: center; }
  .comp-table select { padding: 5px 6px; border: 1px solid #d5d8dc; border-radius: 5px; font-size: .82rem; }
  .comp-table td, .comp-table th { text-align: center; }
  .comp-table td:nth-child(1), .comp-table td:nth-child(2),
  .comp-table th:nth-child(1), .comp-table th:nth-child(2) { text-align: left; }
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
    <h2>🎯 Composizione squadra <span style="font-size:.7rem;font-weight:800;color:#b9770e">TURNO <?= htmlspecialchars($TURNO) ?></span></h2>
    <a href="index.php" class="btn btn-grigio">← Amministrazione</a>
  </div>

  <p class="comp-hint">
    Per ogni mezzo/posizione, il minimo di elementi per ruolo richiesto perché la squadra sia
    considerata completa (controllo prima di Invia/Scarica .odt e caselle vuote evidenziate in rosso
    sul foglio). Una stessa persona può soddisfare più requisiti insieme — es. un Autista 3/4 con
    l'abilitazione richiesta conta per entrambi. <b>Capo Partenza</b> = qualifica Cr o Cs.
    <b>Autista 2/3/4</b> si copre con patente 2, 3 o 4 (una patente più grande vale anche per il
    ruolo più piccolo), <b>Autista 3/4</b> solo con patente 3 o 4; i due ruoli autista non li copre
    la stessa persona. Lascia 0 dove non serve nessun requisito.
    Valori validi solo per il turno <?= htmlspecialchars($TURNO) ?>.
  </p>

  <?php if ($errore): ?><div class="alert alert-err"><?= htmlspecialchars($errore) ?></div><?php endif; ?>
  <?php if ($sucesso): ?><div class="alert alert-ok"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>

  <form method="POST" action="composizione_squadra.php">
    <input type="hidden" name="azione" value="salva">
    <div class="tabella-wrap">
      <table class="comp-table">
        <thead>
          <tr>
            <th>Sede</th><th>Mezzo</th>
            <th>Capo Partenza</th><th>Autista 3/4</th><th>Autista 2/3/4</th><th>Altri</th>
            <th>Abilitazione</th><th>N. Abilitazione</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($posizioni as $p):
              $c = $comp[(int)$p['id']] ?? $vuota; ?>
          <tr>
            <td><?= htmlspecialchars($p['sede_codice']) ?></td>
            <td><?= htmlspecialchars($p['codice']) ?><?= $p['nome'] ? ' — ' . htmlspecialchars($p['nome']) : '' ?>
                <input type="hidden" name="posizione_id[]" value="<?= (int)$p['id'] ?>"></td>
            <td><input type="number" min="0" name="min_capo[<?= (int)$p['id'] ?>]" value="<?= (int)$c['min_capo'] ?>"></td>
            <td><input type="number" min="0" name="min_autista34[<?= (int)$p['id'] ?>]" value="<?= (int)$c['min_autista34'] ?>"></td>
            <td><input type="number" min="0" name="min_autista2[<?= (int)$p['id'] ?>]" value="<?= (int)$c['min_autista2'] ?>"></td>
            <td><input type="number" min="0" name="min_altri[<?= (int)$p['id'] ?>]" value="<?= (int)$c['min_altri'] ?>"></td>
            <td>
              <select name="abilitazione_id[<?= (int)$p['id'] ?>]">
                <option value="0">— nessuna —</option>
                <?php foreach ($abilitazioni as $a): ?>
                  <option value="<?= (int)$a['id'] ?>" <?= (int)($c['abilitazione_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($a['codice']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="number" min="0" name="min_abilitazione[<?= (int)$p['id'] ?>]" value="<?= (int)$c['min_abilitazione'] ?>"></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:14px">
      <button type="submit" class="btn btn-rosso">💾 Salva composizione</button>
    </div>
  </form>
</main>
<?php require __DIR__ . '/../includes/logbook_widget.php'; ?>
</body>
</html>
