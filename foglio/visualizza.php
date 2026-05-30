<?php
// visualizza.php non ancora implementato — reindirizza a nuovo.php
$data = $_GET['data'] ?? '';
$tipo = $_GET['tipo'] ?? 'D';
header('Location: nuovo.php?data=' . urlencode($data) . '&tipo=' . urlencode($tipo));
exit;
