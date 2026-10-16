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
            // จับคู่เดือน เช่น "Thu 01/10/26" หรือ "01/09/2026"
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

// 2. ดึงข้อมูล Sheet 2 (หน้า "Waste": แยก 11 หมวดหมู่ตามเดือนและเครื่อง)
$url2 = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:json&sheet=" . urlencode('Waste');
$raw2 = @file_get_contents($url2, false, $ctx);
$dailyBreakdown = [];

// คำนวณคอลัมน์เริ่มต้นของเดือนใน Sheet 2
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
        $rows = $j2['table']['rows'];
        $currentDay = null;

        for ($r = 0; $r < count($rows); $r++) {
            $cCode = trim($rows[$r]['c'][$baseCol]['v'] ?? '');
            $cKg = floatval($rows[$r]['c'][$kgCol]['v'] ?? 0);

            // ตรวจจับวันที่ของเดือนนั้นๆ เช่น 1/10/2025 หรือ 1/9/2025
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
                if ($cCode === 'BL18') $dailyBreakdown[$currentDay]['ncp'] += $cKg;
                elseif ($cCode === 'BL17') $dailyBreakdown[$currentDay]['def'] += $cKg;
                elseif ($cCode === 'BL16') $dailyBreakdown[$currentDay]['web'] += $cKg;
                elseif ($cCode === 'BL21') $dailyBreakdown[$currentDay]['control'] += $cKg;
                elseif ($cCode === 'BL15') $dailyBreakdown[$currentDay]['order'] += $cKg;
                elseif ($cCode === 'BL12' || $cCode === 'BL14') $dailyBreakdown[$currentDay]['core'] += $cKg;
                elseif ($cCode === 'BL19' || $cCode === 'BL39') $dailyBreakdown[$currentDay]['spec'] += $cKg;
                elseif ($cCode === 'BL22') $dailyBreakdown[$currentDay]['conveyor'] += $cKg;
                elseif ($cCode === 'BL24' || $cCode === 'BL23') $dailyBreakdown[$currentDay]['qc'] += $cKg;
                elseif ($cCode === 'BL25' || $cCode === 'BL37' || $cCode === 'BL31') $dailyBreakdown[$currentDay]['reject'] += $cKg;
            }
        }
    }
}

// 3. รวมข้อมูลตามจำนวนวันของเดือนที่เลือก
$transformed = [];
$totalWasteKgFromBreakdown = 0;

for ($d = 1; $d <= $totalDays; $d++) {
    $consum = $dailySummary[$d]['consum'] ?? 0;
    $output = $dailySummary[$d]['output'] ?? 0;
    $breakdown = $dailyBreakdown[$d] ?? null;

    $ncp = 0; $def = 0; $web = 0; $ctrl = 0; $om = 0;
    $order = 0; $core = 0; $spec = 0; $conveyor = 0; $qc = 0; $reject = 0;
    $allWaste = 0;

    if ($consum > 0 && $breakdown) {
        $ncp = round(($breakdown['ncp'] / $consum) * 100, 2);
        $def = round(($breakdown['def'] / $consum) * 100, 2);
        $web = round(($breakdown['web'] / $consum) * 100, 2);
        $ctrl = round(($breakdown['control'] / $consum) * 100, 2);
        $order = round(($breakdown['order'] / $consum) * 100, 2);
        $core = round(($breakdown['core'] / $consum) * 100, 2);
        $spec = round(($breakdown['spec'] / $consum) * 100, 2);
        $conveyor = round(($breakdown['conveyor'] / $consum) * 100, 2);
        $qc = round(($breakdown['qc'] / $consum) * 100, 2);
        $reject = round(($breakdown['reject'] / $consum) * 100, 2);

        $allWaste = round($ncp + $def + $web + $ctrl + $om + $order + $core + $spec + $conveyor + $qc + $reject, 2);
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

$effectiveWasteKg = ($totalWasteKgMTD > 0) ? $totalWasteKgMTD : $totalWasteKgFromBreakdown;
$actualMTD = 0;
if ($totalConsumMTD > 0 && $effectiveWasteKg > 0) {
    $actualMTD = round(($effectiveWasteKg / $totalConsumMTD) * 100, 2);
}

$summary = [
    'consumption' => round($totalConsumMTD),
    'output' => round($totalOutputMTD),
    'allWaste' => $actualMTD
];

echo json_encode([
    'success' => true,
    'source' => 'google_sheet_filtered',
    'meta' => [
        'machine' => $machine,
        'month' => $month,
        'monthNameTh' => $monthNamesTh[$month] ?? '',
        'totalDays' => $totalDays
    ],
    'data' => $transformed,
    'summary' => $summary
], JSON_UNESCAPED_UNICODE);
