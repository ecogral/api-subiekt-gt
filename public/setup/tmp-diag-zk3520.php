<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$ref = 'ZK 3520/07/2026';

$c = new Config(CONFIG_INI_FILE);
$c->load();
MSSql::getInstance([
    'UID' => $c->getDbUser(),
    'PWD' => $c->getDbUserPass(),
    'Database' => $c->getDatabase(),
], $c->getServer());

$wh = (int) $c->getWarehouse();
$zk = Order::getOrderRowByRefSql($ref);
echo "=== ZK 3520 header ===\n";
print_r($zk);

$orderId = (int) ($zk['dok_Id'] ?? 0);
$status = (int) ($zk['dok_Status'] ?? 0);

echo "\n=== Pozycje ZK ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, p.ob_Ilosc, ISNULL(p.ob_IloscMag, 0) AS wydano,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) AS pozostalo
     FROM dok_Pozycja p
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE p.ob_DokHanId = {$orderId}
     ORDER BY t.tw_Symbol"
));

foreach (array('FC-0006', 'EMS001') as $sym) {
    echo "\n=== Stan magazynowy {$sym} ===\n";
    print_r(MSSql::getInstance()->query(
        "SELECT t.tw_Symbol, ISNULL(s.st_Stan, 0) AS stan, ISNULL(s.st_StanRez, 0) AS rez,
                ISNULL(s.st_Stan, 0) - ISNULL(s.st_StanRez, 0) AS wolne
         FROM tw__Towar t
         LEFT JOIN tw_Stan s ON s.st_TowId = t.tw_Id AND s.st_MagId = {$wh}
         WHERE t.tw_Symbol = '{$sym}'"
    ));

    echo "=== Otwarte ZK (5/6) z {$sym} ===\n";
    print_r(MSSql::getInstance()->query(
        "SELECT d.dok_NrPelny, d.dok_Status, p.ob_Ilosc
         FROM dok__Dokument d
         INNER JOIN dok_Pozycja p ON p.ob_DokHanId = d.dok_Id
         INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
         WHERE d.dok_Typ = 16 AND d.dok_Status IN (5, 6) AND t.tw_Symbol = '{$sym}'
         ORDER BY d.dok_NrPelny"
    ));
}

echo "\n=== SQL shortages (getOrderWarehouseStockShortagesSql) ===\n";
print_r(Order::getOrderWarehouseStockShortagesSql($orderId, $wh, $status));

echo 'orderHasActiveReservationSql=' . (Order::orderHasActiveReservationSql($orderId, $ref) ? 'TAK' : 'nie') . "\n";

echo "\n=== WZ powiązane ===\n";
print_r(Order::getIssueRefsForOrder($ref, $orderId));
