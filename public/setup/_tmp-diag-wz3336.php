<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

$orderRef = 'ZK 3735/07/2026';
$issueRef = 'WZ 3336/07/2026';
$zk = Order::getOrderRowByRefSql($orderRef);
$orderId = (int) ($zk['dok_Id'] ?? 0);
$wz = Order::getIssueDocumentRowByRef($issueRef);
$wzId = (int) ($wz['dok_Id'] ?? 0);

$positions = MSSql::getInstance()->query(
    "SELECT zk.ob_Id AS zk_pos, zk.ob_TowId, tw.tw_Symbol, zk.ob_Ilosc AS zk_qty,
            wz_p.ob_Ilosc AS wz_qty, wz_p.ob_DoId
     FROM dok_Pozycja zk
     LEFT JOIN tw__Towar tw ON tw.tw_Id = zk.ob_TowId
     LEFT JOIN dok_Pozycja wz_p ON wz_p.ob_DoId = zk.ob_Id
     LEFT JOIN dok__Dokument wz ON wz.dok_Id = wz_p.ob_DokMagId AND wz.dok_Typ = 11
     WHERE zk.ob_DokHanId = {$orderId}
     ORDER BY zk.ob_Id"
);

$wzHeader = MSSql::getInstance()->query(
    "SELECT dok_Id, dok_NrPelny, dok_NrPelnyOryg, dok_Status, dok_WartNetto, dok_DoDokId
     FROM dok__Dokument WHERE dok_Id = {$wzId}"
);

echo json_encode(array(
    'zk' => $zk,
    'wz' => $wz,
    'wz_header' => $wzHeader,
    'positions' => $positions,
    'foreign_on_wz' => Order::issueHasForeignPositionLinksSql($wzId, $orderId),
    'linked_ok' => Order::isIssueDocumentLinkedToOrder($issueRef, $orderId, $orderRef),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
