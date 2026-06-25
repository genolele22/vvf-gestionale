<?php
define('DB_HOST',    getenv('MYSQL_HOST')     ?: 'gateway01.eu-central-1.prod.aws.tidbcloud.com');
define('DB_PORT',    getenv('MYSQL_PORT')     ?: '4000');
define('DB_NAME',    getenv('MYSQL_DATABASE') ?: 'vvf_turno_b');
define('DB_USER',    getenv('MYSQL_USER')     ?: '');
define('DB_PASS',    getenv('MYSQL_PASSWORD') ?: '');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
                       DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_SSL_CA       => '/etc/ssl/certs/ca-certificates.crt',
        ]);
    }
    return $pdo;
}

/**
 * Prossimo id per una tabella (TiDB non ha AUTO_INCREMENT implicito → id calcolato
 * a mano). $table deve essere un nome di tabella fidato (mai input utente diretto).
 * NB: a bassissima concorrenza com'è questo gestionale va bene; sotto scritture
 * simultanee sullo stesso table c'è una race teorica (due MAX+1 uguali).
 */
function nextId(PDO $pdo, string $table): int {
    $table = str_replace('`', '', $table);   // hardening
    return (int) $pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM `$table`")->fetchColumn();
}
