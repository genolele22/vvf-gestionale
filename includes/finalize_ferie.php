<?php
/**
 * Approvazione ferie alla generazione dell'ODT (servizio completo).
 * Per ogni ferie (assenza FER) presente sul foglio:
 *   - se la richiesta bot è pending → la approva
 *   - enfila una notifica in bot_outbox (Telegram + mail le manda il bot), idempotente via ctx.
 * Le ferie escluse dalla fureria (assenza rimossa in Agenda) non vengono toccate.
 */
function finalizeFerie(PDO $pdo, int $foglioId, string $dataStr, string $tipo): void
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

    // vigili in FERIE su questo foglio (tipo_assenza FER = id 1)
    $st = $pdo->prepare("SELECT DISTINCT vigile_id FROM assenze WHERE foglio_id=? AND tipo_assenza_id=1");
    $st->execute([$foglioId]);
    $vigili = array_map('intval', array_column($st->fetchAll(), 'vigile_id'));
    if (!$vigili) return;

    $tipi = $tipo === 'N' ? ['N', 'DN'] : ['D', 'DN'];
    $ph   = implode(',', array_fill(0, count($tipi), '?'));

    $findReq = $pdo->prepare(
        "SELECT id, stato, tipo_turno FROM bot_requests
         WHERE vigile_id=? AND data_richiesta=? AND tipo_turno IN ($ph)
         ORDER BY id DESC LIMIT 1"
    );
    $approve = $pdo->prepare("UPDATE bot_requests SET stato='approved', processed_at=NOW() WHERE id=? AND stato='pending'");
    $hasCtx  = $pdo->prepare("SELECT 1 FROM bot_outbox WHERE ctx=? LIMIT 1");
    $insOut  = $pdo->prepare(
        "INSERT INTO bot_outbox (id, vigile_id, tipo, data, tipo_turno, ctx)
         VALUES (?,?,?,?,?,?)"
    );

    foreach ($vigili as $vid) {
        $findReq->execute(array_merge([$vid, $dataStr], $tipi));
        $req = $findReq->fetch();
        if (!$req) continue;                       // ferie a mano (senza richiesta bot): niente notifica
        $reqId = (int)$req['id'];
        $ctx   = 'ferie:' . $reqId;

        if ($req['stato'] === 'pending') $approve->execute([$reqId]);

        $hasCtx->execute([$ctx]);
        if ($hasCtx->fetchColumn()) continue;      // già notificato → idempotente

        $nextId = nextId($pdo, 'bot_outbox');
        $insOut->execute([$nextId, $vid, 'ferie_approvata', $dataStr, $req['tipo_turno'], $ctx]);
    }
}
