<?php
$raw = file_get_contents(__DIR__ . '/_tmp-diag-zk-wz-uwagi-out.json');
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
$j = json_decode($raw, true);
if (!$j) {
    echo "bad json\n";
    exit(1);
}
foreach ($j['orders'] ?? array() as $ref => $o) {
    echo "\n=== $ref ===\n";
    $zk = $o['zk'] ?? array();
    echo "status: " . ($zk['status_label'] ?? '?') . " | oryg: " . ($zk['oryg'] ?? '') . "\n";
    echo "uwagi: " . ($zk['uwagi'] ?? '') . "\n";
    echo "valid WZ: " . implode(', ', $o['wz_valid'] ?? array()) . "\n";
    echo "invalid WZ: " . implode(', ', $o['wz_invalid'] ?? array()) . "\n";
    echo "linked by positions: ";
    foreach ($o['wz_linked_positions'] ?? array() as $w) {
        echo ($w['dok_NrPelny'] ?? '') . ' ';
    }
    echo "\n";
    echo "by uwagi search:\n";
    foreach ($o['wz_found_by_uwagi_tokens'] ?? array() as $hit) {
        $w = $hit['wz'] ?? array();
        $lnk = !empty($hit['linked_to_this_zk']) ? 'LINKED' : 'not-linked';
        echo "  - " . ($w['dok_NrPelny'] ?? '') . " oryg=" . ($w['dok_NrPelnyOryg'] ?? '')
            . " netto=" . ($w['dok_WartNetto'] ?? '') . " [$lnk] uwagi="
            . substr((string)($w['dok_Uwagi'] ?? ''), 0, 80) . "\n";
    }
    echo "stock:\n";
    foreach ($o['stock_snapshot'] ?? array() as $s) {
        echo "  " . ($s['symbol'] ?? '') . " ord=" . ($s['ordered'] ?? '')
            . " ilMag=" . ($s['ilosc_mag_zk'] ?? '') . " stan=" . ($s['stan'] ?? '')
            . " rez=" . ($s['rez'] ?? '') . "\n";
    }
}
echo "\n=== GAMA WZ lipiec 2026 (count " . count($j['gama_wz_july_2026'] ?? array()) . ") ===\n";
foreach ($j['gama_wz_july_2026'] ?? array() as $w) {
    echo ($w['dok_NrPelny'] ?? '') . " | oryg=" . ($w['dok_NrPelnyOryg'] ?? '')
        . " | netto=" . ($w['dok_WartNetto'] ?? '') . " | " . substr((string)($w['uwagi_short'] ?? ''), 0, 60) . "\n";
}
