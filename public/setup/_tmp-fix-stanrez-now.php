<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;
use APISubiektGT\SubiektGT\OrderComWriter;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$comWritesOnly = !isset($cfg->use_com_writes_only) || (string) $cfg->use_com_writes_only !== '0';
OrderComWriter::configure($comWritesOnly, false);
MSSql::setComWritesOnly($comWritesOnly, false);
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

$before = Order::findStockReservationMismatchesSql(1, null);
echo "BEFORE count=" . count($before) . " orphan_sum=" . array_sum(array_map(function ($r) {
    return (float)($r['orphan_rez'] ?? 0);
}, $before)) . "\n";

$result = Order::syncStockReservationsFromOrdersSql(1, false, null);
echo "APPLY state=" . ($result['state'] ?? '?') . " fixed=" . ($result['fixed_count'] ?? 0)
    . " remaining=" . ($result['remaining_count'] ?? '?') . "\n";
echo "message=" . ($result['message'] ?? '') . "\n";

$after = Order::findStockReservationMismatchesSql(1, null);
echo "AFTER count=" . count($after) . " orphan_sum=" . array_sum(array_map(function ($r) {
    return (float)($r['orphan_rez'] ?? 0);
}, $after)) . "\n";
if (!empty($after)) {
    foreach (array_slice($after, 0, 15) as $r) {
        echo '  ' . ($r['symbol'] ?? '') . ' cur=' . ($r['current_rez'] ?? '')
            . ' exp=' . ($r['expected_rez'] ?? '') . ' orphan=' . ($r['orphan_rez'] ?? '') . "\n";
    }
}
