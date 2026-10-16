<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$sheetId = $_GET['sheetId'] ?? '1viioRJ5I3BmqiD7KLwSG9_uNQ_9u_a8LYR-4GaPGuYI';
$machine = strtoupper(trim($_GET['machine'] ?? 'BHS'));
$month = intval($_GET['month'] ?? 10);
if ($month < 1 || $month > 12) $month = 10;
$monthPad = str_pad($month, 2, '0', STR_PAD_LEFT);

$monthNamesTh = [
    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
    5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
    9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
];
$daysInMonth = [
    1 => 31, 2 => 28, 3 => 31, 4 => 30, 5 => 31, 6 => 30,
    7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31
];
$totalDays = $daysInMonth[$month] ?? 31;

$ctx = stream_context_create([
    'http' => [
        'timeout' => 15,
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n"
    ]
]);

// กรณีขอ raw sheet โดยตรง
if (isset($_GET['raw']) || isset($_GET['sheet'])) {
    $param = isset($_GET['sheet']) ? "sheet=" . urlencode($_GET['sheet']) : "gid=" . ($_GET['gid'] ?? '0');
    $url = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:json&{$param}";
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw && preg_match('/setResponse\((.*)\);/s', $raw, $m)) {
        echo $m[1];
    } else {
        echo json_encode(['error' => 'Unable to fetch sheet']);
    }
    exit;
}

// แผนที่คอลัมน์ของแต่ละเครื่องใน Sheet 1 (Summary)
$colMap = [
    'ISOWA' => ['consum' => 1, 'output' => 2, 'waste' => 5],
    'BHS'   => ['consum' => 7, 'output' => 8, 'waste' => 11],
    'YUELI' => ['consum' => 13, 'output' => 14, 'waste' => 17],
    'TOTAL' => ['consum' => 23, 'output' => 24, 'waste' => -1]
];
$mCols = $colMap[$machine] ?? $colMap['BHS'];

// 1. ดึงข้อมูล Sheet 1 (Summary: Consumption & Output)
$url1 = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:json&gid=0";
$raw1 = @file_get_contents($url1, false, $ctx);
$dailySummary = [];
$totalConsumMTD = 0;
$totalOutputMTD = 0;
$totalWasteKgMTD = 0;

if ($raw1 && preg_match('/setResponse\((.*)\);/s', $raw1, $m1)) {
    $j1 = json_decode($m1[1], true);
    if (isset($j1['table']['rows'])) {
        foreach ($j1['table']['rows'] as $r) {
            $dateVal = $r['c'][0]['v'] ?? '';
            if (preg_match('/(\d{1,2})\/' . $monthPad . '\//', $dateVal, $dm) ||
                preg_match('/(\d{1,2})\/' . $month . '\//', $dateVal, $dm)) {
                $day = intval($dm[1]);
                $c = floatval($r['c'][$mCols['consum']]['v'] ?? 0);
                $o = floatval($r['c'][$mCols['output']]['v'] ?? 0);
                $w = ($mCols['waste'] !== -1) ? floatval($r['c'][$mCols['waste']]['v'] ?? 0) : 0;
                $dailySummary[$day] = [
                    'consum' => $c,
                    'output' => $o,
                    'wasteKg' => $w
                ];
                $totalConsumMTD += $c;
                $totalOutputMTD += $o;
                $totalWasteKgMTD += $w;
            }
        }
    }
}

// 2. ดึงข้อมูล Sheet 2 (หน้า "Waste BHS" หรือ "Waste {$machine}" หรือ fallback เป็น "Waste")
$trySheets = ["Waste {$machine}", "Waste BHS", "Waste"];
$raw2 = false;
$usedSheetName = '';

foreach ($trySheets as $sName) {
    $url2 = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:json&sheet=" . urlencode($sName);
    $tmpRaw = @file_get_contents($url2, false, $ctx);
    if ($tmpRaw && strpos($tmpRaw, 'setResponse') !== false && strpos($tmpRaw, '"status":"error"') === false) {
        $raw2 = $tmpRaw;
        $usedSheetName = $sName;
        break;
    }
}

$dailyBreakdown = [];
$baseCol = ($month - 1) * 9;
$mOffset = [
    'ISOWA' => 2,
    'BHS'   => 3,
    'YUELI' => 4,
    'TOTAL' => 5
];
$kgCol = $baseCol + ($mOffset[$machine] ?? 3);

