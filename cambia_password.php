<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
richiediLogin();

$pdo = getDB();
$uc  = utenteCorrente();
$errore = '';
$ok     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attuale = (string)($_POST['attuale'] ?? '');
    $nuova   = (string)($_POST['nuova'] ?? '');
    $conf    = (string)($_POST['conferma'] ?? '');

    $st = $pdo->prepare("SELECT password FROM utenti WHERE id=? LIMIT 1");
    $st->execute([$uc['id']]);
    $hash = $st->fetchColumn();

    if (!$hash || !password_verify($attuale, $hash)) {
        $errore = 'La password attuale non è corretta.';
    } elseif (strlen($nuova) < 6) {
        $errore = 'La nuova password deve avere almeno 6 caratteri.';
    } elseif ($nuova !== $conf) {
        $errore = 'Le due nuove password non coincidono.';
    } else {
        $nuovoHash = password_hash($nuova, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE utenti SET password=? WHERE id=?")->execute([$nuovoHash, $uc['id']]);
        $ok = 'Password aggiornata.';
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Cambia password</title>
<link rel="stylesheet" href="assets/css/stile.css?v=<?= @filemtime(__DIR__.'/assets/css/stile.css') ?>">
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
    <a href="index.php"           class="nav-btn">🚒 Home</a>
    <a href="foglio/nuovo.php"    class="nav-btn">📋 Nuovo Foglio</a>
    <a href="vigili/lista.php"    class="nav-btn">👥 Personale</a>
    <a href="ferie/index.php"     class="nav-btn">🗓️ Agenda</a>
    <a href="report/index.php"    class="nav-btn">📊 Reportistica</a>
    <a href="admin/index.php"     class="nav-btn">⚙️ Amministrazione</a>
    <a href="logbook/index.php"   class="nav-btn">📓 Logbook</a>
    <?php if (!isAdmin()): ?>
    <a href="admin/utenti.php" class="nav-btn">👤 Password profili user</a>
    <?php endif; ?>
    <a href="cambia_password.php" class="nav-btn active ml-auto">🔑 Password</a>
    <?= turnoComandoHtml() ?>
    <a href="logout.php"          class="nav-btn">🚪 Esci</a>
  </div>
</nav>
<main class="main">
  <div class="page-title"><h2>🔑 Cambia password</h2></div>
  <p style="color:var(--grigio-md);margin:0 0 14px">
    Utente <strong><?= htmlspecialchars($uc['username']) ?></strong>
    (<?= htmlspecialchars(ucfirst($uc['ruolo'])) ?><?= $uc['turno'] ? ' · turno ' . htmlspecialchars($uc['turno']) : '' ?>).
  </p>

  <div class="card" style="max-width:420px">
    <div style="padding:18px">
      <form method="POST" action="cambia_password.php">
        <div class="form-group">
          <label>Password attuale</label>
          <input type="password" name="attuale" autocomplete="current-password" required>
        </div>
        <div class="form-group">
          <label>Nuova password</label>
          <input type="password" name="nuova" autocomplete="new-password" required>
        </div>
        <div class="form-group">
          <label>Conferma nuova password</label>
          <input type="password" name="conferma" autocomplete="new-password" required>
        </div>
        <button type="submit" class="btn btn-rosso" style="margin-top:10px">Aggiorna password</button>
      </form>
    </div>
  </div>
</main>
</body>
</html>
