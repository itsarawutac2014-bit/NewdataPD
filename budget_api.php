<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
// Allow browsers to cache for 30 minutes (reduces Render cold-start impact)
$force = isset($_GET['refresh']) && $_GET['refresh'] === '1';
if (!$force) {
    header('Cache-Control: public, max-age=1800');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$selectedMonth = intval($_GET['month'] ?? 1);
if ($selectedMonth < 1 || $selectedMonth > 12) $selectedMonth = 1;

$selectedMachine = strtoupper(trim($_GET['machine'] ?? 'TOTAL'));
if (!in_array($selectedMachine, ['TOTAL', 'BHS', 'YUELI', 'ISOWA'])) {
    $selectedMachine = 'TOTAL';
}

// 12-Month Production Data (Target / Actual kg, Waste %)
$productionMonths = [
    1  => ['con_t' => 7418912, 'con_a' => 6949630, 'out_t' => 6602831, 'out_a' => 6137701, 'w11' => 11.68, 'w485' => 5.55],
    2  => ['con_t' => 6848226, 'con_a' => 5827824, 'out_t' => 6094921, 'out_a' => 5169130, 'w11' => 11.30, 'w485' => 5.20],
    3  => ['con_t' => 7418912, 'con_a' => 7128650, 'out_t' => 6602831, 'out_a' => 6357217, 'w11' => 10.82, 'w485' => 4.83],
    4  => ['con_t' => 6562883, 'con_a' => 6507650, 'out_t' => 5840966, 'out_a' => 5826254, 'w11' => 10.47, 'w485' => 4.89],
    5  => ['con_t' => 7418912, 'con_a' => 8272089, 'out_t' => 6602831, 'out_a' => 7417037, 'w11' => 10.34, 'w485' => 5.10],
    6  => ['con_t' => 6848226, 'con_a' => 7588223, 'out_t' => 6094921, 'out_a' => 6777593, 'w11' => 10.68, 'w485' => 5.19],
    7  => ['con_t' => 7418912, 'con_a' => 8165325, 'out_t' => 6602831, 'out_a' => 7293680, 'w11' => 10.67, 'w485' => 5.24],
    8  => ['con_t' => 7133569, 'con_a' => 7405017, 'out_t' => 6348876, 'out_a' => 6560576, 'w11' => 10.34, 'w485' => 5.95],
    9  => ['con_t' => 7418912, 'con_a' => 7115974, 'out_t' => 6602831, 'out_a' => 6321601, 'w11' =>  9.73, 'w485' => 5.84],
    10 => ['con_t' => 7418912, 'con_a' => 7571110, 'out_t' => 6602831, 'out_a' => 6770906, 'w11' => 10.57, 'w485' => 5.52],
    11 => ['con_t' => 7133569, 'con_a' => 8188675, 'out_t' => 6348876, 'out_a' => 7274966, 'w11' => 11.16, 'w485' => 5.74],
    12 => ['con_t' => 6562883, 'con_a' => 7257540, 'out_t' => 5840966, 'out_a' => 6430414, 'w11' => 11.40, 'w485' => 5.81],
];

// 12-Month Cost Targets (บาท/kg)
// Fixed targets: ถ่านหิน 0.282, กาว 0.376, น้ำ 0.005, ลม 0.025, ไฟฟ้า 0.227, ซ่อมแซม 0.150, ล่วงเวลา 0.051, ค่าแรง 0.112
// Total Target = 1.228
$costTargets = [
    'coal'   => 0.282,
    'glue'   => 0.376,
    'water'  => 0.005,
    'air'    => 0.025,
    'elec'   => 0.227,
    'repair' => 0.150,
    'ot'     => 0.051,
    'labor'  => 0.112,
    'total'  => 1.228,
];

// 12-Month Cost Actuals (บาท/kg) from Budget control sheet
$costActuals = [
    1  => ['coal' => 0.236, 'glue' => 0.509, 'water' => 0.005, 'air' => 0.022, 'elec' => 0.218, 'repair' => 0.207, 'ot' => 0.066, 'labor' => 0.113, 'total' => 1.376],
    2  => ['coal' => 0.248, 'glue' => 0.356, 'water' => 0.004, 'air' => 0.015, 'elec' => 0.177, 'repair' => 0.188, 'ot' => 0.056, 'labor' => 0.104, 'total' => 1.149],
    3  => ['coal' => 0.225, 'glue' => 0.378, 'water' => 0.004, 'air' => 0.017, 'elec' => 0.166, 'repair' => 0.162, 'ot' => 0.059, 'labor' => 0.099, 'total' => 1.109],
    4  => ['coal' => 0.327, 'glue' => 0.379, 'water' => 0.005, 'air' => 0.021, 'elec' => 0.217, 'repair' => 0.190, 'ot' => 0.062, 'labor' => 0.108, 'total' => 1.309],
    5  => ['coal' => 0.234, 'glue' => 0.328, 'water' => 0.004, 'air' => 0.017, 'elec' => 0.160, 'repair' => 0.174, 'ot' => 0.052, 'labor' => 0.089, 'total' => 1.066],
    6  => ['coal' => 0.245, 'glue' => 0.328, 'water' => 0.005, 'air' => 0.017, 'elec' => 0.172, 'repair' => 0.256, 'ot' => 0.059, 'labor' => 0.127, 'total' => 1.209],
    7  => ['coal' => 0.256, 'glue' => 0.368, 'water' => 0.004, 'air' => 0.012, 'elec' => 0.124, 'repair' => 0.094, 'ot' => 0.047, 'labor' => 0.098, 'total' => 1.013],
    8  => ['coal' => 0.248, 'glue' => 0.405, 'water' => 0.004, 'air' => 0.014, 'elec' => 0.159, 'repair' => 0.125, 'ot' => 0.047, 'labor' => 0.109, 'total' => 1.111],
    9  => ['coal' => 0.259, 'glue' => 0.372, 'water' => 0.004, 'air' => 0.016, 'elec' => 0.192, 'repair' => 0.172, 'ot' => 0.056, 'labor' => 0.113, 'total' => 1.183],
    10 => ['coal' => 0.201, 'glue' => 0.374, 'water' => 0.004, 'air' => 0.017, 'elec' => 0.190, 'repair' => 0.245, 'ot' => 0.046, 'labor' => 0.089, 'total' => 1.166],
    11 => ['coal' => 0.227, 'glue' => 0.421, 'water' => 0.004, 'air' => 0.020, 'elec' => 0.209, 'repair' => 0.191, 'ot' => 0.049, 'labor' => 0.096, 'total' => 1.207],
    12 => ['coal' => 0.241, 'glue' => 0.366, 'water' => 0.004, 'air' => 0.017, 'elec' => 0.199, 'repair' => 0.143, 'ot' => 0.052, 'labor' => 0.093, 'total' => 1.164],
];

// 12-Month Financial Summary (Actual บาท) - from Google Sheet Budget control tab:
// profit_paper = รวมต้นทุน - รวมรายได้ (Sheet row 23)
// running_cost = กำไรขาดทุนจากค่าทำ (Sheet row 65)
// net_profit   = กำไรก่อนหักค่าเสื่อมและดอกเบี้ย (Sheet row 67)
$financialMonths = [
    1  => ['paper' => -241436,  'running' => 1407369,  'net' => 1165932],
    2  => ['paper' => -1794292, 'running' => -1200913, 'net' => -2995205],
    3  => ['paper' => 118980,   'running' => -593332,  'net' => -474352],
    4  => ['paper' => 504911,   'running' => -1290269, 'net' => -785358],
    5  => ['paper' => 845005,   'running' => -576945,  'net' => 268060],
    6  => ['paper' => 757618,   'running' => -1018710, 'net' => -261092],
    7  => ['paper' => -791269,  'running' => -3516019, 'net' => -4307288],
    8  => ['paper' => 242033,   'running' => -1407204, 'net' => -1165170],
    9  => ['paper' => -212018,  'running' => -1299787, 'net' => -1511805],
    10 => ['paper' => 0,        'running' => -4703785, 'net' => 0],
    11 => ['paper' => 0,        'running' => -2835487, 'net' => 0],
    12 => ['paper' => 0,        'running' => -34879,   'net' => 0],
];

// If data/budget_monthly.json exists, load dynamically
$monthlyJsonPath = __DIR__ . '/data/budget_monthly.json';
if (file_exists($monthlyJsonPath)) {
    $monthlyData = json_decode(file_get_contents($monthlyJsonPath), true);
    if (!empty($monthlyData['financials'])) {
        for ($i = 1; $i <= 12; $i++) {
            $k = 'm' . $i;
            $p = $monthlyData['financials']['profit_paper'][$k]['actual'] ?? null;
            $r = $monthlyData['financials']['running_cost'][$k]['actual'] ?? null;
            $n = $monthlyData['financials']['profit_net'][$k]['actual'] ?? null;
            if ($p !== null || $r !== null || $n !== null) {
                $financialMonths[$i] = [
                    'paper'   => $p !== null ? round($p) : 0,
                    'running' => $r !== null ? round($r) : 0,
                    'net'     => $n !== null ? round($n) : 0,
                ];
            }
        }
    }
}

// Function to generate data for a given month
function buildMonthKPI($m, $productionMonths, $costTargets, $costActuals, $financialMonths) {
    $p = $productionMonths[$m] ?? $productionMonths[1];
    $c = $costActuals[$m] ?? $costActuals[1];
    $f = $financialMonths[$m] ?? $financialMonths[1];
    $t = $costTargets;

    // Production items
    $conDiff = $p['con_a'] - $p['con_t'];
    $conPct  = round(($p['con_a'] / $p['con_t']) * 100);
    $outDiff = $p['out_a'] - $p['out_t'];
    $outPct  = round(($p['out_a'] / $p['out_t']) * 100);

    $w11Diff = round(11.00 - $p['w11'], 2);
    $w11Pass = ($p['w11'] <= 11.00);
    $w11Pct  = round((11.00 / $p['w11']) * 100);

    $w485Diff = round(4.85 - $p['w485'], 2);
    $w485Pass = ($p['w485'] <= 4.85);
    $w485Pct  = round((4.85 / $p['w485']) * 100);

    // Cost helper: Diff = Target - Actual (positive = saved/pass, negative = over/fail)
    $costItem = function($name, $tVal, $aVal) {
        $diff = round($tVal - $aVal, 3);
        $pass = ($diff >= 0);
        $pct = ($aVal > 0) ? round(($tVal / $aVal) * 100, 1) : 100;
        return [
            'title'       => $name,
            'target'      => $tVal,
            'actual'      => $aVal,
            'unit'        => 'บาท/kg',
            'status'      => $pass ? 'ได้เป้า' : 'เกินเป้า',
            'achievedPct' => $pct,
            'diff'        => $diff,
            'pass'        => $pass,
            'isCurrency'  => true
        ];
    };

    $coal   = $costItem('ถ่านหิน',      $t['coal'],   $c['coal']);
    $glue   = $costItem('กาว',          $t['glue'],   $c['glue']);
    $water  = $costItem('ค่าน้ำประปา',  $t['water'],  $c['water']);
    $air    = $costItem('ค่าลม',        $t['air'],    $c['air']);
    $elec   = $costItem('ค่าไฟฟ้า',      $t['elec'],   $c['elec']);
    $repair = $costItem('ค่าซ่อมแซม',   $t['repair'], $c['repair']);
    $ot     = $costItem('ค่าล่วงเวลา',   $t['ot'],     $c['ot']);
    $labor  = $costItem('ค่าแรง',       $t['labor'],  $c['labor']);
    $budget = $costItem('Budjet B/kg',  $t['total'],  $c['total']);

    return [
        'consum' => [
            'title'       => 'Consumtion',
            'target'      => $p['con_t'],
            'actual'      => $p['con_a'],
            'unit'        => 'กก.',
            'status'      => 'ผลิตได้',
            'achievedPct' => $conPct,
            'diff'        => $conDiff,
            'pass'        => true,
            'isCurrency'  => false
        ],
        'output' => [
            'title'       => 'Out Put',
            'target'      => $p['out_t'],
            'actual'      => $p['out_a'],
            'unit'        => 'กก.',
            'status'      => 'ผลิตได้',
            'achievedPct' => $outPct,
            'diff'        => $outDiff,
            'pass'        => true,
            'isCurrency'  => false
        ],
        'waste11' => [
            'title'       => 'Waste 11.00%',
            'target'      => 11.00,
            'actual'      => $p['w11'],
            'unit'        => '%',
            'status'      => $w11Pass ? 'ได้เป้า' : 'เกินเป้า',
            'achievedPct' => $w11Pct,
            'diff'        => $w11Diff,
            'pass'        => $w11Pass,
            'isCurrency'  => false
        ],
        'waste485' => [
            'title'       => 'Waste 4.85%',
            'target'      => 4.85,
            'actual'      => $p['w485'],
            'unit'        => '%',
            'status'      => $w485Pass ? 'ได้เป้า' : 'เกินเป้า',
            'achievedPct' => $w485Pct,
            'diff'        => $w485Diff,
            'pass'        => $w485Pass,
            'isCurrency'  => false
        ],
        'cost_coal'   => $coal,
        'cost_glue'   => $glue,
        'cost_water'  => $water,
        'cost_air'    => $air,
        'cost_elec'   => $elec,
        'cost_repair' => $repair,
        'cost_ot'     => $ot,
        'cost_labor'  => $labor,
        'budget_b_kg' => $budget,
        'profit_paper' => [
            'title'      => 'กำไรค่ากระดาษ',
            'target'     => null,
            'actual'     => $f['paper'],
            'unit'       => 'บาท',
            'status'     => ($f['paper'] < 0) ? 'ติดลบ' : 'ได้กำไร',
            'note'       => ($f['paper'] < 0) ? 'คุมของเสียเกินเป้าหมาย' : 'ตามเป้าหมาย',
            'diff'       => null,
            'pass'       => ($f['paper'] >= 0),
            'isCurrency' => true
        ],
        'running_cost' => [
            'title'      => 'Runing cost',
            'target'     => null,
            'actual'     => $f['running'],
            'unit'       => 'บาท',
            'status'     => ($f['running'] < 0) ? 'ติดลบ' : 'ได้กำไร',
            'note'       => ($f['running'] < 0) ? 'รวมรายได้หักค่าใช้จ่าย' : 'ตามเป้าหมาย',
            'diff'       => null,
            'pass'       => ($f['running'] >= 0),
            'isCurrency' => true
        ],
        'net_profit' => [
            'title'      => 'กำไรสุทธิ',
            'target'     => null,
            'actual'     => $f['net'],
            'unit'       => 'บาท',
            'status'     => ($f['net'] < 0) ? 'ติดลบ' : 'ได้กำไร',
            'note'       => ($f['net'] < 0) ? 'รวมค่าต้นทุนกระดาษหัก...' : 'ตามเป้าหมาย',
            'diff'       => null,
            'pass'       => ($f['net'] >= 0),
            'isCurrency' => true
        ]
    ];
}

$monthData = buildMonthKPI($selectedMonth, $productionMonths, $costTargets, $costActuals, $financialMonths);

// Calculate pass/fail summary
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
    // Items for Diff bar chart (8 cost items)
    if (in_array($key, [
        'cost_coal', 'cost_glue', 'cost_water', 'cost_air',
        'cost_elec', 'cost_repair', 'cost_ot', 'cost_labor'
    ])) {
        $diffChartItems[] = [
            'key'    => $key,
            'name'   => $item['title'],
            'diff'   => floatval($item['diff'] ?? 0),
            'target' => floatval($item['target'] ?? 0),
            'actual' => floatval($item['actual'] ?? 0),
            'pass'   => $item['pass']
        ];
    }
}

$overallPct = ($totalCount > 0) ? round(($passCount / $totalCount) * 100) : 0;

echo json_encode([
    'success' => true,
    'month'   => $selectedMonth,
    'machine' => $selectedMachine,
    'summary' => [
        'passCount'  => $passCount,
        'failCount'  => $failCount,
        'totalCount' => $totalCount,
        'overallPct' => $overallPct,
        'scoreText'  => "{$overallPct}%",
        'resultText' => "ผลลัพธ์ {$passCount} / {$failCount}"
    ],
    'items'      => $monthData,
    'chart'      => $diffChartItems,
    'updated_at' => date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
