<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$c = new Config(CONFIG_INI_FILE);
$c->load();
$db = MSSql::getInstance([
    'UID' => $c->getDbUser(),
    'PWD' => $c->getDbUserPass(),
    'Database' => $c->getDatabase(),
], $c->getServer());

$rows = $db->query(
    "SELECT TOP 3 dok_Id, dok_NrPelny, dok_Status FROM dok__Dokument
     WHERE dok_Typ=16 AND dok_Status IN (5,6,7) AND dok_DataWyst>='2026-07-01' ORDER BY dok_Id"
);
foreach ($rows as $row) {
    $id = (int)$row['dok_Id'];
    $ref = $row['dok_NrPelny'];
    echo "$ref: ";
    try {
        echo 'coverage=' . (Order::isOrderFullyCoveredByLinkedIssuesSql($id, $ref) ? 'Y' : 'N') . ' ';
        echo 'remaining=' . Order::getOrderRemainingQtyFromSql($id, $ref, true);
        echo "\n";
    } catch (Throwable $e) {
        echo 'ERR: ' . $e->getMessage() . "\n";
    }
}

echo "rez mismatches: ";
$m = Order::findStockReservationMismatchesSql(1);
echo is_array($m) ? count($m) : 'fail';
echo "\n";
