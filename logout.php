<?php
// Logout: chiude la sessione e torna al cruscotto.
// Stub pronto per quando verrà aggiunta l'autenticazione (redirect → login).
session_start();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: index.php');
exit;
