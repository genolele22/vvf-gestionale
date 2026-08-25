<?php
/**
 * #136 (logbook): password dell'account fureria (mittente delle risposte)
 * gestibile dal gestionale invece che solo da Telegram (/aggiorna_password).
 *
 * L'indirizzo mittente per turno è già configurabile in admin/parametri.php
 * (mail_furiera_risposte_A/B/C/D) — qui si aggiorna solo la password
 * cifrata sull'anagrafica del vigile che ha quell'indirizzo come email
 * (stessa colonna vigili.email_password_enc che usa il bot, stessa cifratura
 * Fernet: includes/fernet.php + ENCRYPTION_KEY, condivisa col bot).
 *
 * Verifica le credenziali via un vero login SMTP prima di salvare — se sono
 * sbagliate le notifiche alla fureria smetterebbero di partire in silenzio,
 * stesso principio del controllo che il bot fa su /aggiorna_password.
 */
require_once __DIR__ . '/fernet.php';

if (!function_exists('testSmtpLogin')) {

function testSmtpLogin(string $email, string $password, string $host, int $port): array {
    $errore = null;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $transport = ($port === 465) ? 'ssl://' : 'tcp://';
    $fp = @stream_socket_client("$transport$host:$port", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return [false, "Connessione a $host:$port fallita: $errstr"];
    stream_set_timeout($fp, 10);

    $leggi = function () use ($fp): string {
        $riga = fgets($fp, 1024);
        // multi-riga SMTP: "250-..." continua, "250 ..." è l'ultima
        while ($riga !== false && isset($riga[3]) && $riga[3] === '-') {
            $riga = fgets($fp, 1024);
        }
        return $riga === false ? '' : $riga;
    };
    $scrivi = function (string $cmd) use ($fp): void { fwrite($fp, $cmd . "\r\n"); };

    try {
        $r = $leggi();
        if (!str_starts_with($r, '220')) return [false, "Saluto SMTP inatteso: $r"];

        $scrivi('EHLO gestionale-vvf');
        $r = $leggi();
        if (!str_starts_with($r, '250')) return [false, "EHLO fallito: $r"];

        if ($port !== 465) {
            $scrivi('STARTTLS');
            $r = $leggi();
            if (!str_starts_with($r, '220')) return [false, "STARTTLS fallito: $r"];
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return [false, 'Avvio TLS fallito'];
            }
            $scrivi('EHLO gestionale-vvf');
            $r = $leggi();
            if (!str_starts_with($r, '250')) return [false, "EHLO (TLS) fallito: $r"];
        }

        $scrivi('AUTH LOGIN');
        $r = $leggi();
        if (!str_starts_with($r, '334')) return [false, "AUTH LOGIN non supportato: $r"];

        $scrivi(base64_encode($email));
        $r = $leggi();
        if (!str_starts_with($r, '334')) return [false, "Username rifiutato: $r"];

        $scrivi(base64_encode($password));
        $r = $leggi();
        if (!str_starts_with($r, '235')) return [false, "Login fallito: $r"];

        $scrivi('QUIT');
        return [true, null];
    } finally {
        fclose($fp);
    }
}

/**
 * Aggiorna la password cifrata dell'account fureria che ha $email come
 * indirizzo in anagrafica. Verifica prima con un vero login SMTP.
 * Ritorna [ok, messaggio].
 */
function aggiornaPasswordFureria(PDO $pdo, string $email, string $password, string $smtpHost, int $smtpPort): array {
    $st = $pdo->prepare("SELECT id FROM vigili WHERE email = ?");
    $st->execute([$email]);
    $vigileId = $st->fetchColumn();
    if (!$vigileId) {
        return [false, "Nessun vigile in anagrafica con l'indirizzo $email — deve esistere già (è l'indirizzo configurato sopra)."];
    }

    [$ok, $err] = testSmtpLogin($email, $password, $smtpHost, $smtpPort);
    if (!$ok) {
        return [false, "Credenziali non valide o server non raggiungibile: $err"];
    }

    if (ENCRYPTION_KEY === '') {
        return [false, 'ENCRYPTION_KEY non configurata sul gestionale — impossibile cifrare in modo compatibile col bot.'];
    }
    $token = fernetEncrypt($password, ENCRYPTION_KEY);
    $pdo->prepare("UPDATE vigili SET email_password_enc = ? WHERE id = ?")->execute([$token, $vigileId]);
    return [true, 'Password aggiornata e verificata.'];
}

}
