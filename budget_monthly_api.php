<?php
/**
 * budget_monthly_api.php - Unified Multi-Machine Budget Control API
 * 
 * Sources data from 5 Google Sheet tabs:
 *   - Target Out-put      (gid=1028178886)
 *   - con out             (gid=0)
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
header('Cache-Control: no-cache, no-store, must-revalidate');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$cacheFile = __DIR__ . '/data/budget_database_v2.json';
$force = isset($_GET['refresh']) && $_GET['refresh'] === '1';

$reqMachine = strtoupper(trim($_GET['machine'] ?? 'TOTAL'));
if (!in_array($reqMachine, ['TOTAL', 'BHS', 'ISOWA', 'YUELI'])) {
    $reqMachine = 'TOTAL';
}

function cleanNum($v) {
    if ($v === null || $v === '' || $v === '-') return 0;
    $s = str_replace([',', ' ', '%', '#'], '', trim((string)$v));
    return is_numeric($s) ? floatval($s) : 0;
}

// If not force refresh and cache file exists, serve immediately from budget_database_v2.json
if (!$force && file_exists($cacheFile)) {
    $cached = json_decode(@file_get_contents($cacheFile), true);
    if (!empty($cached['machines'][$reqMachine])) {
        echo json_encode([
            'success'     => true,
            'machine'     => $reqMachine,
            'data'        => $cached['machines'][$reqMachine],
            'machines'    => ['TOTAL', 'BHS', 'ISOWA', 'YUELI'],
            'updated_at'  => $cached['updated_at'] ?? date('Y-m-d H:i:s'),
            'cached'      => true
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Fetch from Google Sheets if force or cache missing
$sheetId = '1viioRJ5I3BmqiD7KLwSG9_uNQ_9u_a8LYR-4GaPGuYI';
$ctx = stream_context_create(['http' => ['timeout' => 25]]);

function fetchCsvData($sheetId, $gid, $ctx) {
    $url = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$gid}";
    $csv = @file_get_contents($url, false, $ctx);
    if (!$csv) return [];
    $lines = explode("\n", $csv);
    $rows = [];
    foreach ($lines as $line) {
        $rows[] = str_getcsv($line);
    }
    return $rows;
}

// 1. Target Out-put
$targetRows = fetchCsvData($sheetId, 1028178886, $ctx);
$targets = ['TOTAL' => [], 'BHS' => [], 'ISOWA' => [], 'YUELI' => []];
for ($m = 1; $m <= 12; $m++) {
    $r = $targetRows[$m + 1] ?? [];
    $isoTon = cleanNum($r[1] ?? 0);
    $bhsTon = cleanNum($r[2] ?? 0);
    $yueTon = cleanNum($r[3] ?? 0);
    $totTon = $isoTon + $bhsTon + $yueTon;

    $isoOut = $isoTon * 1000;
    $bhsOut = $bhsTon * 1000;
    $yueOut = $yueTon * 1000;
    $totOut = $totTon * 1000;

    $targets['ISOWA'][$m] = ['output' => $isoOut, 'consum' => round($isoOut / 0.89, 2)];
    $targets['BHS'][$m]   = ['output' => $bhsOut, 'consum' => round($bhsOut / 0.89, 2)];
    $targets['YUELI'][$m] = ['output' => $yueOut, 'consum' => round($yueOut / 0.89, 2)];
    $targets['TOTAL'][$m] = ['output' => $totOut, 'consum' => round($totOut / 0.89, 2)];
}

// 2. con out
$conOutRows = fetchCsvData($sheetId, 0, $ctx);
$actuals = ['TOTAL' => [], 'BHS' => [], 'ISOWA' => [], 'YUELI' => []];
for ($m = 1; $m <= 12; $m++) {
    foreach (['TOTAL', 'BHS', 'ISOWA', 'YUELI'] as $mach) {
        $actuals[$mach][$m] = ['consum' => 0, 'output' => 0, 'waste_all' => 0, 'waste_pd' => 0];
    }
}

for ($i = 1; $i < count($conOutRows); $i++) {
    $row = $conOutRows[$i];
    if (empty($row[0])) continue;
    $dateStr = trim($row[0]);
    if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $dateStr, $dm)) {
        $month = intval($dm[2]);
        if ($month < 1 || $month > 12) continue;

        $c_iso = cleanNum($row[1] ?? 0);
        $o_iso = cleanNum($row[2] ?? 0);
        $wall_iso = cleanNum($row[3] ?? 0);
        $wpd_iso  = cleanNum($row[5] ?? 0);

        $c_bhs = cleanNum($row[7] ?? 0);
        $o_bhs = cleanNum($row[8] ?? 0);
        $wall_bhs = cleanNum($row[9] ?? 0);
        $wpd_bhs  = cleanNum($row[11] ?? 0);

        $c_yue = cleanNum($row[13] ?? 0);
        $o_yue = cleanNum($row[14] ?? 0);
        $wall_yue = cleanNum($row[15] ?? 0);
        $wpd_yue  = cleanNum($row[17] ?? 0);

        $actuals['ISOWA'][$month]['consum'] += $c_iso;
        $actuals['ISOWA'][$month]['output'] += $o_iso;
        $actuals['ISOWA'][$month]['waste_all'] += $wall_iso;
        $actuals['ISOWA'][$month]['waste_pd'] += $wpd_iso;

        $actuals['BHS'][$month]['consum'] += $c_bhs;
        $actuals['BHS'][$month]['output'] += $o_bhs;
        $actuals['BHS'][$month]['waste_all'] += $wall_bhs;
        $actuals['BHS'][$month]['waste_pd'] += $wpd_bhs;

        $actuals['YUELI'][$month]['consum'] += $c_yue;
        $actuals['YUELI'][$month]['output'] += $o_yue;
        $actuals['YUELI'][$month]['waste_all'] += $wall_yue;
        $actuals['YUELI'][$month]['waste_pd'] += $wpd_yue;

        $actuals['TOTAL'][$month]['consum'] += ($c_iso + $c_bhs + $c_yue);
        $actuals['TOTAL'][$month]['output'] += ($o_iso + $o_bhs + $o_yue);
        $actuals['TOTAL'][$month]['waste_all'] += ($wall_iso + $wall_bhs + $wall_yue);
        $actuals['TOTAL'][$month]['waste_pd'] += ($wpd_iso + $wpd_bhs + $wpd_yue);
    }
}

// 3. Financials
$finSheets = [
    'BHS'   => fetchCsvData($sheetId, 1882565873, $ctx),
    'ISOWA' => fetchCsvData($sheetId, 1941831190, $ctx),
    'YUELI' => fetchCsvData($sheetId, 898712953, $ctx),
];

$finKeys = ['revenue', 'paper_cost', 'sell_fg', 'profit_paper', 'running_cost', 'profit_net'];
$finRowMap = [
    'revenue'      => 1,
    'paper_cost'   => 2,
    'sell_fg'      => 3,
    'profit_paper' => 4,
    'running_cost' => 5,
    'profit_net'   => 6,
];

$financials = ['TOTAL' => [], 'BHS' => [], 'ISOWA' => [], 'YUELI' => []];

foreach (['BHS', 'ISOWA', 'YUELI'] as $mach) {
    $rows = $finSheets[$mach];
    foreach ($finKeys as $key) {
        $rIdx = $finRowMap[$key];
        $r = $rows[$rIdx] ?? [];
        $financials[$mach][$key] = [
            'actual' => [],
            'all'    => cleanNum($r[14] ?? 0),
        ];
        for ($m = 1; $m <= 12; $m++) {
            $val = cleanNum($r[$m + 1] ?? 0);
            $financials[$mach][$key]['actual'][$m] = $val;
            $financials[$mach][$key]['m' . $m] = ['actual' => $val];
        }
    }
}

foreach ($finKeys as $key) {
    $financials['TOTAL'][$key] = [
        'actual' => [],
        'all'    => ($financials['BHS'][$key]['all'] ?? 0) + ($financials['ISOWA'][$key]['all'] ?? 0) + ($financials['YUELI'][$key]['all'] ?? 0),
    ];
    for ($m = 1; $m <= 12; $m++) {
        $totVal = ($financials['BHS'][$key]['actual'][$m] ?? 0) + ($financials['ISOWA'][$key]['actual'][$m] ?? 0) + ($financials['YUELI'][$key]['actual'][$m] ?? 0);
        $financials['TOTAL'][$key]['actual'][$m] = $totVal;
        $financials['TOTAL'][$key]['m' . $m] = ['actual' => $totVal];
    }
}

// Build database
$fullDatabase = [
    'updated_at' => date('Y-m-d H:i:s'),
    'machines'   => [],
];

$pdWasteMonthlyKg = [
    'TOTAL' => [1 => 484474, 2 => 483724, 3 => 468505, 4 => 405690, 5 => 402522, 6 => 288802, 7 => 385780, 8 => 342766, 9 => 348752, 10 => 324808, 11 => 11841, 12 => 0],
    'BHS'   => [1 => 214584, 2 => 198166, 3 => 222742, 4 => 189167, 5 => 171718, 6 => 138341, 7 => 181834, 8 => 180906, 9 => 170811, 10 => 155404, 11 => 5421, 12 => 0],
    'YUELI' => [1 => 217613, 2 => 216929, 3 => 196626, 4 => 125595, 5 => 201906, 6 => 145081, 7 => 148243, 8 => 153256, 9 => 174193, 10 => 162408, 11 => 5907, 12 => 0],
    'ISOWA' => [1 => 0, 2 => 1445, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 6478, 11 => 500, 12 => 0],
];

foreach (['TOTAL', 'BHS', 'ISOWA', 'YUELI'] as $mach) {
    $prod = [];
    $allConT = 0; $allOutT = 0; $allConA = 0; $allOutA = 0; $allWallA = 0; $allWpdA = 0;

    for ($m = 1; $m <= 12; $m++) {
        $cT = $targets[$mach][$m]['consum'] ?? 0;
        $oT = $targets[$mach][$m]['output'] ?? 0;
        $cA = $actuals[$mach][$m]['consum'] ?? 0;
        $oA = $actuals[$mach][$m]['output'] ?? 0;
        $wallA = $actuals[$mach][$m]['waste_all'] ?? 0;
        $wpdA  = $pdWasteMonthlyKg[$mach][$m] ?? 0;

        $allConT += $cT;
        $allOutT += $oT;
        $allConA += $cA;
        $allOutA += $oA;
        $allWallA += $wallA;
        $allWpdA += $wpdA;

        $prod['m' . $m] = [
            'consumption_target' => $cT,
            'output_target'      => $oT,
            'consumption_actual' => $cA,
            'output_actual'      => $oA,
            'waste_all_actual'   => $wallA,
            'waste_pd_actual'    => $wpdA,
            'waste_all_pct'      => $cA > 0 ? round(($wallA / $cA) * 100, 2) : 0,
            'waste_pd_pct'       => $cA > 0 ? round(($wpdA / $cA) * 100, 2) : 0,
            // Backwards compatibility aliases
            'waste_pct_actual'   => $wallA,
            'output_pd_actual'   => $oA,
        ];
    }

    $prod['ALL'] = [
        'consumption_target' => $allConT,
        'output_target'      => $allOutT,
        'consumption_actual' => $allConA,
        'output_actual'      => $allOutA,
        'waste_all_actual'   => $allWallA,
        'waste_pd_actual'    => $allWpdA,
        'waste_all_pct'      => $allConA > 0 ? round(($allWallA / $allConA) * 100, 2) : 0,
        'waste_pd_pct'       => $allConA > 0 ? round(($allWpdA / $allConA) * 100, 2) : 0,
        'waste_pct_actual'   => $allWallA,
        'output_pd_actual'   => $allOutA,
    ];

    $fullDatabase['machines'][$mach] = [
        'machine'    => $mach,
        'production' => $prod,
        'financials' => $financials[$mach] ?? [],
    ];
}

@file_put_contents($cacheFile, json_encode($fullDatabase, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode([
    'success'     => true,
    'machine'     => $reqMachine,
    'data'        => $fullDatabase['machines'][$reqMachine],
    'machines'    => ['TOTAL', 'BHS', 'ISOWA', 'YUELI'],
    'updated_at'  => $fullDatabase['updated_at'],
    'cached'      => false
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
