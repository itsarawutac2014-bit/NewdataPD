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

// ค่าเป้าหมายเริ่มต้น (กก./วัน และ กก./เดือน) สำหรับแต่ละเครื่องจักร
$defaultMachines = [
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
];

$defaultTargets = [
    'machines' => $defaultMachines,
    'monthlyTargets' => [], // key: "YYYY-MM" => [ 'BHS' => ..., 'ISOWA' => ..., 'YUELI' => ..., 'TOTAL' => ... ]
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
            if (!isset($json['monthlyTargets']) || !is_array($json['monthlyTargets'])) {
                $json['monthlyTargets'] = [];
            }
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

    // Load existing
    $currentData = $defaultTargets;
    if (file_exists($dataFile)) {
        $loaded = @json_decode(@file_get_contents($dataFile), true);
        if (is_array($loaded)) {
            $currentData = array_merge($currentData, $loaded);
        }
    }

    // 1. Update machines default daily & monthly targets if passed
    if (isset($payload['machines']) && is_array($payload['machines'])) {
        foreach (['BHS', 'ISOWA', 'YUELI', 'TOTAL'] as $mKey) {
            if (isset($payload['machines'][$mKey])) {
                $mInput = $payload['machines'][$mKey];
                $daily = isset($mInput['dailyTarget']) ? max(0, round((float)$mInput['dailyTarget'])) : ($currentData['machines'][$mKey]['dailyTarget'] ?? $defaultTargets['machines'][$mKey]['dailyTarget']);
                $monthly = isset($mInput['monthlyTarget']) ? max(0, round((float)$mInput['monthlyTarget'])) : ($currentData['machines'][$mKey]['monthlyTarget'] ?? $defaultTargets['machines'][$mKey]['monthlyTarget']);
                $currentData['machines'][$mKey]['dailyTarget'] = $daily;
                $currentData['machines'][$mKey]['monthlyTarget'] = $monthly;
            }
        }
        // Auto compute TOTAL daily & monthly if not explicitly overridden
        if (!isset($payload['machines']['TOTAL']['dailyTarget'])) {
            $currentData['machines']['TOTAL']['dailyTarget'] = 
                ($currentData['machines']['BHS']['dailyTarget'] ?? 100000) +
                ($currentData['machines']['ISOWA']['dailyTarget'] ?? 60000) +
                ($currentData['machines']['YUELI']['dailyTarget'] ?? 50000);
        }
        if (!isset($payload['machines']['TOTAL']['monthlyTarget'])) {
            $currentData['machines']['TOTAL']['monthlyTarget'] = 
                ($currentData['machines']['BHS']['monthlyTarget'] ?? 2200000) +
                ($currentData['machines']['ISOWA']['monthlyTarget'] ?? 1300000) +
                ($currentData['machines']['YUELI']['monthlyTarget'] ?? 1100000);
        }
    }

    // 2. Save Specific Month Target (e.g. key = "2026-10" or "2027-01")
    if ($action === 'save_month' || isset($payload['targetMonthKey'])) {
        $monthKey = trim($payload['targetMonthKey'] ?? '');
        if (!empty($monthKey) && isset($payload['monthTargets']) && is_array($payload['monthTargets'])) {
            if (!isset($currentData['monthlyTargets']) || !is_array($currentData['monthlyTargets'])) {
                $currentData['monthlyTargets'] = [];
            }
            if (!isset($currentData['monthlyTargets'][$monthKey]) || !is_array($currentData['monthlyTargets'][$monthKey])) {
                $currentData['monthlyTargets'][$monthKey] = [];
            }
            $cleanTargets = [];
            foreach (['BHS', 'ISOWA', 'YUELI', 'TOTAL'] as $mKey) {
                if (isset($payload['monthTargets'][$mKey])) {
                    $cleanTargets[$mKey] = max(0, round((float)$payload['monthTargets'][$mKey]));
                }
            }
            $currentData['monthlyTargets'][$monthKey] = array_merge($currentData['monthlyTargets'][$monthKey], $cleanTargets);
            
            // Recalculate TOTAL for this month if not explicitly given
            if (!isset($cleanTargets['TOTAL'])) {
                $bhs = $currentData['monthlyTargets'][$monthKey]['BHS'] ?? $currentData['machines']['BHS']['monthlyTarget'] ?? 2200000;
                $isw = $currentData['monthlyTargets'][$monthKey]['ISOWA'] ?? $currentData['machines']['ISOWA']['monthlyTarget'] ?? 1300000;
                $yue = $currentData['monthlyTargets'][$monthKey]['YUELI'] ?? $currentData['machines']['YUELI']['monthlyTarget'] ?? 1100000;
                $currentData['monthlyTargets'][$monthKey]['TOTAL'] = $bhs + $isw + $yue;
            }
        }
    }

    // 3. Batch update multiple month targets if passed
    if (isset($payload['monthlyTargets']) && is_array($payload['monthlyTargets'])) {
        if (!isset($currentData['monthlyTargets']) || !is_array($currentData['monthlyTargets'])) {
            $currentData['monthlyTargets'] = [];
        }
        foreach ($payload['monthlyTargets'] as $mKey => $vals) {
            if (is_array($vals)) {
                if (!isset($currentData['monthlyTargets'][$mKey]) || !is_array($currentData['monthlyTargets'][$mKey])) {
                    $currentData['monthlyTargets'][$mKey] = [];
                }
                $clean = [];
                foreach (['BHS', 'ISOWA', 'YUELI', 'TOTAL'] as $mach) {
                    if (isset($vals[$mach])) {
                        $clean[$mach] = max(0, round((float)$vals[$mach]));
                    }
                }
                $currentData['monthlyTargets'][$mKey] = array_merge($currentData['monthlyTargets'][$mKey], $clean);

                // Recalculate TOTAL if not explicitly given
                if (!isset($vals['TOTAL'])) {
                    $bhs = $currentData['monthlyTargets'][$mKey]['BHS'] ?? $currentData['machines']['BHS']['monthlyTarget'] ?? 2200000;
                    $isw = $currentData['monthlyTargets'][$mKey]['ISOWA'] ?? $currentData['machines']['ISOWA']['monthlyTarget'] ?? 1300000;
                    $yue = $currentData['monthlyTargets'][$mKey]['YUELI'] ?? $currentData['machines']['YUELI']['monthlyTarget'] ?? 1100000;
                    $currentData['monthlyTargets'][$mKey]['TOTAL'] = $bhs + $isw + $yue;
                }
            }
        }
    }

    $currentData['updated_at'] = date('Y-m-d H:i:s');

    $written = @file_put_contents($dataFile, json_encode($currentData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    if ($written !== false) {
        echo json_encode([
            'success' => true,
            'message' => 'บันทึกเป้าหมาย Target Output สำเร็จเรียบร้อยแล้ว',
            'data' => $currentData
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
