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
    'TOTAL' => ['consum' => 19, 'output' => 20, 'waste' => -1]
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

// 2. ดึงข้อมูล Sheet 2 (หน้า "Waste " หรือ "Waste" หรือ "Waste {$machine}": แยก 11 หมวดหมู่ตามวัน)
$trySheets = ["Waste ", "Waste", "Waste {$machine}", "Waste_{$machine}", "Waste BHS", "Waste YUELI"];
$raw2 = false;
$usedSheetName = '';

foreach ($trySheets as $sName) {
    $url2 = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:json&sheet=" . urlencode($sName);
    $tmpRaw = @file_get_contents($url2, false, $ctx);
    if ($tmpRaw && preg_match('/setResponse\((.*)\);/s', $tmpRaw, $tm)) {
        $tj = json_decode($tm[1], true);
        // ตรวจสอบว่าเป็นชีตแยกหมวดหมู่ของเสียจริง (ไม่ใช่ fallback ไป Sheet1 gid=0 ที่มี label 'Day of Posting Date')
        if (isset($tj['table']['rows']) && count($tj['table']['rows']) > 50 && ($tj['table']['cols'][0]['label'] ?? '') !== 'Day of Posting Date') {
            $raw2 = $tmpRaw;
            $usedSheetName = $sName;
            break;
        }
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
        $currentDay = null;

        for ($r = 0; $r < count($rows2); $r++) {
            $cCode = trim($rows2[$r]['c'][$baseCol]['v'] ?? '');
            $cKg = floatval($rows2[$r]['c'][$kgCol]['v'] ?? 0);

            // ตรวจจับวันที่ของเดือนนั้นๆ เช่น 1/10/2025 หรือ 1/10/2026
            if (preg_match('/^(\d{1,2})\/' . $month . '\/\d{2,4}$/', $cCode, $dm) ||
                preg_match('/^(\d{1,2})\/' . $monthPad . '\/\d{2,4}$/', $cCode, $dm)) {
                $currentDay = intval($dm[1]);
                $dailyBreakdown[$currentDay] = [
                    'ncp' => 0, 'def' => 0, 'web' => 0, 'control' => 0, 'om' => 0,
                    'order' => 0, 'core' => 0, 'spec' => 0, 'conveyor' => 0, 'qc' => 0, 'reject' => 0,
                    'totalKg' => 0
                ];
                continue;
            }

            // เฉพาะของเสีย 11 รายการ ไม่เอารวม (BL33, BL06, BL14, BL05, BL32 ฯลฯ ไม่นับ)
            if ($currentDay !== null && $cCode !== '' && $cCode !== 'Reason Code' && $cCode !== 'Total') {
                if ($cCode === 'BL18') {
                    $dailyBreakdown[$currentDay]['ncp'] += $cKg;
                    $dailyBreakdown[$currentDay]['totalKg'] += $cKg;
                } elseif ($cCode === 'BL17') {
                    $dailyBreakdown[$currentDay]['def'] += $cKg;
                    $dailyBreakdown[$currentDay]['totalKg'] += $cKg;
                } elseif ($cCode === 'BL16') {
                    $dailyBreakdown[$currentDay]['web'] += $cKg;
                    $dailyBreakdown[$currentDay]['totalKg'] += $cKg;
                } elseif ($cCode === 'BL21') {
                    $dailyBreakdown[$currentDay]['control'] += $cKg;
                    $dailyBreakdown[$currentDay]['totalKg'] += $cKg;
                } elseif ($cCode === 'BL15') {
                    $dailyBreakdown[$currentDay]['order'] += $cKg;
                    $dailyBreakdown[$currentDay]['totalKg'] += $cKg;
                } elseif ($cCode === 'BL12') {
                    $dailyBreakdown[$currentDay]['core'] += $cKg;
                    $dailyBreakdown[$currentDay]['totalKg'] += $cKg;
                } elseif ($cCode === 'BL19') {
                    $dailyBreakdown[$currentDay]['spec'] += $cKg;
                    $dailyBreakdown[$currentDay]['totalKg'] += $cKg;
                } elseif ($cCode === 'BL22') {
                    $dailyBreakdown[$currentDay]['conveyor'] += $cKg;
                    $dailyBreakdown[$currentDay]['totalKg'] += $cKg;
                } elseif ($cCode === 'BL24') {
                    $dailyBreakdown[$currentDay]['qc'] += $cKg;
                    $dailyBreakdown[$currentDay]['totalKg'] += $cKg;
                } elseif ($cCode === 'BL25') {
                    $dailyBreakdown[$currentDay]['reject'] += $cKg;
                    $dailyBreakdown[$currentDay]['totalKg'] += $cKg;
                }
            }
        }
    }
}

