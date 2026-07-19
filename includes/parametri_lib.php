<?php
// Tabella `parametri` (chiave/valore): creazione + upsert/lettura condivisi tra
// admin/parametri.php (comando) e admin/stile_patenti.php (admin di turno).

/** Crea la tabella se manca (no AUTO_INCREMENT su TiDB). */
function assicuraTabellaParametri(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS parametri (
        id          INT UNSIGNED NOT NULL,
        chiave      VARCHAR(60)  NOT NULL,
        valore      TEXT,
        descrizione VARCHAR(160) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_chiave (chiave)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Upsert di un parametro per chiave (crea l'id se nuovo — no AUTO_INCREMENT su TiDB). */
function setParam(PDO $pdo, string $chiave, string $valore, ?string $descr = null): void {
    $st = $pdo->prepare("SELECT id FROM parametri WHERE chiave=?");
    $st->execute([$chiave]);
    $id = $st->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE parametri SET valore=?, descrizione=COALESCE(?, descrizione) WHERE id=?")
            ->execute([$valore, $descr, $id]);
    } else {
        $newId = nextId($pdo, 'parametri');
        $pdo->prepare("INSERT INTO parametri (id, chiave, valore, descrizione) VALUES (?,?,?,?)")
            ->execute([$newId, $chiave, $valore, $descr]);
    }
}

function getParam(PDO $pdo, string $chiave, string $default = ''): string {
    $st = $pdo->prepare("SELECT valore FROM parametri WHERE chiave=?");
    $st->execute([$chiave]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? $default : (string)$v;
}
