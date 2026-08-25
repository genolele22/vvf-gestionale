<?php
// Helper assenze (sync foglio/cruscotto) — condivisi tra l'Agenda
// (ferie/index.php) e lo strumento di caricamento ferie simulate
// (admin/ferie_simulate.php). Mirror della logica del bot (database.py):
// un'assenza (tipo_assenza_id=1 FER di default, ma generalizzato a
// PERM/MAL/INF/MISS) marca il vigile assente sul foglio.
// accepted/pending → assenza presente, rejected → assenza rimossa.
// Tutto reversibile: l'assenza si ricrea dai dati della richiesta
// (vigile + data + turno + tipo).
//
// ECCEZIONE: il permesso ORARIO (bot_requests.ora_da/ora_a valorizzati) non
// passa da qui — non deve togliere il vigile dal servizio, vedi
// permessoOrarioSync() più sotto (stessa idea di visite_mediche: annotazione,
// non assenza).

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

function feriaInsertAssenza(PDO $pdo, int $vigileId, int $foglioId, int $tipoAssenzaId = 1): void {
    $st = $pdo->prepare(
        "SELECT id FROM assenze WHERE foglio_id=? AND vigile_id=? AND tipo_assenza_id=?"
    );
    $st->execute([$foglioId, $vigileId, $tipoAssenzaId]);
    if (!$st->fetchColumn()) {
        $next = nextId($pdo, 'assenze');
        $pdo->prepare(
            "INSERT INTO assenze (id, foglio_id, vigile_id, tipo_assenza_id) VALUES (?, ?, ?, ?)"
        )->execute([$next, $foglioId, $vigileId, $tipoAssenzaId]);
    }

    // #225: se il foglio è già compilato (assegnazioni popolate), il vigile
    // resterebbe anche nella squadra — stessa persona in ferie e in servizio.
    // Stesso DELETE già usato in foglio/nuovo.php quando l'assenza si crea da lì.
    $pdo->prepare(
        "DELETE FROM assegnazioni WHERE foglio_id=? AND vigile_id=?"
    )->execute([$foglioId, $vigileId]);
}

function feriaDeleteAssenza(PDO $pdo, int $vigileId, string $data, string $tipo, int $tipoAssenzaId = 1): void {
    $pdo->prepare(
        "DELETE a FROM assenze a
         JOIN fogli_servizio f ON f.id = a.foglio_id
         WHERE a.vigile_id=? AND f.data_servizio=? AND f.tipo_turno=? AND a.tipo_assenza_id=?"
    )->execute([$vigileId, $data, $tipo, $tipoAssenzaId]);
}

function feriaSyncAssenza(PDO $pdo, int $vigileId, string $data, string $tipoTurno, string $stato,
                           int $tipoAssenzaId = 1): void {
    $stT = $pdo->prepare("SELECT turno FROM vigili WHERE id=?");
    $stT->execute([$vigileId]);
    $turno = (string)$stT->fetchColumn();
    $tipi = ($tipoTurno === 'DN') ? ['D', 'N'] : [$tipoTurno];
    foreach ($tipi as $t) {
        if ($stato === 'rejected' || $stato === 'declined') {
            feriaDeleteAssenza($pdo, $vigileId, $data, $t, $tipoAssenzaId);
        } else { // approved | pending → vigile assente sul foglio DEL SUO TURNO
            $foglioId = feriaGetOrCreateFoglio($pdo, $data, $t, $turno);
            feriaInsertAssenza($pdo, $vigileId, $foglioId, $tipoAssenzaId);
        }
    }
}

// Permesso ORARIO: il vigile resta assegnato al turno, nessun tocco su
// `assenze`. Stesso pattern reversibile di feriaSyncAssenza ma su
// permessi_orari (mirror di visite_mediche): approved → upsert, rejected/
// declined → rimuove l'annotazione.
function permessoOrarioSync(PDO $pdo, int $vigileId, int $requestId, string $data, string $tipoTurno,
                             string $oraDa, string $oraA, ?string $note, string $stato): void {
    $del = $pdo->prepare("DELETE FROM permessi_orari WHERE request_id=?");
    $del->execute([$requestId]);
    if ($stato === 'rejected' || $stato === 'declined') return;
    $next = nextId($pdo, 'permessi_orari');
    $pdo->prepare(
        "INSERT INTO permessi_orari (id, vigile_id, data, tipo_turno, ora_da, ora_a, note, request_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([$next, $vigileId, $data, $tipoTurno, $oraDa, $oraA, $note, $requestId]);
}

}
