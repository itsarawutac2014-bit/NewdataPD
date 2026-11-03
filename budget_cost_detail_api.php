<?php
/**
 * budget_cost_detail_api.php - Multi-Machine Cost Detail API
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

$reqMachine = strtoupper(trim($_GET['machine'] ?? 'TOTAL'));
if (!in_array($reqMachine, ['TOTAL', 'BHS', 'ISOWA', 'YUELI'])) {
    $reqMachine = 'TOTAL';
}

$costDetailFile = __DIR__ . '/data/budget_cost_detail.json';
if (file_exists($costDetailFile)) {
    $costData = json_decode(@file_get_contents($costDetailFile), true);
    if (!empty($costData['costs'])) {
        echo json_encode([
            'success'    => true,
            'machine'    => $reqMachine,
            'costs'      => $costData['costs'],
            'updated_at' => $costData['updated_at'] ?? date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$v2File = __DIR__ . '/data/budget_database_v2.json';
if (file_exists($v2File)) {
    $db = json_decode(@file_get_contents($v2File), true);
    $machData = $db['machines'][$reqMachine] ?? ($db['machines']['TOTAL'] ?? null);
    if (!empty($machData['cost_target'])) {
        $cTarget = $machData['cost_target'];
        $cActual = $machData['cost_actual'] ?? [];

        $catNames = [
            'coal'   => 'ถ่านหิน',
            'glue'   => 'กาว',
            'water'  => 'ค่าน้ำประปา',
            'air'    => 'ค่าลม',
            'elec'   => 'ค่าไฟฟ้า',
            'repair' => 'ค่าซ่อมแซมเครื่องจักร',
            'ot'     => 'ค่าล่วงเวลา',
            'labor'  => 'ค่าแรง',
            'total'  => 'Total VC+FC'
        ];

        $costs = [];
        foreach ($catNames as $catKey => $catLabel) {
            $costs[$catLabel] = [];
            $allTv = 0; $allAv = 0; $allTq = 0; $allAq = 0;
            for ($m = 1; $m <= 12; $m++) {
                $t = $cTarget[$m][$catKey] ?? ['val' => 0, 'qty' => 0, 'bkg' => 0];
                $a = $cActual[$m][$catKey] ?? ['val' => 0, 'qty' => 0, 'bkg' => 0];
                $tv = $t['val']; $av = $a['val'];
                $tbk = $t['bkg']; $abk = $a['bkg'];
                $pct = ($tv > 0 && $av > 0) ? round(($av / $tv) * 100, 1) : null;

                $costs[$catLabel]['m' . $m] = [
                    'target_value'  => $tv > 0 ? round($tv) : null,
                    'target_bahtkg' => $tbk > 0 ? round($tbk, 3) : null,
                    'actual_value'  => $av > 0 ? round($av) : null,
                    'actual_bahtkg' => $abk > 0 ? round($abk, 3) : null,
                    'pct_actual'    => $pct,
                ];

                $allTv += $tv; $allAv += $av;
                $allTq += $t['qty']; $allAq += $a['qty'];
            }

            $allPct = ($allTv > 0 && $allAv > 0) ? round(($allAv / $allTv) * 100, 1) : null;
            $costs[$catLabel]['ALL'] = [
                'target_value'  => $allTv > 0 ? round($allTv) : null,
                'target_bahtkg' => $allTq > 0 ? round($allTv / $allTq, 3) : null,
                'actual_value'  => $allAv > 0 ? round($allAv) : null,
                'actual_bahtkg' => $allAq > 0 ? round($allAv / $allAq, 3) : null,
                'pct_actual'    => $allPct,
            ];
        }

        echo json_encode([
            'success'    => true,
            'machine'    => $reqMachine,
            'costs'      => $costs,
            'updated_at' => $db['updated_at'] ?? date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'No cost detail data']);
