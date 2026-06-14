<?php
/** TEST Fase 2/3: genera l'ODT dal DB (FoglioRenderer), senza passare dal bot. */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/FoglioRenderer.php';

$pdo = getDB();
$foglioId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
if (!$foglioId) {
    $data = preg_replace('/[^0-9\-]/', '', $_GET['data'] ?? '');
    $tipo = (($_GET['tipo'] ?? 'D') === 'N') ? 'N' : 'D';
    $st = $pdo->prepare("SELECT id FROM fogli_servizio WHERE data_servizio=? AND tipo_turno=?");
    $st->execute([$data, $tipo]);
    $foglioId = (int)($st->fetchColumn() ?: 0);
}
if (!$foglioId) { http_response_code(404); exit('Foglio non trovato'); }

try {
    $r = new FoglioRenderer($pdo, $foglioId);
    $odt = $r->odt();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Errore renderer: ' . $e->getMessage());
}

if (isset($_GET['debug'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "OK ODT generato: " . strlen($odt) . " bytes\n";
    echo "overflow (nomi oltre gli slot): " . (implode(', ', $r->overflow) ?: '—') . "\n";
    echo "noslot (mezzi senza slot ma con nomi): " . (implode(', ', $r->noslot) ?: '—') . "\n";
    exit;
}

$fn = 'servizio_' . str_replace('-', '', $_GET['data'] ?? (string)$foglioId) . '.odt';
header('Content-Type: application/vnd.oasis.opendocument.text');
header('Content-Disposition: attachment; filename="' . $fn . '"');
header('Content-Length: ' . strlen($odt));
echo $odt;
