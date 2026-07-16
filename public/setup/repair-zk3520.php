<?php
/**
 * Naprawa ZK 3520: zerowanie ob_IloscMag bez WZ + sync rezerwacji towarów.
 *
 *   php public/setup/repair-zk3520.php
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$ref = 'ZK 3520/07/2026';
$symbols = array('FC-0006', 'EMS001');

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$wh = (int) $cfg->getWarehouse() ?: 1;
MSSql::getInstance([
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
], $cfg->getServer());

$zk = Order::getOrderRowByRefSql($ref);
$orderId = (int) ($zk['dok_Id'] ?? 0);
if ($orderId <= 0) {
    fwrite(STDERR, "Nie znaleziono {$ref}\n");
    exit(1);
}

echo "=== PRZED naprawą {$ref} (id={$orderId}, status=" . (int) ($zk['dok_Status'] ?? 0) . ") ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, p.ob_Ilosc, ISNULL(p.ob_IloscMag, 0) AS wydano
     FROM dok_Pozycja p JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE p.ob_DokHanId = {$orderId} ORDER BY t.tw_Symbol"
));

foreach ($symbols as $sym) {
    $m = Order::findStockReservationMismatchesSql($wh, array($sym));
    echo "Rezerwacja {$sym}: ";
    print_r($m ?: array('OK'));
}

echo "\n=== reset ob_IloscMag bez WZ ===\n";
$reset = Order::resetOrderPositionIssuedQtyWithoutIssueSql($orderId);
echo "Zresetowano pozycji: {$reset}\n";

echo "\n=== repairStuckOrderWithoutIssue ===\n";
$repair = MSSql::withSqlWriteFallback(function () use ($ref, $wh) {
    return Order::repairStuckOrderWithoutIssueSql($ref, $wh, false, true);
});
print_r($repair);

echo "\n=== sync st_StanRez (FC-0006, EMS001) ===\n";
$sync = MSSql::withSqlWriteFallback(function () use ($wh, $symbols) {
    return Order::syncStockReservationsFromOrdersSql($wh, false, $symbols);
});
print_r($sync);

echo "\n=== PO naprawie ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, p.ob_Ilosc, ISNULL(p.ob_IloscMag, 0) AS wydano
     FROM dok_Pozycja p JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE p.ob_DokHanId = {$orderId} ORDER BY t.tw_Symbol"
));
foreach ($symbols as $sym) {
    print_r(MSSql::getInstance()->query(
        "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez, s.st_Stan - s.st_StanRez AS wolne
         FROM tw__Towar t LEFT JOIN tw_Stan s ON s.st_TowId = t.tw_Id AND s.st_MagId = {$wh}
         WHERE t.tw_Symbol = '{$sym}'"
    ));
}
echo 'SQL shortages: ';
print_r(Order::getOrderWarehouseStockShortagesSql($orderId, $wh, 5));
