<?php
/**
 * Endpoint AJAX del tasto rapido "Qui non va" (includes/logbook_widget.php):
 * salva una nota col link alla pagina da cui è stata scritta, senza ricaricare
 * o lasciare la pagina corrente. Stessa tabella e stessi permessi della
 * pagina completa (logbook/index.php).
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logbook.php';

header('Content-Type: application/json');

if (!isLogbookUser()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'errore' => 'Accesso negato.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'errore' => 'Metodo non valido.']);
    exit;
}

$testo      = trim($_POST['testo'] ?? '');
$paginaUrl  = trim($_POST['pagina_url'] ?? '');
$autore     = (string)(utenteCorrente()['username'] ?? '');

if ($testo === '') {
    echo json_encode(['ok' => false, 'errore' => 'Scrivi qualcosa prima di salvare.']);
    exit;
}

$pdo = getDB();
assicuraSchemaLogbook($pdo);
logbookAggiungi($pdo, $testo, $autore !== '' ? $autore : null, $paginaUrl !== '' ? $paginaUrl : null);

echo json_encode(['ok' => true]);
