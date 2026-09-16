<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;
use APISubiektGT\SubiektGT\OrderComWriter;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
OrderComWriter::configure(true, false);
MSSql::setComWritesOnly(true, false);
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

$symbols = array('EA00024', 'IW00AV1', 'IW00AV2');
$before = Order::findStockReservationMismatchesSql(1, $symbols);
echo "BEFORE:\n";
foreach ($before as $r) {
    echo '  ' . $r['symbol'] . ' cur=' . $r['current_rez'] . ' exp=' . $r['expected_rez'] . "\n";
}
$res = Order::syncStockReservationsFromOrdersSql(1, false, $symbols);
echo "APPLY fixed=" . ($res['fixed_count'] ?? 0) . " remaining=" . ($res['remaining_count'] ?? '?') . "\n";
$after = Order::findStockReservationMismatchesSql(1, $symbols);
echo "AFTER count=" . count($after) . "\n";
