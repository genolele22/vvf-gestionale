<?php
/**
 * Logbook di sviluppo — condiviso tra la pagina completa (logbook/index.php),
 * l'endpoint AJAX del tasto rapido (logbook/aggiungi.php) e il widget flottante
 * incluso in ogni pagina (includes/logbook_widget.php).
 *
 * pagina_url: aggiunta per il tasto rapido "Qui non va" (porta con sé la pagina
 * esatta da cui è stata scritta, per tornarci con un click — come in The Crew).
 * Le voci create dal form della pagina completa restano senza pagina_url,
 * esattamente come prima.
 */

if (!function_exists('assicuraSchemaLogbook')) {

function assicuraSchemaLogbook(PDO $pdo): void
{
    static $fatto = false;
    if ($fatto) return;
    $fatto = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_logbook (
            id         BIGINT AUTO_INCREMENT PRIMARY KEY,
            testo      TEXT NOT NULL,
            autore     VARCHAR(60) DEFAULT NULL,
            pagina_url VARCHAR(255) DEFAULT NULL,
            fatto      TINYINT(1) NOT NULL DEFAULT 0,
            creato_il  DATETIME DEFAULT CURRENT_TIMESTAMP,
            fatto_il   DATETIME DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bot_logbook' AND COLUMN_NAME = 'pagina_url'"
    );
    $st->execute();
    if (!$st->fetchColumn()) {
        $pdo->exec("ALTER TABLE bot_logbook ADD COLUMN pagina_url VARCHAR(255) DEFAULT NULL");
    }
}

function logbookAggiungi(PDO $pdo, string $testo, ?string $autore, ?string $paginaUrl): void
{
    // id esplicito via MAX+1: TiDB non garantisce AUTO_INCREMENT affidabile
    // (stesso pattern già in uso qui prima di questo refactor).
    $pdo->beginTransaction();
    $nextId = nextId($pdo, 'bot_logbook');
    $st = $pdo->prepare(
        "INSERT INTO bot_logbook (id, testo, autore, pagina_url) VALUES (?, ?, ?, ?)"
    );
    $st->execute([$nextId, $testo, $autore, $paginaUrl]);
    $pdo->commit();
}

}
