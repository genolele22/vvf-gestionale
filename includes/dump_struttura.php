<?php
/**
 * dump_struttura.php — sedi, posizioni e distribuzione del personale (read-only).
 * Uso: php /var/www/html/includes/dump_struttura.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // solo da fly ssh console, mai dal web
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/FoglioRenderer.php';
$pdo = getDB();

echo "=== CAPIENZE (slot dal modello.odt) ===\n";
$cap = FoglioRenderer::slotCapacities();   // codice → n. slot
ksort($cap);
foreach ($cap as $code => $n) echo "  $code = $n\n";

echo "\n=== SEDI ===\n";
foreach ($pdo->query("SELECT id, codice, nome FROM sedi ORDER BY id") as $s)
    echo "  #{$s['id']}  {$s['codice']}  —  {$s['nome']}\n";

echo "\n=== POSIZIONI (per sede) ===\n";
$rows = $pdo->query(
    "SELECT p.id, p.codice, p.nome, p.ordine, s.codice AS sede
       FROM posizioni p JOIN sedi s ON s.id=p.sede_id
      ORDER BY p.sede_id, p.ordine, p.id")->fetchAll();
$cur = null;
foreach ($rows as $p) {
    if ($p['sede'] !== $cur) { echo "\n  [sede {$p['sede']}]\n"; $cur = $p['sede']; }
    echo "     #{$p['id']}  {$p['codice']}  (ord {$p['ordine']})  {$p['nome']}\n";
}

echo "\n=== PERSONALE ATTIVO per sede / qualifica / specialista ===\n";
$agg = $pdo->query(
    "SELECT s.codice AS sede, q.codice AS grado, v.specialista, COUNT(*) AS n
       FROM vigili v JOIN sedi s ON s.id=v.sede_id JOIN qualifiche q ON q.id=v.qualifica_id
      WHERE v.attivo=1
      GROUP BY s.codice, q.codice, v.specialista
      ORDER BY s.codice, q.codice DESC")->fetchAll();
foreach ($agg as $a)
    printf("  sede %-5s %-3s %s n=%d\n", $a['sede'], $a['grado'], $a['specialista'] ? '[SPEC]' : '      ', $a['n']);
