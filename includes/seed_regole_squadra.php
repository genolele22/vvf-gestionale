<?php
/**
 * seed_regole_squadra.php — popola regole_squadra con le 6 regole Centrale
 * equivalenti a quelle finora hardcoded in prepopolaAssegnazioni (#92).
 * Una tantum: non fa nulla se la tabella contiene già righe.
 * Uso: php /var/www/html/includes/seed_regole_squadra.php
 */
require_once __DIR__ . '/../config/db.php';
$pdo = getDB();

$pdo->exec("CREATE TABLE IF NOT EXISTS regole_squadra (
    id              INT UNSIGNED NOT NULL,
    ordine          INT UNSIGNED NOT NULL DEFAULT 100,
    etichetta       VARCHAR(80)  NOT NULL,
    sede_id         INT UNSIGNED NOT NULL,
    qualifiche_ids  VARCHAR(60)  DEFAULT NULL,
    patenti_ids     VARCHAR(60)  DEFAULT NULL,
    abilitazione_id INT UNSIGNED DEFAULT NULL,
    posizioni_ids   VARCHAR(120) NOT NULL,
    attiva          TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$giaPresenti = (int)$pdo->query("SELECT COUNT(*) FROM regole_squadra")->fetchColumn();
if ($giaPresenti > 0) {
    echo "regole_squadra ha già $giaPresenti righe: nessuna azione (script una tantum).\n";
    exit;
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

$sedeC   = idByCodice($pdo, 'sedi', 'codice', 'C');
$abilSO  = idByCodice($pdo, 'abilitazioni', 'codice', 'SO');

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
    "INSERT INTO regole_squadra (id, ordine, etichetta, sede_id, qualifiche_ids, patenti_ids, abilitazione_id, posizioni_ids, attiva)
     VALUES (?,?,?,?,?,?,?,?,1)"
);
foreach ($regole as $r) {
    $id      = nextId($pdo, 'regole_squadra');
    $quali   = $r['quali'] ? csvIds($pdo, 'qualifiche', 'codice', $r['quali']) : '';
    $pat     = $r['pat']   ? csvIds($pdo, 'patenti', 'tipo', $r['pat'])       : '';
    $pos     = csvIds($pdo, 'posizioni', 'codice', $r['pos']);
    $ins->execute([$id, $r['ordine'], $r['etichetta'], $sedeC, $quali, $pat, $r['abil'], $pos]);
    echo "Inserita #$id — {$r['etichetta']} (ordine {$r['ordine']})\n";
}
echo "Fatto: " . count($regole) . " regole inserite.\n";
