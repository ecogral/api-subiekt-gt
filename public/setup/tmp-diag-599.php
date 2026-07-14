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

$zk = Order::getOrderRowByRefSql($orderRef);
echo "=== ZK row ===\n";
print_r($zk);

$orderId = (int) ($zk['dok_Id'] ?? 0);
$wz = Order::getIssueDocumentRowByRef($wzRef);
echo "\n=== WZ row ===\n";
print_r($wz);

$wzId = (int) ($wz['dok_Id'] ?? 0);
echo "\nlinked: " . (Order::isIssueDocumentLinkedToOrder($wzRef, $orderId, $orderRef) ? 'YES' : 'NO') . "\n";
echo "coverage: " . (Order::isOrderFullyCoveredByLinkedIssuesSql($orderId, $orderRef) ? 'YES' : 'NO') . "\n";

$posSql = "SELECT zk.ob_Id, t.tw_Symbol, zk.ob_TowRodzaj, zk.ob_Ilosc AS ordered,
                  ISNULL(zk.ob_IloscMag, 0) AS ilosc_mag,
                  ISNULL(SUM(CASE WHEN wz.dok_Id IS NOT NULL THEN wz_p.ob_Ilosc ELSE 0 END), 0) AS issued,
                  COUNT(wz_p.ob_Id) AS wz_pos_links
           FROM dok_Pozycja zk
           LEFT JOIN tw__Towar t ON t.tw_Id = zk.ob_TowId
           LEFT JOIN dok_Pozycja wz_p ON wz_p.ob_DoId = zk.ob_Id
           LEFT JOIN dok__Dokument wz ON wz.dok_Id = wz_p.ob_DokMagId AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
           WHERE zk.ob_DokHanId = {$orderId}
           GROUP BY zk.ob_Id, t.tw_Symbol, zk.ob_TowRodzaj, zk.ob_Ilosc, zk.ob_IloscMag
           ORDER BY zk.ob_Id";
echo "\n=== ZK positions ===\n";
print_r(MSSql::getInstance()->query($posSql));

$wzPosSql = "SELECT wp.ob_Id, t.tw_Symbol, wp.ob_TowRodzaj, wp.ob_Ilosc, wp.ob_DoId, wp.ob_DokMagId
             FROM dok_Pozycja wp
             LEFT JOIN tw__Towar t ON t.tw_Id = wp.ob_TowId
             WHERE wp.ob_DokMagId = {$wzId}
             ORDER BY wp.ob_Id";
echo "\n=== WZ positions ===\n";
print_r(MSSql::getInstance()->query($wzPosSql));

echo "\nvalid issue refs: ";
print_r((new Order(null, array()))->getValidIssueRefsForOrder());
// need loaded order
$sgt = new SubiektGT($cfg);
$sgt->connect();
$order = Order::loadExistingByRefVariants($sgt, array('order_ref' => $orderRef));
if ($order) {
    $order->setCfg($cfg);
    echo "\nvalid refs: ";
    print_r($order->getValidIssueRefsForOrder());
    echo "findValid: " . ($order->findValidIssueForOrder() ?? 'null') . "\n";
    $order->reloadOrderFromGt();
    echo "remaining goods: " . Order::sumRemainingToRealize($order->orderGt, true, $orderId, $orderRef) . "\n";
    echo "remaining all: " . Order::sumRemainingToRealize($order->orderGt, false, $orderId, $orderRef) . "\n";
    echo "state: " . (int) $order->state . " status_ex: " . (int) $order->status_ex . "\n";
}
