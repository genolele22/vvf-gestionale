<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
richiediComando();   // gestione account = solo Comando
$pdo = getDB();
$errore = '';
$ok     = '';

$RUOLI  = ['comando' => 'Comando', 'admin' => 'Admin di turno', 'user' => 'User (sola lettura)'];
$TURNI  = ['A','B','C','D'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';
    try {
        if ($azione === 'salva') {
            $id       = (int)($_POST['id'] ?? 0);
            $username = strtolower(trim($_POST['username'] ?? ''));
            $nome     = trim($_POST['nome'] ?? '');
            $ruolo    = $_POST['ruolo'] ?? 'user';
            $turno    = $_POST['turno'] ?? '';
            $attivo   = isset($_POST['attivo']) ? 1 : 0;
            $password = (string)($_POST['password'] ?? '');

            if (!isset($RUOLI[$ruolo]))                 throw new RuntimeException('Ruolo non valido.');
            if ($username === '')                       throw new RuntimeException('Username obbligatorio.');
            // comando = nessun turno; admin/user = turno obbligatorio
            $turnoVal = ($ruolo === 'comando') ? null : ($turno !== '' && in_array($turno, $TURNI, true) ? $turno : null);
            if ($ruolo !== 'comando' && $turnoVal === null) throw new RuntimeException('Turno obbligatorio per admin/user.');

            if ($id > 0) {
                $pdo->prepare("UPDATE utenti SET username=?, nome=?, ruolo=?, turno=?, attivo=? WHERE id=?")
                    ->execute([$username, $nome, $ruolo, $turnoVal, $attivo, $id]);
                if ($password !== '') {
                    if (strlen($password) < 6) throw new RuntimeException('La password deve avere almeno 6 caratteri.');
                    $pdo->prepare("UPDATE utenti SET password=? WHERE id=?")
                        ->execute([password_hash($password, PASSWORD_BCRYPT), $id]);
                }
                $ok = 'Utente aggiornato.';
            } else {
                if (strlen($password) < 6) throw new RuntimeException('Password obbligatoria (min 6 caratteri) per un nuovo utente.');
                $newId = nextId($pdo, 'utenti');
                $pdo->prepare("INSERT INTO utenti (id,username,password,nome,ruolo,turno,attivo) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$newId, $username, password_hash($password, PASSWORD_BCRYPT), $nome, $ruolo, $turnoVal, $attivo]);
                $ok = 'Utente creato.';
            }
        } elseif ($azione === 'elimina') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === (int)utenteCorrente()['id']) throw new RuntimeException('Non puoi eliminare il tuo stesso account.');
            $pdo->prepare("DELETE FROM utenti WHERE id=?")->execute([$id]);
            $ok = 'Utente eliminato.';
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'uq_username') !== false || stripos($msg, 'Duplicate') !== false)
            $msg = 'Esiste già un utente con questo username.';
        $errore = $msg;
    }
}

