<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$sheetId = $_GET['sheetId'] ?? '1viioRJ5I3BmqiD7KLwSG9_uNQ_9u_a8LYR-4GaPGuYI';
$machine = strtoupper(trim($_GET['machine'] ?? 'BHS'));
$month = intval($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) $month = intval(date('n'));
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
    'TOTAL' => ['consum' => 19, 'output' => 20, 'waste' => 23]
];
$mCols = $colMap[$machine] ?? $colMap['BHS'];

if (!function_exists('extractGvizArray')) {
    function extractGvizArray($raw) {
        if (!$raw || !is_string($raw)) return null;
        $p1 = strpos($raw, 'setResponse(');
        if ($p1 === false) return null;
        $p1 += 12;
        $p2 = strrpos($raw, ');');
        if ($p2 === false || $p2 <= $p1) return null;
        return json_decode(substr($raw, $p1, $p2 - $p1), true);
    }
}

// ระบบแคชข้อมูลดิบจาก Google Sheets ลงไฟล์ชั่วคราว (ลดเวลาดึงข้อมูลจาก 3-5 วินาที เหลือ < 0.01 วินาที เมื่อเปลี่ยนตัวกรอง)
$forceRefresh = !empty($_GET['refresh']);
$rawCacheDir = __DIR__ . '/data/cache';
if (!is_dir($rawCacheDir)) {
    @mkdir($rawCacheDir, 0777, true);
}
$safeSheetId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sheetId);
$rawCacheFile = $rawCacheDir . '/gviz_parsed_' . $safeSheetId . '.json';
$rawCacheTtl = 120; // แคช 2 นาที (กดปุ่มรีเฟรชจะดึงใหม่ทันที)

global $__gvizMemoryCache;
if (!isset($__gvizMemoryCache)) $__gvizMemoryCache = [];

$j1 = $__gvizMemoryCache[$safeSheetId]['j1'] ?? null;
$j2 = $__gvizMemoryCache[$safeSheetId]['j2'] ?? null;
$usedSheetName = $__gvizMemoryCache[$safeSheetId]['usedSheetName'] ?? '';

if ($forceRefresh || !$j1 || !$j2) {
    if (!$forceRefresh && file_exists($rawCacheFile) && (time() - filemtime($rawCacheFile) < $rawCacheTtl)) {
        $cachedParsed = @json_decode(@file_get_contents($rawCacheFile), true);
        if ($cachedParsed && isset($cachedParsed['j1'], $cachedParsed['j2'])) {
            $j1 = $cachedParsed['j1'];
            $j2 = $cachedParsed['j2'];
            $usedSheetName = $cachedParsed['usedSheetName'] ?? 'Waste ';
        }
    }
}

// 1. ดึงข้อมูล Sheet 1 (Summary: Consumption & Output) หากยังไม่มีในแคช
if (!$j1) {
    $url1 = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:json&gid=0";
    $raw1 = @file_get_contents($url1, false, $ctx);
    $j1 = extractGvizArray($raw1);
}
$dailySummary = [];
$totalConsumMTD = 0;
$totalOutputMTD = 0;
$totalWasteKgMTD = 0;

// สรุปยอดรวมสำหรับตารางเปรียบเทียบผลผลิตแยกเครื่อง (Machine Output Summary) ทั้ง 3 เครื่อง + TOTAL
$machineSummaries = [
    'BHS'   => ['consum' => 0, 'output' => 0, 'wasteKg' => 0],
    'YUELI' => ['consum' => 0, 'output' => 0, 'wasteKg' => 0],
    'ISOWA' => ['consum' => 0, 'output' => 0, 'wasteKg' => 0],
    'TOTAL' => ['consum' => 0, 'output' => 0, 'wasteKg' => 0]
];

