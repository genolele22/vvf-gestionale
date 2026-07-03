<?php
/**
 * dump_foglio.php — stampa la composizione ATTUALE di un foglio (read-only).
 * Uso:  php /var/www/html/includes/dump_foglio.php 2026-06-27 D
 * Mostra, posizione per posizione, chi è assegnato (grado, patente, sede,
 * abilitazione SO, flag specialista). Non modifica nulla.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/turni.php';
$pdo = getDB();

$data = $argv[1] ?? '';
$tipo = ($argv[2] ?? 'D') === 'N' ? 'N' : 'D';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
    echo "Uso: php dump_foglio.php YYYY-MM-DD D|N\n\nFogli recenti:\n";
    foreach ($pdo->query("SELECT id, data_servizio, tipo_turno FROM fogli_servizio ORDER BY id DESC LIMIT 10") as $r)
        echo "  #{$r['id']}  {$r['data_servizio']}  {$r['tipo_turno']}\n";
    exit;
}

$st = $pdo->prepare("SELECT id, salto_riposo_id FROM fogli_servizio WHERE data_servizio=? AND tipo_turno=?");
$st->execute([$data, $tipo]);
$f = $st->fetch();
if (!$f) exit("Nessun foglio per $data $tipo.\n");
$fid = (int)$f['id'];
echo "Foglio #$fid — $data $tipo\n";

// ── diagnostica salti (per il 1A) ──
$saltoOggi = saltoRiposoNum($data, $tipo);
$prevNum   = (($saltoOggi - 2 + 8) % 8) + 1;
echo "saltoRiposo oggi = $saltoOggi  →  salto precedente (1A) = $prevNum\n";
echo "salti_turno: ";
foreach ($pdo->query("SELECT id, codice FROM salti_turno ORDER BY id") as $s) echo "{$s['codice']}(#{$s['id']}) ";
echo "\nvigili CENTRALI attivi per salto:\n";
$q = $pdo->query(
    "SELECT st.codice, COUNT(*) n
       FROM vigili v JOIN sedi s ON s.id=v.sede_id JOIN salti_turno st ON st.id=v.salto_id
      WHERE v.attivo=1 AND s.codice='C' GROUP BY st.codice ORDER BY st.codice");
foreach ($q as $r) echo "   {$r['codice']}: {$r['n']}\n";
echo "\n";

$rows = $pdo->prepare(
    "SELECT p.codice AS pos, p.id AS pos_id, v.cognome, q.codice AS grado, s.codice AS sede,
            v.specialista,
            (SELECT MAX(pt.tipo) FROM vigili_patenti vp JOIN patenti pt ON pt.id=vp.patente_id WHERE vp.vigile_id=v.id) AS pat,
            EXISTS(SELECT 1 FROM vigili_abilitazioni va JOIN abilitazioni ab ON ab.id=va.abilitazione_id WHERE va.vigile_id=v.id AND ab.codice='SO') AS so,
            a.ordine
       FROM assegnazioni a
       JOIN vigili v ON v.id=a.vigile_id
       JOIN qualifiche q ON q.id=v.qualifica_id
       JOIN sedi s ON s.id=v.sede_id
       JOIN posizioni p ON p.id=a.posizione_id
      WHERE a.foglio_id=?
      ORDER BY p.ordine, p.id, a.ordine");
$rows->execute([$fid]);

$cur = null;
foreach ($rows->fetchAll() as $r) {
    if ($r['pos'] !== $cur) { echo "\n[{$r['pos']}]\n"; $cur = $r['pos']; }
    $tags = [];
    if ($r['so'])          $tags[] = 'SO';
    if ($r['specialista']) $tags[] = 'SPEC';
    $tagStr = $tags ? ' {' . implode(',', $tags) . '}' : '';
    printf("   %-18s %-3s pat:%-2s sede:%-6s%s\n",
        ucfirst(strtolower($r['cognome'])), $r['grado'], $r['pat'] ?? '—', $r['sede'], $tagStr);
}

// disponibili: attivi, non assegnati, non a riposo (salto), non assenti
echo "\n[NON ASSEGNATI / DISPONIBILI]\n";
$disp = $pdo->prepare(
    "SELECT v.cognome, q.codice AS grado, s.codice AS sede, v.specialista,
            (SELECT MAX(pt.tipo) FROM vigili_patenti vp JOIN patenti pt ON pt.id=vp.patente_id WHERE vp.vigile_id=v.id) AS pat
       FROM vigili v JOIN qualifiche q ON q.id=v.qualifica_id JOIN sedi s ON s.id=v.sede_id
      WHERE v.attivo=1
        AND v.id NOT IN (SELECT vigile_id FROM assegnazioni WHERE foglio_id=?)
        AND v.id NOT IN (SELECT vigile_id FROM assenze WHERE foglio_id=?)
        AND v.salto_id <> ?
      ORDER BY s.codice, q.codice DESC, v.cognome");
$disp->execute([$fid, $fid, (int)$f['salto_riposo_id']]);
foreach ($disp->fetchAll() as $r) {
    printf("   %-18s %-3s pat:%-2s sede:%-6s%s\n",
        ucfirst(strtolower($r['cognome'])), $r['grado'], $r['pat'] ?? '—', $r['sede'],
        $r['specialista'] ? ' {SPEC}' : '');
}
