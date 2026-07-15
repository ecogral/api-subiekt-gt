<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$ref = 'ZK 3656/07/2026';

$c = new Config(CONFIG_INI_FILE);
$c->load();
MSSql::getInstance([
    'UID' => $c->getDbUser(),
    'PWD' => $c->getDbUserPass(),
    'Database' => $c->getDatabase(),
], $c->getServer());

echo "=== Nagłówek ZK 3656 ===\n";
$zk = Order::getOrderRowByRefSql($ref);
print_r($zk);

$orderId = (int) ($zk['dok_Id'] ?? 0);
echo "\n=== Pozycje ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, p.ob_Ilosc, ISNULL(p.ob_IloscMag,0) AS wydano,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) AS pozostalo
     FROM dok_Pozycja p
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE p.ob_DokHanId = {$orderId}
     ORDER BY t.tw_Symbol"
));

echo "\n=== WZ powiązane ===\n";
print_r(Order::getIssueRefsForOrder($ref, $orderId));

echo "\n=== Diagnostyka rezerwacji ===\n";
echo 'status=' . (int) ($zk['dok_Status'] ?? 0) . "\n";
echo 'orderHasActiveReservationSql=' . (Order::orderHasActiveReservationSql($orderId, $ref) ? 'TAK' : 'nie') . "\n";
echo 'isOrderStuckWithoutIssue=' . (Order::isOrderStuckWithoutIssueSql($orderId, $ref) ? 'TAK' : 'nie') . "\n";

echo "\n=== Pełny nagłówek SQL ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT dok_NrPelny, dok_Status, dok_StatusEx, dok_ZrealizowaneZRezerwacja,
            dok_ObiektGT, dok_DataWyst, dok_DataMag, dok_Uwagi, dok_NrPelnyOryg
     FROM dok__Dokument WHERE dok_NrPelny = '{$ref}'"
));

echo "\n=== Historia dokumentu ===\n";
if ($orderId > 0) {
    $hist = MSSql::getInstance()->query(
        "SELECT TOP 20 hi_Id, hi_Data, hi_Opis, hi_UzytkId
         FROM dok_Historia WHERE hi_DokId = {$orderId} ORDER BY hi_Id DESC"
    );
    print_r($hist ?: array('brak tabeli/historii'));
}

echo "\n=== Logi API (3656) ===\n";
$logDir = defined('LOG_DIR') ? LOG_DIR : dirname(__FILE__) . '/../../logs/';
foreach (glob($logDir . '/*.log') ?: array() as $logFile) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        continue;
    }
    $hits = array();
    foreach ($lines as $line) {
        if (stripos($line, '3656') !== false) {
            $hits[] = $line;
        }
    }
    if (!empty($hits)) {
        echo basename($logFile) . " (" . count($hits) . " linii):\n";
        foreach (array_slice($hits, -15) as $h) {
            echo "  " . $h . "\n";
        }
    }
}