if ($j1 && isset($j1['table']['rows'])) {
    foreach ($j1['table']['rows'] as $r) {
            $dateVal = $r['c'][0]['v'] ?? '';
            if (preg_match('/(\d{1,2})\/' . $monthPad . '\//', $dateVal, $dm) ||
                preg_match('/(\d{1,2})\/' . $month . '\//', $dateVal, $dm)) {
                $day = intval($dm[1]);

                // คำนวณแต่ละเครื่องจากคอลัมน์มาตรฐาน
                $rowBhsC = floatval($r['c'][7]['v'] ?? 0);
                $rowBhsO = max(0.0, floatval($r['c'][8]['v'] ?? 0));
                $rowYueliC = floatval($r['c'][13]['v'] ?? 0);
                $rowYueliO = max(0.0, floatval($r['c'][14]['v'] ?? 0));
                $rowIsowaC = floatval($r['c'][1]['v'] ?? 0);
                $rowIsowaO = max(0.0, floatval($r['c'][2]['v'] ?? 0));
                $rowTotC = floatval($r['c'][19]['v'] ?? 0);
                $rowTotO = max(0.0, floatval($r['c'][20]['v'] ?? 0));
                if ($rowTotC <= 0 && $rowTotO <= 0) {
                    $rowTotC = $rowBhsC + $rowYueliC + $rowIsowaC;
                    $rowTotO = $rowBhsO + $rowYueliO + $rowIsowaO;
                }

                $machineSummaries['BHS']['consum'] += $rowBhsC;
                $machineSummaries['BHS']['output'] += $rowBhsO;
                $machineSummaries['YUELI']['consum'] += $rowYueliC;
                $machineSummaries['YUELI']['output'] += $rowYueliO;
                $machineSummaries['ISOWA']['consum'] += $rowIsowaC;
                $machineSummaries['ISOWA']['output'] += $rowIsowaO;
                $machineSummaries['TOTAL']['consum'] += $rowTotC;
                $machineSummaries['TOTAL']['output'] += $rowTotO;

                if ($machine === 'TOTAL') {
                    $c = $rowTotC;
                    $o = $rowTotO;
                    $w = floatval($r['c'][23]['v'] ?? 0);
                } else {
                    $c = floatval($r['c'][$mCols['consum']]['v'] ?? 0);
                    $o = max(0.0, floatval($r['c'][$mCols['output']]['v'] ?? 0)); // clamp ค่าลบเป็น 0
                    $w = ($mCols['waste'] !== -1) ? floatval($r['c'][$mCols['waste']]['v'] ?? 0) : 0;
                }
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

foreach ($machineSummaries as $mKey => &$mInfo) {
    $mInfo['consum'] = round($mInfo['consum']);
    $mInfo['output'] = round($mInfo['output']);
    $mInfo['wasteKg'] = max(0, $mInfo['consum'] - $mInfo['output']);
    $mInfo['wastePct'] = ($mInfo['consum'] > 0) ? round((($mInfo['consum'] - $mInfo['output']) / $mInfo['consum']) * 100, 2) : 0;
}
unset($mInfo);

// 2. ดึงข้อมูล Sheet 2 (หน้า "Waste " หรือ "Waste" หรือ "Waste {$machine}": แยก 11 หมวดหมู่ตามวัน)
$trySheets = ["Waste ", "Waste", "Waste {$machine}", "Waste_{$machine}", "Waste BHS", "Waste YUELI"];
if (!$j2) {
    foreach ($trySheets as $sName) {
        $url2 = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:json&sheet=" . urlencode($sName);
        $tmpRaw = @file_get_contents($url2, false, $ctx);
        $tj = extractGvizArray($tmpRaw);
        if ($tj && isset($tj['table']['rows']) && count($tj['table']['rows']) > 50 && ($tj['table']['cols'][0]['label'] ?? '') !== 'Day of Posting Date') {
            $j2 = $tj;
            $usedSheetName = $sName;
            break;
        }
    }
    if ($j1 && $j2) {
        @file_put_contents($rawCacheFile, json_encode([
            'updated_at' => time(),
            'j1' => $j1,
            'j2' => $j2,
            'usedSheetName' => $usedSheetName
        ], JSON_UNESCAPED_UNICODE));
    }
}
if ($j1 && $j2) {
    $__gvizMemoryCache[$safeSheetId] = [
        'j1' => $j1,
        'j2' => $j2,
        'usedSheetName' => $usedSheetName
    ];
}

$dailyBreakdown = [];
$baseCol = ($month - 1) * 9;
// offset ภายในกลุ่มเดือน (baseCol = (month-1)*9):
// โครงสร้าง Sheet2: col+0=Date/Code, col+1=Desc, col+2=BHS(เดือน10+ เท่านั้น), col+3=YUELI, col+4=ISOWA, col+5=Total(เดือน1-9)/Other(เดือน10+), col+6=Grand Total(เดือน10+)
$mOffset = [
    'BHS'   => 2,   // เดือน 10+: col+2=BHS; เดือน 1-9: ว่าง (BHS ยังไม่เข้าระบบ)
    'YUELI' => 3,   // ทุกเดือน: col+3=YUELI
    'ISOWA' => 4,   // ทุกเดือน: col+4=ISOWA
    'TOTAL' => 6    // เดือน 10+: col+6=Grand Total; fallback: รวม col+2+3+4
];

// โครงสร้างรายการของเสียนอกเหนือจาก 11 รายการผลิต แยกตามแผนก (PL, RM, WH, LTS, MK, DIFF) - ไม่รวม PU ตามที่ผู้ใช้ระบุ
$deptItemKeys = [
    // Waste PL (3.50%)
    'pl_trim', 'pl_silo', 'pl_plan',
    // Waste RM (1.58%)
    'rm_dirty', 'rm_cut_wh', 'rm_cut_pd', 'rm_cut_full', 'rm_core_plus',
    'rm_core_pd', 'rm_core_wh', 'rm_rewind', 'rm_termite_crush',
    'rm_core_crush', 'rm_termite', 'rm_impact', 'rm_splice',
    'rm_head_split', 'rm_head_split_pd', 'rm_wet',
    // Waste WH (0.01%)
    'wh_fg', 'wh_repair', 'wh_ct', 'wh_va',
    // Waste LTS (0.02%)
    'lts_waste', 'lts_reject',
    // Waste MK (0.04%)
    'mk_cancel', 'mk_close', 'mk_reject', 'mk_sample'
];
$dailyDeptBreakdown = [];
$dailySheetTotalBlKg = [];

if (!function_exists('mapCodeToDeptItemKey')) {
function mapCodeToDeptItemKey($code, $desc) {
    $code = strtoupper(trim((string)$code));
    $desc = trim((string)$desc);
    // PL
    if ($code === 'BL32' || stripos($desc, 'Trim') !== false) return 'pl_trim';
    if ($code === 'BL33' || stripos($desc, 'ไซโล') !== false) return 'pl_silo';
    if ($code === 'BL34' || stripos($desc, 'วางแผน') !== false) return 'pl_plan';

    // RM
    if (stripos($desc, 'สกปรก') !== false || stripos($desc, 'คราบน้ำมัน') !== false) return 'rm_dirty';
    if (stripos($desc, 'ตัดก่อนคลังม้วน เต็ม') !== false) return 'rm_cut_full';
    if ($code === 'BL06' || stripos($desc, 'ตัดก่อนคลังม้วน') !== false) return 'rm_cut_wh';
    if ($code === 'BL13' || stripos($desc, 'ตัดก่อนผลิต') !== false) return 'rm_cut_pd';
    if ($code === 'BL64' || stripos($desc, 'เศษติดแกน+แกน') !== false) return 'rm_core_plus';
    if ($code === 'BL14' || $desc === 'แกน-ผลิต') return 'rm_core_pd';
    if ($code === 'BL05' || stripos($desc, 'เศษติดแกน-คลัง') !== false) return 'rm_core_wh';
    if ($code === 'BL07' || stripos($desc, 'กรอม้วน') !== false) return 'rm_rewind';
    if ($code === 'BL08') {
        if (stripos($desc, 'ปลวก') !== false && stripos($desc, 'แกนบี้') !== false) return 'rm_termite_crush';
        if (stripos($desc, 'แกนบี้') !== false) return 'rm_core_crush';
        return 'rm_termite_crush';
    }
    if (stripos($desc, 'ปลวก') !== false) return 'rm_termite';
    if ($code === 'BL66' || stripos($desc, 'กระแทก') !== false) return 'rm_impact';
    if ($code === 'BL09' || stripos($desc, 'ม้วนกรอ') !== false || stripos($desc, 'รอยยับ') !== false) return 'rm_splice';
    if (stripos($desc, 'ม้วนหัวแตก (ผลิต)') !== false) return 'rm_head_split_pd';
    if ($code === 'BL03' || stripos($desc, 'ม้วนหัวแตก') !== false) return 'rm_head_split';
    if (stripos($desc, 'เปียกน้ำ') !== false) return 'rm_wet';

    // WH
    if ($code === 'BL29' || stripos($desc, 'คลังFG') !== false) return 'wh_fg';
    if ($code === 'BL62' || stripos($desc, 'ซ่อมงานผิดพลาด') !== false) return 'wh_repair';
    if ($code === 'BL27' || stripos($desc, 'งาน CT') !== false) return 'wh_ct';
    if ($code === 'BL28' || stripos($desc, 'เศษVA') !== false) return 'wh_va';

    // LTS
    if ($code === 'BL30' || stripos($desc, 'ของเสีย จัดส่ง') !== false) return 'lts_waste';
    if ($code === 'BL31' || stripos($desc, 'Reject จัดส่ง') !== false) return 'lts_reject';

    // MK
    if ($code === 'BL35' || stripos($desc, 'ลูกค้ายกเลิก') !== false) return 'mk_cancel';
    if ($code === 'BL36' || stripos($desc, 'งานตัดจบ') !== false) return 'mk_close';
    if ($code === 'BL37' || stripos($desc, 'Reject ตลาด') !== false) return 'mk_reject';
    if ($code === 'BL61' || stripos($desc, 'MKT ตัวอย่าง') !== false) return 'mk_sample';

    return null;
}
}

if ($j2 && isset($j2['table']['rows'])) {
        $rows2 = $j2['table']['rows'];
        $currentDay = null;
        $dayHasCol6 = false;

        for ($r = 0; $r < count($rows2); $r++) {
            $cCode = trim($rows2[$r]['c'][$baseCol]['v'] ?? '');
            $cDesc = trim($rows2[$r]['c'][$baseCol + 1]['v'] ?? '');

            // ตรวจจับวันที่ของเดือนนั้นๆ ในกลุ่ม offset ที่กำหนด (ไม่กรองปีเพราะ Sheet 2 มีข้อมูลปนหลายปี)
            if (preg_match('/^(\d{1,2})\/' . $month . '\/\d{2,4}$/', $cCode, $dm) ||
                preg_match('/^(\d{1,2})\/' . $monthPad . '\/\d{2,4}$/', $cCode, $dm)) {
                $currentDay = intval($dm[1]);
                $dailyBreakdown[$currentDay] = [
                    'ncp' => 0, 'def' => 0, 'web' => 0, 'control' => 0, 'om' => 0,
                    'order' => 0, 'core' => 0, 'spec' => 0, 'conveyor' => 0, 'qc' => 0, 'reject' => 0,
                    'totalKg' => 0
                ];
                $dailyDeptBreakdown[$currentDay] = array_fill_keys($deptItemKeys, 0);
                // ค้นหาแถว Total ล่วงหน้าของวันนี้เพื่อดูว่ามีคอลัมน์ที่ 6 (ISOWA + BHS + YUELI = 3 เครื่อง) หรือไม่
                $dayHasCol6 = false;
                for ($k = $r + 1; $k < min(count($rows2), $r + 35); $k++) {
                    $checkCode = trim($rows2[$k]['c'][$baseCol]['v'] ?? '');
                    if (preg_match('/^\d{1,2}\/' . $month . '\/\d{2,4}$/', $checkCode) ||
                        preg_match('/^\d{1,2}\/' . $monthPad . '\/\d{2,4}$/', $checkCode)) {
                        break;
                    }
                    if ($checkCode === 'Total') {
                        $dayHasCol6 = (floatval($rows2[$k]['c'][$baseCol + 6]['v'] ?? 0) > 0);
                        break;
                    }
                }
                continue;
            }

            $bhsVal = floatval($rows2[$r]['c'][$baseCol + 3]['v'] ?? 0);
            $isoVal = $dayHasCol6 ? floatval($rows2[$r]['c'][$baseCol + 4]['v'] ?? 0) : 0;
            $yueVal = $dayHasCol6 ? floatval($rows2[$r]['c'][$baseCol + 5]['v'] ?? 0) : floatval($rows2[$r]['c'][$baseCol + 4]['v'] ?? 0);
            $totVal = $dayHasCol6 ? floatval($rows2[$r]['c'][$baseCol + 6]['v'] ?? 0) : floatval($rows2[$r]['c'][$baseCol + 5]['v'] ?? 0);

            if ($machine === 'BHS') {
                $cKg = $bhsVal;
            } elseif ($machine === 'ISOWA') {
                // ถ้าวันนี้ ISOWA ไม่ได้เดิน (มีแค่ 2 เครื่อง) col+4 จะเป็นของ YUELI ให้ ISOWA เป็น 0
                $cKg = $isoVal;
            } elseif ($machine === 'YUELI') {
                // ถ้ามี 3 เครื่อง YUELI อยู่ col+5; ถ้ามี 2 เครื่อง YUELI อยู่ col+4
                $cKg = $yueVal;
            } elseif ($machine === 'TOTAL') {
                $cKg = $totVal;
            } else {
                $cKg = $bhsVal;
            }

            if ($currentDay !== null && $cCode === 'Total') {
                $dailySheetTotalBlKg[$currentDay] = $cKg;
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
                } elseif ($cCode === 'BL20') {
                    $dailyBreakdown[$currentDay]['om'] += $cKg;
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
                } else {
                    // แยกของเสียแผนกอื่นๆ (PL, RM, PU, WH, LTS, MK)
                    $deptItemKey = mapCodeToDeptItemKey($cCode, $cDesc);
                    if ($deptItemKey !== null) {
                        $deptKg = $cKg;
                        // กรณีรายการคลังม้วน เช่น กรอม้วน (BL07) ที่บันทึกในรหัสคลัง 30101 (BHS=0, ISOWA=0, YUELI=0 แต่มีใน Grand Total)
                        if ($deptKg == 0 && $totVal > 0 && $bhsVal == 0 && $isoVal == 0 && $yueVal == 0 && ($machine === 'BHS' || $machine === 'YUELI')) {
                            $deptKg = $totVal / 2.0;
                        }
                        $dailyDeptBreakdown[$currentDay][$deptItemKey] += $deptKg;
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
                            } elseif ($cd === 'BL20' || stripos($nm, 'om') !== false || stripos($nm, 'ชานไม้') !== false) {
                                $dailyBreakdown[$day]['om'] = $kg;
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

// 3.5 คำนวณข้อมูลของเสียแยกตามแผนก (PL, RM, WH, LTS, MK) และ Diff waste (ตัด PU ออกตามที่ผู้ใช้ระบุ)
$deptGroupsMap = [
    'PL'  => ['pl_trim', 'pl_silo', 'pl_plan'],
    'RM'  => [
        'rm_dirty', 'rm_cut_wh', 'rm_cut_pd', 'rm_cut_full', 'rm_core_plus',
        'rm_core_pd', 'rm_core_wh', 'rm_rewind', 'rm_termite_crush',
        'rm_core_crush', 'rm_termite', 'rm_impact', 'rm_splice',
        'rm_head_split', 'rm_head_split_pd', 'rm_wet'
    ],
    'WH'  => ['wh_fg', 'wh_repair', 'wh_ct', 'wh_va'],
    'LTS' => ['lts_waste', 'lts_reject'],
    'MK'  => ['mk_cancel', 'mk_close', 'mk_reject', 'mk_sample']
];

$deptTargets = [
    'PD'   => 5.00,
    'PL'   => 3.50,
    'RM'   => 1.58,
    'WH'   => 0.01,
    'LTS'  => 0.02,
    'MK'   => 0.04,
    'DIFF' => 0.85
];

$deptDailyPct = [];
$deptDailyKg = [];
$deptItemTotalsKg = array_fill_keys($deptItemKeys, 0);
$deptItemTotalsKg['pd_waste'] = 0;
$deptItemTotalsKg['diff_waste'] = 0;

$deptGroupTotalsKg = [
    'PD' => 0, 'PL' => 0, 'RM' => 0, 'WH' => 0, 'LTS' => 0, 'MK' => 0, 'DIFF' => 0
];
$totalSheetBlKgMTD = 0;

for ($d = 1; $d <= $totalDays; $d++) {
    $consum = $dailySummary[$d]['consum'] ?? 0;
    $output = $dailySummary[$d]['output'] ?? 0;
    $dItems = $dailyDeptBreakdown[$d] ?? array_fill_keys($deptItemKeys, 0);
    $pdRow = $dailyBreakdown[$d] ?? null;
    $pdDayKg = $pdRow ? floatval($pdRow['totalKg'] ?? 0) : 0;

    $rowKg = ['date' => "{$d}/{$month}/2026", 'consumption' => round($consum), 'output' => round($output)];
    $rowPct = ['date' => "{$d}/{$month}/2026", 'consumption' => round($consum), 'output' => round($output)];

    // Waste Production (11 รายการ)
    $rowKg['pd_waste'] = round($pdDayKg, 1);
    $rowKg['dept_PD'] = round($pdDayKg, 1);
    $rowPct['pd_waste'] = ($consum > 0) ? round(($pdDayKg / $consum) * 100, 2) : 0;
    $rowPct['dept_PD'] = $rowPct['pd_waste'];
    $deptItemTotalsKg['pd_waste'] += $pdDayKg;
    $deptGroupTotalsKg['PD'] += $pdDayKg;

    $dayOtherBlSum = 0;
    foreach ($deptGroupsMap as $gKey => $keysList) {
        $gDayKg = 0;
        foreach ($keysList as $ik) {
            $valKg = floatval($dItems[$ik] ?? 0);
            $rowKg[$ik] = round($valKg, 1);
            $rowPct[$ik] = ($consum > 0) ? round(($valKg / $consum) * 100, 2) : 0;
            $deptItemTotalsKg[$ik] += $valKg;
            $gDayKg += $valKg;
        }
        $rowKg["dept_{$gKey}"] = round($gDayKg, 1);
        $rowPct["dept_{$gKey}"] = ($consum > 0) ? round(($gDayKg / $consum) * 100, 2) : 0;
        $deptGroupTotalsKg[$gKey] += $gDayKg;
        $dayOtherBlSum += $gDayKg;
    }

    // คำนวณ Diff waste รายวัน = (Consumption - Output) - ของเสียชั่งจริงทั้งหมดในชีต Waste
    $sheetBlTotal = isset($dailySheetTotalBlKg[$d]) ? floatval($dailySheetTotalBlKg[$d]) : ($pdDayKg + $dayOtherBlSum);
    $totalSheetBlKgMTD += $sheetBlTotal;

    $diffDayKg = 0;
    if ($consum > 0 && ($pdDayKg > 0 || $sheetBlTotal > 0 || $output > 0)) {
        $diffDayKg = ($consum - $output) - $sheetBlTotal;
    }
    $rowKg['diff_waste'] = round($diffDayKg, 1);
    $rowKg['dept_DIFF'] = round($diffDayKg, 1);
    $rowKg['weighed_total'] = round($sheetBlTotal, 1);

    $rowPct['diff_waste'] = ($consum > 0) ? round(($diffDayKg / $consum) * 100, 2) : 0;
    $rowPct['dept_DIFF'] = $rowPct['diff_waste'];

    $deptItemTotalsKg['diff_waste'] += $diffDayKg;
    $deptGroupTotalsKg['DIFF'] += $diffDayKg;

    $deptDailyKg[] = $rowKg;
    $deptDailyPct[] = $rowPct;
}

$deptItemTotalsPct = [];
foreach ($deptItemTotalsKg as $ik => $totKgVal) {
    $deptItemTotalsPct[$ik] = ($denom > 0) ? round(($totKgVal / $denom) * 100, 2) : 0;
    $deptItemTotalsKg[$ik] = round($totKgVal, 1);
}

$deptGroupTotalsPct = [];
foreach ($deptGroupTotalsKg as $gk => $gTotKgVal) {
    $deptGroupTotalsPct[$gk] = ($denom > 0) ? round(($gTotKgVal / $denom) * 100, 2) : 0;
    $deptGroupTotalsKg[$gk] = round($gTotKgVal, 1);
}
$deptGroupTotalsPct['PD'] = $actualMTD;
$deptGroupTotalsKg['PD'] = round($weightTotals['totalAll'], 1);

// ตารางสรุปเทียบ 7 รายการหลักตามที่ผู้ใช้ระบุ: Waste Production, Waste Planning, Waste RM, Waste Maketting, Waste WH, Waste LTS, Diff waste
$deptComparison = [
    [
        'key' => 'PD',
        'code' => 'Waste Production',
        'nameTh' => 'แผนกผลิต (11 รายการ)',
        'target' => $deptTargets['PD'],
        'actualPct' => $actualMTD,
        'actualKg' => round($weightTotals['totalAll'], 1),
        'diffPct' => round($actualMTD - $deptTargets['PD'], 2)
    ],
    [
        'key' => 'PL',
        'code' => 'Waste Planning',
        'nameTh' => 'แผนกวางแผน (Waste PL)',
        'target' => $deptTargets['PL'],
        'actualPct' => $deptGroupTotalsPct['PL'],
        'actualKg' => $deptGroupTotalsKg['PL'],
        'diffPct' => round($deptGroupTotalsPct['PL'] - $deptTargets['PL'], 2)
    ],
    [
        'key' => 'RM',
        'code' => 'Waste RM',
        'nameTh' => 'แผนกวัตถุดิบ/คลังม้วน (Waste RM)',
        'target' => $deptTargets['RM'],
        'actualPct' => $deptGroupTotalsPct['RM'],
        'actualKg' => $deptGroupTotalsKg['RM'],
        'diffPct' => round($deptGroupTotalsPct['RM'] - $deptTargets['RM'], 2)
    ],
    [
        'key' => 'MK',
        'code' => 'Waste Maketting',
        'nameTh' => 'แผนกการตลาด (Waste MK)',
        'target' => $deptTargets['MK'],
        'actualPct' => $deptGroupTotalsPct['MK'],
        'actualKg' => $deptGroupTotalsKg['MK'],
        'diffPct' => round($deptGroupTotalsPct['MK'] - $deptTargets['MK'], 2)
    ],
    [
        'key' => 'WH',
        'code' => 'Waste WH',
        'nameTh' => 'แผนกคลังสินค้า FG (Waste WH)',
        'target' => $deptTargets['WH'],
        'actualPct' => $deptGroupTotalsPct['WH'],
        'actualKg' => $deptGroupTotalsKg['WH'],
        'diffPct' => round($deptGroupTotalsPct['WH'] - $deptTargets['WH'], 2)
    ],
    [
        'key' => 'LTS',
        'code' => 'Waste LTS',
        'nameTh' => 'แผนกจัดส่ง (Waste LTS)',
        'target' => $deptTargets['LTS'],
        'actualPct' => $deptGroupTotalsPct['LTS'],
        'actualKg' => $deptGroupTotalsKg['LTS'],
        'diffPct' => round($deptGroupTotalsPct['LTS'] - $deptTargets['LTS'], 2)
    ],
    [
        'key' => 'DIFF',
        'code' => 'Diff waste',
        'nameTh' => 'ส่วนต่าง นน.เข้า-ออก หักของเสีย (Diff waste)',
        'target' => $deptTargets['DIFF'],
        'actualPct' => $deptGroupTotalsPct['DIFF'],
        'actualKg' => $deptGroupTotalsKg['DIFF'],
        'diffPct' => round($deptGroupTotalsPct['DIFF'] - $deptTargets['DIFF'], 2)
    ]
];

// 4. คำนวณแนวโน้มรายปี (Yearly Trend 12 เดือน) และแยก Week (Weekly Trend ตาม Week Num) + ข้อมูลรายเดือนแยกตามแผนก
$allDaysData = [];
$monthlyDeptAgg = [];
for ($m = 1; $m <= 12; $m++) {
    $monthlyDeptAgg[$m] = [
        'consum' => 0,
        'output' => 0,
        'consumWithWaste' => 0,
        'pd_waste' => 0,
        'sheetTotalBl' => 0,
        'items' => array_fill_keys($deptItemKeys, 0)
    ];
}

// 4.1 ดึงประวัติทั้งปีจาก Sheet 1 (con out)
if ($j1 && isset($j1['table']['rows'])) {
    foreach ($j1['table']['rows'] as $r) {
        $dateVal = $r['c'][0]['v'] ?? '';
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $dateVal, $dm)) {
            $d = intval($dm[1]);
            $mo = intval($dm[2]);
            $yr = intval($dm[3]);
            if ($yr < 100) $yr += 2000;
            $isoDate = sprintf('%04d-%02d-%02d', $yr, $mo, $d);
            
            $c = 0; $o = 0;
            if ($machine === 'BHS') {
                $c = floatval($r['c'][7]['v'] ?? 0);
                $o = floatval($r['c'][8]['v'] ?? 0);
            } elseif ($machine === 'YUELI') {
                $c = floatval($r['c'][13]['v'] ?? 0);
                $o = floatval($r['c'][14]['v'] ?? 0);
            } elseif ($machine === 'ISOWA') {
                $c = floatval($r['c'][1]['v'] ?? 0);
                $o = floatval($r['c'][2]['v'] ?? 0);
            } elseif ($machine === 'TOTAL') {
                $c = floatval($r['c'][7]['v'] ?? 0) + floatval($r['c'][13]['v'] ?? 0) + floatval($r['c'][1]['v'] ?? 0);
                $o = floatval($r['c'][8]['v'] ?? 0) + floatval($r['c'][14]['v'] ?? 0) + floatval($r['c'][2]['v'] ?? 0);
            }
            
            $allDaysData[$isoDate] = [
                'consum' => $c,
                'output' => $o,
                'waste11' => 0,
                'sheetTotalBl' => 0,
                'day' => $d,
                'month' => $mo,
                'year' => $yr
            ];
        }
    }
}

// 4.2 ดึงของเสีย 11 รายการ + ของเสียแผนกอื่นๆ ทั้งปีจาก Sheet 2 (Waste )
if ($j2 && isset($j2['table']['rows'])) {
    $rows2All = $j2['table']['rows'];
    $allowedCodes11 = ['BL18', 'BL17', 'BL16', 'BL21', 'BL20', 'BL15', 'BL12', 'BL19', 'BL22', 'BL24', 'BL25'];
    
    for ($mo = 1; $mo <= 12; $mo++) {
        $mBaseCol = ($mo - 1) * 9;
        $currIso = null;
        $dayHasCol6Year = false;
        
        for ($r = 0; $r < count($rows2All); $r++) {
            $cCode = trim($rows2All[$r]['c'][$mBaseCol]['v'] ?? '');
            $cDesc = trim($rows2All[$r]['c'][$mBaseCol + 1]['v'] ?? '');
            
            if (preg_match('/^(\d{1,2})\/' . $mo . '\/\d{2,4}$/', $cCode, $dm) ||
                preg_match('/^(\d{1,2})\/' . sprintf('%02d', $mo) . '\/\d{2,4}$/', $cCode, $dm)) {
                $d = intval($dm[1]);
                $currIso = sprintf('2026-%02d-%02d', $mo, $d);
                
                // ค้นหาแถว Total ล่วงหน้าของวันนี้
                $dayHasCol6Year = false;
                for ($k = $r + 1; $k < min(count($rows2All), $r + 35); $k++) {
                    $checkCode = trim($rows2All[$k]['c'][$mBaseCol]['v'] ?? '');
                    if (preg_match('/^\d{1,2}\/' . $mo . '\/\d{2,4}$/', $checkCode) ||
                        preg_match('/^\d{1,2}\/' . sprintf('%02d', $mo) . '\/\d{2,4}$/', $checkCode)) {
                        break;
                    }
                    if ($checkCode === 'Total') {
                        $dayHasCol6Year = (floatval($rows2All[$k]['c'][$mBaseCol + 6]['v'] ?? 0) > 0);
                        break;
                    }
                }
                continue;
            }
            
            $bhsVal = floatval($rows2All[$r]['c'][$mBaseCol + 3]['v'] ?? 0);
            $isoVal = $dayHasCol6Year ? floatval($rows2All[$r]['c'][$mBaseCol + 4]['v'] ?? 0) : 0;
            $yueVal = $dayHasCol6Year ? floatval($rows2All[$r]['c'][$mBaseCol + 5]['v'] ?? 0) : floatval($rows2All[$r]['c'][$mBaseCol + 4]['v'] ?? 0);
            $totVal = $dayHasCol6Year ? floatval($rows2All[$r]['c'][$mBaseCol + 6]['v'] ?? 0) : floatval($rows2All[$r]['c'][$mBaseCol + 5]['v'] ?? 0);

            if ($machine === 'BHS') {
                $cKg = $bhsVal;
            } elseif ($machine === 'ISOWA') {
                $cKg = $isoVal;
            } elseif ($machine === 'YUELI') {
                $cKg = $yueVal;
            } elseif ($machine === 'TOTAL') {
                $cKg = $totVal;
            } else {
                $cKg = $bhsVal;
            }

            if ($currIso && $cCode === 'Total') {
                if (!isset($allDaysData[$currIso])) {
                    $allDaysData[$currIso] = ['consum' => 0, 'output' => 0, 'waste11' => 0, 'sheetTotalBl' => 0, 'day' => $d, 'month' => $mo, 'year' => 2026];
                }
                $allDaysData[$currIso]['sheetTotalBl'] = $cKg;
                continue;
            }
            
            if ($currIso && $cCode !== '' && $cCode !== 'Reason Code' && $cCode !== 'Total') {
                if (in_array($cCode, $allowedCodes11)) {
                    if (!isset($allDaysData[$currIso])) {
                        $allDaysData[$currIso] = ['consum' => 0, 'output' => 0, 'waste11' => 0, 'sheetTotalBl' => 0, 'day' => $d, 'month' => $mo, 'year' => 2026];
                    }
                    $allDaysData[$currIso]['waste11'] += $cKg;
                } else {
                    $deptItemKey = mapCodeToDeptItemKey($cCode, $cDesc);
                    if ($deptItemKey !== null && isset($monthlyDeptAgg[$mo])) {
                        $deptKg = $cKg;
                        if ($deptKg == 0 && $totVal > 0 && $bhsVal == 0 && $isoVal == 0 && $yueVal == 0 && ($machine === 'BHS' || $machine === 'YUELI')) {
                            $deptKg = $totVal / 2.0;
                        }
                        $monthlyDeptAgg[$mo]['items'][$deptItemKey] += $deptKg;
                    }
                }
            }
        }
    }
}

// 4.3 รวมประวัติจาก imported_data.json เข้าไปในภาพรวมทั้งปีด้วย
if (isset($importedJson) && is_array($importedJson)) {
    if (isset($importedJson['con_out']) && is_array($importedJson['con_out'])) {
        foreach ($importedJson['con_out'] as $dateKey => $cRow) {
            if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $dateKey, $dm)) {
                $d = intval($dm[1]);
                $mo = intval($dm[2]);
                $yr = intval($dm[3]);
                if ($yr < 100) $yr += 2000;
                $isoDate = sprintf('%04d-%02d-%02d', $yr, $mo, $d);
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
                    if (!isset($allDaysData[$isoDate])) {
                        $allDaysData[$isoDate] = ['consum' => 0, 'output' => 0, 'waste11' => 0, 'sheetTotalBl' => 0, 'day' => $d, 'month' => $mo, 'year' => $yr];
                    }
                    $allDaysData[$isoDate]['consum'] = $c;
                    $allDaysData[$isoDate]['output'] = $o;
                }
            }
        }
    }
    if (isset($importedJson['waste']) && is_array($importedJson['waste'])) {
        $allowedCodes11 = ['BL18', 'BL17', 'BL16', 'BL21', 'BL20', 'BL15', 'BL12', 'BL19', 'BL22', 'BL24', 'BL25'];
        foreach ($importedJson['waste'] as $dateKey => $mList) {
            if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $dateKey, $dm)) {
                $d = intval($dm[1]);
                $mo = intval($dm[2]);
                $yr = intval($dm[3]);
                if ($yr < 100) $yr += 2000;
                $isoDate = sprintf('%04d-%02d-%02d', $yr, $mo, $d);
                $wMachine = $mList[$machine] ?? ($mList['ALL'] ?? null);
                $wItems = $wMachine['items'] ?? null;
                $waste11Day = 0;
                if ($wItems && is_array($wItems)) {
                    foreach ($wItems as $it) {
                        $cd = strtoupper(trim($it['code'] ?? ''));
                        if (in_array($cd, $allowedCodes11)) {
                            $waste11Day += floatval($it['kg'] ?? 0);
                        }
                    }
                }
                if ($waste11Day > 0) {
                    if (!isset($allDaysData[$isoDate])) {
                        $allDaysData[$isoDate] = ['consum' => 0, 'output' => 0, 'waste11' => 0, 'sheetTotalBl' => 0, 'day' => $d, 'month' => $mo, 'year' => $yr];
                    }
                    $allDaysData[$isoDate]['waste11'] = $waste11Day;
                }
            }
        }
    }
}

