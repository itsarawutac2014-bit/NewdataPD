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

// โครงสร้างข้อมูลเริ่มต้น (ตาม Google Sheet แผ่นงาน "Target Out-put")
$defaultData = [
    'updated_at' => date('Y-m-d H:i:s'),
    'defaultTargetDays' => [
        'BHS' => 27,
        'YUELI' => 27,
        'ISOWA' => 27,
        'TOTAL' => 81
    ],
    'monthlyTargetDays' => [
        '2026-01' => ['ISOWA' => 27, 'BHS' => 27, 'YUELI' => 27, 'TOTAL' => 81],
        '2026-02' => ['ISOWA' => 25, 'BHS' => 25, 'YUELI' => 25, 'TOTAL' => 75],
        '2026-03' => ['ISOWA' => 27, 'BHS' => 27, 'YUELI' => 27, 'TOTAL' => 81],
        '2026-04' => ['ISOWA' => 24, 'BHS' => 24, 'YUELI' => 24, 'TOTAL' => 72],
        '2026-05' => ['ISOWA' => 26, 'BHS' => 26, 'YUELI' => 26, 'TOTAL' => 78],
        '2026-06' => ['ISOWA' => 26, 'BHS' => 26, 'YUELI' => 26, 'TOTAL' => 78],
        '2026-07' => ['ISOWA' => 27, 'BHS' => 27, 'YUELI' => 27, 'TOTAL' => 81],
        '2026-08' => ['ISOWA' => 26, 'BHS' => 26, 'YUELI' => 26, 'TOTAL' => 78],
        '2026-09' => ['ISOWA' => 27, 'BHS' => 27, 'YUELI' => 27, 'TOTAL' => 81],
        '2026-10' => ['ISOWA' => 27, 'BHS' => 27, 'YUELI' => 27, 'TOTAL' => 81],
        '2026-11' => ['ISOWA' => 26, 'BHS' => 26, 'YUELI' => 26, 'TOTAL' => 78],
        '2026-12' => ['ISOWA' => 24, 'BHS' => 24, 'YUELI' => 24, 'TOTAL' => 72]
    ],
    'records' => [] // key: "YYYY-MM" => [ 'targetDays' => [...], 'BHS' => [...], 'YUELI' => [...], 'ISOWA' => [...] ]
];

// ดึง Target วันเดินงานสดจากแผ่นงาน Google Sheet "Target Out-put" (Rows 26-37)
function fetchTargetDaysFromGoogleSheet($sheetId = '1viioRJ5I3BmqiD7KLwSG9_uNQ_9u_a8LYR-4GaPGuYI') {
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
    if (count($rows) < 27) return null;

    $thaiMonths = [
        'มกราคม' => '01', 'กุมภาพันธ์' => '02', 'มีนาคม' => '03', 'เมษายน' => '04',
        'พฤษภาคม' => '05', 'มิถุนายน' => '06', 'กรกฎาคม' => '07', 'สิงหาคม' => '08',
        'กันยายน' => '09', 'ตุลาคม' => '10', 'พฤศจิกายน' => '11', 'ธันวาคม' => '12'
    ];

    $targetDays = [];
    for ($i = 26; $i < min(38, count($rows)); $i++) {
        $r = $rows[$i]['c'] ?? [];
        $mName = trim($r[0]['v'] ?? '');
        $mCode = $thaiMonths[$mName] ?? sprintf('%02d', $i - 26 + 1);
        $key = "2026-{$mCode}";

        $isowa = intval($r[1]['v'] ?? 27);
        $bhs = intval($r[2]['v'] ?? 27);
        $yueli = intval($r[3]['v'] ?? 27);
        $tot = intval($r[4]['v'] ?? 0);
        if ($tot <= 0) $tot = $isowa + $bhs + $yueli;

        $targetDays[$key] = [
            'ISOWA' => $isowa,
            'BHS' => $bhs,
            'YUELI' => $yueli,
            'TOTAL' => $tot
        ];
    }

    return $targetDays;
}