if ($raw2 && preg_match('/setResponse\((.*)\);/s', $raw2, $m2)) {
    $j2 = json_decode($m2[1], true);
    if (isset($j2['table']['rows'])) {
        $rows2 = $j2['table']['rows'];

        // ตรวจสอบว่า Sheet Waste BHS มี format วันที่แบบ "Thu 01/10/26" (จาก pivot/summary)
        // หรือแบบ "BL18", "BL17" (จาก breakdown detail)
        // Sheet Waste BHS = Summary format: col0=Day, col7=BHSConsum, col8=BHSOut, col11=WasteBHSKg
        $isWasteBHSSummary = false;
        foreach ($rows2 as $testRow) {
            $v0 = trim($testRow['c'][0]['v'] ?? '');
            // รูปแบบ "Thu 01/10/26" = day-of-week + date
            if (preg_match('/^[A-Za-z]{2,3}\s+\d{2}\/\d{2}\/\d{2}$/', $v0)) {
                $isWasteBHSSummary = true;
                break;
            }
        }

        if ($isWasteBHSSummary) {
            // Sheet เป็น Summary format: "Thu 01/10/26"
            // Col indices: 0=Day, 7=BHSConsum, 8=BHSOut, 10=Waste%(con-out)BHS, 11=WasteKgBHS
            $monthPad2 = str_pad($month, 2, '0', STR_PAD_LEFT);
            foreach ($rows2 as $row) {
                $v0 = trim($row['c'][0]['v'] ?? '');
                // Match "Thu 01/10/26" or "Mon 05/10/26"
                if (preg_match('/^[A-Za-z]{2,3}\s+(\d{2})\/(\d{2})\/\d{2}$/', $v0, $dm)) {
                    $rowMonth = intval($dm[2]);
                    $rowDay   = intval($dm[1]);
                    if ($rowMonth !== $month) continue;

                    // ใช้ค่า Consumption/Output จาก Sheet นี้ (แม่นยำกว่า Sheet1)
                    $c = floatval($row['c'][7]['v'] ?? 0);
                    $o = floatval($row['c'][8]['v'] ?? 0);
                    $wKg = floatval($row['c'][11]['v'] ?? 0); // Waste kg (BL) BHS

                    // ถ้ายังไม่มีข้อมูลจาก Sheet1 ให้ override ด้วยค่านี้
                    if ($c > 0) {
                        $dailySummary[$rowDay]['consum'] = $c;
                        $dailySummary[$rowDay]['output'] = $o;
                        // update totals
                        $totalConsumMTD += $c - ($dailySummary[$rowDay]['consum_orig'] ?? 0);
                        $totalOutputMTD += $o - ($dailySummary[$rowDay]['output_orig'] ?? 0);
                    }

                    // ใส่ wasteKg เข้า weightTotals โดยตรง (ยังไม่มี BL breakdown ใน sheet นี้)
                    // เก็บ totalKg ไว้ใน dailyBreakdown เพื่อคำนวณ %
                    if (!isset($dailyBreakdown[$rowDay])) {
                        $dailyBreakdown[$rowDay] = [
                            'ncp' => 0, 'def' => 0, 'web' => 0, 'control' => 0, 'om' => 0,
                            'order' => 0, 'core' => 0, 'spec' => 0, 'conveyor' => 0, 'qc' => 0, 'reject' => 0,
                            'totalKg' => 0
                        ];
                    }
                    // ใช้ Waste kg จาก col11 เป็น totalKg (ยังไม่แยก BL)
                    if ($wKg > 0) {
                        $dailyBreakdown[$rowDay]['totalKg'] = $wKg;
                    }
                }
            }

            // Recalculate MTD totals from dailySummary (rebuild)
            $totalConsumMTD = 0;
            $totalOutputMTD = 0;
            foreach ($dailySummary as $ds) {
                $totalConsumMTD += $ds['consum'] ?? 0;
                $totalOutputMTD += $ds['output'] ?? 0;
            }

        } else {
            // Sheet เป็น Detail format: มี BL codes ในแต่ละแถว
            $currentDay = null;
            foreach ($rows2 as $row) {
                $cCode = trim($row['c'][$baseCol]['v'] ?? '');
                $cKg = floatval($row['c'][$kgCol]['v'] ?? 0);

                // ตรวจจับวันที่ เช่น 1/10/2026 หรือ 01/10/2026
                if (preg_match('/^(\d{1,2})\/' . $month . '\/\d{4}$/', $cCode, $dm) ||
                    preg_match('/^(\d{1,2})\/' . $monthPad . '\/\d{4}$/', $cCode, $dm)) {
                    $currentDay = intval($dm[1]);
                    $dailyBreakdown[$currentDay] = [
                        'ncp' => 0, 'def' => 0, 'web' => 0, 'control' => 0, 'om' => 0,
                        'order' => 0, 'core' => 0, 'spec' => 0, 'conveyor' => 0, 'qc' => 0, 'reject' => 0,
                        'totalKg' => 0
                    ];
                    continue;
                }

                if ($currentDay !== null && $cCode !== '' && $cCode !== 'Reason Code' && $cCode !== 'Total') {
                    $dailyBreakdown[$currentDay]['totalKg'] += $cKg;
                    if ($cCode === 'BL18')      $dailyBreakdown[$currentDay]['ncp']      += $cKg;
                    elseif ($cCode === 'BL17')  $dailyBreakdown[$currentDay]['def']      += $cKg;
                    elseif ($cCode === 'BL16')  $dailyBreakdown[$currentDay]['web']      += $cKg;
                    elseif ($cCode === 'BL21')  $dailyBreakdown[$currentDay]['control']  += $cKg;
                    elseif ($cCode === 'BL15')  $dailyBreakdown[$currentDay]['order']    += $cKg;
                    elseif ($cCode === 'BL12')  $dailyBreakdown[$currentDay]['core']     += $cKg;
                    elseif ($cCode === 'BL19')  $dailyBreakdown[$currentDay]['spec']     += $cKg;
                    elseif ($cCode === 'BL22')  $dailyBreakdown[$currentDay]['conveyor'] += $cKg;
                    elseif ($cCode === 'BL24')  $dailyBreakdown[$currentDay]['qc']       += $cKg;
                    elseif ($cCode === 'BL25')  $dailyBreakdown[$currentDay]['reject']   += $cKg;
                }
            }
        }
    }
}

