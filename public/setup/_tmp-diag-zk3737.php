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

$ref = 'ZK 3737/07/2026';
$zk = Order::getOrderRowByRefSql($ref);
$id = (int) ($zk['dok_Id'] ?? 0);
$refs = Order::getIssueRefsForOrder($ref, $id);
$valid = Order::getValidIssueRefsForOrderSql($id, $ref);
$invalid = array();
foreach ($refs as $r) {
    if (!in_array($r, $valid, true)) {
        $wz = Order::getIssueDocumentRowByRef($r);
        $invalid[$r] = array(
            'linked' => Order::isIssueDocumentLinkedToOrder($r, $id, $ref),
            'foreign_pos' => $wz ? Order::issueHasForeignPositionLinksSql((int)$wz['dok_Id'], $id) : null,
            'oryg' => $wz['dok_NrPelnyOryg'] ?? null,
            'wz_do_dok' => $wz['dok_DoDokId'] ?? null,
        );
    }
}

echo json_encode(array(
    'zk' => $zk,
    'issue_refs' => $refs,
    'valid' => $valid,
    'invalid_detail' => $invalid,
    'diag' => Order::diagnoseStaleOrderIssueHeaderLinkSql($ref),
    'stuck8' => Order::isOrderStuckWithoutIssueSql($id, $ref),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
