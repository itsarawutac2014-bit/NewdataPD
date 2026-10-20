<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$dataFile = __DIR__ . '/data/targets.json';

$defaultTargets = [
    'allWaste' => 5.00,
    'items' => [
        'ncp' => 2.00,
        'def' => 1.10,
        'web' => 0.15,
        'control' => 1.01,
        'om' => 0.05,
        'order' => 0.20,
        'core' => 0.15,
        'spec' => 0.15,
        'conveyor' => 0.03,
        'qc' => 0.07,
        'reject' => 0.09
    ],
    'updated_at' => date('Y-m-d H:i:s')
];

// Ensure directory exists
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
}

if ($method === 'GET') {
    if (file_exists($dataFile)) {
        $content = @file_get_contents($dataFile);
        $json = @json_decode($content, true);
        if (is_array($json) && isset($json['items'])) {
            echo json_encode([
                'success' => true,
                'data' => $json,
                'is_default' => false
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $defaultTargets,
        'is_default' => true
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!$payload && !empty($_POST)) {
        $payload = $_POST;
    }

    $action = $payload['action'] ?? 'save';

    if ($action === 'reset') {
        file_put_contents($dataFile, json_encode($defaultTargets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode([
            'success' => true,
            'message' => 'รีเซ็ตค่าเป้าหมายเป็นค่าเริ่มต้นเรียบร้อยแล้ว',
            'data' => $defaultTargets
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // Save
    $newTargets = $defaultTargets;
    if (isset($payload['allWaste'])) {
        $newTargets['allWaste'] = round((float)$payload['allWaste'], 2);
    }

    if (isset($payload['items']) && is_array($payload['items'])) {
        foreach ($defaultTargets['items'] as $k => $defVal) {
            if (isset($payload['items'][$k])) {
                $newTargets['items'][$k] = round((float)$payload['items'][$k], 2);
            }
        }
    }

    $newTargets['updated_at'] = date('Y-m-d H:i:s');

    $written = @file_put_contents($dataFile, json_encode($newTargets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    if ($written !== false) {
        echo json_encode([
            'success' => true,
            'message' => 'บันทึกเป้าหมาย Target สำเร็จเรียบร้อยแล้ว',
            'data' => $newTargets
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถเขียนไฟล์ targets.json ได้ กรุณาตรวจสอบสิทธิ์การเขียนโฟลเดอร์ data/'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
