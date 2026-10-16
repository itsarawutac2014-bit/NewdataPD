<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$sheetId = $_GET['sheetId'] ?? '1viioRJ5I3BmqiD7KLwSG9_uNQ_9u_a8LYR-4GaPGuYI';
$gid = $_GET['gid'] ?? '0';

$url = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:json&gid={$gid}";

$ctx = stream_context_create([
    'http' => [
        'timeout' => 8,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
    ]
]);

$raw = @file_get_contents($url, false, $ctx);
if ($raw === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to fetch from Google Sheets']);
    exit;
}

if (preg_match('/setResponse\((.*)\);/s', $raw, $m)) {
    echo $m[1];
} else {
    echo json_encode(['error' => 'Invalid Google response']);
}
