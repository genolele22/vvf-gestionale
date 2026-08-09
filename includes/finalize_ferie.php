<?php
/**
 * Comunicazioni ferie/permesso alla finalizzazione del foglio (tasto ✉️ Invia).
 * Un solo gesto raccoglie tutte le comunicazioni del turno per un dato tipo di
 * assenza approvabile (FER o PERM — MISS/MAL/INF non passano da qui, si
 * registrano da sole senza bisogno di conferma fureria):
 *   - APPROVATE  : assenza sul foglio con richiesta bot → approva la richiesta + notifica
 *   - D'UFFICIO  : assenza sul foglio SENZA richiesta bot (es. ferie d'ufficio) → notifica
 *   - NEGATE     : richiesta bot 'declined' per la data/turno (l'Agenda l'ha respinta
 *                  ma non avvisa) → notifica
 * Tutto idempotente via bot_outbox.ctx (Telegram + mail le manda il bot).
 * Ritorna i conteggi delle notifiche effettivamente accodate.
 */
function finalizeAssenza(PDO $pdo, int $foglioId, string $dataStr, string $tipo,
                          int $tipoAssenzaId, string $ctxPrefix,
                          string $eventoApprovata, string $eventoUfficio, string $eventoNegata): array
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
    $decline = $pdo->prepare("UPDATE bot_requests SET stato='rejected', processed_at=NOW() WHERE id=? AND stato='declined'");
    $hasCtx  = $pdo->prepare("SELECT 1 FROM bot_outbox WHERE ctx=? LIMIT 1");
    $insOut  = $pdo->prepare(
        "INSERT INTO bot_outbox (id, vigile_id, tipo, data, tipo_turno, ctx)
         VALUES (?,?,?,?,?,?)"
    );

    $cnt = ['approvate' => 0, 'ufficio' => 0, 'negate' => 0];

    // ── 1) assenza sul foglio: approvata (con richiesta) o d'ufficio (senza) ──
    $st = $pdo->prepare("SELECT DISTINCT vigile_id FROM assenze WHERE foglio_id=? AND tipo_assenza_id=?");
    $st->execute([$foglioId, $tipoAssenzaId]);
    $vigili = array_map('intval', array_column($st->fetchAll(), 'vigile_id'));

    // Solo richieste non respinte/decise-negativamente: un'assenza sul foglio con
    // richiesta 'rejected'/'declined' è un override a mano dell'operatore →
    // la trattiamo come d'ufficio (non negata).
    $findReq = $pdo->prepare(
        "SELECT id, stato, tipo_turno FROM bot_requests
         WHERE vigile_id=? AND data_richiesta=? AND tipo_turno IN ($ph) AND tipo_assenza_id=?
           AND stato NOT IN ('rejected','declined')
         ORDER BY id DESC LIMIT 1"
    );

    foreach ($vigili as $vid) {
        $findReq->execute(array_merge([$vid, $dataStr], $tipi, [$tipoAssenzaId]));
        $req = $findReq->fetch();

        if ($req) {
            // da richiesta bot → approva + notifica
            $reqId = (int)$req['id'];
            if ($req['stato'] === 'pending') $approve->execute([$reqId]);
            $hasCtx->execute([$ctxPrefix . ':' . $reqId]);
            if ($hasCtx->fetchColumn()) continue;       // già notificato
            $insOut->execute([nextId($pdo, 'bot_outbox'), $vid, $eventoApprovata, $dataStr, $req['tipo_turno'], $ctxPrefix . ':' . $reqId]);
            $cnt['approvate']++;
        } else {
            // d'ufficio (nessuna richiesta bot)
            $hasCtx->execute([$ctxPrefix . '_uff:' . $foglioId . ':' . $vid]);
            if ($hasCtx->fetchColumn()) continue;       // già notificato
            $insOut->execute([nextId($pdo, 'bot_outbox'), $vid, $eventoUfficio, $dataStr, $tipo, $ctxPrefix . '_uff:' . $foglioId . ':' . $vid]);
            $cnt['ufficio']++;
        }
    }

    // ── 2) NEGATE: richieste 'declined' (respinte ma non ancora comunicate) per
    // questa data/turno, per chi NON è (più) assente sul foglio (chi lo è è
    // gestito sopra come d'ufficio). 'declined' → 'rejected' solo qui, alla
    // comunicazione vera (mirror di pending→approved sopra, vedi feedback Moli).
    $stNeg = $pdo->prepare(
        "SELECT r.id, r.vigile_id, r.tipo_turno FROM bot_requests r
         WHERE r.data_richiesta=? AND r.stato='declined' AND r.tipo_turno IN ($ph) AND r.tipo_assenza_id=?
           AND NOT EXISTS (SELECT 1 FROM assenze a
                           WHERE a.foglio_id=? AND a.vigile_id=r.vigile_id AND a.tipo_assenza_id=?)"
    );
    $stNeg->execute(array_merge([$dataStr], $tipi, [$tipoAssenzaId, $foglioId, $tipoAssenzaId]));
    foreach ($stNeg->fetchAll() as $r) {
        $reqId = (int)$r['id'];
        $hasCtx->execute([$ctxPrefix . '_neg:' . $reqId]);
        if ($hasCtx->fetchColumn()) continue;            // già notificato
        $decline->execute([$reqId]);
        $insOut->execute([nextId($pdo, 'bot_outbox'), (int)$r['vigile_id'], $eventoNegata, $dataStr, $r['tipo_turno'], $ctxPrefix . '_neg:' . $reqId]);
        $cnt['negate']++;
    }

    return $cnt;
}

