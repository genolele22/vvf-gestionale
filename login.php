<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

// Già loggato → al cruscotto
if (isLoggato()) { header('Location: index.php'); exit; }

$errore = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $errore = 'Inserisci utente e password.';
    } else {
        $st = getDB()->prepare("SELECT * FROM utenti WHERE username=? AND attivo=1 LIMIT 1");
        $st->execute([$username]);
        $u = $st->fetch();
        if ($u && password_verify($password, $u['password'])) {
            session_regenerate_id(true);
            unset($_SESSION['turno_attivo']);   // niente turno ereditato da una sessione precedente
            $_SESSION['utente'] = [
                'id'       => (int)$u['id'],
                'username' => $u['username'],
                'nome'     => $u['nome'],
                'ruolo'    => $u['ruolo'],
                'turno'    => $u['turno'],
            ];
            header('Location: index.php');
            exit;
        }
        $errore = 'Credenziali non valide.';
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Accesso</title>
<link rel="stylesheet" href="assets/css/stile.css?v=<?= @filemtime(__DIR__.'/assets/css/stile.css') ?>">
<style>
  body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:#1f2933; }
  .login-card { background:#fff; border-radius:14px; box-shadow:0 10px 40px rgba(0,0,0,.35);
                padding:30px 28px; width:min(360px,92vw); }
  .login-logo { text-align:center; font-size:2.4rem; }
  .login-card h1 { font-size:1.15rem; text-align:center; margin:4px 0 2px; }
  .login-card p.sub { text-align:center; color:#7b8794; font-size:.82rem; margin:0 0 18px; }
  .login-card label { display:block; font-size:.74rem; font-weight:700; text-transform:uppercase;
                      color:#52606d; margin:12px 0 4px; }
  .login-card input { width:100%; padding:10px 12px; border:1px solid #cbd2d9; border-radius:8px; font-size:.95rem; }
  .login-card button { width:100%; margin-top:20px; padding:11px; border:0; border-radius:8px;
                       background:#c0392b; color:#fff; font-size:1rem; font-weight:700; cursor:pointer; }
  .login-err { background:#fdecea; border:1px solid #e6b0aa; color:#922b21; border-radius:8px;
               padding:9px 12px; font-size:.85rem; margin-top:12px; text-align:center; }
</style>
</head>
<body>
  <form class="login-card" method="POST" action="login.php">
    <div class="login-logo">🚒</div>
    <h1>Gestionale Foglio di Servizio</h1>
    <p class="sub">Comando Provinciale VVF di Genova</p>
    <?php if ($errore): ?><div class="login-err">⚠️ <?= htmlspecialchars($errore) ?></div><?php endif; ?>
    <label>Utente</label>
    <input type="text" name="username" autofocus autocomplete="username"
           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
    <label>Password</label>
    <input type="password" name="password" autocomplete="current-password">
    <button type="submit">Entra</button>
  </form>
</body>
</html>
