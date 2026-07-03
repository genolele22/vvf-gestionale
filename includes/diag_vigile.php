<?php
/**
 * diag_vigile.php — da dove arriva l'assegnazione "di default" di un vigile.
 * Uso:  php /var/www/html/includes/diag_vigile.php MAGNETTI
 */
require_once __DIR__ . '/../config/db.php';
$pdo = getDB();

$cognome = strtoupper(trim($argv[1] ?? ''));
if ($cognome === '') { exit("Uso: php diag_vigile.php COGNOME\n"); }

$st = $pdo->prepare(
    "SELECT v.*, q.codice AS qcodice, s.codice AS sede_cod
       FROM vigili v JOIN qualifiche q ON q.id=v.qualifica_id JOIN sedi s ON s.id=v.sede_id
      WHERE UPPER(v.cognome)=?");
$st->execute([$cognome]);
$vigili = $st->fetchAll();
if (!$vigili) { exit("Nessun vigile '$cognome'.\n"); }

foreach ($vigili as $v) {
    $id = (int)$v['id'];
    echo "── {$v['cognome']} {$v['nome']} (id $id) ──\n";
    echo "  qualifica: {$v['qcodice']} · sede: {$v['sede_cod']} · specialista: " . (int)($v['specialista'] ?? 0) . "\n";
    echo "  posizione_default_id: " . ($v['posizione_default_id'] ?? 'NULL') . "\n";

    $p = $pdo->prepare("SELECT GROUP_CONCAT(pt.tipo) FROM vigili_patenti vp JOIN patenti pt ON pt.id=vp.patente_id WHERE vp.vigile_id=?");
    $p->execute([$id]);
    echo "  patenti: " . ($p->fetchColumn() ?: '—') . "\n";

    $a = $pdo->prepare("SELECT GROUP_CONCAT(ab.codice) FROM vigili_abilitazioni va JOIN abilitazioni ab ON ab.id=va.abilitazione_id WHERE va.vigile_id=?");
    $a->execute([$id]);
    echo "  abilitazioni: " . ($a->fetchColumn() ?: '—') . "\n";

    foreach ([
        'assegnazioni_fisse' => "SELECT af.posizione_id, p.codice FROM assegnazioni_fisse af JOIN posizioni p ON p.id=af.posizione_id WHERE af.vigile_id=?",
        'posizione_template' => "SELECT pt.posizione_id, p.codice FROM posizione_template pt JOIN posizioni p ON p.id=pt.posizione_id WHERE pt.vigile_id=?",
    ] as $tab => $sql) {
        try {
            $q = $pdo->prepare($sql); $q->execute([$id]);
            $r = $q->fetchAll();
            echo "  $tab: " . ($r ? implode(', ', array_map(fn($x) => $x['codice'], $r)) : '—') . "\n";
        } catch (Throwable $e) { echo "  $tab: (tabella assente)\n"; }
    }
    echo "\n";
}
