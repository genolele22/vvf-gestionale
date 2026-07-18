<?php
/**
 * LOGBOOK — pagina temporanea per i beta-tester durante lo sviluppo.
 * Lista condivisa di cose da fare, flaggabili. Salvata su DB (tabella bot_logbook).
 * RIMUOVERE a sviluppo ultimato: cancellare la cartella logbook/, il tasto in navbar
 * e (se si vuole) la tabella bot_logbook.
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
richiediLogin();
richiediLogbook();   // pagina di lavoro interna: solo Comando + beta-tester (lelemele, adminb)

$pdo = getDB();

// Tabella creata al volo (feature temporanea, niente migrazione separata)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS bot_logbook (
        id        BIGINT AUTO_INCREMENT PRIMARY KEY,
        testo     TEXT NOT NULL,
        autore    VARCHAR(60) DEFAULT NULL,
        fatto     TINYINT(1) NOT NULL DEFAULT 0,
        creato_il DATETIME DEFAULT CURRENT_TIMESTAMP,
        fatto_il  DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Azioni (POST → redirect, pattern PRG per evitare doppio invio) ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') vietaSeSolaLettura();
$azione = $_POST['azione'] ?? '';

if ($azione === 'add') {
    $testo  = trim($_POST['testo'] ?? '');
    $autore = trim($_POST['autore'] ?? '');
    if ($testo !== '') {
        // id esplicito via MAX+1: TiDB non garantisce AUTO_INCREMENT affidabile
        $pdo->beginTransaction();
        $nextId = nextId($pdo, 'bot_logbook');
        $st = $pdo->prepare("INSERT INTO bot_logbook (id, testo, autore) VALUES (?, ?, ?)");
        $st->execute([$nextId, $testo, $autore !== '' ? $autore : null]);
        $pdo->commit();
    }
    header('Location: index.php'); exit;
}

if ($azione === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    // fatto_il valutato PRIMA di fatto: MySQL/TiDB assegna left-to-right,
    // così legge il valore vecchio (0 = sta per diventare fatta → timbra NOW()).
    $st = $pdo->prepare(
        "UPDATE bot_logbook
            SET fatto_il = CASE WHEN fatto = 0 THEN NOW() ELSE NULL END,
                fatto    = 1 - fatto
          WHERE id = ?"
    );
    $st->execute([$id]);
    header('Location: index.php'); exit;
}

if ($azione === 'delete') {
    $st = $pdo->prepare("DELETE FROM bot_logbook WHERE id = ?");
    $st->execute([(int)($_POST['id'] ?? 0)]);
    header('Location: index.php'); exit;
}

// ── Lettura: da fare in cima, fatte in fondo ────────────────────────────────────
$voci = $pdo->query(
    "SELECT * FROM bot_logbook ORDER BY fatto ASC, creato_il DESC"
)->fetchAll();

$nDaFare = 0; $nFatte = 0;
foreach ($voci as $v) { $v['fatto'] ? $nFatte++ : $nDaFare++; }
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Logbook – Gestionale VVF</title>
<link rel="stylesheet" href="../assets/css/stile.css">
<style>
  .lb-wrap   { max-width: 820px; margin: 0 auto; }
  .lb-banner { background:#fff8e1; border:1px solid #f0d27a; color:#7a5c00;
               padding:.6rem .9rem; border-radius:8px; font-size:.9rem; margin-bottom:1rem; }
  .lb-add    { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1.2rem; }
  .lb-add textarea { flex:1 1 320px; min-height:2.6rem; padding:.55rem .7rem;
               border:1px solid #ccc; border-radius:8px; font:inherit; resize:vertical; }
  .lb-add input.autore { flex:0 0 140px; padding:.55rem .7rem; border:1px solid #ccc;
               border-radius:8px; font:inherit; }
  .lb-btn    { padding:.55rem 1rem; border:none; border-radius:8px; cursor:pointer;
               font:inherit; background:#0a58ca; color:#fff; }
  .lb-btn.ghost { background:transparent; color:#999; padding:.3rem .5rem; }
  .lb-list   { list-style:none; padding:0; margin:0; }
  .lb-item   { display:flex; align-items:flex-start; gap:.6rem; padding:.6rem .4rem;
               border-bottom:1px solid #eee; }
  .lb-item.done .lb-testo { text-decoration:line-through; color:#999; }
  .lb-testo  { flex:1; white-space:pre-wrap; word-break:break-word; }
  .lb-meta   { font-size:.75rem; color:#aaa; margin-top:.15rem; }
  .lb-check  { width:1.25rem; height:1.25rem; margin-top:.15rem; cursor:pointer; }
  .lb-count  { color:#666; font-size:.9rem; margin-bottom:.8rem; }
  .lb-num    { color:#999; font-weight:700; font-size:.8rem; margin-right:6px; }
  .lb-empty  { color:#999; text-align:center; padding:2rem; }
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
    <a href="../index.php"        class="nav-btn">🚒 Home</a>
    <a href="../foglio/nuovo.php" class="nav-btn">📋 Foglio</a>
    <a href="../vigili/lista.php" class="nav-btn">👥 Personale</a>
    <a href="../ferie/index.php"  class="nav-btn">🗓️ Agenda</a>
    <a href="../report/index.php" class="nav-btn">📊 Reportistica</a>
    <a href="../admin/index.php"  class="nav-btn">⚙️ Amministrazione</a>
    <a href="index.php"           class="nav-btn active">📓 Logbook</a>
    <a href="../cambia_password.php" class="nav-btn ml-auto">🔑 Password</a>
    <?= turnoComandoHtml() ?>
    <a href="../logout.php"       class="nav-btn">🚪 Esci</a>
  </div>
</nav>

<main class="main">
  <div class="lb-wrap">

    <div class="lb-banner">
      📓 <strong>Logbook di sviluppo</strong> — annotate qui le cose da fare e i bug.
      Pagina condivisa e temporanea, verrà rimossa a sviluppo concluso.
    </div>

    <!-- AGGIUNGI -->
    <form method="post" class="lb-add">
      <input type="hidden" name="azione" value="add">
      <textarea name="testo" placeholder="Cosa c'è da fare / cosa non va…" required></textarea>
      <input class="autore" type="text" name="autore" placeholder="Chi sei (facolt.)"
             maxlength="60">
      <button class="lb-btn" type="submit">Aggiungi</button>
    </form>

    <div class="lb-count">
      <?= $nDaFare ?> da fare · <?= $nFatte ?> fatte
    </div>

    <!-- LISTA -->
    <?php if (!$voci): ?>
      <div class="lb-empty">Niente ancora. Aggiungi la prima voce.</div>
    <?php else: ?>
      <ul class="lb-list">
        <?php foreach ($voci as $v): ?>
          <li class="lb-item <?= $v['fatto'] ? 'done' : '' ?>">

            <form method="post">
              <input type="hidden" name="azione" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
              <input class="lb-check" type="checkbox" onchange="this.form.submit()"
                     <?= $v['fatto'] ? 'checked' : '' ?>>
            </form>

            <div class="lb-testo">
              <span class="lb-num">#<?= (int)$v['id'] ?></span>
              <?= nl2br(htmlspecialchars($v['testo'])) ?>
              <div class="lb-meta">
                <?php if (!empty($v['autore'])): ?>
                  <?= htmlspecialchars($v['autore']) ?> ·
                <?php endif; ?>
                <?= htmlspecialchars(substr((string)$v['creato_il'], 0, 16)) ?>
              </div>
            </div>

            <form method="post" onsubmit="return confirm('Eliminare questa voce?')">
              <input type="hidden" name="azione" value="delete">
              <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
              <button class="lb-btn ghost" type="submit" title="Elimina">✕</button>
            </form>

          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

  </div>
</main>
</body>
</html>
