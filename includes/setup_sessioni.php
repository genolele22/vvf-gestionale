<?php
/**
 * setup_sessioni.php — crea la tabella `sessioni` (una tantum).
 * Lanciare sul container:  php /var/www/html/includes/setup_sessioni.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // solo da fly ssh console, mai dal web
require_once __DIR__ . '/../config/db.php';

getDB()->exec("
  CREATE TABLE IF NOT EXISTS sessioni (
    id            VARCHAR(128) NOT NULL PRIMARY KEY,
    data          MEDIUMTEXT   NOT NULL,
    last_activity INT          NOT NULL,
    INDEX idx_last_activity (last_activity)
  ) DEFAULT CHARSET=utf8mb4
");

echo "Tabella 'sessioni' pronta.\n";
