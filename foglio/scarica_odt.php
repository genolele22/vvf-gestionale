<?php
$data = preg_replace('/[^0-9\-]/', '', $_GET['data'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
    http_response_code(400);
    exit('Data non valida');
}

// Risolve IPv6 interno Fly.io (6PN)
$records = dns_get_record('vvf-ferie-bot.internal', DNS_AAAA);
if (empty($records)) {
    http_response_code(502);
    exit('Errore: bot non raggiungibile (DNS)');
}
$ipv6 = $records[0]['ipv6'];
$url  = "http://[{$ipv6}]:8080/odt?data={$data}";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V6,
]);
$odt      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($odt === false || $httpCode !== 200 || empty($odt)) {
    http_response_code(502);
    exit("Errore generazione ODT ($ipv6 — " . ($err ?: "HTTP $httpCode") . ')');
}

$filename = 'servizio_' . str_replace('-', '', $data) . '.odt';
header('Content-Type: application/vnd.oasis.opendocument.text');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($odt));
echo $odt;
