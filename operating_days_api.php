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

$dataFile = __DIR__ . '/data/operating_days.json';
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
}

// โครงสร้างข้อมูลเริ่มต้น
$defaultData = [
    'updated_at' => date('Y-m-d H:i:s'),
    'defaultTargetDays' => [
        'BHS' => 22,
        'YUELI' => 22,
        'ISOWA' => 22,
        'TOTAL' => 22
    ],
    'records' => [] // key: "YYYY-MM" => [ 'targetDays' => [...], 'BHS' => [...], 'YUELI' => [...], 'ISOWA' => [...] ]
];

function getStoredData($dataFile, $defaultData) {
    if (file_exists($dataFile)) {
        $content = @file_get_contents($dataFile);
        $json = @json_decode($content, true);
        if (is_array($json)) {
            if (!isset($json['defaultTargetDays'])) {
                $json['defaultTargetDays'] = $defaultData['defaultTargetDays'];
            }
            if (!isset($json['records']) || !is_array($json['records'])) {
                $json['records'] = [];
            }
            return $json;
        }
    }
    return $defaultData;
}

if ($method === 'GET') {
    $current = getStoredData($dataFile, $defaultData);
    $monthKey = trim($_GET['monthKey'] ?? ''); // e.g. "2026-10"
    $machine = trim($_GET['machine'] ?? '');   // e.g. "BHS"

    if (!empty($monthKey)) {
        $monthRecord = $current['records'][$monthKey] ?? null;
        echo json_encode([
            'success' => true,
            'monthKey' => $monthKey,
            'data' => $monthRecord,
            'defaultTargetDays' => $current['defaultTargetDays']
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => $current
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!$payload && !empty($_POST)) {
        $payload = $_POST;
    }

    if (!$payload) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $current = getStoredData($dataFile, $defaultData);
    $action = $payload['action'] ?? 'save_month';

    if ($action === 'save_month') {
        $monthKey = trim($payload['monthKey'] ?? '');
        if (empty($monthKey)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'monthKey is required (e.g. 2026-10)'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!isset($current['records'][$monthKey])) {
            $current['records'][$monthKey] = [
                'targetDays' => $current['defaultTargetDays'],
                'BHS' => [],
                'YUELI' => [],
                'ISOWA' => []
            ];
        }

        // 1. บันทึก Target วันเดินงาน
        if (isset($payload['targetDays']) && is_array($payload['targetDays'])) {
            foreach (['BHS', 'YUELI', 'ISOWA', 'TOTAL'] as $m) {
                if (isset($payload['targetDays'][$m])) {
                    $current['records'][$monthKey]['targetDays'][$m] = max(0, round((float)$payload['targetDays'][$m]));
                }
            }
        }

        // 2. บันทึกข้อมูลรายวันของแต่ละเครื่อง (daysData: { BHS: { "1": { run: true, idleHours: 0, reason: '' } } })
        if (isset($payload['daysData']) && is_array($payload['daysData'])) {
            foreach (['BHS', 'YUELI', 'ISOWA'] as $m) {
                if (isset($payload['daysData'][$m]) && is_array($payload['daysData'][$m])) {
                    $current['records'][$monthKey][$m] = $payload['daysData'][$m];
                }
            }
        }

        // หรือถ้าส่งเฉพาะเครื่องเดียว (machine + days)
        $singleMachine = trim($payload['machine'] ?? '');
        if (!empty($singleMachine) && in_array($singleMachine, ['BHS', 'YUELI', 'ISOWA']) && isset($payload['days']) && is_array($payload['days'])) {
            $current['records'][$monthKey][$singleMachine] = $payload['days'];
            if (isset($payload['targetDay']) && is_numeric($payload['targetDay'])) {
                $current['records'][$monthKey]['targetDays'][$singleMachine] = max(0, round((float)$payload['targetDay']));
            }
        }
    } elseif ($action === 'reset_month') {
        $monthKey = trim($payload['monthKey'] ?? '');
        if (!empty($monthKey) && isset($current['records'][$monthKey])) {
            unset($current['records'][$monthKey]);
        }
    }

    $current['updated_at'] = date('Y-m-d H:i:s');
    $written = @file_put_contents($dataFile, json_encode($current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    if ($written !== false) {
        echo json_encode([
            'success' => true,
            'message' => 'บันทึกข้อมูลวันเดินเครื่องจักรเรียบร้อยแล้ว',
            'data' => $current
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถเขียนไฟล์ data/operating_days.json ได้'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