function finalizeFerie(PDO $pdo, int $foglioId, string $dataStr, string $tipo): array
{
    return finalizeAssenza($pdo, $foglioId, $dataStr, $tipo, 1, 'ferie',
        'ferie_approvata', 'ferie_ufficio', 'ferie_negata');
}

function finalizePermesso(PDO $pdo, int $foglioId, string $dataStr, string $tipo): array
{
    return finalizeAssenza($pdo, $foglioId, $dataStr, $tipo, 4, 'permesso',
        'permesso_approvato', 'permesso_ufficio', 'permesso_negato');
}

/**
 * Permesso ORARIO: non lascia traccia in `assenze` (il vigile resta assegnato,
 * vedi permessoOrarioSync in ferie_assenze.php), quindi finalizeAssenza — che
 * scansiona `assenze` — non lo vede. Stessa idea (comunicazione al gesto
 * ✉️ Invia, idempotente via bot_outbox.ctx) ma lette direttamente da
 * bot_requests. Niente "d'ufficio": un permesso orario nasce sempre da una
 * richiesta bot.
 */
function finalizePermessoOrario(PDO $pdo, string $dataStr, string $tipo): array
{
    $tipi = $tipo === 'N' ? ['N', 'DN'] : ['D', 'DN'];
    $ph   = implode(',', array_fill(0, count($tipi), '?'));

    $approve = $pdo->prepare("UPDATE bot_requests SET stato='approved', processed_at=NOW() WHERE id=? AND stato='pending'");
    $decline = $pdo->prepare("UPDATE bot_requests SET stato='rejected', processed_at=NOW() WHERE id=? AND stato='declined'");
    $hasCtx  = $pdo->prepare("SELECT 1 FROM bot_outbox WHERE ctx=? LIMIT 1");
    $insOut  = $pdo->prepare(
        "INSERT INTO bot_outbox (id, vigile_id, tipo, data, tipo_turno, ctx) VALUES (?,?,?,?,?,?)"
    );
    $cnt = ['approvate' => 0, 'negate' => 0];

    $st = $pdo->prepare(
        "SELECT id, vigile_id, tipo_turno, stato FROM bot_requests
         WHERE data_richiesta=? AND tipo_turno IN ($ph) AND tipo_assenza_id=4 AND ora_da IS NOT NULL
           AND stato IN ('pending','declined')"
    );
    $st->execute(array_merge([$dataStr], $tipi));
    foreach ($st->fetchAll() as $r) {
        $reqId = (int)$r['id'];
        if ($r['stato'] === 'pending') {
            $approve->execute([$reqId]);
            $hasCtx->execute(['permesso:' . $reqId]);
            if ($hasCtx->fetchColumn()) continue;
            $insOut->execute([nextId($pdo, 'bot_outbox'), (int)$r['vigile_id'], 'permesso_approvato', $dataStr, $r['tipo_turno'], 'permesso:' . $reqId]);
            $cnt['approvate']++;
        } else {
            $decline->execute([$reqId]);
            $hasCtx->execute(['permesso_neg:' . $reqId]);
            if ($hasCtx->fetchColumn()) continue;
            $insOut->execute([nextId($pdo, 'bot_outbox'), (int)$r['vigile_id'], 'permesso_negato', $dataStr, $r['tipo_turno'], 'permesso_neg:' . $reqId]);
            $cnt['negate']++;
        }
    }
    return $cnt;
}
