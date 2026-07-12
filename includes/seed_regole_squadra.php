<?php
/**
 * seed_regole_squadra.php — popola regole_squadra con le 6 regole Centrale
 * equivalenti a quelle finora hardcoded in prepopolaAssegnazioni (#92), per
 * ogni turno che non ne ha ancora. Idempotente: si può rilanciare, salta i
 * turni già popolati. Se la tabella esiste già senza colonna `turno` (prima
 * versione, un solo set di regole condiviso) la aggiunge — le righe esistenti
 * diventano turno 'B' (default colonna), poi vengono duplicate per A/C/D.
 * Uso: php /var/www/html/includes/seed_regole_squadra.php
 */
require_once __DIR__ . '/../config/db.php';
$pdo = getDB();

$pdo->exec("CREATE TABLE IF NOT EXISTS regole_squadra (
    id              INT UNSIGNED NOT NULL,
    ordine          INT UNSIGNED NOT NULL DEFAULT 100,
    turno           CHAR(1)      NOT NULL DEFAULT 'B',
    etichetta       VARCHAR(80)  NOT NULL,
    sede_id         INT UNSIGNED NOT NULL,
    qualifiche_ids  VARCHAR(60)  DEFAULT NULL,
    patenti_ids     VARCHAR(60)  DEFAULT NULL,
    abilitazione_id INT UNSIGNED DEFAULT NULL,
    posizioni_ids   VARCHAR(120) NOT NULL,
    attiva          TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$hasCol = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='regole_squadra' AND COLUMN_NAME='turno'"
)->fetchColumn();
if ($hasCol === 0) {
    $pdo->exec("ALTER TABLE regole_squadra ADD COLUMN turno CHAR(1) NOT NULL DEFAULT 'B' AFTER ordine");
    echo "Colonna turno aggiunta (righe esistenti -> turno B).\n";
}

function idByCodice(PDO $pdo, string $tab, string $col, string $val): int {
    $st = $pdo->prepare("SELECT id FROM `$tab` WHERE `$col`=? LIMIT 1");
    $st->execute([$val]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id === 0) throw new RuntimeException("Non trovato: $tab.$col = $val");
    return $id;
}
function csvIds(PDO $pdo, string $tab, string $col, array $vals): string {
    return implode(',', array_map(fn($v) => idByCodice($pdo, $tab, $col, $v), $vals));
}

$sedeC  = idByCodice($pdo, 'sedi', 'codice', 'C');
$abilSO = idByCodice($pdo, 'abilitazioni', 'codice', 'SO');

$regole = [
    ['ordine' => 10, 'etichetta' => 'Operatore Centrale (SO)',
     'quali' => [], 'pat' => ['1', '2'], 'abil' => $abilSO,
     'pos' => ['CENTR-OP']],
    ['ordine' => 20, 'etichetta' => 'Vp patente 2',
     'quali' => ['Vp'], 'pat' => ['2'], 'abil' => null,
     'pos' => ['3A', '4B']],
    ['ordine' => 30, 'etichetta' => 'Vp patente 3/4',
     'quali' => ['Vp'], 'pat' => ['3', '4'], 'abil' => null,
     'pos' => ['4A', '1FUN-AUTORADIO']],
    ['ordine' => 40, 'etichetta' => 'Vp patente 1',
     'quali' => ['Vp'], 'pat' => ['1'], 'abil' => null,
     'pos' => ['5A', '1SOP-AUTORIM']],
    ['ordine' => 50, 'etichetta' => 'Cs/Cr patente 3/4',
     'quali' => ['Cs', 'Cr'], 'pat' => ['3', '4'], 'abil' => null,
     'pos' => ['3B']],
    ['ordine' => 60, 'etichetta' => 'Cs/Cr altri',
     'quali' => ['Cs', 'Cr'], 'pat' => [], 'abil' => null,
     'pos' => ['2A']],
];

$ins = $pdo->prepare(
    "INSERT INTO regole_squadra (id, ordine, turno, etichetta, sede_id, qualifiche_ids, patenti_ids, abilitazione_id, posizioni_ids, attiva)
     VALUES (?,?,?,?,?,?,?,?,?,1)"
);
foreach (['A', 'B', 'C', 'D'] as $turno) {
    $stChk = $pdo->prepare("SELECT COUNT(*) FROM regole_squadra WHERE turno=?");
    $stChk->execute([$turno]);
    if ((int)$stChk->fetchColumn() > 0) {
        echo "Turno $turno ha già regole: salto.\n";
        continue;
    }
    foreach ($regole as $r) {
        $id    = nextId($pdo, 'regole_squadra');
        $quali = $r['quali'] ? csvIds($pdo, 'qualifiche', 'codice', $r['quali']) : '';
        $pat   = $r['pat']   ? csvIds($pdo, 'patenti', 'tipo', $r['pat'])       : '';
        $pos   = csvIds($pdo, 'posizioni', 'codice', $r['pos']);
        $ins->execute([$id, $r['ordine'], $turno, $r['etichetta'], $sedeC, $quali, $pat, $r['abil'], $pos]);
        echo "Turno $turno: inserita #$id — {$r['etichetta']} (ordine {$r['ordine']})\n";
    }
}
echo "Fatto.\n";
