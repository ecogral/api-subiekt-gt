<?php
/**
 * Odpina błędne WZ od ZK i usuwa je (żeby wystawić WZ od nowa).
 *
 * Subiekt blokuje Usun/Anuluj, gdy WZ ma powiązania w bazie — skrypt najpierw czyści linki SQL.
 *
 * Przykłady:
 *   php public/setup/reset-zk-wz.php --dry-run --month=6 --year=2026 --numbers=3395
 *   php public/setup/reset-zk-wz.php --apply --month=6 --year=2026 --numbers=3395
 *   php public/setup/reset-zk-wz.php --apply --month=6 --year=2026 --numbers=3395 --wz="WZ 3500/06/2026"
 *   php public/setup/reset-zk-wz.php --apply --sql-only --month=6 --year=2026 --numbers=3395
 *   php public/setup/reset-zk-wz.php --apply --with-com --month=6 --year=2026 --numbers=3395
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\Order;

$options = getopt('', array(
    'dry-run',
    'apply',
    'month::',
    'year::',
    'numbers::',
    'numbers-file::',
    'wz::',
    'sql-only',
    'with-com',
));

$apply = isset($options['apply']);
$dryRun = isset($options['dry-run']) || !$apply;
$month = isset($options['month']) ? (int) $options['month'] : (int) date('n');
$year = isset($options['year']) ? (int) $options['year'] : (int) date('Y');
$issueRefFilter = isset($options['wz']) ? trim((string) $options['wz']) : '';
$removeViaCom = isset($options['with-com']);
if (isset($options['sql-only'])) {
    $removeViaCom = false;
}

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

if (empty($orderNumbers)) {
    fwrite(STDERR, "Podaj --numbers=... lub --numbers-file=...\n");
    exit(1);
}

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();

$payload = array(
    'month' => $month,
    'year' => $year,
    'apply' => $apply && !$dryRun,
    'remove_via_com' => $removeViaCom,
    'order_numbers' => $orderNumbers,
);
if ($issueRefFilter !== '') {
    $payload['issue_ref'] = $issueRefFilter;
}

if ($dryRun || !$removeViaCom) {
    MSSql::getInstance(array(
        'UID' => $cfg->getDbUser(),
        'PWD' => $cfg->getDbUserPass(),
        'Database' => $cfg->getDatabase(),
    ), $cfg->getServer());
    $orderApi = new Order(null, array());
    $orderApi->setCfg($cfg);
    $result = $orderApi->batchResetOrderIssues($payload);
} else {
    $subiektGt = SubiektGT::getInstance($cfg);
    $subiektGtCom = $subiektGt->connect();
    $orderApi = new Order($subiektGtCom, $payload);
    $orderApi->setCfg($cfg);
    $result = $orderApi->batchResetOrderIssues($payload);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (($result['state'] ?? '') !== 'success') {
    exit(1);
}

exit(0);
