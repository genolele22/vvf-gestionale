<?php
/**
 * ANTEPRIMA DI STAMPA — generata dallo STESSO modello.odt dell'ODT (identica al 100%).
 * Read-only. Parametri: ?id=<foglio> oppure ?data=YYYY-MM-DD&tipo=D|N
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
richiediLogin();
require_once __DIR__ . '/../includes/FoglioRenderer.php';

$pdo = getDB();

$foglioId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
if (!$foglioId) {
    $data = preg_replace('/[^0-9\-]/', '', $_GET['data'] ?? date('Y-m-d'));
    $tipo = (($_GET['tipo'] ?? 'D') === 'N') ? 'N' : 'D';
    $st = $pdo->prepare("SELECT id FROM fogli_servizio WHERE data_servizio=? AND tipo_turno=?");
    $st->execute([$data, $tipo]);
    $foglioId = (int)($st->fetchColumn() ?: 0);
}
if (!$foglioId) { http_response_code(404); exit('Foglio non trovato: compilalo prima dalla pagina del servizio.'); }

try {
    $r = new FoglioRenderer($pdo, $foglioId);
    echo $r->html();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Errore anteprima: ' . $e->getMessage());
}
