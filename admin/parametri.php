<?php
session_start();
require_once __DIR__ . '/../config/db.php';
$pdo     = getDB();
$errore  = '';
$sucesso = '';

// Tabella parametri (chiave/valore). Creata al volo se manca (no AUTO_INCREMENT su TiDB).
$pdo->exec("CREATE TABLE IF NOT EXISTS parametri (
    id          INT UNSIGNED NOT NULL,
    chiave      VARCHAR(60)  NOT NULL,
    valore      TEXT,
    descrizione VARCHAR(160) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chiave (chiave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';
    if ($azione === 'salva') {
        $id     = (int)($_POST['id'] ?? 0);
        $chiave = trim($_POST['chiave'] ?? '');
        $valore = trim($_POST['valore'] ?? '');
        $descr  = trim($_POST['descrizione'] ?? '') ?: null;
        if ($chiave === '') {
            $errore = 'La chiave è obbligatoria.';
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE parametri SET chiave=?, valore=?, descrizione=? WHERE id=?")
                        ->execute([$chiave, $valore, $descr, $id]);
                    $sucesso = 'Parametro aggiornato.';
                } else {
                    $newId = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM parametri")->fetchColumn();
                    $pdo->prepare("INSERT INTO parametri (id, chiave, valore, descrizione) VALUES (?,?,?,?)")
                        ->execute([$newId, $chiave, $valore, $descr]);
                    $sucesso = 'Parametro aggiunto.';
                }
            } catch (Throwable $e) {
                $errore = (strpos($e->getMessage(), 'uq_chiave') !== false)
                    ? 'Esiste già un parametro con questa chiave.'
                    : 'Errore salvataggio: ' . $e->getMessage();
            }
        }
    } elseif ($azione === 'elimina') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM parametri WHERE id=?")->execute([$id]);
            $sucesso = 'Parametro eliminato.';
        }
    }
}

$rigaEdit = null;
if (isset($_GET['modifica'])) {
    $st = $pdo->prepare("SELECT * FROM parametri WHERE id=?");
    $st->execute([(int)$_GET['modifica']]);
    $rigaEdit = $st->fetch() ?: null;
}
$righe = $pdo->query("SELECT * FROM parametri ORDER BY chiave")->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Parametri fissi</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/vigili.css?v=<?= @filemtime(__DIR__.'/../assets/css/vigili.css') ?>">
<script defer src="../assets/js/conferma.js?v=<?= @filemtime(__DIR__.'/../assets/js/conferma.js') ?>"></script>
<style>
  .par-form { display:flex; flex-direction:column; gap:10px; max-width:640px; }
  .par-form label { font-size:.72rem; font-weight:700; text-transform:uppercase; color:var(--grigio-md); display:block; margin-bottom:3px; }
  .par-form input, .par-form textarea { width:100%; padding:8px 10px; border:1px solid #d5d8dc; border-radius:6px; font-size:.85rem; font-family:inherit; }
  .par-form textarea { min-height:64px; resize:vertical; }
  code.kk { background:#f1f3f5; padding:1px 6px; border-radius:4px; font-size:.82rem; }
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
    <h2>📝 Testi / parametri fissi</h2>
    <a href="index.php" class="btn btn-grigio">← Amministrazione</a>
  </div>

  <?php if ($sucesso): ?><div class="alert alert-ok">✅ <?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
  <?php if ($errore):  ?><div class="alert alert-err">⚠️ <?= htmlspecialchars($errore) ?></div><?php endif; ?>

  <div class="card">
    <div class="card-head"><?= $rigaEdit ? '✏️ Modifica parametro' : '➕ Nuovo parametro' ?></div>
    <div style="padding:14px">
      <form method="POST" action="parametri.php" class="par-form">
        <input type="hidden" name="azione" value="salva">
        <input type="hidden" name="id" value="<?= $rigaEdit['id'] ?? 0 ?>">
        <div>
          <label>Chiave (identificativo univoco)</label>
          <input type="text" name="chiave" value="<?= htmlspecialchars($rigaEdit['chiave'] ?? '') ?>"
                 placeholder="es. nome_comando, mail_furieri, nota_standard">
        </div>
        <div>
          <label>Valore</label>
          <textarea name="valore" placeholder="Contenuto del parametro…"><?= htmlspecialchars($rigaEdit['valore'] ?? '') ?></textarea>
        </div>
        <div>
          <label>Descrizione (facoltativa)</label>
          <input type="text" name="descrizione" value="<?= htmlspecialchars($rigaEdit['descrizione'] ?? '') ?>"
                 placeholder="A cosa serve questo parametro">
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-rosso"><?= $rigaEdit ? 'Salva modifiche' : 'Aggiungi' ?></button>
          <?php if ($rigaEdit): ?><a href="parametri.php" class="btn btn-grigio">Annulla</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="tabella-wrap" style="margin-top:16px">
    <table>
      <thead><tr><th>Chiave</th><th>Valore</th><th>Descrizione</th><th style="text-align:center">Azioni</th></tr></thead>
      <tbody>
        <?php if (empty($righe)): ?>
          <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--grigio-md)">Nessun parametro.</td></tr>
        <?php endif; ?>
        <?php foreach ($righe as $r): ?>
          <tr>
            <td><code class="kk"><?= htmlspecialchars($r['chiave']) ?></code></td>
            <td style="white-space:pre-wrap"><?= htmlspecialchars($r['valore'] ?? '') ?></td>
            <td style="color:var(--grigio-md);font-size:.82rem"><?= htmlspecialchars($r['descrizione'] ?? '') ?></td>
            <td>
              <div class="azioni">
                <a href="parametri.php?modifica=<?= (int)$r['id'] ?>" class="btn btn-grigio btn-sm">✏️ Modifica</a>
                <form method="POST" action="parametri.php"
                      onsubmit="return confermaSubmit(this, 'Eliminare questo parametro?', {titolo:'Elimina parametro', okLabel:'🗑️ Elimina'})">
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