// 4.4 สร้างชุดข้อมูลแนวโน้มรายปี (Yearly Trend 1-12 เดือน) และข้อมูลรายเดือนแยกตามแผนก (deptMonthlyKg / deptMonthlyPct)
$monthShortNamesTh = [
    1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
    5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
    9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
];
$yearlyTrend = [];
for ($m = 1; $m <= 12; $m++) {
    $yearlyTrend[] = [
        'month' => $m,
        'label' => $monthShortNamesTh[$m],
        'fullName' => $monthNamesTh[$m],
        'consumption' => 0,
        'consumptionWithWaste' => 0,
        'output' => 0,
        'wasteKg' => 0,
        'wastePct' => 0,
        'daysCount' => 0
    ];
}
foreach ($allDaysData as $iso => $val) {
    $mIdx = $val['month'] - 1;
    $mo = $val['month'];
    if (isset($yearlyTrend[$mIdx])) {
        $c = $val['consum'];
        $o = $val['output'];
        $w = $val['waste11'];
        $sTot = $val['sheetTotalBl'] ?? 0;
        $yearlyTrend[$mIdx]['consumption'] += $c;
        $yearlyTrend[$mIdx]['output'] += $o;
        $yearlyTrend[$mIdx]['wasteKg'] += $w;
        if ($w > 0 && $c > 0) {
            $yearlyTrend[$mIdx]['consumptionWithWaste'] += $c;
        }
        if ($c > 0 || $w > 0) {
            $yearlyTrend[$mIdx]['daysCount']++;
        }
        if (isset($monthlyDeptAgg[$mo])) {
            $monthlyDeptAgg[$mo]['consum'] += $c;
            $monthlyDeptAgg[$mo]['output'] += $o;
            $monthlyDeptAgg[$mo]['pd_waste'] += $w;
            $monthlyDeptAgg[$mo]['sheetTotalBl'] += $sTot;
            if (($w > 0 || $sTot > 0) && $c > 0) {
                $monthlyDeptAgg[$mo]['consumWithWaste'] += $c;
            }
        }
    }
}
foreach ($yearlyTrend as &$y) {
    // หารด้วย consumption ทั้งเดือน (ไม่กรองเฉพาะวันที่มีของเสีย)
    $denomY = $y['consumption'];
    $y['wastePct'] = ($denomY > 0 && $y['wasteKg'] > 0) ? round(($y['wasteKg'] / $denomY) * 100, 2) : 0;
    $y['consumption'] = round($y['consumption']);
    $y['consumptionWithWaste'] = round($y['consumptionWithWaste']);
    $y['output'] = round($y['output']);
    $y['wasteKg'] = round($y['wasteKg']);
    $y['hasData'] = ($denomY > 0 || $y['output'] > 0 || $y['daysCount'] > 0);
}
unset($y);

