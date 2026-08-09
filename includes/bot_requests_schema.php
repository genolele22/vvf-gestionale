<?php
/**
 * Schema condiviso con il bot (database.py:init_db) per le richieste di assenza
 * oltre le ferie: tipo_assenza_id/note/ora_da/ora_a su bot_requests, tabella
 * permessi_orari. ALTER/CREATE idempotenti — il gestionale può ripartire prima
 * del bot senza restare senza queste colonne (stesso principio già usato per
 * visite_mediche in ferie/index.php).
 */

if (!function_exists('assicuraSchemaRichiesteAssenza')) {

function assicuraSchemaRichiesteAssenza(PDO $pdo): void
{
    static $fatto = false;
    if ($fatto) return;
    $fatto = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS permessi_orari (
            id         INT UNSIGNED NOT NULL,
            vigile_id  INT UNSIGNED NOT NULL,
            data       DATE NOT NULL,
            ora_da     TIME NOT NULL,
            ora_a      TIME NOT NULL,
            note       VARCHAR(200) DEFAULT NULL,
            request_id INT UNSIGNED DEFAULT NULL,
            creato_il  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $colonne = [
        'tipo_assenza_id' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
        'note'            => 'VARCHAR(200) DEFAULT NULL',
        'ora_da'          => 'TIME DEFAULT NULL',
        'ora_a'           => 'TIME DEFAULT NULL',
    ];
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bot_requests' AND COLUMN_NAME = ?"
    );
    foreach ($colonne as $col => $ddl) {
        $st->execute([$col]);
        if ($st->fetchColumn()) continue;
        $pdo->exec("ALTER TABLE bot_requests ADD COLUMN $col $ddl");
    }
}

}
