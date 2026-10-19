<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$dataFile = __DIR__ . '/data/imported_data.json';

// โหลดข้อมูลที่เคยนำเข้าไว้
function loadImportedData($path) {
    if (file_exists($path)) {
        $content = file_get_contents($path);
        $json = json_decode($content, true);
        if (is_array($json)) return $json;
    }
    return [
        'con_out' => [],
        'waste' => []
    ];
}

// บันทึกข้อมูล
function saveImportedData($path, $data) {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? ($_GET['action'] ?? '');

$imported = loadImportedData($dataFile);

switch ($action) {
    case 'save_con_out':
        $rows = $input['rows'] ?? [];
        if (empty($rows)) {
            echo json_encode(['success' => false, 'error' => 'ไม่มีข้อมูล rows ที่ส่งมา']);
            exit;
        }

        $count = 0;
        foreach ($rows as $r) {
            $date = trim($r['date'] ?? '');
            if (!$date) continue;

            $imported['con_out'][$date] = [
                'date' => $date,
                'bhs_consum' => floatval($r['bhs_consum'] ?? 0),
                'bhs_output' => floatval($r['bhs_output'] ?? 0),
                'yueli_consum' => floatval($r['yueli_consum'] ?? 0),
                'yueli_output' => floatval($r['yueli_output'] ?? 0),
                'isowa_consum' => floatval($r['isowa_consum'] ?? 0),
                'isowa_output' => floatval($r['isowa_output'] ?? 0),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            $count++;
        }

        saveImportedData($dataFile, $imported);
        echo json_encode([
            'success' => true,
            'message' => "บันทึกข้อมูล con out สำเร็จ {$count} วัน",
            'count' => $count
        ]);
        break;

    case 'save_waste':
        $date = trim($input['date'] ?? '');
        $machine = strtoupper(trim($input['machine'] ?? 'BHS'));
        $items = $input['items'] ?? [];

        if (!$date) {
            echo json_encode(['success' => false, 'error' => 'กรุณาระบุวันที่ของข้อมูล Waste']);
            exit;
        }

        if (empty($items)) {
            echo json_encode(['success' => false, 'error' => 'ไม่มีรายการของเสีย']);
            exit;
        }

        // จัดโครงสร้างเก็บตาม วันที่ -> เครื่อง
        if (!isset($imported['waste'][$date])) {
            $imported['waste'][$date] = [];
        }

        $totalKg = 0;
        $mappedItems = [];
        foreach ($items as $it) {
            $code = strtoupper(trim($it['code'] ?? ''));
            $name = trim($it['name'] ?? '');
            $kg = floatval($it['kg'] ?? 0);
            $totalKg += $kg;

            $mappedItems[] = [
                'code' => $code,
                'name' => $name,
                'kg' => $kg
            ];
        }

        $imported['waste'][$date][$machine] = [
            'date' => $date,
            'machine' => $machine,
            'items' => $mappedItems,
            'totalKg' => $totalKg,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        saveImportedData($dataFile, $imported);
        echo json_encode([
            'success' => true,
            'message' => "บันทึกข้อมูล Waste วันที่ {$date} เครื่อง {$machine} สำเร็จ (รวม " . number_format($totalKg) . " กก.)",
            'date' => $date,
            'machine' => $machine,
            'totalKg' => $totalKg
        ]);
        break;

    case 'sync_google_sheet':
        $webhookUrl = trim($input['webhook_url'] ?? '');
        $payload = $input['payload'] ?? null;

        if (!$webhookUrl) {
            echo json_encode(['success' => false, 'error' => 'กรุณาระบุ Google Apps Script Web App URL']);
            exit;
        }

        if (!$payload) {
            echo json_encode(['success' => false, 'error' => 'ไม่มีข้อมูล payload ที่จะส่ง']);
            exit;
        }

        // ส่งผ่าน cURL พร้อม follow redirect
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            echo json_encode(['success' => false, 'error' => 'เชื่อมต่อ Google Apps Script ผิดพลาด: ' . $err]);
        } else {
            $resJson = json_decode($response, true);
            echo json_encode([
                'success' => true,
                'httpCode' => $httpCode,
                'response' => $resJson ?? $response
            ]);
        }
        break;

    case 'get_status':
        $conOutDays = count($imported['con_out'] ?? []);
        $wasteEntries = 0;
        foreach ($imported['waste'] ?? [] as $d => $machines) {
            $wasteEntries += count($machines);
        }
        echo json_encode([
            'success' => true,
            'conOutDays' => $conOutDays,
            'wasteEntries' => $wasteEntries,
            'data' => $imported
        ]);
        break;

    case 'clear':
        $imported = ['con_out' => [], 'waste' => []];
        saveImportedData($dataFile, $imported);
        echo json_encode(['success' => true, 'message' => 'ล้างข้อมูลที่นำเข้าเรียบร้อยแล้ว']);
        break;

    default:
        echo json_encode([
            'status' => 'online',
            'importedSummary' => [
                'conOutDays' => count($imported['con_out'] ?? []),
                'wasteDays' => count($imported['waste'] ?? [])
            ]
        ]);
        break;
}