// 3. คำนวณตาราง % (transformed) และตารางน้ำหนัก kg (transformedWeight)
$transformed = [];
$transformedWeight = [];
$totalWasteKgFromBreakdown = 0;

$weightTotals = [
    'ncp' => 0, 'def' => 0, 'web' => 0, 'control' => 0, 'om' => 0,
    'order' => 0, 'core' => 0, 'spec' => 0, 'conveyor' => 0, 'qc' => 0, 'reject' => 0,
    'totalAll' => 0
];

for ($d = 1; $d <= $totalDays; $d++) {
    $consum = $dailySummary[$d]['consum'] ?? 0;
    $output = $dailySummary[$d]['output'] ?? 0;
    $breakdown = $dailyBreakdown[$d] ?? null;

    $ncpKg = $breakdown['ncp'] ?? 0;
    $defKg = $breakdown['def'] ?? 0;
    $webKg = $breakdown['web'] ?? 0;
    $ctrlKg = $breakdown['control'] ?? 0;
    $omKg = $breakdown['om'] ?? 0;
    $orderKg = $breakdown['order'] ?? 0;
    $coreKg = $breakdown['core'] ?? 0;
    $specKg = $breakdown['spec'] ?? 0;
    $conveyorKg = $breakdown['conveyor'] ?? 0;
    $qcKg = $breakdown['qc'] ?? 0;
    $rejectKg = $breakdown['reject'] ?? 0;

    $dayWeightTotal = $ncpKg + $defKg + $webKg + $ctrlKg + $omKg + $orderKg + $coreKg + $specKg + $conveyorKg + $qcKg + $rejectKg;

    // ถ้า BL codes ทั้งหมดเป็น 0 แต่ totalKg มีค่า (Summary sheet mode: ไม่มี BL breakdown)
    // ให้ใช้ totalKg จาก breakdown แทน dayWeightTotal
    if ($dayWeightTotal == 0 && $breakdown !== null && ($breakdown['totalKg'] ?? 0) > 0) {
        $dayWeightTotal = $breakdown['totalKg'];
    }

    // สะสมยอดน้ำหนัก
    $weightTotals['ncp'] += $ncpKg;
    $weightTotals['def'] += $defKg;
    $weightTotals['web'] += $webKg;
    $weightTotals['control'] += $ctrlKg;
    $weightTotals['om'] += $omKg;
    $weightTotals['order'] += $orderKg;
    $weightTotals['core'] += $coreKg;
    $weightTotals['spec'] += $specKg;
    $weightTotals['conveyor'] += $conveyorKg;
    $weightTotals['qc'] += $qcKg;
    $weightTotals['reject'] += $rejectKg;
    $weightTotals['totalAll'] += $dayWeightTotal;

    $transformedWeight[] = [
        'date' => "{$d}/{$month}/2026",
        'ncp' => $ncpKg,
        'def' => $defKg,
        'web' => $webKg,
        'control' => $ctrlKg,
        'om' => $omKg,
        'order' => $orderKg,
        'core' => $coreKg,
        'spec' => $specKg,
        'conveyor' => $conveyorKg,
        'qc' => $qcKg,
        'reject' => $rejectKg,
        'totalWeight' => $dayWeightTotal
    ];

    // ตาราง %
    $ncp = 0; $def = 0; $web = 0; $ctrl = 0; $om = 0;
    $order = 0; $core = 0; $spec = 0; $conveyor = 0; $qc = 0; $reject = 0;
    $allWaste = 0;

    if ($consum > 0) {
        $ncp = round(($ncpKg / $consum) * 100, 2);
        $def = round(($defKg / $consum) * 100, 2);
        $web = round(($webKg / $consum) * 100, 2);
        $ctrl = round(($ctrlKg / $consum) * 100, 2);
        $om = round(($omKg / $consum) * 100, 2);
        $order = round(($orderKg / $consum) * 100, 2);
        $core = round(($coreKg / $consum) * 100, 2);
        $spec = round(($specKg / $consum) * 100, 2);
        $conveyor = round(($conveyorKg / $consum) * 100, 2);
        $qc = round(($qcKg / $consum) * 100, 2);
        $reject = round(($rejectKg / $consum) * 100, 2);

        $allWaste = round($ncp + $def + $web + $ctrl + $om + $order + $core + $spec + $conveyor + $qc + $reject, 2);

        // ถ้า BL codes ทั้งหมดเป็น 0 แต่ totalKg มีค่า (Summary sheet mode)
        // ให้คำนวณ allWaste% จาก totalKg โดยตรง
        if ($allWaste == 0 && $dayWeightTotal > 0) {
            $allWaste = round(($dayWeightTotal / $consum) * 100, 2);
        }

        $totalWasteKgFromBreakdown += ($breakdown['totalKg'] ?? 0);
    }

    $transformed[] = [
        'date' => "{$d}/{$month}/2026",
        'ncp' => $ncp,
        'def' => $def,
        'web' => $web,
        'control' => $ctrl,
        'om' => $om,
        'order' => $order,
        'core' => $core,
        'spec' => $spec,
        'conveyor' => $conveyor,
        'qc' => $qc,
        'reject' => $reject,
        'allWaste' => $allWaste,
        'consumption' => round($consum),
        'output' => round($output)
    ];
}

