<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$c = new Config(CONFIG_INI_FILE);
$c->load();
MSSql::getInstance([
    'UID' => $c->getDbUser(),
    'PWD' => $c->getDbUserPass(),
    'Database' => $c->getDatabase(),
], $c->getServer());

$issueRef = $argv[1] ?? 'WZ 3250/07/2026';
$orderId = Order::resolveOrderIdForIssueSql($issueRef);
$orderRef = '';
if ($orderId > 0) {
    $zk = Order::getOrderRowByIdSql($orderId);
    $orderRef = $zk !== null ? trim((string) ($zk['dok_NrPelny'] ?? '')) : '';
}

echo "issue_ref={$issueRef}\n";
echo "order_ref={$orderRef}\n";
echo "order_id={$orderId}\n";
print_r(Order::getIssueDocumentRowByRef($issueRef));
print_r(Order::diagnoseIssueForInvoicingSql($issueRef));
