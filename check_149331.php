<?php
$sheetId = '1viioRJ5I3BmqiD7KLwSG9_uNQ_9u_a8LYR-4GaPGuYI';
$url = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:csv&sheet=" . urlencode('Waste BHS');
$c = file_get_contents($url);
$lines = explode("\n", $c);

// Search for 149,331 or 149331
foreach ($lines as $i => $line) {
    if (strpos($line, '149,331') !== false || strpos($line, '149331') !== false) {
        echo "Found 149331 at line $i: $line\n";
    }
}
