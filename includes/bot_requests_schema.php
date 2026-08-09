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

    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $aggiungiSeManca = function (string $tabella, array $colonne) use ($pdo, $st) {
        foreach ($colonne as $col => $ddl) {
            $st->execute([$tabella, $col]);
            if ($st->fetchColumn()) continue;
            $pdo->exec("ALTER TABLE $tabella ADD COLUMN $col $ddl");
        }
    };
    $aggiungiSeManca('bot_requests', [
        'tipo_assenza_id' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
        'note'            => 'VARCHAR(200) DEFAULT NULL',
        'ora_da'          => 'TIME DEFAULT NULL',
        'ora_a'           => 'TIME DEFAULT NULL',
        // Range di calendario COMUNICATO dal vigile (malattia/infortunio/missione),
        // uguale su ogni turno decomposto: l'Agenda lo mostra invece di
        // ricalcolarlo dai turni (vedi ferie/index.php, blocco vigile-card).
        'range_da'        => 'DATE DEFAULT NULL',
        'range_a'         => 'DATE DEFAULT NULL',
    ]);
    // tipo_turno (D/N): senza, il foglio non sa su quale dei due fogli del
    // giorno (diurno/notturno) mostrare il badge orario.
    $aggiungiSeManca('permessi_orari', [
        'tipo_turno' => "ENUM('D','N') DEFAULT NULL",
    ]);
}

}