// 2.5 รวมข้อมูลที่นำเข้าจากหน้า upload.html (ถ้ามี)
$importedFile = __DIR__ . '/data/imported_data.json';
if (file_exists($importedFile)) {
    $importedContent = @file_get_contents($importedFile);
    $importedJson = json_decode($importedContent, true);
    if (is_array($importedJson)) {
        // รวม con_out
        if (isset($importedJson['con_out']) && is_array($importedJson['con_out'])) {
            foreach ($importedJson['con_out'] as $dateKey => $cRow) {
                if (preg_match('/(\d{1,2})\/' . $monthPad . '\//', $dateKey, $dm) ||
                    preg_match('/(\d{1,2})\/' . $month . '\//', $dateKey, $dm)) {
                    $day = intval($dm[1]);
                    $c = 0; $o = 0;
                    if ($machine === 'BHS') {
                        $c = floatval($cRow['bhs_consum'] ?? 0);
                        $o = floatval($cRow['bhs_output'] ?? 0);
                    } elseif ($machine === 'YUELI') {
                        $c = floatval($cRow['yueli_consum'] ?? 0);
                        $o = floatval($cRow['yueli_output'] ?? 0);
                    } elseif ($machine === 'ISOWA') {
                        $c = floatval($cRow['isowa_consum'] ?? 0);
                        $o = floatval($cRow['isowa_output'] ?? 0);
                    } elseif ($machine === 'TOTAL') {
                        $c = floatval($cRow['bhs_consum'] ?? 0) + floatval($cRow['yueli_consum'] ?? 0) + floatval($cRow['isowa_consum'] ?? 0);
                        $o = floatval($cRow['bhs_output'] ?? 0) + floatval($cRow['yueli_output'] ?? 0) + floatval($cRow['isowa_output'] ?? 0);
                    }
                    if ($c > 0 || $o > 0) {
                        if (isset($dailySummary[$day])) {
                            $totalConsumMTD -= $dailySummary[$day]['consum'];
                            $totalOutputMTD -= $dailySummary[$day]['output'];
                        }
                        $dailySummary[$day] = [
                            'consum' => $c,
                            'output' => $o,
                            'wasteKg' => 0
                        ];
                        $totalConsumMTD += $c;
                        $totalOutputMTD += $o;
                    }
                }
            }
        }

        // รวม waste
        if (isset($importedJson['waste']) && is_array($importedJson['waste'])) {
            foreach ($importedJson['waste'] as $dateKey => $mList) {
                if (preg_match('/(\d{1,2})\/' . $monthPad . '\//', $dateKey, $dm) ||
                    preg_match('/(\d{1,2})\/' . $month . '\//', $dateKey, $dm)) {
                    $day = intval($dm[1]);
                    $wItems = $mList[$machine]['items'] ?? ($mList['ALL']['items'] ?? null);
                    if ($wItems && is_array($wItems)) {
                        if (!isset($dailyBreakdown[$day])) {
                            $dailyBreakdown[$day] = [
                                'ncp' => 0, 'def' => 0, 'web' => 0, 'control' => 0, 'om' => 0,
                                'order' => 0, 'core' => 0, 'spec' => 0, 'conveyor' => 0, 'qc' => 0, 'reject' => 0,
                                'totalKg' => 0
                            ];
                        }
                        foreach ($wItems as $it) {
                            $cd = strtoupper(trim($it['code'] ?? ''));
                            $kg = floatval($it['kg'] ?? 0);
                            $nm = $it['name'] ?? '';
                            if ($cd === 'BL18' || stripos($nm, 'ncp') !== false) {
                                $dailyBreakdown[$day]['ncp'] = $kg;
                            } elseif ($cd === 'BL17' || stripos($nm, 'def') !== false) {
                                $dailyBreakdown[$day]['def'] = $kg;
                            } elseif ($cd === 'BL16' || stripos($nm, 'web') !== false) {
                                $dailyBreakdown[$day]['web'] = $kg;
                            } elseif ($cd === 'BL21' || stripos($nm, 'control') !== false) {
                                $dailyBreakdown[$day]['control'] = $kg;
                            } elseif ($cd === 'BL15' || stripos($nm, 'order') !== false || stripos($nm, 'รอยต่อ') !== false) {
                                $dailyBreakdown[$day]['order'] = $kg;
                            } elseif ($cd === 'BL12' || stripos($nm, 'core') !== false || stripos($nm, 'แกน') !== false) {
                                $dailyBreakdown[$day]['core'] = $kg;
                            } elseif ($cd === 'BL19' || stripos($nm, 'spec') !== false || stripos($nm, 'สันนูน') !== false) {
                                $dailyBreakdown[$day]['spec'] = $kg;
                            } elseif ($cd === 'BL22' || stripos($nm, 'conveyor') !== false) {
                                $dailyBreakdown[$day]['conveyor'] = $kg;
                            } elseif ($cd === 'BL24' || stripos($nm, 'qc') !== false) {
                                $dailyBreakdown[$day]['qc'] = $kg;
                            } elseif ($cd === 'BL25' || stripos($nm, 'reject') !== false) {
                                $dailyBreakdown[$day]['reject'] = $kg;
                            }
                        }
                    }
                }
            }
        }
    }
}

