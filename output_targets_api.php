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

$dataFile = __DIR__ . '/data/output_targets.json';

// ค่าเป้าหมายเริ่มต้น (กก./วัน) สำหรับแต่ละเครื่องจักร
$defaultTargets = [
    'machines' => [
        'BHS' => [
            'name' => 'เครื่อง BHS',
            'dailyTarget' => 100000,
            'monthlyTarget' => 2200000,
            'unit' => 'kg'
        ],
        'ISOWA' => [
            'name' => 'เครื่อง ISOWA',
            'dailyTarget' => 60000,
            'monthlyTarget' => 1300000,
            'unit' => 'kg'
        ],
        'YUELI' => [
            'name' => 'เครื่อง YUELI',
            'dailyTarget' => 50000,
            'monthlyTarget' => 1100000,
            'unit' => 'kg'
        ],
        'TOTAL' => [
            'name' => 'รวมทุกเครื่อง (TOTAL)',
            'dailyTarget' => 210000,
            'monthlyTarget' => 4600000,
            'unit' => 'kg'
        ]
    ],
    'updated_at' => date('Y-m-d H:i:s')
];

// สร้างโฟลเดอร์ data/ หากยังไม่มี
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
}

if ($method === 'GET') {
    if (file_exists($dataFile)) {
        $content = @file_get_contents($dataFile);
        $json = @json_decode($content, true);
        if (is_array($json) && isset($json['machines'])) {
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
            'message' => 'รีเซ็ตเป้าหมาย Target Output เป็นค่าเริ่มต้นเรียบร้อยแล้ว',
            'data' => $defaultTargets
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // Save
    $newTargets = $defaultTargets;
    if (isset($payload['machines']) && is_array($payload['machines'])) {
        foreach (['BHS', 'ISOWA', 'YUELI', 'TOTAL'] as $mKey) {
            if (isset($payload['machines'][$mKey])) {
                $mInput = $payload['machines'][$mKey];
                $daily = isset($mInput['dailyTarget']) ? max(0, round((float)$mInput['dailyTarget'])) : $defaultTargets['machines'][$mKey]['dailyTarget'];
                $monthly = isset($mInput['monthlyTarget']) ? max(0, round((float)$mInput['monthlyTarget'])) : ($daily * 22);
                $newTargets['machines'][$mKey]['dailyTarget'] = $daily;
                $newTargets['machines'][$mKey]['monthlyTarget'] = $monthly;
            }
        }
    }

    $newTargets['updated_at'] = date('Y-m-d H:i:s');

    $written = @file_put_contents($dataFile, json_encode($newTargets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    if ($written !== false) {
        echo json_encode([
            'success' => true,
            'message' => 'บันทึกเป้าหมาย Target Output สำเร็จเรียบร้อยแล้ว',
            'data' => $newTargets
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถเขียนไฟล์ data/output_targets.json ได้ กรุณาตรวจสอบสิทธิ์ของโฟลเดอร์'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
