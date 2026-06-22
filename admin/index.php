<?php
session_start();
require_once __DIR__ . '/../config/db.php';
$pdo = getDB();

// Conteggi rapidi per le card (best-effort: se una tabella manca, 0)
function contaTab(PDO $pdo, string $sql): int {
    try { return (int)$pdo->query($sql)->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
$nSedi    = contaTab($pdo, "SELECT COUNT(*) FROM sedi");
$nPos     = contaTab($pdo, "SELECT COUNT(*) FROM posizioni");
$nAssFix  = contaTab($pdo, "SELECT COUNT(*) FROM assegnazioni_fisse");
$nParam   = contaTab($pdo, "SELECT COUNT(*) FROM parametri");
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Amministrazione</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/vigili.css?v=<?= @filemtime(__DIR__.'/../assets/css/vigili.css') ?>">
<style>
  .admin-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:16px; margin-top:8px; }
  .admin-card { display:block; background:var(--bianco); border:1px solid #e4e7ea; border-radius:10px;
                padding:18px 20px; text-decoration:none; color:inherit; box-shadow:var(--shadow);
                transition:border-color .15s, box-shadow .15s, transform .1s; }
  .admin-card:hover { border-color:var(--rosso); box-shadow:0 4px 14px rgba(0,0,0,.1); transform:translateY(-1px); }
  .admin-card .ac-ico { font-size:1.8rem; }
  .admin-card h3 { margin:6px 0 4px; font-size:1.05rem; }
  .admin-card p  { margin:0; color:var(--grigio-md); font-size:.82rem; line-height:1.35; }
  .admin-card .ac-meta { margin-top:10px; font-size:.74rem; color:var(--grigio-md); font-weight:600; }
</style>
</head>
<body>
<header class="header">
  <div class="header-inner">
    <div class="header-logo">🚒</div>
    <div class="header-testi">
      <h1>Comando Provinciale VVF di Genova</h1>
      <p>Gestionale Foglio di Servizio &mdash; Turno B</p>
    </div>
    <div class="header-badge">TURNO&nbsp;B</div>
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
    <h2>⚙️ Amministrazione</h2>
  </div>
  <p style="color:var(--grigio-md);margin:0 0 14px">
    Dati fissi del gestionale: anagrafiche di sistema, assegnazioni ricorrenti e parametri.
  </p>

  <div class="admin-grid">

    <a href="anagrafiche.php" class="admin-card">
      <div class="ac-ico">🗂️</div>
      <h3>Anagrafiche di sistema</h3>
      <p>Sedi, mezzi/posizioni, qualifiche, salti turno, tipi assenza, patenti, abilitazioni.</p>
      <div class="ac-meta"><?= $nSedi ?> sedi · <?= $nPos ?> posizioni</div>
    </a>

    <a href="assegnazioni_fisse.php" class="admin-card">
      <div class="ac-ico">📌</div>
      <h3>Assegnazioni fisse</h3>
      <p>Personale che va sempre in una certa posizione, da pre-caricare sui fogli.</p>
      <div class="ac-meta"><?= $nAssFix ?> regole</div>
    </a>

    <a href="parametri.php" class="admin-card">
      <div class="ac-ico">📝</div>
      <h3>Testi / parametri fissi</h3>
      <p>Valori riutilizzabili: intestazioni, note standard, nome comando, indirizzi mail.</p>
      <div class="ac-meta"><?= $nParam ?> parametri</div>
    </a>

  </div>
</main>
</body>
</html>
