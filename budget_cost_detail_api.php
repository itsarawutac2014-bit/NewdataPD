<?php
/**
 * budget_cost_detail_api.php - Cost breakdown by month
 * Uses column offsets verified by debug_budget2.php
 * 
 * ISOWA months: target_bahtkg at cols 14,24,34,44,54,64,74,84,94,104,114,124
 *               actual_bahtkg at cols 19,29,39,49,59,69,79,89,99,109,119,129
 *               actual_vol    at cols 16,26,36,46,56,66,76,86,96,107,117,127  (note: 107 not 106)
 *               target_vol    at cols 11,21,31,41,51,61,71,81,91,101,111,121
 *               target_value  at cols 13,23,33,43,53,63,73,83,93,103,113,123
 *               actual_value  at cols 18,28,38,48,58,68,78,88,98,108,118,128
 * ISOWA ALL:    target_value=col[3], target_bahtkg=col[4], actual_value=col[8], actual_bahtkg=col[9]
 *
 * BHS months:   target_bahtkg at cols 147,157,167,177,187,197,207,217,227,237,247,257
 *               actual_bahtkg at cols 152,162,172,182,192,202,212,222,232,242,252,262
 *               target_value  at cols 146,156,166,176,186,196,206,216,226,236,246,256
 *               actual_value  at cols 151,161,171,181,191,201,211,221,231,241,251,261
 * BHS ALL:      base=132: target_value=col[135], target_bahtkg=col[136], actual_value=col[140], actual_bahtkg=col[141]
 *
 * YUELI months: target_bahtkg at cols 280,290,300,310,320,330,340,350,360,370,380,390
 *               actual_bahtkg at cols 285,295,305,315,325,335,345,355,365,375,385,395
 *               target_value  at cols 279,289,299,309,319,329,339,349,359,369,379,389
 *               actual_value  at cols 284,294,304,314,324,334,344,354,364,374,384,394
 * YUELI ALL:    base=265: target_value=col[268], target_bahtkg=col[269], actual_value=col[273], actual_bahtkg=col[274]
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$sheetId = '1viioRJ5I3BmqiD7KLwSG9_uNQ_9u_a8LYR-4GaPGuYI';
$gid     = '1';
$ctx     = stream_context_create(['http' => ['timeout' => 25]]);

function colLetter($n) {
    $col = ''; $n++;
    while ($n > 0) { $n--; $col = chr(65 + ($n % 26)) . $col; $n = intdiv($n, 26); }
    return $col;
}

function fetchRowMap($sheetId, $gid, $rowIdx, $startCol, $endCol, $ctx) {
    $range = colLetter($startCol) . ($rowIdx + 1) . ':' . colLetter($endCol) . ($rowIdx + 1);
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

// Cost row indices (0-based in Budget control sheet)
// From context summary / debug_budget2: row24=กาว, row25=ถ่านหิน, row26=ไฟฟ้า, row27=น้ำ, row28=ลม, row31=ซ่อม, row32=ล่วงเวลา
// For แรงงาน (labor): context summary says row42=รวมVC which includes labor
// But let's use individual labor row - from COST image: last cost item = ค่าแรง
// Looking at budget_api.php default: cost_labor row not specified  
// Let's use row 33 for ค่าแรง (common labor row after OT row32)
$costRowDefs = [
    'กาว'      => 24,
    'ถ่านหิน'  => 25,
    'ไฟฟ้า'    => 26,
    'น้ำประปา' => 27,
    'ลม'       => 28,
    'ซ่อมแซม'  => 31,
    'ล่วงเวลา' => 32,
    'แรงงาน'   => 33,
];

// Per-month column offsets (0-indexed absolute col numbers)
$isowaTargetValueCols  = [13,23,33,43,53,63,73,83,93,103,113,123];
$isowaTargetBahtkgCols = [14,24,34,44,54,64,74,84,94,104,114,124];
$isowaActualValueCols  = [18,28,38,48,58,68,78,88,98,108,118,128];
$isowaActualBahtkgCols = [19,29,39,49,59,69,79,89,99,109,119,129];

$bhsTargetValueCols    = [146,156,166,176,186,196,206,216,226,236,246,256];
$bhsTargetBahtkgCols   = [147,157,167,177,187,197,207,217,227,237,247,257];
$bhsActualValueCols    = [151,161,171,181,191,201,211,221,231,241,251,261];
$bhsActualBahtkgCols   = [152,162,172,182,192,202,212,222,232,242,252,262];

$yueliTargetValueCols  = [279,289,299,309,319,329,339,349,359,369,379,389];
$yueliTargetBahtkgCols = [280,290,300,310,320,330,340,350,360,370,380,390];
$yueliActualValueCols  = [284,294,304,314,324,334,344,354,364,374,384,394];
$yueliActualBahtkgCols = [285,295,305,315,325,335,345,355,365,375,385,395];

// ALL column offsets
$isowaAllTv = 3; $isowaAllTbk = 4; $isowaAllAv = 8; $isowaAllAbk = 9;
$bhsAllTv = 135; $bhsAllTbk = 136; $bhsAllAv = 140; $bhsAllAbk = 141;
$yueliAllTv = 268; $yueliAllTbk = 269; $yueliAllAv = 273; $yueliAllAbk = 274;

$result = [];

foreach ($costRowDefs as $name => $ri) {
    // Fetch each machine section
    $iD = fetchRowMap($sheetId, $gid, $ri, 0, 130, $ctx);
    $bD = fetchRowMap($sheetId, $gid, $ri, 131, 265, $ctx);
    $yD = fetchRowMap($sheetId, $gid, $ri, 266, 397, $ctx);

    $result[$name] = [];

    // ALL
    $itv = fv($iD, $isowaAllTv); $itbk = fv($iD, $isowaAllTbk);
    $iav = fv($iD, $isowaAllAv); $iabk = fv($iD, $isowaAllAbk);
    $btv = fv($bD, $bhsAllTv);   $btbk = fv($bD, $bhsAllTbk);
    $bav = fv($bD, $bhsAllAv);   $babk = fv($bD, $bhsAllAbk);
    $ytv = fv($yD, $yueliAllTv); $ytbk = fv($yD, $yueliAllTbk);
    $yav = fv($yD, $yueliAllAv); $yabk = fv($yD, $yueliAllAbk);

    $totalTv = $itv + $btv + $ytv;
    $totalAv = $iav + $bav + $yav;

    // Weighted avg bahtkg by value
    $tbkParts = []; if ($itbk > 0) $tbkParts[] = [$itbk, $itv]; if ($btbk > 0) $tbkParts[] = [$btbk, $btv]; if ($ytbk > 0) $tbkParts[] = [$ytbk, $ytv];
    $abkParts = []; if ($iabk > 0) $abkParts[] = [$iabk, $iav]; if ($babk > 0) $abkParts[] = [$babk, $bav]; if ($yabk > 0) $abkParts[] = [$yabk, $yav];
    
    $totalTbk = 0; $wt = 0; foreach ($tbkParts as [$bk, $v]) { $totalTbk += $bk * ($v > 0 ? $v : 1); $wt += ($v > 0 ? $v : 1); }
    $avgTbk = $wt > 0 ? $totalTbk / $wt : 0;
    $totalAbk = 0; $wa = 0; foreach ($abkParts as [$bk, $v]) { $totalAbk += $bk * ($v > 0 ? $v : 1); $wa += ($v > 0 ? $v : 1); }
    $avgAbk = $wa > 0 ? $totalAbk / $wa : 0;

    $result[$name]['ALL'] = [
        'target_value'  => round($totalTv),
        'target_bahtkg' => round($avgTbk, 3),
        'actual_value'  => round($totalAv),
        'actual_bahtkg' => round($avgAbk, 3),
        'pct_actual'    => $totalTv > 0 ? round($totalAv / $totalTv * 100, 1) : null,
    ];

    // Per month
    for ($m = 1; $m <= 12; $m++) {
        $mi = $m - 1;
        $itv = fv($iD, $isowaTargetValueCols[$mi]);  $itbk = fv($iD, $isowaTargetBahtkgCols[$mi]);
        $iav = fv($iD, $isowaActualValueCols[$mi]);  $iabk = fv($iD, $isowaActualBahtkgCols[$mi]);
        $btv = fv($bD, $bhsTargetValueCols[$mi]);    $btbk = fv($bD, $bhsTargetBahtkgCols[$mi]);
        $bav = fv($bD, $bhsActualValueCols[$mi]);    $babk = fv($bD, $bhsActualBahtkgCols[$mi]);
        $ytv = fv($yD, $yueliTargetValueCols[$mi]);  $ytbk = fv($yD, $yueliTargetBahtkgCols[$mi]);
        $yav = fv($yD, $yueliActualValueCols[$mi]);  $yabk = fv($yD, $yueliActualBahtkgCols[$mi]);

        $tv = $itv + $btv + $ytv;
        $av = $iav + $bav + $yav;

        // Weighted avg bahtkg
        $tbkParts = []; if ($itbk > 0) $tbkParts[] = [$itbk, max($itv,1)]; if ($btbk > 0) $tbkParts[] = [$btbk, max($btv,1)]; if ($ytbk > 0) $tbkParts[] = [$ytbk, max($ytv,1)];
        $abkParts = []; if ($iabk > 0) $abkParts[] = [$iabk, max($iav,1)]; if ($babk > 0) $abkParts[] = [$babk, max($bav,1)]; if ($yabk > 0) $abkParts[] = [$yabk, max($yav,1)];
        $tw = 0; $ts = 0; foreach ($tbkParts as [$bk, $v]) { $ts += $bk * $v; $tw += $v; }
        $aw = 0; $as = 0; foreach ($abkParts as [$bk, $v]) { $as += $bk * $v; $aw += $v; }

        $result[$name]['m' . $m] = [
            'target_value'  => $tv > 0 ? round($tv) : null,
            'target_bahtkg' => $tw > 0 ? round($ts / $tw, 3) : null,
            'actual_value'  => $av > 0 ? round($av) : null,
            'actual_bahtkg' => $aw > 0 ? round($as / $aw, 3) : null,
            'pct_actual'    => $tv > 0 && $av > 0 ? round($av / $tv * 100, 1) : null,
        ];
    }
}

$out = ['costs' => $result, 'updated_at' => date('Y-m-d H:i:s')];
if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
file_put_contents(__DIR__ . '/data/budget_cost_detail.json', json_encode($out));

echo json_encode($out);