// 3. คำนวณตาราง % (transformed) และตารางน้ำหนัก kg (transformedWeight) - เอาเฉพาะ 11 รายการ ไม่เอารวม
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

    // ยอดรวมน้ำหนักเฉพาะของเสีย 11 รายการ ไม่เอารวม
    $dayWeightTotal = $ncpKg + $defKg + $webKg + $ctrlKg + $omKg + $orderKg + $coreKg + $specKg + $conveyorKg + $qcKg + $rejectKg;

    // สะสมยอดน้ำหนักเฉพาะ 11 รายการ
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

    // ตาราง % เฉพาะ 11 รายการ
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

        // เอาแค่ของเสีย 11 รายการ ไม่เอารวม!
        $allWaste = round($ncp + $def + $web + $ctrl + $om + $order + $core + $spec + $conveyor + $qc + $reject, 2);
        $totalWasteKgFromBreakdown += $dayWeightTotal;
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

// คำนวณยอด Consumption เฉพาะวันที่ชั่ง Waste เพื่อคิด % สะสมให้ตรงตามจริง
$totalConsumWithWaste = 0;
for ($d = 1; $d <= $totalDays; $d++) {
    $c = $dailySummary[$d]['consum'] ?? 0;
    $b = $dailyBreakdown[$d] ?? null;
    $dayKg = 0;
    if ($b) {
        $dayKg = ($b['ncp']??0) + ($b['def']??0) + ($b['web']??0) + ($b['control']??0) + ($b['om']??0) + ($b['order']??0) + ($b['core']??0) + ($b['spec']??0) + ($b['conveyor']??0) + ($b['qc']??0) + ($b['reject']??0);
    }
    if ($dayKg > 0 && $c > 0) {
        $totalConsumWithWaste += $c;
    }
}

$denom = $totalConsumWithWaste > 0 ? $totalConsumWithWaste : $totalConsumMTD;
$actualMTD = 0;
if ($denom > 0 && $weightTotals['totalAll'] > 0) {
    $actualMTD = round(($weightTotals['totalAll'] / $denom) * 100, 2);
}

$summary = [
    'consumption' => round($totalConsumMTD),
    'output' => round($totalOutputMTD),
    'consumptionWithWaste' => round($totalConsumWithWaste),
    'allWaste' => $actualMTD,
    'ncp' => ($denom > 0) ? round(($weightTotals['ncp'] / $denom) * 100, 2) : 0,
    'def' => ($denom > 0) ? round(($weightTotals['def'] / $denom) * 100, 2) : 0,
    'web' => ($denom > 0) ? round(($weightTotals['web'] / $denom) * 100, 2) : 0,
    'control' => ($denom > 0) ? round(($weightTotals['control'] / $denom) * 100, 2) : 0,
    'om' => ($denom > 0) ? round(($weightTotals['om'] / $denom) * 100, 2) : 0,
    'order' => ($denom > 0) ? round(($weightTotals['order'] / $denom) * 100, 2) : 0,
    'core' => ($denom > 0) ? round(($weightTotals['core'] / $denom) * 100, 2) : 0,
    'spec' => ($denom > 0) ? round(($weightTotals['spec'] / $denom) * 100, 2) : 0,
    'conveyor' => ($denom > 0) ? round(($weightTotals['conveyor'] / $denom) * 100, 2) : 0,
    'qc' => ($denom > 0) ? round(($weightTotals['qc'] / $denom) * 100, 2) : 0,
    'reject' => ($denom > 0) ? round(($weightTotals['reject'] / $denom) * 100, 2) : 0
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
