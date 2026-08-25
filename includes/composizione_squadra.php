<?php
/**
 * #172 (logbook): composizione minima per ruolo di una squadra/mezzo, al
 * posto del semplice "N. richiesti" — usata dal controllo pre-invio/pre-odt
 * (#149, foglio/nuovo.php) per capire SE la squadra è completa, non solo
 * QUANTI elementi ha.
 *
 * Le colonne aggiunte a `posizioni`:
 *   min_capo         — minimo di Cr/Cs (capo partenza)
 *   min_autista34     — minimo di elementi con patente 3 o 4
 *   min_autista2      — minimo di elementi con patente 2
 *   min_altri         — minimo di elementi SENZA vincolo, in aggiunta ai precedenti
 *   abilitazione_id   — abilitazione richiesta (opzionale, FK verso `abilitazioni`)
 *   min_abilitazione  — minimo di elementi con quell'abilitazione
 *
 * Una stessa persona può soddisfare più requisiti insieme (es. un autista 3/4
 * con l'abilitazione richiesta conta per entrambi) — l'esempio del ticket:
 * Autisti 3/4 (1) + Altri (1) si valida con un autista 3/4 abilitato più UN
 * qualunque altro elemento, 2 persone in tutto.
 */

if (!function_exists('assicuraSchemaComposizioneSquadra')) {

function assicuraSchemaComposizioneSquadra(PDO $pdo): void
{
    static $fatto = false;
    if ($fatto) return;
    $fatto = true;

    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posizioni' AND COLUMN_NAME = ?"
    );
    $aggiungiSeManca = function (string $colonna, string $ddl) use ($pdo, $st) {
        $st->execute([$colonna]);
        if ($st->fetchColumn()) return;
        $pdo->exec("ALTER TABLE posizioni ADD COLUMN $colonna $ddl");
    };
    $aggiungiSeManca('min_capo',        'TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $aggiungiSeManca('min_autista34',   'TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $aggiungiSeManca('min_autista2',    'TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $aggiungiSeManca('min_altri',       'TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $aggiungiSeManca('abilitazione_id', 'TINYINT UNSIGNED DEFAULT NULL');
    $aggiungiSeManca('min_abilitazione','TINYINT UNSIGNED NOT NULL DEFAULT 0');
}

/** Totale minimo di elementi richiesti dalla composizione — sostituisce il
 * vecchio n_richiesti per il conteggio caselle vuote in rosso (#148). */
function richiestiTotalePosizione(array $pos): int {
    return (int)($pos['min_capo'] ?? 0) + (int)($pos['min_autista34'] ?? 0)
         + (int)($pos['min_autista2'] ?? 0) + (int)($pos['min_altri'] ?? 0);
}

/** Ruoli di una persona assegnata, ai fini della verifica composizione.
 * $qcodice = codice qualifica ('Cr','Cs','Vp',...), $patente = patente_max
 * (int|string|null), $abilCodici = elenco codici abilitazione posseduti. */
function ruoliComposizione(?string $qcodice, $patente, array $abilCodici = []): array {
    $pat = (string)($patente ?? '');
    return [
        'capo'      => in_array($qcodice, ['Cr', 'Cs'], true),
        'autista34' => in_array($pat, ['3', '4'], true),
        'autista2'  => $pat === '2',
        'abil'      => $abilCodici,
    ];
}

}
