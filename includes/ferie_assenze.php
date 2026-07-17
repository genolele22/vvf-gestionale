<?php
// Helper assenze ferie (sync foglio/cruscotto) — condivisi tra l'Agenda
// (ferie/index.php) e lo strumento di caricamento ferie simulate
// (admin/ferie_simulate.php). Mirror della logica del bot (database.py):
// l'assenza tipo_assenza_id=1 marca il vigile assente sul foglio.
// accepted/pending → assenza presente, rejected → assenza rimossa.
// Tutto reversibile: l'assenza si ricrea dai dati della richiesta
// (vigile + data + turno).

require_once __DIR__ . '/turni.php';

if (!function_exists('feriaGetOrCreateFoglio')) {

function feriaGetOrCreateFoglio(PDO $pdo, string $data, string $tipo, string $turno): int {
    // Identità del foglio = (turno, data, tipo), come in foglio/nuovo.php.
    // Senza il turno si agganciava/creava il foglio di un altro turno
    // (assenze finite su fogli fantasma col default di colonna 'B').
    $st = $pdo->prepare("SELECT id FROM fogli_servizio WHERE turno=? AND data_servizio=? AND tipo_turno=?");
    $st->execute([$turno, $data, $tipo]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;

    $stS = $pdo->prepare("SELECT id FROM salti_turno WHERE codice=?");
    $stS->execute([$turno . saltoRiposoNum($data, $tipo)]);
    $saltoRiposoId = (int)($stS->fetchColumn() ?: 1);

    $next = nextId($pdo, 'fogli_servizio');
    $pdo->prepare(
        "INSERT INTO fogli_servizio (id, turno, data_servizio, tipo_turno, salto_riposo_id, creato_da)
         VALUES (?, ?, ?, ?, ?, 'ferie')"
    )->execute([$next, $turno, $data, $tipo, $saltoRiposoId]);
    return $next;
}

function feriaInsertAssenza(PDO $pdo, int $vigileId, int $foglioId): void {
    $st = $pdo->prepare(
        "SELECT id FROM assenze WHERE foglio_id=? AND vigile_id=? AND tipo_assenza_id=1"
    );
    $st->execute([$foglioId, $vigileId]);
    if ($st->fetchColumn()) return;
    $next = nextId($pdo, 'assenze');
    $pdo->prepare(
        "INSERT INTO assenze (id, foglio_id, vigile_id, tipo_assenza_id) VALUES (?, ?, ?, 1)"
    )->execute([$next, $foglioId, $vigileId]);
}

function feriaDeleteAssenza(PDO $pdo, int $vigileId, string $data, string $tipo): void {
    $pdo->prepare(
        "DELETE a FROM assenze a
         JOIN fogli_servizio f ON f.id = a.foglio_id
         WHERE a.vigile_id=? AND f.data_servizio=? AND f.tipo_turno=? AND a.tipo_assenza_id=1"
    )->execute([$vigileId, $data, $tipo]);
}

function feriaSyncAssenza(PDO $pdo, int $vigileId, string $data, string $tipoTurno, string $stato): void {
    $stT = $pdo->prepare("SELECT turno FROM vigili WHERE id=?");
    $stT->execute([$vigileId]);
    $turno = (string)$stT->fetchColumn();
    $tipi = ($tipoTurno === 'DN') ? ['D', 'N'] : [$tipoTurno];
    foreach ($tipi as $t) {
        if ($stato === 'rejected' || $stato === 'declined') {
            feriaDeleteAssenza($pdo, $vigileId, $data, $t);
        } else { // approved | pending → vigile assente sul foglio DEL SUO TURNO
            $foglioId = feriaGetOrCreateFoglio($pdo, $data, $t, $turno);
            feriaInsertAssenza($pdo, $vigileId, $foglioId);
        }
    }
}

}