// ค่าเฉลี่ยสะสม ALL Waste MTD = รวมน้ำหนัก 11 รายการชั่งจริง / รวม Consumption
$effectiveWasteKg = ($weightTotals['totalAll'] > 0) ? $weightTotals['totalAll'] : $totalWasteKgMTD;
$actualMTD = 0;
if ($totalConsumMTD > 0 && $effectiveWasteKg > 0) {
    $actualMTD = round(($effectiveWasteKg / $totalConsumMTD) * 100, 2);
}

$summary = [
    'consumption' => round($totalConsumMTD),
    'output' => round($totalOutputMTD),
    'allWaste' => $actualMTD,
    'ncp' => ($totalConsumMTD > 0) ? round(($weightTotals['ncp'] / $totalConsumMTD) * 100, 2) : 0,
    'def' => ($totalConsumMTD > 0) ? round(($weightTotals['def'] / $totalConsumMTD) * 100, 2) : 0,
    'web' => ($totalConsumMTD > 0) ? round(($weightTotals['web'] / $totalConsumMTD) * 100, 2) : 0,
    'control' => ($totalConsumMTD > 0) ? round(($weightTotals['control'] / $totalConsumMTD) * 100, 2) : 0,
    'om' => ($totalConsumMTD > 0) ? round(($weightTotals['om'] / $totalConsumMTD) * 100, 2) : 0,
    'order' => ($totalConsumMTD > 0) ? round(($weightTotals['order'] / $totalConsumMTD) * 100, 2) : 0,
    'core' => ($totalConsumMTD > 0) ? round(($weightTotals['core'] / $totalConsumMTD) * 100, 2) : 0,
    'spec' => ($totalConsumMTD > 0) ? round(($weightTotals['spec'] / $totalConsumMTD) * 100, 2) : 0,
    'conveyor' => ($totalConsumMTD > 0) ? round(($weightTotals['conveyor'] / $totalConsumMTD) * 100, 2) : 0,
    'qc' => ($totalConsumMTD > 0) ? round(($weightTotals['qc'] / $totalConsumMTD) * 100, 2) : 0,
    'reject' => ($totalConsumMTD > 0) ? round(($weightTotals['reject'] / $totalConsumMTD) * 100, 2) : 0
];

echo json_encode([
    'success' => true,
    'source' => 'google_sheet_filtered',
    'usedSheet' => $usedSheetName,
    'meta' => [
        'machine' => $machine,
        'month' => $month,
        'monthNameTh' => $monthNamesTh[$month] ?? '',
        'totalDays' => $totalDays
    ],
    'data' => $transformed,
    'summary' => $summary,
    'weightData' => $transformedWeight,
    'weightSummary' => $weightTotals
], JSON_UNESCAPED_UNICODE);
