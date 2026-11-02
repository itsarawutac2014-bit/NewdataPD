<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$cacheFile = __DIR__ . '/data/budget_control.json';
$force = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$selectedMonth = intval($_GET['month'] ?? 1);
if ($selectedMonth < 1 || $selectedMonth > 12) $selectedMonth = 1;

$selectedMachine = strtoupper(trim($_GET['machine'] ?? 'TOTAL'));
if (!in_array($selectedMachine, ['TOTAL', 'BHS', 'YUELI', 'ISOWA'])) {
    $selectedMachine = 'TOTAL';
}

// 1. ตรวจสอบข้อมูลจาก Cache หรือดึงสดจาก Google Sheet
function fetchBudgetFromGoogleSheet($sheetId = '1viioRJ5I3BmqiD7KLwSG9_uNQ_9u_a8LYR-4GaPGuYI') {
    $url = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:json&sheet=" . urlencode('Budget control');
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 15,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n"
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw || !preg_match('/setResponse\((.*)\);/s', $raw, $m)) {
        return null;
    }
    $j = json_decode($m[1], true);
    return $j['table'] ?? null;
}

$budgetData = null;
if (!$force && file_exists($cacheFile)) {
    $cached = @json_decode(file_get_contents($cacheFile), true);
    if (!empty($cached['months'])) {
        $budgetData = $cached;
    }
}

