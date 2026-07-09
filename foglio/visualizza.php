<?php
// visualizza.php non ancora implementato — reindirizza a nuovo.php.
// Inoltra anche ?turno=: senza, nuovo.php ricadrebbe sul turno di sessione
// e un link multi-turno (cruscotto) aprirebbe il foglio del turno sbagliato.
$data  = $_GET['data']  ?? '';
$tipo  = $_GET['tipo']  ?? 'D';
$turno = $_GET['turno'] ?? '';
$qs = 'data=' . urlencode($data) . '&tipo=' . urlencode($tipo);
if ($turno !== '') $qs .= '&turno=' . urlencode($turno);
header('Location: nuovo.php?' . $qs);
exit;
