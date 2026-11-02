<?php
/**
 * budget_monthly_api.php - 12-month summary for Budget Control
 * 
 * KEY: Use fetchRowBySheetRow() with exact 1-indexed sheet row numbers
 * to avoid gviz empty-row-skipping issue.
 *
 * Verified sheet rows (1-indexed) for Budget control (gid=1):
 *   Row 1-8:  ISOWA header + production rows
 *   Production (all machines summed):
 *     consumption  = rows that use block structure
 *     ISOWA Jan block base = col 11 (0-indexed); each month +10
 *     BHS   Jan block base = col 144; YUELI = col 277
 *     target_qty = base+0, actual_qty = base+5
 *   
 *   Financial sheet rows (1-indexed):
 *     รวมรายได้       = sheet row 12  (col base+2=target_value, base+7=actual_value)
 *     รวมต้นทุน       = sheet row 21
 *     TOTAL VC+FC     = sheet row 60  (running cost)
 *     กำไรสุทธิ       = sheet row 72
 *
 * Production sheet rows (only non-empty rows matter):
 *   Actual: Row 1 = consumption (gviz row 0), Row 2 = OUTPUT (gviz row 1), Row 3 = waste (gviz row 2)...
 *   But we fetch by machine block columns, not by label.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$cacheFile = __DIR__ . '/data/budget_monthly.json';
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

// Fetch by exact sheet row number (1-indexed)
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
function fvn($map, $col) { $v = $map[$col] ?? null; return ($v !== null && $v !== '' && $v !== '-') ? floatval($v) : null; }

// =====================================================================
// Production rows - gviz sheet rows (1-indexed):
// Sheet has an "ISOWA" header at row 1 (col A = "ISOWA")
// Then production rows:
//   sheet row 2 = consumption (gviz row 1 when fetching from A2)
//   sheet row 3 = OUTPUT
//   sheet row 4 = waste Con-out
//   sheet row 5 = waste to bailer
//   sheet row 6 = OUTPUT PD
//   sheet row 7 = Waste PD
// When we fetch range "A2:OJ2", we get the consumption row.
// =====================================================================

// Production sheet rows (1-indexed, verified by scanning)
$prodSheetRows = [
    'con'     => 3,   // consumption: sheet row 3
    'out'     => 4,   // OUTPUT: sheet row 4
    'waste'   => 5,   // waste Con-out: sheet row 5
    'wailer'  => 6,   // waste to bailer: sheet row 6
    'outpd'   => 7,   // OUTPUT PD: sheet row 7
    'wastepd' => 8,   // Waste PD: sheet row 8
];

// Machine block bases (0-indexed column)
$isowaMonthBase = []; for ($m=1;$m<=12;$m++) $isowaMonthBase[$m] = 11 + ($m-1)*10;
$bhsMonthBase   = []; for ($m=1;$m<=12;$m++) $bhsMonthBase[$m]   = 144 + ($m-1)*10;
$yueliMonthBase = []; for ($m=1;$m<=12;$m++) $yueliMonthBase[$m] = 277 + ($m-1)*10;

// ALL columns (0-indexed)
// ISOWA ALL: col[0]=ISOWA label, [1]=target_qty, [6]=actual_qty
// BHS   ALL: col[132]=label, [133]=target_qty, [138]=actual_qty
// YUELI ALL: col[265]=label, [266]=target_qty, [271]=actual_qty

// Fetch all production rows
$prodData = [];
foreach ($prodSheetRows as $key => $sheetRow) {
    $prodData[$key]['I'] = fetchRowBySheetRow($sheetId, $gid, $sheetRow, 0,   130, $ctx);
    $prodData[$key]['B'] = fetchRowBySheetRow($sheetId, $gid, $sheetRow, 131, 265, $ctx);
    $prodData[$key]['Y'] = fetchRowBySheetRow($sheetId, $gid, $sheetRow, 266, 397, $ctx);
}

function getProdQtyTotal($prodData, $key, $monthIdx) {
    global $isowaMonthBase, $bhsMonthBase, $yueliMonthBase;
    $iD = $prodData[$key]['I']; $bD = $prodData[$key]['B']; $yD = $prodData[$key]['Y'];
    
    if ($monthIdx === 0) {
        // ALL: ISOWA col[1]/[6], BHS col[133]/[138], YUELI col[266]/[271]
        $tI = fv($iD,1); $aI = fv($iD,6);
        $tB = fv($bD,133); $aB = fv($bD,138);
        $tY = fv($yD,266); $aY = fv($yD,271);
        return ['target'=>$tI+$tB+$tY, 'actual'=>$aI+$aB+$aY];
    }
    $ib = $isowaMonthBase[$monthIdx];
    $bb = $bhsMonthBase[$monthIdx];
    $yb = $yueliMonthBase[$monthIdx];
    // target: base+0=qty; actual: base+5=qty
    $tI = fvn($iD,$ib);   $aI = fvn($iD,$ib+5);
    $tB = fvn($bD,$bb);   $aB = fvn($bD,$bb+5);
    $tY = fvn($yD,$yb);   $aY = fvn($yD,$yb+5);
    $t = ($tI??0)+($tB??0)+($tY??0);
    $a = ($aI??0)+($aB??0)+($aY??0);
    return ['target'=>$t>0?$t:null, 'actual'=>$a>0?$a:null];
}

$production = [];
for ($m = 0; $m <= 12; $m++) {
    $label = $m === 0 ? 'ALL' : 'm'.$m;
    $con   = getProdQtyTotal($prodData, 'con',     $m);
    $out   = getProdQtyTotal($prodData, 'out',     $m);
    $waste = getProdQtyTotal($prodData, 'waste',   $m);
    $wastepd = getProdQtyTotal($prodData, 'wastepd',$m);
    $outpd   = getProdQtyTotal($prodData, 'outpd',  $m);
    $production[$label] = [
        'consumption_target' => $con['target'],
        'consumption_actual' => $con['actual'],
        'output_target'      => $out['target'],
        'output_actual'      => $out['actual'],
        'waste_pct_target'   => $waste['target'],  // absolute kg
        'waste_pct_actual'   => $waste['actual'],
        'waste_pd_actual'    => $wastepd['actual'],
        'output_pd_actual'   => $outpd['actual'],
    ];
}

// =====================================================================
// Financial rows - sheet rows (1-indexed), verified:
//   รวมรายได้  = sheet row 12
//   รวมต้นทุน  = sheet row 21
//   ขาย FG     = sheet row 26  (รวม FG ราคา SD)
//   กำไรหลักค่ากระดาษ = sheet row 30 (กำไรขั้นต้น)
//   running_cost (TOTAL VC+FC) = sheet row 60
//   กำไรสุทธิ  = sheet row 72
//
// Column offsets within each month block:
//   target_value = base+2, actual_value = base+7
// ALL columns:
//   ISOWA: target_value=col[3], actual_value=col[8]
//   BHS:   target_value=col[135], actual_value=col[140]
//   YUELI: target_value=col[268], actual_value=col[273]
// =====================================================================

$finDefs = [
    'revenue'      => 12,   // รวมรายได้
    'paper_cost'   => 21,   // รวมต้นทุน
    'sell_fg'      => 26,   // รวม FG (ราคา SD)
    'profit_paper' => 30,   // กำไรขั้นต้น
    'running_cost' => 60,   // TOTAL VC+FC
    'profit_net'   => 72,   // กำไรสุทธิ
];

$financials = [];
foreach ($finDefs as $key => $sheetRow) {
    $iD = fetchRowBySheetRow($sheetId, $gid, $sheetRow, 0,   130, $ctx);
    $bD = fetchRowBySheetRow($sheetId, $gid, $sheetRow, 131, 264, $ctx);
    $yD = fetchRowBySheetRow($sheetId, $gid, $sheetRow, 265, 397, $ctx);

    $financials[$key] = [];

    // ALL
    $it = fv($iD, 3);   $ia = fv($iD, 8);
    $bt = fv($bD, 135); $ba = fv($bD, 140);
    $yt = fv($yD, 268); $ya = fv($yD, 273);
    $financials[$key]['ALL'] = ['target'=>$it+$bt+$yt, 'actual'=>$ia+$ba+$ya];

    // Per month
    for ($m = 1; $m <= 12; $m++) {
        $ib = $isowaMonthBase[$m];
        $bb = $bhsMonthBase[$m];
        $yb = $yueliMonthBase[$m];
        $it = fv($iD, $ib+2); $ia = fv($iD, $ib+7);
        $bt = fv($bD, $bb+2); $ba = fv($bD, $bb+7);
        $yt = fv($yD, $yb+2); $ya = fv($yD, $yb+7);
        $financials[$key]['m'.$m] = ['target'=>$it+$bt+$yt, 'actual'=>$ia+$ba+$ya];
    }
}

$output = [
    'production' => $production,
    'financials' => $financials,
    'updated_at' => date('Y-m-d H:i:s'),
];

if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
file_put_contents(__DIR__ . '/data/budget_monthly.json', json_encode($output));
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