// สร้างชุดข้อมูลรายเดือน 1..12 ของของเสียแยกตามแผนก
$deptMonthlyKg = [];
$deptMonthlyPct = [];
for ($m = 1; $m <= 12; $m++) {
    $mAgg = $monthlyDeptAgg[$m];
    $mCon = $mAgg['consum'];
    $mOut = $mAgg['output'];
    $mDenom = $mAgg['consumWithWaste'] > 0 ? $mAgg['consumWithWaste'] : $mCon;
    $mPdKg = $mAgg['pd_waste'];

    $mRowKg = [
        'month' => $m,
        'date' => $monthShortNamesTh[$m] . ' 2026',
        'fullName' => $monthNamesTh[$m] . ' 2026',
        'consumption' => round($mCon),
        'output' => round($mOut),
        'hasData' => ($mCon > 0 || $mOut > 0 || $mPdKg > 0)
    ];
    $mRowPct = [
        'month' => $m,
        'date' => $monthShortNamesTh[$m] . ' 2026',
        'fullName' => $monthNamesTh[$m] . ' 2026',
        'consumption' => round($mCon),
        'output' => round($mOut),
        'hasData' => ($mCon > 0 || $mOut > 0 || $mPdKg > 0)
    ];

    $mRowKg['pd_waste'] = round($mPdKg, 1);
    $mRowKg['dept_PD'] = round($mPdKg, 1);
    $mRowPct['pd_waste'] = ($mDenom > 0) ? round(($mPdKg / $mDenom) * 100, 2) : 0;
    $mRowPct['dept_PD'] = $mRowPct['pd_waste'];

    $mOtherBlSum = 0;
    foreach ($deptGroupsMap as $gKey => $keysList) {
        $gMoKg = 0;
        foreach ($keysList as $ik) {
            $valKg = floatval($mAgg['items'][$ik] ?? 0);
            $mRowKg[$ik] = round($valKg, 1);
            $mRowPct[$ik] = ($mDenom > 0) ? round(($valKg / $mDenom) * 100, 2) : 0;
            $gMoKg += $valKg;
        }
        $mRowKg["dept_{$gKey}"] = round($gMoKg, 1);
        $mRowPct["dept_{$gKey}"] = ($mDenom > 0) ? round(($gMoKg / $mDenom) * 100, 2) : 0;
        $mOtherBlSum += $gMoKg;
    }

    $mSheetTot = $mAgg['sheetTotalBl'] > 0 ? $mAgg['sheetTotalBl'] : ($mPdKg + $mOtherBlSum);
    $mDiffKg = ($mCon > 0 && ($mPdKg > 0 || $mSheetTot > 0 || $mOut > 0)) ? (($mCon - $mOut) - $mSheetTot) : 0;
    $mRowKg['diff_waste'] = round($mDiffKg, 1);
    $mRowKg['dept_DIFF'] = round($mDiffKg, 1);
    $mRowPct['diff_waste'] = ($mDenom > 0) ? round(($mDiffKg / $mDenom) * 100, 2) : 0;
    $mRowPct['dept_DIFF'] = $mRowPct['diff_waste'];

    $deptMonthlyKg[] = $mRowKg;
    $deptMonthlyPct[] = $mRowPct;
}