function getStoredData($dataFile, $defaultData) {
    if (file_exists($dataFile)) {
        $content = @file_get_contents($dataFile);
        $json = @json_decode($content, true);
        if (is_array($json)) {
            if (!isset($json['defaultTargetDays'])) {
                $json['defaultTargetDays'] = $defaultData['defaultTargetDays'];
            }
            if (!isset($json['monthlyTargetDays']) || !is_array($json['monthlyTargetDays'])) {
                $json['monthlyTargetDays'] = $defaultData['monthlyTargetDays'];
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
    $forceSync = isset($_GET['sync']) || isset($_GET['refresh']);
    $fileAge = file_exists($dataFile) ? (time() - filemtime($dataFile)) : 999999;

    $current = getStoredData($dataFile, $defaultData);

    // ดึง Target วันเดินงานสดจาก Google Sheet อัตโนมัติเมื่อไฟล์เก่าหรือบังคับซิงค์
    if ($forceSync || $fileAge > 60 || !file_exists($dataFile)) {
        $liveDays = fetchTargetDaysFromGoogleSheet();
        if ($liveDays && !empty($liveDays)) {
            if (!isset($current['monthlyTargetDays'])) $current['monthlyTargetDays'] = [];
            $current['monthlyTargetDays'] = array_merge($current['monthlyTargetDays'], $liveDays);
            if (isset($liveDays['2026-10'])) {
                $current['defaultTargetDays'] = $liveDays['2026-10'];
            }
            $current['updated_at'] = date('Y-m-d H:i:s');
            @file_put_contents($dataFile, json_encode($current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }
    $monthKey = trim($_GET['monthKey'] ?? ''); // e.g. "2026-10"
    $year = trim($_GET['year'] ?? '');

    if (!empty($monthKey)) {
        $monthRecord = $current['records'][$monthKey] ?? null;
        $monthTargets = $current['monthlyTargetDays'][$monthKey] ?? ($monthRecord['targetDays'] ?? $current['defaultTargetDays']);
        echo json_encode([
            'success' => true,
            'monthKey' => $monthKey,
            'data' => $monthRecord,
            'targetDays' => $monthTargets,
            'defaultTargetDays' => $current['defaultTargetDays'],
            'monthlyTargetDays' => $current['monthlyTargetDays']
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

    // 1. บันทึกข้อมูลเมทริกซ์ 1-31 วันรายเดือน (Matrix Table Save)
    if ($action === 'save_matrix') {
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

        if (isset($payload['matrix']) && is_array($payload['matrix'])) {
            foreach (['BHS', 'YUELI', 'ISOWA'] as $m) {
                if (isset($payload['matrix'][$m]) && is_array($payload['matrix'][$m])) {
                    $current['records'][$monthKey][$m] = $payload['matrix'][$m];
                }
            }
        }

        if (isset($payload['targetDays']) && is_array($payload['targetDays'])) {
            $current['records'][$monthKey]['targetDays'] = $payload['targetDays'];
            $current['monthlyTargetDays'][$monthKey] = $payload['targetDays'];
        }
    }
    // 2. บันทึก Target วันเดินงาน 12 เดือน (แยกตามเดือน)
    elseif ($action === 'save_12months_target_days') {
        if (isset($payload['monthlyTargetDays']) && is_array($payload['monthlyTargetDays'])) {
            foreach ($payload['monthlyTargetDays'] as $mKey => $tObj) {
                if (is_array($tObj)) {
                    $cleanObj = [];
                    foreach (['BHS', 'YUELI', 'ISOWA', 'TOTAL'] as $m) {
                        if (isset($tObj[$m])) {
                            $cleanObj[$m] = max(0, round((float)$tObj[$m]));
                        }
                    }
                    $current['monthlyTargetDays'][$mKey] = $cleanObj;

                    if (!isset($current['records'][$mKey])) {
                        $current['records'][$mKey] = [
                            'targetDays' => $cleanObj,
                            'BHS' => [],
                            'YUELI' => [],
                            'ISOWA' => []
                        ];
                    } else {
                        $current['records'][$mKey]['targetDays'] = $cleanObj;
                    }
                }
            }
        }
    }
    // 3. บันทึกรายเดือนทั่วไป
    elseif ($action === 'save_month') {
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

        if (isset($payload['targetDays']) && is_array($payload['targetDays'])) {
            foreach (['BHS', 'YUELI', 'ISOWA', 'TOTAL'] as $m) {
                if (isset($payload['targetDays'][$m])) {
                    $current['records'][$monthKey]['targetDays'][$m] = max(0, round((float)$payload['targetDays'][$m]));
                }
            }
            $current['monthlyTargetDays'][$monthKey] = $current['records'][$monthKey]['targetDays'];
        }

        if (isset($payload['daysData']) && is_array($payload['daysData'])) {
            foreach (['BHS', 'YUELI', 'ISOWA'] as $m) {
                if (isset($payload['daysData'][$m]) && is_array($payload['daysData'][$m])) {
                    $current['records'][$monthKey][$m] = $payload['daysData'][$m];
                }
            }
        }

        $singleMachine = trim($payload['machine'] ?? '');
        if (!empty($singleMachine) && in_array($singleMachine, ['BHS', 'YUELI', 'ISOWA']) && isset($payload['days']) && is_array($payload['days'])) {
            $current['records'][$monthKey][$singleMachine] = $payload['days'];
            if (isset($payload['targetDay']) && is_numeric($payload['targetDay'])) {
                $current['records'][$monthKey]['targetDays'][$singleMachine] = max(0, round((float)$payload['targetDay']));
                if (!isset($current['monthlyTargetDays'][$monthKey])) $current['monthlyTargetDays'][$monthKey] = [];
                $current['monthlyTargetDays'][$monthKey][$singleMachine] = max(0, round((float)$payload['targetDay']));
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