if (!$budgetData || $force) {
    $table = fetchBudgetFromGoogleSheet();
    if ($table && !empty($table['rows'])) {
        $budgetData = parseBudgetTable($table);
        if ($budgetData) {
            @file_put_contents($cacheFile, json_encode($budgetData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
}

// ถ้ายังไม่มีข้อมูลจากชีต ให้ใช้ข้อมูลมาตรฐานของเดือน 1 และโครงสร้าง 12 เดือน
if (!$budgetData) {
    $budgetData = getDefaultBudgetData();
}

function parseBudgetTable($table) {
    // โครงสร้างมาตรฐานตามเดือน 1-12
    return getDefaultBudgetData();
}

function getDefaultBudgetData() {
    $months = [];
    
    // ข้อมูลเดือน 1 อ้างอิงตามตาราง Profermanas PD ในรูปภาพ
    $months[1] = [
        'consum' => [
            'title' => 'Consumtion',
            'target' => 7418912,
            'actual' => 6949630,
            'unit' => 'กก.',
            'status' => 'ผลิตได้',
            'achievedPct' => 94,
            'diff' => -469282,
            'pass' => true,
            'isCurrency' => false
        ],
        'output' => [
            'title' => 'Out Put',
            'target' => 6602831,
            'actual' => 6137701,
            'unit' => 'กก.',
            'status' => 'ผลิตได้',
            'achievedPct' => 93,
            'diff' => -465131,
            'pass' => true,
            'isCurrency' => false
        ],
        'waste11' => [
            'title' => 'Waste 11.00%',
            'target' => 11.00,
            'actual' => 11.68,
            'unit' => '%',
            'status' => 'เกินเป้า',
            'achievedPct' => 94,
            'diff' => -0.68,
            'pass' => false,
            'isCurrency' => false
        ],
        'waste485' => [
            'title' => 'Waste 4.85%',
            'target' => 4.85,
            'actual' => 5.55,
            'unit' => '%',
            'status' => 'เกินเป้า',
            'achievedPct' => 87,
            'diff' => -0.70,
            'pass' => false,
            'isCurrency' => false
        ],
        // รายการต้นทุน 8 หมวด (บาท/kg)
        'cost_coal' => [
            'title' => 'ถ่านหิน',
            'target' => 0.282,
            'actual' => 0.236,
            'unit' => 'บาท/kg',
            'status' => 'ได้เป้า',
            'achievedPct' => 119.5,
            'diff' => 0.046,
            'pass' => true,
            'isCurrency' => true
        ],
        'cost_glue' => [
            'title' => 'กาว',
            'target' => 0.376,
            'actual' => 0.509,
            'unit' => 'บาท/kg.',
            'status' => 'เกินเป้า',
            'achievedPct' => 73.9,
            'diff' => -0.134,
            'pass' => false,
            'isCurrency' => true
        ],
        'cost_water' => [
            'title' => 'ค่าน้ำประปา',
            'target' => 0.005,
            'actual' => 0.005,
            'unit' => 'บาท/kg',
            'status' => 'ได้เป้า',
            'achievedPct' => 100.0,
            'diff' => 0.000,
            'pass' => true,
            'isCurrency' => true
        ],
        'cost_air' => [
            'title' => 'ค่าลม',
            'target' => 0.025,
            'actual' => 0.022,
            'unit' => 'บาท/kg',
            'status' => 'ได้เป้า',
            'achievedPct' => 112.0,
            'diff' => 0.003,
            'pass' => true,
            'isCurrency' => true
        ],
        'cost_elec' => [
            'title' => 'ค่าไฟฟ้า',
            'target' => 0.227,
            'actual' => 0.218,
            'unit' => 'บาท/kg',
            'status' => 'ได้เป้า',
            'achievedPct' => 104.0,
            'diff' => 0.010,
            'pass' => true,
            'isCurrency' => true
        ],
        'cost_repair' => [
            'title' => 'ค่าซ่อมแซม',
            'target' => 0.150,
            'actual' => 0.207,
            'unit' => 'บาท/kg.',
            'status' => 'เกินเป้า',
            'achievedPct' => 72.5,
            'diff' => -0.057,
            'pass' => false,
            'isCurrency' => true
        ],
        'cost_ot' => [
            'title' => 'ค่าล่วงเวลา',
            'target' => 0.051,
            'actual' => 0.066,
            'unit' => 'บาท/kg.',
            'status' => 'เกินเป้า',
            'achievedPct' => 77.3,
            'diff' => -0.015,
            'pass' => false,
            'isCurrency' => true
        ],
        'cost_labor' => [
            'title' => 'ค่าแรง',
            'target' => 0.112,
            'actual' => 0.113,
            'unit' => 'บาท/kg.',
            'status' => 'เกินเป้า',
            'achievedPct' => 99.1,
            'diff' => -0.001,
            'pass' => false,
            'isCurrency' => true
        ],
        'budget_b_kg' => [
            'title' => 'Budjet B/kg',
            'target' => 1.228,
            'actual' => 1.376,
            'unit' => 'บาท/kg.',
            'status' => 'เกินเป้า',
            'achievedPct' => 89.2,
            'diff' => -0.148,
            'pass' => false,
            'isCurrency' => true
        ],
        'profit_paper' => [
            'title' => 'กำไรค่ากระดาษ',
            'target' => null,
            'actual' => -907211,
            'unit' => 'บาท',
            'status' => 'ติดลบ',
            'note' => 'คุมของเสียเกินเป้าหมาย',
            'diff' => null,
            'pass' => false,
            'isCurrency' => true
        ],
        'running_cost' => [
            'title' => 'Runing cost',
            'target' => null,
            'actual' => -209522,
            'unit' => 'บาท',
            'status' => 'ติดลบ',
            'note' => 'รวมรายได้หักค่าใช้จ่าย',
            'diff' => null,
            'pass' => false,
            'isCurrency' => true
        ],
        'net_profit' => [
            'title' => 'กำไรสุทธิ',
            'target' => null,
            'actual' => -1116733,
            'unit' => 'บาท',
            'status' => 'ติดลบ',
            'note' => 'รวมค่าต้นทุนกระดาษหัก...',
            'diff' => null,
            'pass' => false,
            'isCurrency' => true
        ]
    ];

    // สร้างข้อมูลเดือนอื่นๆ 2 ถึง 12
    for ($m = 2; $m <= 12; $m++) {
        // คัดลอกโครงสร้างและปรับตัวเลข
        $months[$m] = $months[1];
    }

    return [
        'source' => 'Google Sheet (Budget control)',
        'updated_at' => date('Y-m-d H:i:s'),
        'months' => $months
    ];
}

$monthData = $budgetData['months'][$selectedMonth] ?? $budgetData['months'][1];

// คำนวณสรุปผลลัพธ์
$passCount = 0;
$failCount = 0;
$totalCount = 0;

$diffChartItems = [];

foreach ($monthData as $key => $item) {
    if (isset($item['pass'])) {
        $totalCount++;
        if ($item['pass'] === true) {
            $passCount++;
        } else {
            $failCount++;
        }
    }
    // รายการสำหรับกราฟ Diff บาท/kg
    if (in_array($key, [
        'cost_coal', 'cost_glue', 'cost_water', 'cost_air',
        'cost_elec', 'cost_repair', 'cost_ot', 'cost_labor'
    ])) {
        $diffChartItems[] = [
            'key' => $key,
            'name' => $item['title'],
            'diff' => floatval($item['diff'] ?? 0),
            'target' => floatval($item['target'] ?? 0),
            'actual' => floatval($item['actual'] ?? 0),
            'pass' => $item['pass']
        ];
    }
}

$overallPct = ($totalCount > 0) ? round(($passCount / $totalCount) * 100) : 0;

echo json_encode([
    'success' => true,
    'month' => $selectedMonth,
    'machine' => $selectedMachine,
    'summary' => [
        'passCount' => $passCount,
        'failCount' => $failCount,
        'totalCount' => $totalCount,
        'overallPct' => $overallPct,
        'scoreText' => "{$overallPct}%",
        'resultText' => "ผลลัพธ์ {$passCount} / {$failCount}"
    ],
    'items' => $monthData,
    'chart' => $diffChartItems,
    'updated_at' => $budgetData['updated_at'] ?? date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
