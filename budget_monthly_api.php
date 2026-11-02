<?php
/**
 * budget_monthly_api.php
 * 12-month summary data for Budget Control page
 * Sheet structure (gid=1, Budget control):
 *   Row 0  = Month headers (JANUARY at col11, FEBRUARY at col21, ...)
 *   Row 1  = "TARGET 2026 / ACTUAL 2026" sub-header per month
 *   Row 2  = Consumption:  target=col11, actual=col16, next month +10
 *   Row 3  = Output:       target=col11, actual=col16, next month +10
 *   Row 4  = Waste Con-Out: target=col11(%), actual=col16(%)
 *   Row 5  = Waste to Bailer: actual=col16(%)
 *   Row 6  = Output PD:    actual=col16(%)
 *   Row 7  = Waste PD:     actual=col16(%)
 * January block starts at col 11, each month +10 cols
 * ISOWA ALL: col 2 (target), col 7 (actual)  -- rows 2-7
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

function fetchRangeToMap($sheetId, $gid, $rowIdx, $startCol, $endCol, $ctx) {
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

// Month → starting column index (JANUARY = col 11, each month +10)
// Month index 1-12, ALL uses col 2 (target) / col 7 (actual)
$monthStartCols = [];
for ($m = 1; $m <= 12; $m++) {
    $monthStartCols[$m] = 11 + ($m - 1) * 10;
}

// Within each month block:
//   target values : base + 0  (col 11, 21, 31, ...)
//   actual values : base + 5  (col 16, 26, 36, ...)
// ISOWA ALL: col 2 = target, col 7 = actual

// Data rows in Budget control sheet (0-indexed):
//   2 = Consumption
//   3 = Output
//   4 = Waste Con-Out
//   5 = Waste to Bailer (actual only)
//   6 = Output PD (actual only)
//   7 = Waste PD (actual only)

// Fetch each production row (rows 2-7) full ISOWA span (cols 0-130)
$prodRows = [2, 3, 4, 5, 6, 7];
$prodData = [];
foreach ($prodRows as $ri) {
    $prodData[$ri] = fetchRangeToMap($sheetId, $gid, $ri, 0, 130, $ctx);
}

function getProd($prodData, $rowIdx, $monthIdx) {
    // monthIdx 0=ALL, 1-12=months
    global $monthStartCols;
    $d = $prodData[$rowIdx] ?? [];
    if ($monthIdx === 0) {
        return ['target' => floatval($d[2] ?? 0), 'actual' => floatval($d[7] ?? 0)];
    }
    $base = $monthStartCols[$monthIdx];
    return [
        'target' => $d[$base]       !== null ? floatval($d[$base])     : null,
        'actual' => $d[$base + 5]   !== null ? floatval($d[$base + 5]) : null,
    ];
}

// Build production data per month
$production = [];
for ($m = 0; $m <= 12; $m++) {
    $label = ($m === 0) ? 'ALL' : 'm' . $m;
    $production[$label] = [
        'consumption_target' => getProd($prodData, 2, $m)['target'],
        'consumption_actual' => getProd($prodData, 2, $m)['actual'],
        'output_target'      => getProd($prodData, 3, $m)['target'],
        'output_actual'      => getProd($prodData, 3, $m)['actual'],
        'waste_pct_target'   => getProd($prodData, 4, $m)['target'],
        'waste_pct_actual'   => getProd($prodData, 4, $m)['actual'],
        'waste_pd_actual'    => getProd($prodData, 7, $m)['actual'],
        'output_pd_actual'   => getProd($prodData, 6, $m)['actual'],
    ];
}

// =====================================================================
// Financial rows from Budget control sheet
// Revenue row 11 (0-based): per month actual value col
//   ISOWA actual value cols per month: base+5+2 = base+7? 
//   Actually from earlier debug: row11 "รวมรายได้":
//     col[2]=19.38 (price), col[3]=184064709.45 (value), col[7]=19.44 (actual price), col[8] next...
//   Wait - from scan_jan_block row 9 showed: col11=จำนวน, col12=ราคา/หน่วย, col13=มูลค่า, col14=บาท/KG, col15=%
//                                              col16=จำนวน, col17=ราคา/หน่วย, col18=มูลค่า, col19=บาท/KG, col20=%
//   So within monthly block: target: [base]=qty, [base+1]=price, [base+2]=value, [base+3]=baht/kg, [base+4]=%
//                              actual: [base+5]=qty, [base+6]=price, [base+7]=value, [base+8]=baht/kg, [base+9]=%
//   For financial value (มูลค่า): target = base+2, actual = base+7
//   For ALL: ISOWA target value=col[2] (after col[0]=ISOWA label), actual value=col[7]
//     Wait need to verify - from earlier debug row11: [1]=9497623.4,[2]=19.38,[3]=184064709.45
//     So for ALL section col[3]=184064709.45 (target value) and actual value...
//     Actually col[2] in the ALL block = price, col[3] = value, col[7] = actual qty?
// 
// Let me use the data I already confirmed working from budget_monthly_api output:
// revenue actual m1=143797802.62 (from fetching row 11 ISOWA col 18, BHS col 151, YUELI col 284)
// But that was actual volume not value.
// 
// From the original debug (budget_api working): revenue for m1 = sum across machines
// Those values were summed using cols 18,28... which are "จำนวน" (volume), not value (มูลค่า)
// For value (มูลค่า): use col 18+2=20 for target per month? or base+7 for actual?
//
// From scan_jan_block row 9: actual มูลค่า is at col[18] (S) = base(11)+7 = 18 ✓
//                              target มูลค่า is at col[13] (N) = base(11)+2 = 13 ✓
// So for financial rows: target_value = base+2, actual_value = base+7
// =====================================================================

// Financial row indices (0-based in sheet):
// Need to find: revenue, paper_cost, profit_paper, running_cost, profit_net
// From earlier debug (get_financial_rows.php using A-H for rows 11-65):
//   Row 11 = รวมรายได้
//   Row 16 = รวมต้นทุน (purchase price)
//   Row 22 = ค่ากาว (cost item, not FG)
//   Row 52 = รวมค่าใช้จ่ายทั้งสิ้น = running cost
//   Row 60 = กำไรสุทธิ

// Correct row indices for the FULL sheet (0-based):
// From context summary: 11=รวมรายได้, 16=รวมต้นทุน, 17=กำไรหลักค่ากระดาษ, 22=รวม FG SD, 
//                        42=รวมVC, 52=รวมVC+FC, 55=รวมค่าใช้จ่าย, 60=กำไรสุทธิ

$finRowDefs = [
    'revenue'      => 11,
    'paper_cost'   => 16,
    'profit_paper' => 17,
    'sell_fg'      => 22,
    'running_cost' => 52,
    'profit_net'   => 60,
];

// BHS section starts at col 132, YUELI at col 265
// Within BHS: header at col 132, January block at col 132+11=143? or col 144?
// From context summary: BHS months target_bahtkg at 147,157,...
// So BHS base for January = 143 (147 = 143+4 for baht/kg), meaning BHS Jan block starts at col 143
// BHS target value (มูลค่า) = 143+2 = 145, actual value = 143+7 = 150
// For Feb: 143+10=153, target_val=155, actual_val=160

// Wait from context summary: BHS target_bahtkg at cols 147,157,... (monthly)
// BHS target cols per month: 146(qty),147(price),148(val?),149(baht/kg),150(%)
// Hmm but 146 to 147 is price, 148=value? Let me use baht/kg to re-derive:
// If baht/kg is col 147 = base+3 → base = 144
// So BHS Jan block starts at col 144:
//   target: [144]=qty, [145]=price, [146]=value, [147]=baht/kg, [148]=%
//   actual: [149]=qty, [150]=price, [151]=value, [152]=baht/kg, [153]=%
// WAIT context says actual_bahtkg=152 and actual_vol=150, so:
//   actual: [149]=qty, [150]=price_or_vol?, [151]=value, [152]=baht/kg, [153]=%
//   Actually: [149]=qty, [150]=something, [151]=value, [152]=baht/kg, [153]=%
//   So for BHS actual value: base+5+2 = base+7 = 144+7=151

// YUELI: target_bahtkg at 280, so base = 280-3 = 277
//   YUELI Jan block base = 277
//   actual value = 277+7 = 284

// For ALL columns:
//   ISOWA: col2=target_value_ALL?, col7=actual_value_ALL?
//   BHS: col135+2=137?, col135+7=142?
//   YUELI: col268+2=270?, col268+7=275?
// Need verification - use the confirmed working data instead

// Month value offsets (relative to month block base)
define('T_VAL_OFF', 2);  // target มูลค่า offset from block start
define('A_VAL_OFF', 7);  // actual มูลค่า offset from block start

// Machine block starts per month
$isowaBase = [];  // ISOWA month bases: Jan=11, Feb=21,...
$bhsBase   = [];  // BHS month bases: Jan=143+1=144? Let's use 144
$yueliBase = [];  // YUELI: 277
for ($m = 1; $m <= 12; $m++) {
    $isowaBase[$m] = 11  + ($m - 1) * 10;
    $bhsBase[$m]   = 144 + ($m - 1) * 10;
    $yueliBase[$m] = 277 + ($m - 1) * 10;
}

// ALL columns (0-indexed from start of sheet)
// ISOWA ALL: target_val at col 3, actual_val at col 8? Or col2/7?
// From earlier get_financial_rows debug (rows A-H for rows 11-65):
//   Row 11: [2]=19.38, [3]=184064709.45 → col[3] is target_value for ISOWA ALL ✓
//            [7]=19.44, [8]=88772876.16 → col[8] is actual_value for ISOWA ALL ✓ (wait that was BHS qty)
// Actually that was just cols A-H = [0]-[7] for ISOWA:
//   [0]=label, [1]=qty, [2]=price, [3]=value, [4]=baht/kg, [5]=%, [6]=actual_qty, [7]=actual_price
//   Missing: [8]=actual_value... so actual value for ISOWA ALL is beyond col 7
//   That means: ALL block has 10 cols too: target[0-4], actual[5-9]?
//   col[8] for ISOWA ALL actual_value = col index 8 (I in spreadsheet)
// Similarly BHS ALL: base=132, target_val=132+3=135, actual_val=132+8=140
// YUELI ALL: base=265, target_val=265+3=268, actual_val=265+8=273

$isowaAllBase = 0;
$bhsAllBase   = 132;
$yueliAllBase = 265;

function finVal($rowData, $base, $isTarget) {
    $off = $isTarget ? (T_VAL_OFF + 1) : (A_VAL_OFF + 1); 
    // +1 because ALL block: [0]=label, [1]=qty, [2]=price, [3]=value... so target_val = base+3
    // and actual [base+6]=qty,[base+7]=price,[base+8]=actual_val
    // For monthly block: base+0=qty, base+2=value for target; base+5=qty, base+7=value for actual
    // ALL is slightly different: base+0=label, base+1=qty, base+2=price, base+3=target_val
    //                             base+6=actual_qty, base+7=actual_price, base+8=actual_val
    return isset($rowData[$base + $off]) ? floatval($rowData[$base + $off]) : 0;
}

// Fetch financial rows
$financials = [];
foreach ($finRowDefs as $key => $ri) {
    // Fetch ISOWA section (0-130), BHS (131-264), YUELI (265-397)
    $iD = fetchRangeToMap($sheetId, $gid, $ri, 0, 130, $ctx);
    $bD = fetchRangeToMap($sheetId, $gid, $ri, 131, 264, $ctx);
    $yD = fetchRangeToMap($sheetId, $gid, $ri, 265, 397, $ctx);

    $financials[$key] = [];

    // ALL value
    $it = floatval($iD[$isowaAllBase + 3] ?? 0);
    $ia = floatval($iD[$isowaAllBase + 8] ?? 0);
    $bt = floatval($bD[$bhsAllBase   + 3] ?? 0);
    $ba = floatval($bD[$bhsAllBase   + 8] ?? 0);
    $yt = floatval($yD[$yueliAllBase + 3] ?? 0);
    $ya = floatval($yD[$yueliAllBase + 8] ?? 0);
    $financials[$key]['ALL'] = [
        'target' => $it + $bt + $yt,
        'actual' => $ia + $ba + $ya,
    ];

    // Per month
    for ($m = 1; $m <= 12; $m++) {
        $ib = $isowaBase[$m];  $bb = $bhsBase[$m];  $yb = $yueliBase[$m];
        $it = floatval($iD[$ib + T_VAL_OFF] ?? 0);
        $ia = floatval($iD[$ib + A_VAL_OFF] ?? 0);
        $bt = floatval($bD[$bb + T_VAL_OFF] ?? 0);
        $ba = floatval($bD[$bb + A_VAL_OFF] ?? 0);
        $yt = floatval($yD[$yb + T_VAL_OFF] ?? 0);
        $ya = floatval($yD[$yb + A_VAL_OFF] ?? 0);
        $financials[$key]['m' . $m] = [
            'target' => $it + $bt + $yt,
            'actual' => $ia + $ba + $ya,
        ];
    }
}

$output = [
    'production'  => $production,
    'financials'  => $financials,
    'updated_at'  => date('Y-m-d H:i:s'),
];

if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
file_put_contents(__DIR__ . '/data/budget_monthly.json', json_encode($output));

echo json_encode($output, JSON_PRETTY_PRINT);
