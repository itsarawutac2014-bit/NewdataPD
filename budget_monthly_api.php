<?php
/**
 * budget_monthly_api.php - Unified Multi-Machine Budget Control API
 * 
 * Sources data from 3 Google Sheet tabs:
 *   - Budget controlBHS   (gid=1882565873)
 *   - Budget controlISOWA (gid=1941831190)
 *   - Budget controlYUELI (gid=898712953)
 * 
 * Supports:
 *   - ?machine=TOTAL|BHS|ISOWA|YUELI (default: TOTAL)
 *   - ?refresh=1 (bypass cache)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$cacheFile = __DIR__ . '/data/budget_database_v2.json';
$force = isset($_GET['refresh']) && $_GET['refresh'] === '1';

if (!$force) {
    header('Cache-Control: public, max-age=1800');
}

$reqMachine = strtoupper(trim($_GET['machine'] ?? 'TOTAL'));
if (!in_array($reqMachine, ['TOTAL', 'BHS', 'ISOWA', 'YUELI'])) {
    $reqMachine = 'TOTAL';
}

function cleanNum($v) {
    if ($v === null || $v === '' || $v === '-') return 0;
    $s = str_replace([',', ' ', '%', '#'], '', trim((string)$v));
    return is_numeric($s) ? floatval($s) : 0;
}

// 12-Month Production Baseline (Total / Combined production targets and actuals)
$productionData = [
    'ALL' => ['consumption_target' => 10775493.89, 'consumption_actual' => 5204441, 'output_target' => 9497623.40, 'output_actual' => 4561301.15, 'waste_pct_target' => 1277870.49, 'waste_pct_actual' => 643139.85, 'waste_pd_actual' => 641612.41, 'output_pd_actual' => 4562826.59],
    'm1'  => ['consumption_target' => 8522022.31,  'consumption_actual' => 8540953, 'output_target' => 7570481.68, 'output_actual' => 7570481.68, 'waste_pct_target' => 951540.63,  'waste_pct_actual' => 970471.32, 'waste_pd_actual' => 963997.40, 'output_pd_actual' => 7576955.60],
    'm2'  => ['consumption_target' => 7344825.60,  'consumption_actual' => 7467014, 'output_target' => 6523260.33, 'output_actual' => 6523260.33, 'waste_pct_target' => 821565.27,  'waste_pct_actual' => 943753.67, 'waste_pd_actual' => 935516.41, 'output_pd_actual' => 6531497.59],
    'm3'  => ['consumption_target' => 9031057.95,  'consumption_actual' => 9031612, 'output_target' => 8017928.58, 'output_actual' => 8017928.58, 'waste_pct_target' => 1013129.37, 'waste_pct_actual' => 1013683.42,'waste_pd_actual' => 1009869.69,'output_pd_actual' => 8021742.31],
    'm4'  => ['consumption_target' => 7701120.13,  'consumption_actual' => 7674332, 'output_target' => 6822837.13, 'output_actual' => 6822837.13, 'waste_pct_target' => 878283.00,  'waste_pct_actual' => 851494.87, 'waste_pd_actual' => 848821.23, 'output_pd_actual' => 6825510.77],
    'm5'  => ['consumption_target' => 8139263.05,  'consumption_actual' => 8096330, 'output_target' => 7231052.44, 'output_actual' => 7231052.44, 'waste_pct_target' => 908210.60,  'waste_pct_actual' => 865277.56, 'waste_pd_actual' => 862441.84, 'output_pd_actual' => 7233888.16],
    'm6'  => ['consumption_target' => 5856687.28,  'consumption_actual' => 5816754, 'output_target' => 5211402.59, 'output_actual' => 5211402.59, 'waste_pct_target' => 645284.69,  'waste_pct_actual' => 605351.41, 'waste_pd_actual' => 605903.32, 'output_pd_actual' => 5210850.68],
    'm7'  => ['consumption_target' => 8530285.97,  'consumption_actual' => 6437220, 'output_target' => 7591954.52, 'output_actual' => 5689409.63, 'waste_pct_target' => 938331.45,  'waste_pct_actual' => 747810.37, 'waste_pd_actual' => 745719.03, 'output_pd_actual' => 5691500.97],
    'm8'  => ['consumption_target' => 8214349.46,  'consumption_actual' => 6614259, 'output_target' => 7310771.02, 'output_actual' => 5896704.74, 'waste_pct_target' => 903578.44,  'waste_pct_actual' => 717554.26, 'waste_pd_actual' => 719181.96, 'output_pd_actual' => 5895077.04],
    'm9'  => ['consumption_target' => 8530285.97,  'consumption_actual' => 6211776, 'output_target' => 7591954.52, 'output_actual' => 5516370.99, 'waste_pct_target' => 938331.45,  'waste_pct_actual' => 695405.01, 'waste_pd_actual' => 694720.40, 'output_pd_actual' => 5517055.60],
    'm10' => ['consumption_target' => 8530285.97,  'consumption_actual' => 7571110, 'output_target' => 7591954.52, 'output_actual' => 6770906.00, 'waste_pct_target' => 938331.45,  'waste_pct_actual' => 800266.00, 'waste_pd_actual' => 800266.00, 'output_pd_actual' => 6770906.00],
    'm11' => ['consumption_target' => 8214349.46,  'consumption_actual' => 8188675, 'output_target' => 7310771.02, 'output_actual' => 7274966.00, 'waste_pct_target' => 903578.44,  'waste_pct_actual' => 913856.00, 'waste_pd_actual' => 913856.00, 'output_pd_actual' => 7274966.00],
    'm12' => ['consumption_target' => 7582476.42,  'consumption_actual' => 7257540, 'output_target' => 6748404.01, 'output_actual' => 6430414.00, 'waste_pct_target' => 834072.41,  'waste_pct_actual' => 827359.00, 'waste_pd_actual' => 827359.00, 'output_pd_actual' => 6430414.00],
];

// Check cache
if (!$force && file_exists($cacheFile)) {
    $rawCached = @file_get_contents($cacheFile);
    $cached = json_decode($rawCached, true);
    if (!empty($cached['machines'][$reqMachine])) {
        $machData = $cached['machines'][$reqMachine];
        $machData['production'] = $productionData;
        echo json_encode([
            'success'     => true,
            'machine'     => $reqMachine,
            'data'        => $machData,
            'machines'    => ['TOTAL', 'BHS', 'ISOWA', 'YUELI'],
            'updated_at'  => $cached['updated_at'] ?? date('Y-m-d H:i:s'),
            'cached'      => true
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Fetch from Google Sheets
$sheetId = '1viioRJ5I3BmqiD7KLwSG9_uNQ_9u_a8LYR-4GaPGuYI';
$ctx = stream_context_create(['http' => ['timeout' => 25]]);
$gids = [
    'BHS'   => 1882565873,
    'ISOWA' => 1941831190,
    'YUELI' => 898712953,
];

$rawSheets = [];
foreach ($gids as $mach => $gid) {
    $url = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$gid}";
    $csv = @file_get_contents($url, false, $ctx);
    if (!$csv) {
        if (file_exists($cacheFile)) {
            $rawCached = @file_get_contents($cacheFile);
            $cached = json_decode($rawCached, true);
            $machData = $cached['machines'][$reqMachine] ?? [];
            $machData['production'] = $productionData;
            echo json_encode([
                'success'     => true,
                'machine'     => $reqMachine,
                'data'        => $machData,
                'cached'      => true
            ]);
            exit;
        }
        echo json_encode(['success' => false, 'error' => "Failed to fetch sheet for {$mach}"]);
        exit;
    }
    $lines = explode("\n", $csv);
    $rows = [];
    foreach ($lines as $line) {
        $rows[] = str_getcsv($line);
    }
    $rawSheets[$mach] = $rows;
}

$finKeys = ['revenue', 'paper_cost', 'sell_fg', 'profit_paper', 'running_cost', 'profit_net'];
$finLabels = [
    'revenue'      => 'รายได้',
    'paper_cost'   => 'รวมต้นทุน',
    'sell_fg'      => 'ขาย FG',
    'profit_paper' => 'กำไรค่ากระดาษ',
    'running_cost' => 'Running cost',
    'profit_net'   => 'กำไรสุทธิ',
];
$finRowMap = [
    'revenue'      => 1,
    'paper_cost'   => 2,
    'sell_fg'      => 3,
    'profit_paper' => 4,
    'running_cost' => 5,
    'profit_net'   => 6,
];

$machinesData = [];

foreach (['BHS', 'ISOWA', 'YUELI'] as $mach) {
    $rows = $rawSheets[$mach];
    $fin = [];

    foreach ($finKeys as $key) {
        $rIdx = $finRowMap[$key];
        $row = $rows[$rIdx] ?? [];
        $tCol1 = cleanNum($row[1] ?? 0);
        $mActual = [];
        for ($m = 1; $m <= 12; $m++) {
            $cIdx = $m + 1; // Col C is index 2 = Month 1
            $mActual[$m] = cleanNum($row[$cIdx] ?? 0);
        }
        $allVal = cleanNum($row[14] ?? 0); // Col O = ALL

        $fin[$key] = [
            'label'       => $finLabels[$key],
            'actual'      => $mActual,
            'all'         => $allVal,
            'target_col1' => $tCol1,
        ];
    }

    // Cost target table (Rows 11-22)
    // Cost actual table (Rows 27-38)
    $catOffsets = [
        'coal'   => 1,
        'glue'   => 5,
        'water'  => 9,
        'air'    => 13,
        'elec'   => 17,
        'repair' => 21,
        'ot'     => 25,
        'labor'  => 29,
        'total'  => 33,
    ];

    $costTarget = [];
    $costActual = [];

    for ($m = 1; $m <= 12; $m++) {
        $rTarget = 10 + $m;
        $rActual = 26 + $m;

        $targetRow = $rows[$rTarget] ?? [];
        $actualRow = $rows[$rActual] ?? [];

        $costTarget[$m] = [];
        $costActual[$m] = [];

        foreach ($catOffsets as $cat => $colBase) {
            $costTarget[$m][$cat] = [
                'val' => cleanNum($targetRow[$colBase] ?? 0),
                'qty' => cleanNum($targetRow[$colBase + 1] ?? 0),
                'bkg' => cleanNum($targetRow[$colBase + 2] ?? 0),
            ];
            $costActual[$m][$cat] = [
                'val' => cleanNum($actualRow[$colBase] ?? 0),
                'qty' => cleanNum($actualRow[$colBase + 1] ?? 0),
                'bkg' => cleanNum($actualRow[$colBase + 2] ?? 0),
            ];
        }
    }

    $machinesData[$mach] = [
        'financials'  => $fin,
        'cost_target' => $costTarget,
        'cost_actual' => $costActual,
    ];
}

// Compute TOTAL (BHS + ISOWA + YUELI)
$totalFin = [];
foreach ($finKeys as $key) {
    $tActual = [];
    for ($m = 1; $m <= 12; $m++) {
        $tActual[$m] = $machinesData['BHS']['financials'][$key]['actual'][$m]
                     + $machinesData['ISOWA']['financials'][$key]['actual'][$m]
                     + $machinesData['YUELI']['financials'][$key]['actual'][$m];
    }
    $totalFin[$key] = [
        'label'       => $finLabels[$key],
        'actual'      => $tActual,
        'all'         => $machinesData['BHS']['financials'][$key]['all']
                       + $machinesData['ISOWA']['financials'][$key]['all']
                       + $machinesData['YUELI']['financials'][$key]['all'],
        'target_col1' => $machinesData['BHS']['financials'][$key]['target_col1']
                       + $machinesData['ISOWA']['financials'][$key]['target_col1']
                       + $machinesData['YUELI']['financials'][$key]['target_col1'],
    ];
}

$cats = ['coal', 'glue', 'water', 'air', 'elec', 'repair', 'ot', 'labor', 'total'];
$totalCostTarget = [];
$totalCostActual = [];
for ($m = 1; $m <= 12; $m++) {
    $totalCostTarget[$m] = [];
    $totalCostActual[$m] = [];
    foreach ($cats as $cat) {
        $tv = 0; $tq = 0; $av = 0; $aq = 0;
        foreach (['BHS', 'ISOWA', 'YUELI'] as $mach) {
            $tv += $machinesData[$mach]['cost_target'][$m][$cat]['val'];
            $tq += $machinesData[$mach]['cost_target'][$m][$cat]['qty'];
            $av += $machinesData[$mach]['cost_actual'][$m][$cat]['val'];
            $aq += $machinesData[$mach]['cost_actual'][$m][$cat]['qty'];
        }
        $totalCostTarget[$m][$cat] = [
            'val' => $tv,
            'qty' => $tq,
            'bkg' => $tq > 0 ? round($tv / $tq, 4) : 0,
        ];
        $totalCostActual[$m][$cat] = [
            'val' => $av,
            'qty' => $aq,
            'bkg' => $aq > 0 ? round($av / $aq, 4) : 0,
        ];
    }
}

$machinesData['TOTAL'] = [
    'financials'  => $totalFin,
    'cost_target' => $totalCostTarget,
    'cost_actual' => $totalCostActual,
];

// Target Lines Calculation & Month structure enrichment for each machine
foreach (['TOTAL', 'BHS', 'ISOWA', 'YUELI'] as $mach) {
    $fin = &$machinesData[$mach]['financials'];
    $cTarget = $machinesData[$mach]['cost_target'];
    
    // 1. revenue Target (break-even against paper_cost or annual avg)
    $revTargets = [];
    $avgRev = $fin['revenue']['all'] > 0 ? round($fin['revenue']['all'] / 12) : 0;
    for ($m = 1; $m <= 12; $m++) {
        $col1 = $fin['revenue']['target_col1'];
        $revTargets[$m] = $col1 > 0 ? $col1 : ($fin['paper_cost']['actual'][$m] > 0 ? $fin['paper_cost']['actual'][$m] : $avgRev);
    }
    $fin['revenue']['targets'] = $revTargets;

    // 2. paper_cost Target
    $costTargets = [];
    $avgCost = $fin['paper_cost']['all'] > 0 ? round($fin['paper_cost']['all'] / 12) : 0;
    for ($m = 1; $m <= 12; $m++) {
        $col1 = $fin['paper_cost']['target_col1'];
        $costTargets[$m] = $col1 > 0 ? $col1 : ($fin['revenue']['actual'][$m] > 0 ? $fin['revenue']['actual'][$m] : $avgCost);
    }
    $fin['paper_cost']['targets'] = $costTargets;

    // 3. sell_fg Target
    $fgTargets = [];
    $avgFg = $fin['sell_fg']['all'] > 0 ? round($fin['sell_fg']['all'] / 12) : 0;
    for ($m = 1; $m <= 12; $m++) {
        $col1 = $fin['sell_fg']['target_col1'];
        $fgTargets[$m] = $col1 > 0 ? $col1 : $avgFg;
    }
    $fin['sell_fg']['targets'] = $fgTargets;

    // 4. profit_paper Target (0 break-even)
    $ppTargets = [];
    for ($m = 1; $m <= 12; $m++) {
        $col1 = $fin['profit_paper']['target_col1'];
        $ppTargets[$m] = $col1 != 0 ? $col1 : 0;
    }
    $fin['profit_paper']['targets'] = $ppTargets;

    // 5. running_cost Target (from cost_target total val)
    $rcTargets = [];
    for ($m = 1; $m <= 12; $m++) {
        $tVal = $cTarget[$m]['total']['val'] ?? 0;
        $rcTargets[$m] = $tVal > 0 ? $tVal : ($fin['running_cost']['target_col1'] > 0 ? $fin['running_cost']['target_col1'] : 0);
    }
    $fin['running_cost']['targets'] = $rcTargets;

    // 6. profit_net Target (0 break-even)
    $pnTargets = [];
    for ($m = 1; $m <= 12; $m++) {
        $col1 = $fin['profit_net']['target_col1'];
        $pnTargets[$m] = $col1 != 0 ? $col1 : 0;
    }
    $fin['profit_net']['targets'] = $pnTargets;

    // Also enrich each key with 'm1'..'m12' and 'ALL' objects for table compatibility
    foreach ($finKeys as $k) {
        for ($m = 1; $m <= 12; $m++) {
            $fin[$k]['m' . $m] = [
                'target' => $fin[$k]['targets'][$m] ?? 0,
                'actual' => $fin[$k]['actual'][$m] ?? 0,
            ];
        }
        $fin[$k]['ALL'] = [
            'target' => $fin[$k]['all'] ?? 0,
            'actual' => $fin[$k]['all'] ?? 0,
        ];
    }
}

// Save unified cache file
$fullData = [
    'machines'   => $machinesData,
    'production' => $productionData,
    'updated_at' => date('Y-m-d H:i:s'),
];
if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
file_put_contents($cacheFile, json_encode($fullData, JSON_UNESCAPED_UNICODE));

// Also write legacy budget_monthly.json
$legacyFin = $machinesData['TOTAL']['financials'];
$legacyOutput = [
    'production' => $productionData,
    'financials' => $legacyFin,
    'updated_at' => date('Y-m-d H:i:s'),
];
file_put_contents(__DIR__ . '/data/budget_monthly.json', json_encode($legacyOutput, JSON_UNESCAPED_UNICODE));

$respData = $machinesData[$reqMachine];
$respData['production'] = $productionData;

echo json_encode([
    'success'     => true,
    'machine'     => $reqMachine,
    'data'        => $respData,
    'machines'    => ['TOTAL', 'BHS', 'ISOWA', 'YUELI'],
    'updated_at'  => date('Y-m-d H:i:s'),
    'cached'      => false
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
