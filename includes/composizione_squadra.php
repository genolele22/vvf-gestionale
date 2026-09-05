<?php
/**
 * #172/#163 (logbook): composizione minima per ruolo di una squadra/mezzo, al
 * posto del semplice "N. richiesti" — usata dal controllo pre-invio/pre-odt
 * (#149, foglio/nuovo.php) per capire SE la squadra è completa, non solo
 * QUANTI elementi ha. Per-turno (#163: "ogni turno i propri valori"), come
 * regole_squadra — editabile in admin/composizione_squadra.php.
 *
 * Colonne di `posizione_composizione` (una riga per posizione+turno):
 *   min_capo         — minimo di Cr/Cs (capo partenza)
 *   min_autista34     — minimo di elementi con patente 3 o 4
 *   min_autista2      — minimo di elementi con patente 2, 3 o 4 (#257: "Autista
 *                       2/3/4" — una patente più grande copre il ruolo più
 *                       piccolo; la 1 no). I 3/4 vengono contati prima su
 *                       min_autista34 e solo gli avanzi scendono qui: la stessa
 *                       persona non copre i due ruoli autista insieme.
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS posizione_composizione (
        id               INT UNSIGNED NOT NULL,
        posizione_id     SMALLINT UNSIGNED NOT NULL,
        turno            CHAR(1) NOT NULL,
        min_capo         TINYINT UNSIGNED NOT NULL DEFAULT 0,
        min_autista34    TINYINT UNSIGNED NOT NULL DEFAULT 0,
        min_autista2     TINYINT UNSIGNED NOT NULL DEFAULT 0,
        min_altri        TINYINT UNSIGNED NOT NULL DEFAULT 0,
        abilitazione_id  TINYINT UNSIGNED DEFAULT NULL,
        min_abilitazione TINYINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pos_turno (posizione_id, turno)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Mappa posizione_id => riga di composizione per il turno dato (default a
 * zero/null per le posizioni senza riga configurata — nessun requisito). */
function composizionePerTurno(PDO $pdo, string $turno): array {
    $out = [];
    $st = $pdo->prepare("SELECT * FROM posizione_composizione WHERE turno=?");
    $st->execute([$turno]);
    foreach ($st->fetchAll() as $r) $out[(int)$r['posizione_id']] = $r;
    return $out;
}

/** Riga di default (nessun requisito) per una posizione senza configurazione. */
function composizioneVuota(): array {
    return ['min_capo' => 0, 'min_autista34' => 0, 'min_autista2' => 0,
            'min_altri' => 0, 'abilitazione_id' => null, 'min_abilitazione' => 0];
}

/** Totale minimo di elementi richiesti — sostituisce il vecchio n_richiesti
 * per il conteggio caselle vuote in rosso (#148). */
function richiestiTotalePosizione(array $comp): int {
    return (int)($comp['min_capo'] ?? 0) + (int)($comp['min_autista34'] ?? 0)
         + (int)($comp['min_autista2'] ?? 0) + (int)($comp['min_altri'] ?? 0);
}

}
