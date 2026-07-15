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

$issueRef = $argv[1] ?? 'WZ 3249/07/2026';
$orderRef = $argv[2] ?? 'ZK 3651/07/2026';

echo "=== WZ ===\n";
print_r(Order::getIssueDocumentRowByRef($issueRef));

echo "\n=== Diag ===\n";
print_r(Order::diagnoseIssueForInvoicingSql($issueRef));

echo "\n=== ZK ===\n";
print_r(Order::getOrderRowByRefSql($orderRef));
