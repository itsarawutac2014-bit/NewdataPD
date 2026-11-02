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

// ค่าเป้าหมายเริ่มต้นตาม Google Sheet แผ่นงาน "Target Out-put" (กก./วัน และ กก./เดือน)
$defaultMachines = [
    'BHS' => [
        'name' => 'เครื่อง BHS',
        'dailyTarget' => 126978,
        'monthlyTarget' => 3326000,
        'unit' => 'kg'
    ],
    'ISOWA' => [
        'name' => 'เครื่อง ISOWA',
        'dailyTarget' => 35925,
        'monthlyTarget' => 941000,
        'unit' => 'kg'
    ],
    'YUELI' => [
        'name' => 'เครื่อง YUELI',
        'dailyTarget' => 126978,
        'monthlyTarget' => 3326000,
        'unit' => 'kg'
    ],
    'TOTAL' => [
        'name' => 'รวมทุกเครื่อง (TOTAL)',
        'dailyTarget' => 289881,
        'monthlyTarget' => 7593000,
        'unit' => 'kg'
    ]
];

$defaultTargets = [
    'machines' => $defaultMachines,
    'monthlyTargets' => [
        '2026-01' => ['ISOWA' => 614000,  'BHS' => 3757000, 'YUELI' => 3200000, 'TOTAL' => 7571000],
        '2026-02' => ['ISOWA' => 593000,  'BHS' => 3266000, 'YUELI' => 2664000, 'TOTAL' => 6523000],
        '2026-03' => ['ISOWA' => 858000,  'BHS' => 3806000, 'YUELI' => 3355000, 'TOTAL' => 8019000],
        '2026-04' => ['ISOWA' => 1355000, 'BHS' => 3761000, 'YUELI' => 1706000, 'TOTAL' => 6822000],
        '2026-05' => ['ISOWA' => 561000,  'BHS' => 3526000, 'YUELI' => 3144000, 'TOTAL' => 7231000],
        '2026-06' => ['ISOWA' => 46000,   'BHS' => 2722000, 'YUELI' => 2444000, 'TOTAL' => 5212000],
        '2026-07' => ['ISOWA' => 941000,  'BHS' => 3326000, 'YUELI' => 3326000, 'TOTAL' => 7593000],
        '2026-08' => ['ISOWA' => 906000,  'BHS' => 3202000, 'YUELI' => 3202000, 'TOTAL' => 7310000],
        '2026-09' => ['ISOWA' => 941000,  'BHS' => 3326000, 'YUELI' => 3326000, 'TOTAL' => 7593000],
        '2026-10' => ['ISOWA' => 941000,  'BHS' => 3326000, 'YUELI' => 3326000, 'TOTAL' => 7593000],
        '2026-11' => ['ISOWA' => 906000,  'BHS' => 3202000, 'YUELI' => 3202000, 'TOTAL' => 7310000],
        '2026-12' => ['ISOWA' => 836000,  'BHS' => 2956000, 'YUELI' => 2956000, 'TOTAL' => 6748000]
    ],
    'updated_at' => date('Y-m-d H:i:s')
];

