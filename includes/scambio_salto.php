<?php
/**
 * Scambio salto turno — logica condivisa (gestionale).
 *
 * Replica esatta di approva_scambio / annulla_scambio_approvato del bot
 * (database.py): scrive bot_scambi_salto + salto_override e patcha i fogli
 * di servizio già esistenti. Usata da:
 *   - foglio/nuovo.php   → crea/annulla lo scambio direttamente sul foglio
 *   - ferie/index.php    → approva/rifiuta in Agenda gli scambi nati dal bot
 *
 * Le funzioni assumono di girare DENTRO una transazione gestita dal chiamante.
 */
require_once __DIR__ . '/turni.php';

class ScambioConflitto extends RuntimeException {}

/**
 * Le 4 righe override [[data, tipo, vigile_out, vigile_in], ...] di uno scambio,
 * calcolate dai due slot e dal blocco. A cede il suo riposo a B e viceversa:
 *   - sulle date di A (slot_a): out=A, in=B  (B riposa al posto di A)
 *   - sulle date di B (slot_b): out=B, in=A
 * $sc deve avere: slot_a, slot_b, blocco_inizio, vigile_a_id, vigile_b_id.
 */
function scambioOverrideRows(array $sc): array
{
    $aOcc = slotDatesInBlocco((int)$sc['slot_a'], $sc['blocco_inizio']);
    $bOcc = slotDatesInBlocco((int)$sc['slot_b'], $sc['blocco_inizio']);
    if (!$aOcc || !$bOcc) {
        throw new RuntimeException('Slot fuori dal blocco.');
    }
    $aId = (int)$sc['vigile_a_id'];
    $bId = (int)$sc['vigile_b_id'];
    return [
        [$aOcc[0], 'D', $aId, $bId],
        [$aOcc[1], 'N', $aId, $bId],
        [$bOcc[0], 'D', $bId, $aId],
        [$bOcc[1], 'N', $bId, $aId],
    ];
}

/**
 * Guardia anti-conflitto: nessuno dei due vigili è già in uno scambio attivo
 * (salto_override.attivo=1) su una di queste righe. Solleva ScambioConflitto.
 */
function scambioGuardiaConflitto(PDO $pdo, array $rows, int $aId, int $bId): void
{
    $stDup = $pdo->prepare(
        "SELECT COUNT(*) FROM salto_override
         WHERE attivo=1 AND data=? AND tipo=?
           AND (vigile_in_id IN (?,?) OR vigile_out_id IN (?,?))"
    );
    foreach ($rows as [$d, $t, , ]) {
        $stDup->execute([$d, $t, $aId, $bId, $aId, $bId]);
        if ((int)$stDup->fetchColumn() > 0) {
            throw new ScambioConflitto(
                'Esiste già uno scambio attivo per uno dei due vigili in questo blocco.'
            );
        }
    }
}

/**
 * Scrive le righe salto_override e applica lo scambio sui fogli esistenti:
 *   - out torna a lavorare (esce dal salto)
 *   - in va a riposo (esce dalle assegnazioni, entra nel salto)
 * I fogli futuri non ancora creati li sistema prepopolaFoglio leggendo gli override.
 */
function scambioScriviOverride(PDO $pdo, int $sid, array $rows): void
{
    $insOv  = $pdo->prepare(
        "INSERT INTO salto_override
            (id, scambio_id, data, tipo, vigile_out_id, vigile_in_id, attivo)
         VALUES (?,?,?,?,?,?,1)"
    );
    $selF   = $pdo->prepare("SELECT id FROM fogli_servizio WHERE data_servizio=? AND tipo_turno=?");
    $delSS  = $pdo->prepare("DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?");
    $delAss = $pdo->prepare("DELETE FROM assegnazioni  WHERE foglio_id=? AND vigile_id=?");
    $chkSS  = $pdo->prepare("SELECT 1 FROM salto_servizio WHERE foglio_id=? AND vigile_id=?");
    $insSS  = $pdo->prepare("INSERT INTO salto_servizio (id, foglio_id, vigile_id, richiamato) VALUES (?,?,?,0)");

    foreach ($rows as [$d, $t, $outId, $inId]) {
        $oid = nextId($pdo, 'salto_override');
        $insOv->execute([$oid, $sid, $d, $t, $outId, $inId]);

        $selF->execute([$d, $t]);
        $fid = $selF->fetchColumn();
        if ($fid) {
            $delSS->execute([$fid, $outId]);
            $delAss->execute([$fid, $inId]);
            $chkSS->execute([$fid, $inId]);
            if (!$chkSS->fetchColumn()) {
                $nss = nextId($pdo, 'salto_servizio');
                $insSS->execute([$nss, $fid, $inId]);
            }
        }
    }
}

/**
 * Approva uno scambio ESISTENTE (nato dal bot, stato proposto/confermato):
 * guardia, stato='approvato', scrive override + patcha i fogli.
 * $approvatoDa = id del vigile/furiere che approva (0 → default = vigile_a_id).
 * Ritorna la riga bot_scambi_salto (per le notifiche). Solleva su stato non valido
 * o ScambioConflitto. Da chiamare dentro una transazione.
 */
