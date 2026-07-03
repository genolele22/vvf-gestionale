<?php
/**
 * Comunicazioni ferie alla finalizzazione del foglio (tasto ✉️ Invia).
 * Un solo gesto raccoglie tutte le comunicazioni ferie del turno:
 *   - APPROVATE  : FER sul foglio con richiesta bot → approva la richiesta + notifica
 *   - D'UFFICIO  : FER sul foglio SENZA richiesta bot (ferie d'ufficio) → notifica
 *   - NEGATE     : richiesta bot 'rejected' per la data/turno (l'Agenda l'ha respinta
 *                  ma non avvisa) → notifica
 * Tutto idempotente via bot_outbox.ctx (Telegram + mail le manda il bot).
 * Ritorna i conteggi delle notifiche effettivamente accodate.
 */
function finalizeFerie(PDO $pdo, int $foglioId, string $dataStr, string $tipo): array
{
    // coda notifiche (idempotente: la crea se manca, stessa struttura del bot)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_outbox (
            id          INT UNSIGNED NOT NULL,
            vigile_id   INT UNSIGNED NOT NULL,
            tipo        VARCHAR(30) NOT NULL,
            data        DATE DEFAULT NULL,
            tipo_turno  VARCHAR(2) DEFAULT NULL,
            stato       ENUM('pending','sent','error') NOT NULL DEFAULT 'pending',
            tentativi   INT NOT NULL DEFAULT 0,
            ctx         VARCHAR(80) DEFAULT NULL,
            errore      VARCHAR(255) DEFAULT NULL,
            creato_il   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            inviato_il  DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ctx (ctx),
            KEY idx_stato (stato)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $tipi = $tipo === 'N' ? ['N', 'DN'] : ['D', 'DN'];
    $ph   = implode(',', array_fill(0, count($tipi), '?'));

    $approve = $pdo->prepare("UPDATE bot_requests SET stato='approved', processed_at=NOW() WHERE id=? AND stato='pending'");
    $hasCtx  = $pdo->prepare("SELECT 1 FROM bot_outbox WHERE ctx=? LIMIT 1");
    $insOut  = $pdo->prepare(
        "INSERT INTO bot_outbox (id, vigile_id, tipo, data, tipo_turno, ctx)
         VALUES (?,?,?,?,?,?)"
    );

    $cnt = ['approvate' => 0, 'ufficio' => 0, 'negate' => 0];

    // ── 1) FER sul foglio: approvata (con richiesta) o d'ufficio (senza) ──
    $st = $pdo->prepare("SELECT DISTINCT vigile_id FROM assenze WHERE foglio_id=? AND tipo_assenza_id=1");
    $st->execute([$foglioId]);
    $vigili = array_map('intval', array_column($st->fetchAll(), 'vigile_id'));

    // Solo richieste non respinte: una FER sul foglio con richiesta 'rejected' è
    // un override a mano dell'operatore → la trattiamo come d'ufficio (non negata).
    $findReq = $pdo->prepare(
        "SELECT id, stato, tipo_turno FROM bot_requests
         WHERE vigile_id=? AND data_richiesta=? AND tipo_turno IN ($ph) AND stato<>'rejected'
         ORDER BY id DESC LIMIT 1"
    );

    foreach ($vigili as $vid) {
        $findReq->execute(array_merge([$vid, $dataStr], $tipi));
        $req = $findReq->fetch();

        if ($req) {
            // ferie da richiesta bot → approva + notifica
            $reqId = (int)$req['id'];
            if ($req['stato'] === 'pending') $approve->execute([$reqId]);
            $hasCtx->execute(['ferie:' . $reqId]);
            if ($hasCtx->fetchColumn()) continue;       // già notificato
            $insOut->execute([nextId($pdo, 'bot_outbox'), $vid, 'ferie_approvata', $dataStr, $req['tipo_turno'], 'ferie:' . $reqId]);
            $cnt['approvate']++;
        } else {
            // ferie d'ufficio (nessuna richiesta bot)
            $hasCtx->execute(['ferie_uff:' . $foglioId . ':' . $vid]);
            if ($hasCtx->fetchColumn()) continue;       // già notificato
            $insOut->execute([nextId($pdo, 'bot_outbox'), $vid, 'ferie_ufficio', $dataStr, $tipo, 'ferie_uff:' . $foglioId . ':' . $vid]);
            $cnt['ufficio']++;
        }
    }

    // ── 2) NEGATE: richieste 'rejected' per questa data/turno, per chi NON è
    // (più) in FER sul foglio (chi è in FER è stato gestito sopra come d'ufficio) ──
    $stNeg = $pdo->prepare(
        "SELECT r.id, r.vigile_id, r.tipo_turno FROM bot_requests r
         WHERE r.data_richiesta=? AND r.stato='rejected' AND r.tipo_turno IN ($ph)
           AND NOT EXISTS (SELECT 1 FROM assenze a
                           WHERE a.foglio_id=? AND a.vigile_id=r.vigile_id AND a.tipo_assenza_id=1)"
    );
    $stNeg->execute(array_merge([$dataStr], $tipi, [$foglioId]));
    foreach ($stNeg->fetchAll() as $r) {
        $reqId = (int)$r['id'];
        $hasCtx->execute(['ferie_neg:' . $reqId]);
        if ($hasCtx->fetchColumn()) continue;           // già notificato
        $insOut->execute([nextId($pdo, 'bot_outbox'), (int)$r['vigile_id'], 'ferie_negata', $dataStr, $r['tipo_turno'], 'ferie_neg:' . $reqId]);
        $cnt['negate']++;
    }

    return $cnt;
}