// ดึงและแปลงข้อมูลเป้าหมายสดจากแผ่นงาน Google Sheet "Target Out-put"
function fetchTargetsFromGoogleSheet($sheetId = '1viioRJ5I3BmqiD7KLwSG9_uNQ_9u_a8LYR-4GaPGuYI') {
    $url = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:json&sheet=" . urlencode('Target Out-put');
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n"
        ]
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw || !preg_match('/setResponse\((.*)\);/s', $raw, $m)) {
        return null;
    }
    $j = json_decode($m[1], true);
    $rows = $j['table']['rows'] ?? [];
    if (empty($rows)) return null;

    $thaiMonths = [
        'มกราคม' => '01', 'กุมภาพันธ์' => '02', 'มีนาคม' => '03', 'เมษายน' => '04',
        'พฤษภาคม' => '05', 'มิถุนายน' => '06', 'กรกฎาคม' => '07', 'สิงหาคม' => '08',
        'กันยายน' => '09', 'ตุลาคม' => '10', 'พฤศจิกายน' => '11', 'ธันวาคม' => '12'
    ];

    $monthlyTargets = [];
    $dailyTargets = [
        'ISOWA' => 35925,
        'BHS' => 126978,
        'YUELI' => 126978,
        'TOTAL' => 289881
    ];

    // 1. เป้าหมายรายเดือน 12 เดือนของปี 2026 (Rows 0-11, หน่วยตันแปลงเป็น กก.)
    for ($i = 0; $i < min(12, count($rows)); $i++) {
        $r = $rows[$i]['c'] ?? [];
        $mName = trim($r[0]['v'] ?? '');
        $mCode = $thaiMonths[$mName] ?? sprintf('%02d', $i + 1);
        $key = "2026-{$mCode}";

        $isowaTon = floatval($r[1]['v'] ?? 0);
        $bhsTon = floatval($r[2]['v'] ?? 0);
        $yueliTon = floatval($r[3]['v'] ?? 0);
        $totTon = floatval($r[4]['v'] ?? 0);
        if ($totTon <= 0) $totTon = $isowaTon + $bhsTon + $yueliTon;

        $monthlyTargets[$key] = [
            'ISOWA' => round($isowaTon * 1000),
            'BHS' => round($bhsTon * 1000),
            'YUELI' => round($yueliTon * 1000),
            'TOTAL' => round($totTon * 1000)
        ];
    }

    // 2. เป้าหมายรายวัน 2026 (Rows 13-24, หน่วย กก.)
    if (isset($rows[13])) {
        $r = $rows[13]['c'] ?? [];
        $isowaDaily = floatval($r[1]['v'] ?? 0);
        $bhsDaily = floatval($r[2]['v'] ?? 0);
        $yueliDaily = floatval($r[3]['v'] ?? 0);
        if ($isowaDaily > 0) $dailyTargets['ISOWA'] = round($isowaDaily);
        if ($bhsDaily > 0) $dailyTargets['BHS'] = round($bhsDaily);
        if ($yueliDaily > 0) $dailyTargets['YUELI'] = round($yueliDaily);
        $dailyTargets['TOTAL'] = $dailyTargets['ISOWA'] + $dailyTargets['BHS'] + $dailyTargets['YUELI'];
    }

    // กำหนดค่ามาตรฐานรายเดือนตามเดือนปัจจุบัน (ตุลาคม 2026)
    $octTarget = $monthlyTargets['2026-10'] ?? [
        'BHS' => 3326000, 'ISOWA' => 941000, 'YUELI' => 3326000, 'TOTAL' => 7593000
    ];

    $machines = [
        'BHS' => [
            'name' => 'เครื่อง BHS',
            'dailyTarget' => $dailyTargets['BHS'],
            'monthlyTarget' => $octTarget['BHS'],
            'unit' => 'kg'
        ],
        'ISOWA' => [
            'name' => 'เครื่อง ISOWA',
            'dailyTarget' => $dailyTargets['ISOWA'],
            'monthlyTarget' => $octTarget['ISOWA'],
            'unit' => 'kg'
        ],
        'YUELI' => [
            'name' => 'เครื่อง YUELI',
            'dailyTarget' => $dailyTargets['YUELI'],
            'monthlyTarget' => $octTarget['YUELI'],
            'unit' => 'kg'
        ],
        'TOTAL' => [
            'name' => 'รวมทุกเครื่อง (TOTAL)',
            'dailyTarget' => $dailyTargets['TOTAL'],
            'monthlyTarget' => $octTarget['TOTAL'],
            'unit' => 'kg'
        ]
    ];

    return [
        'machines' => $machines,
        'monthlyTargets' => $monthlyTargets,
        'source' => 'google_sheet_target_output',
        'updated_at' => date('Y-m-d H:i:s')
    ];
}

// สร้างโฟลเดอร์ data/ หากยังไม่มี
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
}

if ($method === 'GET') {
    $forceSync = isset($_GET['sync']) || isset($_GET['refresh']);
    $fileAge = file_exists($dataFile) ? (time() - filemtime($dataFile)) : 999999;

    // ถ้าบังคับซิงค์ หรือแคชเก่าเกิน 60 วินาที หรือยังไม่มีไฟล์ ให้ดึงสดจาก Google Sheet
    if ($forceSync || $fileAge > 60 || !file_exists($dataFile)) {
        $gsData = fetchTargetsFromGoogleSheet();
        if ($gsData && !empty($gsData['machines'])) {
            // โหลดค่าเดิมมา merge (หากผู้ใช้มีการ override เฉพาะบางเดือนที่ไม่ใช่ 2026)
            if (file_exists($dataFile)) {
                $old = @json_decode(@file_get_contents($dataFile), true);
                if (is_array($old) && isset($old['monthlyTargets'])) {
                    foreach ($old['monthlyTargets'] as $mKey => $vals) {
                        if (!isset($gsData['monthlyTargets'][$mKey])) {
                            $gsData['monthlyTargets'][$mKey] = $vals;
                        }
                    }
                }
            }
            @file_put_contents($dataFile, json_encode($gsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            echo json_encode([
                'success' => true,
                'data' => $gsData,
                'source' => 'google_sheet_live',
                'is_default' => false
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
    }

    if (file_exists($dataFile)) {
        $content = @file_get_contents($dataFile);
        $json = @json_decode($content, true);
        if (is_array($json) && isset($json['machines'])) {
            if (!isset($json['monthlyTargets']) || !is_array($json['monthlyTargets'])) {
                $json['monthlyTargets'] = $defaultTargets['monthlyTargets'];
            }
            echo json_encode([
                'success' => true,
                'data' => $json,
                'source' => 'cached_file',
                'is_default' => false
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $defaultTargets,
        'source' => 'system_defaults',
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
