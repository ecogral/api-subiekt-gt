<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

$orderRef = 'ZK 599/07/2026';
$wzRef = 'WZ 486/07/2026';

$sgt = new SubiektGT($cfg);
$sgt->connect();
$order = Order::loadExistingByRefVariants($sgt, array('order_ref' => $orderRef));
$order->setCfg($cfg);
$orderId = (int) $order->gt_id;

echo "orphans before: ";
print_r($order->findOrphanIssueRefsForOrder());

$repair = Order::repairWzToOrderPositionLinksSql(
    $orderId,
    array($wzRef),
    $orderRef,
    true,
    true,
    true,
    true,
    true
);
echo "\nrepair: ";
print_r($repair);

echo "linked: " . (Order::isIssueDocumentLinkedToOrder($wzRef, $orderId, $orderRef) ? 'YES' : 'NO') . "\n";
echo "coverage: " . (Order::isOrderFullyCoveredByLinkedIssuesSql($orderId, $orderRef) ? 'YES' : 'NO') . "\n";

$closure = $order->reconcileOrderCloseFromExistingIssues(array($wzRef));
echo "\nclosure: ";
print_r($closure);

$order->reloadOrderFromGt();
echo "state after: " . (int) $order->state . " status_ex: " . (int) $order->status_ex . "\n";
echo "business complete: " . ($order->isBusinessComplete() ? 'YES' : 'NO') . "\n";
