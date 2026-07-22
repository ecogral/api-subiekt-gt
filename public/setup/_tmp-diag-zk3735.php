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

$ref = 'ZK 3735/07/2026';
$zk = Order::getOrderRowByRefSql($ref);
$id = (int) ($zk['dok_Id'] ?? 0);
$refs = Order::getIssueRefsForOrder($ref, $id);
$valid = array();
foreach ($refs as $r) {
    $valid[$r] = Order::isIssueDocumentLinkedToOrder($r, $id, $ref);
}

echo json_encode(array(
    'zk' => $zk,
    'issue_refs' => $refs,
    'valid_map' => $valid,
    'coverage' => Order::isOrderFullyCoveredByLinkedIssuesSql($id, $ref),
    'has_links' => Order::orderHasActiveIssueLinksSql($id, $ref),
    'stale_diag' => Order::diagnoseStaleOrderIssueHeaderLinkSql($ref),
    'status_ex_label' => Order::getOrderStatusExLabel((int) ($zk['dok_StatusEx'] ?? 0)),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
