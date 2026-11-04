<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$dataFile = __DIR__ . '/data/employees_store.json';
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
}

// ล้างค่าวันที่ที่มี T17:00:00.000Z ให้เหลือเฉพาะ YYYY-MM-DD ตามเวลาประเทศไทย
function cleanDatePhp($val) {
    if (empty($val) || !is_string($val)) return $val ?: '';
    $trimmed = trim($val);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) return $trimmed;
    if (strpos($trimmed, 'T') !== false) {
        try {
            $dt = new DateTime($trimmed);
            $dt->setTimezone(new DateTimeZone('Asia/Bangkok'));
            return $dt->format('Y-m-d');
        } catch (Exception $e) {}
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $trimmed, $m)) {
        return $m[1];
    }
    return $trimmed;
}

function sanitizeEmployeesDates(&$employees) {
    if (!is_array($employees)) return;
    foreach ($employees as &$emp) {
        if (isset($emp['start_date'])) $emp['start_date'] = cleanDatePhp($emp['start_date']);
        if (isset($emp['birth_date'])) $emp['birth_date'] = cleanDatePhp($emp['birth_date']);
        if (isset($emp['resigned_date'])) $emp['resigned_date'] = cleanDatePhp($emp['resigned_date']);
    }
}

// โหลดข้อมูลพนักงานจาก data/employees_store.json หรือ fallback จาก datamanbhs_export.json
function loadStore($dataFile) {
    if (file_exists($dataFile)) {
        $content = @file_get_contents($dataFile);
        $json = @json_decode($content, true);
        if (is_array($json) && isset($json['employees']) && is_array($json['employees']) && count($json['employees']) > 0) {
            sanitizeEmployeesDates($json['employees']);
            return $json;
        }
    }
    // Fallback to initial export
    $exportFile = __DIR__ . '/assets/data/datamanbhs_export.json';
    if (file_exists($exportFile)) {
        $content = @file_get_contents($exportFile);
        $json = @json_decode($content, true);
        if (is_array($json) && isset($json['employees'])) {
            sanitizeEmployeesDates($json['employees']);
            return $json;
        }
    }
    return ['employees' => [], 'attendance' => [], 'daily_shifts' => []];
}

// บันทึกลง data/employees_store.json
function saveStore($dataFile, $store) {
    if (isset($store['employees']) && is_array($store['employees'])) {
        sanitizeEmployeesDates($store['employees']);
    }
    return @file_put_contents($dataFile, json_encode($store, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// GET: ส่งข้อมูลพนักงานล่าสุดให้ทุกเครื่องที่เปิดดู
if ($method === 'GET') {
    $store = loadStore($dataFile);
    echo json_encode([
        'success' => true,
        'employees' => $store['employees'] ?? [],
        'attendance' => $store['attendance'] ?? [],
        'updated_at' => $store['updated_at'] ?? null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// POST: บันทึกข้อมูลที่มีการแก้ไขจากเครื่องใดๆ
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $payload = @json_decode($rawInput, true);

    if (!$payload) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    $action = $payload['action'] ?? 'edit_employee';
    $store = loadStore($dataFile);
    if (!isset($store['employees']) || !is_array($store['employees'])) {
        $store['employees'] = [];
    }

    // ซิงค์ทั้งชุด
    if ($action === 'sync_all' || $action === 'save_all') {
        if (isset($payload['employees']) && is_array($payload['employees'])) {
            $store['employees'] = $payload['employees'];
        }
        if (isset($payload['attendance'])) {
            $store['attendance'] = $payload['attendance'];
        }
        $store['updated_at'] = date('Y-m-d H:i:s');
        saveStore($dataFile, $store);
        echo json_encode(['success' => true, 'message' => 'บันทึกข้อมูลพนักงานทั้งหมดสำเร็จ']);
        exit;
    }

    // แก้ไขพนักงานรายบุคคล
    if ($action === 'edit_employee' || $action === 'update_employee') {
        $emp = $payload['employee'] ?? $payload;
        $code = strval($emp['employee_code'] ?? '');
        $id = $emp['id'] ?? null;
        
        $found = false;
        foreach ($store['employees'] as $idx => $existing) {
            if (($id !== null && strval($existing['id'] ?? '') === strval($id)) || 
                ($code !== '' && strval($existing['employee_code'] ?? '') === $code)) {
                $merged = array_merge($existing, $emp);
                // ป้องกันการทับข้อมูลสำคัญหากฝั่งส่งมาเป็นค่าว่าง
                if (empty($emp['start_date']) && !empty($existing['start_date'])) {
                    $merged['start_date'] = $existing['start_date'];
                }
                if (empty($emp['birth_date']) && !empty($existing['birth_date'])) {
                    $merged['birth_date'] = $existing['birth_date'];
                }
                if (empty($emp['phone']) && !empty($existing['phone'])) {
                    $merged['phone'] = $existing['phone'];
                }
                $store['employees'][$idx] = $merged;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $store['employees'][] = $emp;
        }

        $store['updated_at'] = date('Y-m-d H:i:s');
        saveStore($dataFile, $store);
        echo json_encode(['success' => true, 'message' => 'อัปเดตข้อมูลพนักงานสำเร็จ', 'employee' => $emp]);
        exit;
    }

    // ปรับสถานะพนักงานแบบด่วน
    if ($action === 'update_status') {
        $id = $payload['id'] ?? null;
        $code = strval($payload['employee_code'] ?? '');
        $status = $payload['status'] ?? 'active';
        $resignedDate = $payload['resigned_date'] ?? '';
        $notes = $payload['notes'] ?? '';

        $updatedEmp = null;
        foreach ($store['employees'] as $idx => $existing) {
            if (($id !== null && strval($existing['id'] ?? '') === strval($id)) || 
                ($code !== '' && strval($existing['employee_code'] ?? '') === $code)) {
                $store['employees'][$idx]['status'] = $status;
                $store['employees'][$idx]['resigned_date'] = in_array($status, ['resigned', 'terminated', 'suspended']) ? $resignedDate : '';
                $store['employees'][$idx]['notes'] = $notes;
                $store['employees'][$idx]['updated_at'] = date('Y-m-d H:i:s');
                $updatedEmp = $store['employees'][$idx];
                break;
            }
        }
        $store['updated_at'] = date('Y-m-d H:i:s');
        saveStore($dataFile, $store);
        echo json_encode(['success' => true, 'message' => 'ปรับสถานะพนักงานสำเร็จ', 'employee' => $updatedEmp]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}
