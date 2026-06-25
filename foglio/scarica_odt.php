<?php
/**
 * Download del Foglio di Servizio in .odt — generato DAL DB (FoglioRenderer).
 * Niente più proxy al bot. Parametri: ?id=<foglio> oppure ?data=YYYY-MM-DD&tipo=D|N
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/FoglioRenderer.php';

$pdo = getDB();

$foglioId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$dataStr  = '';
if (!$foglioId) {
    $dataStr = preg_replace('/[^0-9\-]/', '', $_GET['data'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataStr)) { http_response_code(400); exit('Data non valida'); }
    $tipo = (($_GET['tipo'] ?? 'D') === 'N') ? 'N' : 'D';
    $st = $pdo->prepare("SELECT id FROM fogli_servizio WHERE data_servizio=? AND tipo_turno=?");
    $st->execute([$dataStr, $tipo]);
    $foglioId = (int)($st->fetchColumn() ?: 0);
}
if (!$foglioId) { http_response_code(404); exit('Foglio non trovato: compilalo prima dalla pagina del servizio.'); }

// L'ODT è solo un export del documento: NON approva le ferie né notifica.
// L'approvazione + invio notifiche avviene dal tasto "Salva" del foglio
// (con conferma esplicita) → azione 'finalizza_ferie' in foglio/nuovo.php.

try {
    $r   = new FoglioRenderer($pdo, $foglioId);
    $odt = $r->odt();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Errore generazione ODT: ' . $e->getMessage());
}

$tag = $dataStr ? str_replace('-', '', $dataStr) : (string)$foglioId;
$filename = 'servizio_' . $tag . '.odt';
header('Content-Type: application/vnd.oasis.opendocument.text');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($odt));
echo $odt;
