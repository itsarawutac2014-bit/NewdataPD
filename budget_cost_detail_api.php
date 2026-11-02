<?php
/**
 * budget_cost_detail_api.php - Cost breakdown by month (FIXED row indices)
 * 
 * KEY: fetchRowBySheetRow($sheetRow) fetches using exact range "A{sheetRow}:OJ{sheetRow}"
 * This avoids gviz empty-row-skipping issue.
 *
 * Verified sheet rows (1-indexed) for Budget control (gid=1):
 *   กาว      = sheet row 32
 *   ถ่านหิน  = sheet row 33
 *   ไฟฟ้า    = sheet row 34
 *   น้ำประปา = sheet row 35
 *   ลม       = sheet row 36
 *   ซ่อมแซม  = sheet row 39
 *   ล่วงเวลา = sheet row 40
 *   แรงงาน   = sheet row 52 (รวม ค่าใช้จ่าย VC)
 *
 * Column structure (same for all rows with cost data):
 *   ISOWA Jan block base = col 11 (0-indexed)
 *     target: [base+2]=value, [base+3]=bahtkg
 *     actual: [base+7]=value, [base+8]=bahtkg
 *   Feb = base+10 = 21, Mar = 31, ...
 *
 *   ISOWA ALL: target_value=col[3], target_bahtkg=col[4], actual_value=col[8], actual_bahtkg=col[9]
 *   BHS   Jan base = col 144, ALL base = col 132
 *   YUELI Jan base = col 277, ALL base = col 265
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$cacheFile = __DIR__ . '/data/budget_cost_detail.json';
$force = isset($_GET['refresh']) && $_GET['refresh'] === '1';
if (!$force && file_exists($cacheFile)) {
    readfile($cacheFile);
    exit;
}

$sheetId = '1viioRJ5I3BmqiD7KLwSG9_uNQ_9u_a8LYR-4GaPGuYI';
$gid     = '1';
$ctx     = stream_context_create(['http' => ['timeout' => 25]]);

function colLetter($n) {
    $col = ''; $n++;
    while ($n > 0) { $n--; $col = chr(65 + ($n % 26)) . $col; $n = intdiv($n, 26); }
    return $col;
}

// Fetch a row by EXACT sheet row number (1-indexed) using explicit range
function fetchRowBySheetRow($sheetId, $gid, $sheetRow, $startCol, $endCol, $ctx) {
    $range = colLetter($startCol) . $sheetRow . ':' . colLetter($endCol) . $sheetRow;
    $url   = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:json&gid={$gid}&range={$range}";
    $raw   = @file_get_contents($url, false, $ctx);
    if (!$raw) return [];
    $json  = preg_replace('/^[^(]+\(|\);?\s*$/s', '', $raw);
    $d     = json_decode($json, true);
    $cells = isset($d['table']['rows'][0]['c']) ? $d['table']['rows'][0]['c'] : [];
    $map   = [];
    foreach ($cells as $i => $cell) {
        $map[$startCol + $i] = isset($cell['v']) ? $cell['v'] : null;
    }
    return $map;
}

function fv($map, $col) { return isset($map[$col]) && $map[$col] !== null ? floatval($map[$col]) : 0; }
function fvn($map, $col) { return isset($map[$col]) && $map[$col] !== null && $map[$col] !== '' ? floatval($map[$col]) : null; }

// =====================================================================
// Cost items: sheet rows (1-indexed), in exact visual order as reference
// =====================================================================
$costItems = [
    'ถ่านหิน'              => 33,
    'กาว'                  => 32,
    'ค่าน้ำประปา'          => 35,
    'ค่าลม'                => 36,
    'ค่าไฟฟ้า'              => 34,
    'ค่าซ่อมแซมเครื่องจักร' => 39,
    'ค่าล่วงเวลา'           => 40,
    'ค่าแรง'               => 52,   // รวม ค่าใช้จ่าย VC
    'Total VC+FC'          => 60,   // TOTAL VC+FC
];

// Per-month column offsets (0-indexed absolute col numbers)
// ISOWA: Jan base=11, each month +10. Within block: [base+2]=target_value, [base+3]=target_bahtkg, [base+7]=actual_value, [base+8]=actual_bahtkg
// BHS:   Jan base=144, same offsets
// YUELI: Jan base=277, same offsets

$isowaMonthBase = []; for ($m=1;$m<=12;$m++) $isowaMonthBase[$m] = 11 + ($m-1)*10;
$bhsMonthBase   = []; for ($m=1;$m<=12;$m++) $bhsMonthBase[$m]   = 144 + ($m-1)*10;
$yueliMonthBase = []; for ($m=1;$m<=12;$m++) $yueliMonthBase[$m] = 277 + ($m-1)*10;

// ALL column bases
$isowaAllBase = 0;    // ISOWA ALL: target_value=col[3], target_bahtkg=col[4], actual_value=col[8], actual_bahtkg=col[9]
$bhsAllBase   = 132;  // BHS   ALL: target_value=col[135], target_bahtkg=col[136], actual_value=col[140], actual_bahtkg=col[141]
$yueliAllBase = 265;  // YUELI ALL: target_value=col[268], target_bahtkg=col[269], actual_value=col[273], actual_bahtkg=col[274]

$result = [];

foreach ($costItems as $name => $sheetRow) {
    // Fetch each machine section
    $iD = fetchRowBySheetRow($sheetId, $gid, $sheetRow, 0,   130, $ctx);
    $bD = fetchRowBySheetRow($sheetId, $gid, $sheetRow, 131, 264, $ctx);
    $yD = fetchRowBySheetRow($sheetId, $gid, $sheetRow, 265, 397, $ctx);

    $result[$name] = [];

    // ALL
    $itv  = fv($iD, 3);   $itbk = fv($iD, 4);
    $iav  = fv($iD, 8);   $iabk = fv($iD, 9);
    $btv  = fv($bD, 135); $btbk = fv($bD, 136);
    $bav  = fv($bD, 140); $babk = fv($bD, 141);
    $ytv  = fv($yD, 268); $ytbk = fv($yD, 269);
    $yav  = fv($yD, 273); $yabk = fv($yD, 274);

    $totalTv = $itv + $btv + $ytv;
    $totalAv = $iav + $bav + $yav;

    // Weighted avg bahtkg
    $tbkW = 0; $tbkS = 0;
    foreach ([[$itbk,$itv],[$btbk,$btv],[$ytbk,$ytv]] as [$bk,$v]) { if ($bk > 0 && $v > 0) { $tbkS += $bk*$v; $tbkW += $v; } }
    $abkW = 0; $abkS = 0;
    foreach ([[$iabk,$iav],[$babk,$bav],[$yabk,$yav]] as [$bk,$v]) { if ($bk > 0 && $v > 0) { $abkS += $bk*$v; $abkW += $v; } }
    $avgTbk = $tbkW > 0 ? $tbkS/$tbkW : 0;
    $avgAbk = $abkW > 0 ? $abkS/$abkW : 0;

    $result[$name]['ALL'] = [
        'target_value'  => $totalTv > 0 ? round($totalTv) : null,
        'target_bahtkg' => $avgTbk > 0 ? round($avgTbk, 3) : null,
        'actual_value'  => $totalAv > 0 ? round($totalAv) : null,
        'actual_bahtkg' => $avgAbk > 0 ? round($avgAbk, 3) : null,
        'pct_actual'    => $totalTv > 0 && $totalAv > 0 ? round($totalAv/$totalTv*100, 1) : null,
    ];

    // Per month
    for ($m = 1; $m <= 12; $m++) {
        $ib = $isowaMonthBase[$m];
        $bb = $bhsMonthBase[$m];
        $yb = $yueliMonthBase[$m];

        // target: base+2=value, base+3=bahtkg; actual: base+7=value, base+8=bahtkg
        $itv  = fv($iD, $ib+2); $itbk = fvn($iD, $ib+3);
        $iav  = fv($iD, $ib+7); $iabk = fvn($iD, $ib+8);
        $btv  = fv($bD, $bb+2); $btbk = fvn($bD, $bb+3);
        $bav  = fv($bD, $bb+7); $babk = fvn($bD, $bb+8);
        $ytv  = fv($yD, $yb+2); $ytbk = fvn($yD, $yb+3);
        $yav  = fv($yD, $yb+7); $yabk = fvn($yD, $yb+8);

        $tv = $itv + $btv + $ytv;
        $av = $iav + $bav + $yav;

        // Weighted avg bahtkg
        $tbkW = 0; $tbkS = 0;
        foreach ([[$itbk,$itv],[$btbk,$btv],[$ytbk,$ytv]] as [$bk,$v]) { if ($bk !== null && $bk > 0) { $w = $v > 0 ? $v : 1; $tbkS += $bk*$w; $tbkW += $w; } }
        $abkW = 0; $abkS = 0;
        foreach ([[$iabk,$iav],[$babk,$bav],[$yabk,$yav]] as [$bk,$v]) { if ($bk !== null && $bk > 0) { $w = $v > 0 ? $v : 1; $abkS += $bk*$w; $abkW += $w; } }

        $result[$name]['m'.$m] = [
            'target_value'  => $tv > 0 ? round($tv) : null,
            'target_bahtkg' => $tbkW > 0 ? round($tbkS/$tbkW, 3) : null,
            'actual_value'  => $av > 0 ? round($av) : null,
            'actual_bahtkg' => $abkW > 0 ? round($abkS/$abkW, 3) : null,
            'pct_actual'    => $tv > 0 && $av > 0 ? round($av/$tv*100, 1) : null,
        ];
    }
}

$out = ['costs' => $result, 'updated_at' => date('Y-m-d H:i:s')];
if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
file_put_contents(__DIR__ . '/data/budget_cost_detail.json', json_encode($out));

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
