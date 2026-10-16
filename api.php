<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$sheetId = $_GET['sheetId'] ?? '1viioRJ5I3BmqiD7KLwSG9_uNQ_9u_a8LYR-4GaPGuYI';
$mode = $_GET['mode'] ?? 'auto';

$ctx = stream_context_create([
    'http' => [
        'timeout' => 15,
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n"
    ]
]);

// ถ้าผู้ใช้ระบุ sheet หรือ gid เจาะจงให้ส่ง raw gviz json กลับไป
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

// 1. ดึงข้อมูล Sheet 1 (Summary: Consumption & Output BHS)
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
            if (preg_match('/(\d{1,2})\/10\//', $dateVal, $dm)) {
                $day = intval($dm[1]);
                $c = floatval($r['c'][7]['v'] ?? 0);
                $o = floatval($r['c'][8]['v'] ?? 0);
                $w = floatval($r['c'][11]['v'] ?? 0);
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

// 2. ดึงข้อมูล Sheet 2 (หน้า "Waste": แยก 11 หมวดหมู่ตามวัน)
$url2 = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:json&sheet=" . urlencode('Waste');
$raw2 = @file_get_contents($url2, false, $ctx);
$dailyBreakdown = [];

if ($raw2 && preg_match('/setResponse\((.*)\);/s', $raw2, $m2)) {
    $j2 = json_decode($m2[1], true);
    if (isset($j2['table']['rows'])) {
        $rows = $j2['table']['rows'];
        $currentDay = null;

        for ($r = 0; $r < count($rows); $r++) {
            $c81 = trim($rows[$r]['c'][81]['v'] ?? '');
            $c82 = trim($rows[$r]['c'][82]['v'] ?? '');
            $c84 = floatval($rows[$r]['c'][84]['v'] ?? 0);

            // ตรวจจับวันที่ เช่น 1/10/2025 หรือ 1/10/2026
            if (preg_match('/^(\d{1,2})\/10\/\d{4}$/', $c81, $dm)) {
                $currentDay = intval($dm[1]);
                $dailyBreakdown[$currentDay] = [
                    'ncp' => 0, 'def' => 0, 'web' => 0, 'control' => 0, 'om' => 0,
                    'order' => 0, 'core' => 0, 'spec' => 0, 'conveyor' => 0, 'qc' => 0, 'reject' => 0,
                    'totalKg' => 0
                ];
                continue;
            }

            if ($currentDay !== null && $c81 !== '' && $c81 !== 'Reason Code' && $c81 !== 'Total') {
                $dailyBreakdown[$currentDay]['totalKg'] += $c84;
                if ($c81 === 'BL18') $dailyBreakdown[$currentDay]['ncp'] += $c84;
                elseif ($c81 === 'BL17') $dailyBreakdown[$currentDay]['def'] += $c84;
                elseif ($c81 === 'BL16') $dailyBreakdown[$currentDay]['web'] += $c84;
                elseif ($c81 === 'BL21') $dailyBreakdown[$currentDay]['control'] += $c84;
                elseif ($c81 === 'BL15') $dailyBreakdown[$currentDay]['order'] += $c84;
                elseif ($c81 === 'BL12' || $c81 === 'BL14') $dailyBreakdown[$currentDay]['core'] += $c84;
                elseif ($c81 === 'BL19' || $c81 === 'BL39') $dailyBreakdown[$currentDay]['spec'] += $c84;
                elseif ($c81 === 'BL22') $dailyBreakdown[$currentDay]['conveyor'] += $c84;
                elseif ($c81 === 'BL24' || $c81 === 'BL23') $dailyBreakdown[$currentDay]['qc'] += $c84;
                elseif ($c81 === 'BL25' || $c81 === 'BL37' || $c81 === 'BL31') $dailyBreakdown[$currentDay]['reject'] += $c84;
            }
        }
    }
}

// 3. รวมข้อมูลทั้ง 31 วัน
$transformed = [];
$totalWastePctSum = 0;
$daysRunCount = 0;

for ($d = 1; $d <= 31; $d++) {
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

        // รวม % waste ของหมวดหมู่ทั้งหมด
        $allWaste = round($ncp + $def + $web + $ctrl + $om + $order + $core + $spec + $conveyor + $qc + $reject, 2);
        if ($allWaste > 0) {
            $daysRunCount++;
        }
    }

    $transformed[] = [
        'date' => "{$d}/10/2026",
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

$actualMTD = 4.61;
if ($totalConsumMTD > 0 && $totalWasteKgMTD > 0) {
    // คิด % actual MTD จากยอดรวมสะสม
    $actualMTD = round(($totalWasteKgMTD / $totalConsumMTD) * 100, 2);
}

$summary = [
    'consumption' => round($totalConsumMTD),
    'output' => round($totalOutputMTD),
    'allWaste' => $actualMTD
];

echo json_encode([
    'success' => true,
    'source' => 'google_sheet_merged',
    'data' => $transformed,
    'summary' => $summary
], JSON_UNESCAPED_UNICODE);
