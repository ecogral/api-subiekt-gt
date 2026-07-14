<?php
/**
 * Naprawa ZK 7 bez WZ (Avocado) — cofnięcie do statusu 5 + rezerwacje.
 *
 *   php public/setup/repair-avocado-zk.php --dry-run
 *   php public/setup/repair-avocado-zk.php --apply
 *   php public/setup/repair-avocado-zk.php --apply --with-com
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\Order;

$options = getopt('', array('dry-run', 'apply', 'with-com'));
$apply = isset($options['apply']);
$dryRun = isset($options['dry-run']) || !$apply;
$withCom = isset($options['with-com']);

$orderRefs = array(
    'ZK 3603/07/2026',
    'ZK 3614/07/2026',
    'ZK 3615/07/2026',
    'ZK 3618/07/2026',
    'ZK 3620/07/2026',
    'ZK 3629/07/2026',
);

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

$warehouseId = (int) $cfg->getWarehouse();
if ($warehouseId <= 0) {
    $warehouseId = 1;
}

$results = array();
foreach ($orderRefs as $orderRef) {
    if ($dryRun) {
        $results[] = Order::repairStuckOrderWithoutIssueSql($orderRef, $warehouseId, true);
        continue;
    }

    if ($withCom) {
        try {
            $subiektGt = SubiektGT::getInstance($cfg);
            $com = $subiektGt->connect();
        } catch (\Throwable $e) {
            $results[] = array(
                'state' => 'com_unavailable',
                'order_ref' => $orderRef,
                'message' => 'Sfera COM niedostępna: ' . $e->getMessage()
                    . ' — naprawa SQL wykonana, ale dropdown w GT może być pusty do czasu Zapisz przez COM.',
            );
            continue;
        }
        $order = Order::loadExistingByRefVariants($com, array('order_ref' => $orderRef));
        if ($order === null) {
            $results[] = array('state' => 'not_found', 'order_ref' => $orderRef);
            continue;
        }
        $order->setCfg($cfg);
        $results[] = $order->repairStuckOrderWithoutIssue($warehouseId);
    } else {
        $results[] = Order::repairStuckOrderWithoutIssueSql($orderRef, $warehouseId, false);
    }
}

$symbols = array('IW00AV1', 'IW00AV2');
$resAfter = Order::findStockReservationMismatchesSql($warehouseId, $symbols);
$stocks = MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez, s.st_Stan - ISNULL(s.st_StanRez, 0) AS dostepne
     FROM tw__Towar t
     INNER JOIN tw_Stan s ON s.st_TowId = t.tw_Id AND s.st_MagId = {$warehouseId}
     WHERE t.tw_Symbol IN ('IW00AV1', 'IW00AV2')
     ORDER BY t.tw_Symbol"
);

echo json_encode(array(
    'dry_run' => $dryRun,
    'with_com' => $withCom,
    'orders' => $results,
    'stocks_after' => $stocks,
    'reservation_mismatches' => $resAfter,
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
