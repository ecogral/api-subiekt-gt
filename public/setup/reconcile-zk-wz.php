<?php
/**
 * Masowy audyt / domknięcie ZK z poprawnym WZ (bez tworzenia nowego WZ).
 *
 * Przykłady:
 *   php public/setup/reconcile-zk-wz.php --dry-run --month=6 --year=2026 --numbers=2866,2879,2885
 *   php public/setup/reconcile-zk-wz.php --dry-run --month=6 --year=2026 --auto-scan
 *   php public/setup/reconcile-zk-wz.php --apply --month=6 --year=2026 --numbers-file=zk-list.txt
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\Order;

$options = getopt('', array('dry-run', 'apply', 'month::', 'year::', 'numbers::', 'numbers-file::', 'auto-scan'));

$apply = isset($options['apply']);
$dryRun = isset($options['dry-run']) || !$apply;
$month = isset($options['month']) ? (int) $options['month'] : (int) date('n');
$year = isset($options['year']) ? (int) $options['year'] : (int) date('Y');
$autoScan = isset($options['auto-scan']);

$orderNumbers = array();
if (!empty($options['numbers'])) {
    foreach (explode(',', (string) $options['numbers']) as $part) {
        $part = trim($part);
        if ($part !== '') {
            $orderNumbers[] = $part;
        }
    }
}
if (!empty($options['numbers-file'])) {
    $file = (string) $options['numbers-file'];
    if (!is_file($file)) {
        fwrite(STDERR, "Brak pliku: {$file}\n");
        exit(1);
    }
    $raw = file_get_contents($file);
    foreach (preg_split('/[\s,;]+/', $raw) as $part) {
        $part = trim($part);
        if ($part !== '') {
            $orderNumbers[] = $part;
        }
    }
}

if (!$autoScan && empty($orderNumbers)) {
    fwrite(STDERR, "Podaj --numbers=... lub --numbers-file=... albo --auto-scan\n");
    exit(1);
}

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();

$subiektGt = SubiektGT::getInstance($cfg);
$subiektGtCom = $subiektGt->connect();

$payload = array(
    'month' => $month,
    'year' => $year,
    'apply' => $apply && !$dryRun,
    'auto_scan' => $autoScan,
);
if (!empty($orderNumbers)) {
    $payload['order_numbers'] = $orderNumbers;
}

$orderApi = new Order($subiektGtCom, $payload);
$orderApi->setCfg($cfg);
$result = $orderApi->batchReconcileIssueCoverage();

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (($result['state'] ?? '') !== 'success') {
    exit(1);
}

exit(0);