$deptWaste = [
    'dailyPct' => $deptDailyPct,
    'dailyKg' => $deptDailyKg,
    'monthlyPct' => $deptMonthlyPct,
    'monthlyKg' => $deptMonthlyKg,
    'itemTotalsKg' => $deptItemTotalsKg,
    'itemTotalsPct' => $deptItemTotalsPct,
    'groupTotalsKg' => $deptGroupTotalsKg,
    'groupTotalsPct' => $deptGroupTotalsPct,
    'comparison' => $deptComparison,
    'totalWeighedBlKg' => round($totalSheetBlKgMTD, 1)
];

// 4.5 สร้างชุดข้อมูลแยกตาม Week Number (Weekly Trend)
$weeklyMap = [];
foreach ($allDaysData as $iso => $val) {
    $dt = new DateTime($iso);
    $wNum = intval($dt->format('W'));
    $wKey = sprintf('W%02d', $wNum);
    
    if (!isset($weeklyMap[$wKey])) {
        $weeklyMap[$wKey] = [
            'weekNum' => $wNum,
            'weekKey' => $wKey,
            'label' => "W{$wNum}",
            'consumption' => 0,
            'consumptionWithWaste' => 0,
            'output' => 0,
            'wasteKg' => 0,
            'wastePct' => 0,
            'startDate' => $iso,
            'endDate' => $iso,
            'daysCount' => 0
        ];
    }
    $c = $val['consum'];
    $w = $val['waste11'];
    $weeklyMap[$wKey]['consumption'] += $c;
    $weeklyMap[$wKey]['output'] += $val['output'];
    $weeklyMap[$wKey]['wasteKg'] += $w;
    if ($w > 0 && $c > 0) {
        $weeklyMap[$wKey]['consumptionWithWaste'] += $c;
    }
    if ($c > 0 || $w > 0) {
        $weeklyMap[$wKey]['daysCount']++;
    }
    if ($iso < $weeklyMap[$wKey]['startDate']) $weeklyMap[$wKey]['startDate'] = $iso;
    if ($iso > $weeklyMap[$wKey]['endDate']) $weeklyMap[$wKey]['endDate'] = $iso;
}
ksort($weeklyMap);
$weeklyTrend = [];
foreach ($weeklyMap as $wKey => $wObj) {
    $denom = $wObj['consumptionWithWaste'] > 0 ? $wObj['consumptionWithWaste'] : $wObj['consumption'];
    $wObj['wastePct'] = ($denom > 0 && $wObj['wasteKg'] > 0) ? round(($wObj['wasteKg'] / $denom) * 100, 2) : 0;
    $wObj['consumption'] = round($wObj['consumption']);
    $wObj['consumptionWithWaste'] = round($wObj['consumptionWithWaste']);
    $wObj['output'] = round($wObj['output']);
    $wObj['wasteKg'] = round($wObj['wasteKg']);
    $weeklyTrend[] = $wObj;
}

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
    'weightSummary' => $weightTotals,
    'deptWaste' => $deptWaste,
    'yearlyTrend' => $yearlyTrend,
    'weeklyTrend' => $weeklyTrend,
    'machineSummaries' => $machineSummaries
], JSON_UNESCAPED_UNICODE);