$rigaEdit = null;
if (isset($_GET['modifica'])) {
    $st = $pdo->prepare("SELECT * FROM utenti WHERE id=?");
    $st->execute([(int)$_GET['modifica']]);
    $rigaEdit = $st->fetch() ?: null;
}
$righe = $pdo->query("SELECT * FROM utenti ORDER BY ruolo, turno, username")->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Gestione utenti</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/vigili.css?v=<?= @filemtime(__DIR__.'/../assets/css/vigili.css') ?>">
<script defer src="../assets/js/conferma.js?v=<?= @filemtime(__DIR__.'/../assets/js/conferma.js') ?>"></script>
<style>
  .u-form { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
  .u-form .ff { display:flex; flex-direction:column; gap:3px; }
  .u-form label { font-size:.72rem; font-weight:700; text-transform:uppercase; color:var(--grigio-md); }
  .u-form input, .u-form select { padding:7px 9px; border:1px solid #d5d8dc; border-radius:6px; font-size:.85rem; }
  .badge-ruolo { font-size:.7rem; font-weight:800; padding:2px 7px; border-radius:6px; color:#fff; }
  .r-comando { background:#5b2c83; } .r-admin { background:#1e6091; } .r-user { background:#5b6b7b; }
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
    <a href="../index.php"  class="nav-btn">🏠 Cruscotto</a>
    <a href="index.php"     class="nav-btn">⚙️ Amministrazione</a>
    <a href="utenti.php"    class="nav-btn active">👤 Utenti</a>
    <a href="../logout.php" class="nav-btn ml-auto">🚪 Esci</a>
  </div>
</nav>
<main class="main">
  <div class="page-title">
    <h2>👤 Gestione utenti</h2>
    <a href="index.php" class="btn btn-grigio">← Amministrazione</a>
  </div>

  <?php if ($ok):     ?><div class="alert alert-ok">✅ <?= htmlspecialchars($ok) ?></div><?php endif; ?>
  <?php if ($errore): ?><div class="alert alert-err">⚠️ <?= htmlspecialchars($errore) ?></div><?php endif; ?>

  <div class="card">
    <div class="card-head"><?= $rigaEdit ? '✏️ Modifica utente' : '➕ Nuovo utente' ?></div>
    <div style="padding:14px">
      <form method="POST" action="utenti.php" class="u-form">
        <input type="hidden" name="azione" value="salva">
        <input type="hidden" name="id" value="<?= (int)($rigaEdit['id'] ?? 0) ?>">
        <div class="ff">
          <label>Username</label>
          <input type="text" name="username" value="<?= htmlspecialchars($rigaEdit['username'] ?? '') ?>" required>
        </div>
        <div class="ff">
          <label>Nome (descrizione)</label>
          <input type="text" name="nome" value="<?= htmlspecialchars($rigaEdit['nome'] ?? '') ?>">
        </div>
        <div class="ff">
          <label>Ruolo</label>
          <select name="ruolo">
            <?php foreach ($RUOLI as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= ($rigaEdit['ruolo'] ?? 'user') === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ff">
          <label>Turno (no per Comando)</label>
          <select name="turno">
            <option value="">—</option>
            <?php foreach ($TURNI as $t): ?>
              <option value="<?= $t ?>" <?= ($rigaEdit['turno'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ff">
          <label><?= $rigaEdit ? 'Nuova password (vuoto = invariata)' : 'Password' ?></label>
          <input type="text" name="password" placeholder="<?= $rigaEdit ? 'lascia vuoto' : 'min 6 caratteri' ?>">
        </div>
        <div class="ff">
          <label>Attivo</label>
          <input type="checkbox" name="attivo" value="1" <?= (!$rigaEdit || $rigaEdit['attivo']) ? 'checked' : '' ?>>
        </div>
        <div class="ff">
          <button type="submit" class="btn btn-rosso"><?= $rigaEdit ? 'Salva' : 'Crea utente' ?></button>
        </div>
        <?php if ($rigaEdit): ?>
          <div class="ff"><a href="utenti.php" class="btn btn-grigio">Annulla</a></div>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <div class="tabella-wrap" style="margin-top:16px">
    <table>
      <thead>
        <tr><th>Username</th><th>Nome</th><th>Ruolo</th><th>Turno</th><th>Stato</th><th style="text-align:center">Azioni</th></tr>
      </thead>
      <tbody>
        <?php foreach ($righe as $r): ?>
          <tr>
            <td><strong><?= htmlspecialchars($r['username']) ?></strong></td>
            <td><?= htmlspecialchars($r['nome'] ?? '') ?></td>
            <td><span class="badge-ruolo r-<?= htmlspecialchars($r['ruolo']) ?>"><?= htmlspecialchars(strtoupper($r['ruolo'])) ?></span></td>
            <td><?= htmlspecialchars($r['turno'] ?? '—') ?></td>
            <td><?= $r['attivo'] ? '● Attivo' : '○ Disattivato' ?></td>
            <td>
              <div class="azioni">
                <a href="utenti.php?modifica=<?= (int)$r['id'] ?>" class="btn btn-grigio btn-sm">✏️ Modifica</a>
                <?php if ((int)$r['id'] !== (int)utenteCorrente()['id']): ?>
                <form method="POST" action="utenti.php"
                      onsubmit="return confermaSubmit(this, 'Eliminare l\'utente <?= htmlspecialchars(addslashes($r['username'])) ?>?', {titolo:'Elimina utente', okLabel:'🗑️ Elimina'})">
                  <input type="hidden" name="azione" value="elimina">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="btn btn-sm" style="background:var(--rosso);color:#fff">🗑️</button>
                </form>
                <?php endif; ?>
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
