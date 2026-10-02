<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/sicherheit.php';
td_api_schuetzen();

header('Content-Type: application/json; charset=utf-8');

$allowed = ['status', 'teslamate', 'combined', 'stats', 'calibrate/status'];

$endpoint = isset($_GET['endpoint']) ? trim($_GET['endpoint']) : '';

if ($endpoint === '' || !in_array($endpoint, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültiger Endpunkt']);
    exit;
}

if (DAHEIM_API === '') { echo json_encode(['ok' => false, 'error' => 'Kein DaheimLader eingerichtet']); exit; }
$url = rtrim(DAHEIM_API, '/') . '/api/' . $endpoint;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);

$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($body === false || $err !== '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Verbindung zur Wallbox-API fehlgeschlagen']);
    exit;
}

http_response_code($code ?: 200);
echo $body;