function scambioApprovaEsistente(PDO $pdo, int $sid, int $approvatoDa = 0): array
{
    $st = $pdo->prepare("SELECT * FROM bot_scambi_salto WHERE id=?");
    $st->execute([$sid]);
    $sc = $st->fetch();
    if (!$sc) {
        throw new RuntimeException('Scambio inesistente.');
    }
    if (!in_array($sc['stato'], ['proposto', 'confermato'], true)) {
        throw new RuntimeException('Scambio non più in attesa (stato: ' . $sc['stato'] . ').');
    }

    $rows = scambioOverrideRows($sc);
    scambioGuardiaConflitto($pdo, $rows, (int)$sc['vigile_a_id'], (int)$sc['vigile_b_id']);

    $appBy = $approvatoDa ?: (int)$sc['vigile_a_id'];
    $pdo->prepare("UPDATE bot_scambi_salto SET stato='approvato', approvato_da=? WHERE id=?")
        ->execute([$appBy, $sid]);
    scambioScriviOverride($pdo, $sid, $rows);
    return $sc;
}

/**
 * Rifiuta uno scambio ESISTENTE in attesa (proposto/confermato → rifiutato).
 * Nessun override viene scritto (lo scambio non era stato applicato).
 * Ritorna la riga bot_scambi_salto. Solleva su stato non valido.
 */
function scambioRifiuta(PDO $pdo, int $sid): array
{
    $st = $pdo->prepare("SELECT * FROM bot_scambi_salto WHERE id=?");
    $st->execute([$sid]);
    $sc = $st->fetch();
    if (!$sc) {
        throw new RuntimeException('Scambio inesistente.');
    }
    if (!in_array($sc['stato'], ['proposto', 'confermato'], true)) {
        throw new RuntimeException('Scambio non più in attesa.');
    }
    $pdo->prepare("UPDATE bot_scambi_salto SET stato='rifiutato' WHERE id=?")->execute([$sid]);
    return $sc;
}

/**
 * Annulla uno scambio APPROVATO (reversibile): per ogni override attivo inverte
 * il patch sui fogli esistenti, disattiva la riga e marca lo scambio 'annullato'.
 * Ritorna false se non c'era nulla da annullare. Da chiamare dentro una transazione.
 */
function scambioAnnulla(PDO $pdo, int $sid): bool
{
    $stO = $pdo->prepare(
        "SELECT id, data, tipo, vigile_out_id, vigile_in_id
         FROM salto_override WHERE scambio_id=? AND attivo=1"
    );
    $stO->execute([$sid]);
    $rows = $stO->fetchAll();
    if (!$rows) {
        return false;
    }

    $selF  = $pdo->prepare("SELECT id FROM fogli_servizio WHERE data_servizio=? AND tipo_turno=?");
    $delSS = $pdo->prepare("DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?");
    $chkSS = $pdo->prepare("SELECT 1 FROM salto_servizio WHERE foglio_id=? AND vigile_id=?");
    $insSS = $pdo->prepare("INSERT INTO salto_servizio (id, foglio_id, vigile_id, richiamato) VALUES (?,?,?,0)");
    $offO  = $pdo->prepare("UPDATE salto_override SET attivo=0 WHERE id=?");

    foreach ($rows as $r) {
        $selF->execute([$r['data'], $r['tipo']]);
        $fid = $selF->fetchColumn();
        if ($fid) {
            // chi era entrato in salto esce; chi cedeva (rester canonico) rientra
            $delSS->execute([$fid, (int)$r['vigile_in_id']]);
            $chkSS->execute([$fid, (int)$r['vigile_out_id']]);
            if (!$chkSS->fetchColumn()) {
                $nss = nextId($pdo, 'salto_servizio');
                $insSS->execute([$nss, $fid, (int)$r['vigile_out_id']]);
            }
        }
        $offO->execute([(int)$r['id']]);
    }
    $pdo->prepare("UPDATE bot_scambi_salto SET stato='annullato' WHERE id=?")->execute([$sid]);
    return true;
}

/**
 * Enfila una notifica per il bot in bot_outbox (Telegram + mail le manda il bot,
 * drain). Idempotente via ctx univoco. data/tipo_turno restano NULL: per gli
 * scambi il bot ricostruisce le date dallo scambio (ctx = "scambio:<id>:<stato>").
 */
function scambioEnqueueOutbox(PDO $pdo, int $vigileId, string $tipo, string $ctx): void
{
    $has = $pdo->prepare("SELECT 1 FROM bot_outbox WHERE ctx=? LIMIT 1");
    $has->execute([$ctx]);
    if ($has->fetchColumn()) {
        return;
    }
    $nextId = nextId($pdo, 'bot_outbox');
    $pdo->prepare("INSERT INTO bot_outbox (id, vigile_id, tipo, ctx) VALUES (?,?,?,?)")
        ->execute([$nextId, $vigileId, $tipo, $ctx]);
}
