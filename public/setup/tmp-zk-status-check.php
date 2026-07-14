<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance([
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
], $cfg->getServer());

$refs = ['ZK 598/06/2026', 'ZK 589/06/2026', 'ZK 597/06/2026'];
foreach ($refs as $ref) {
    $row = Order::getOrderRowByRefSql($ref);
    $issues = Order::getIssueRefsForOrder($ref, (int) ($row['dok_Id'] ?? 0));
    $coverage = Order::isOrderFullyCoveredByLinkedIssuesSql((int) ($row['dok_Id'] ?? 0), $ref);
    echo "=== {$ref} ===\n";
    echo json_encode([
        'status' => (int) ($row['dok_Status'] ?? 0),
        'status_ex' => (int) ($row['dok_StatusEx'] ?? 0),
        'status_label' => Order::getOrderStatusLabel((int) ($row['dok_Status'] ?? 0)),
        'do_dok' => trim((string) ($row['dok_DoDokNrPelny'] ?? '')),
        'issue_refs' => $issues,
        'coverage' => $coverage,
        'remaining_goods' => Order::getOrderRemainingQtyFromSql((int) ($row['dok_Id'] ?? 0), $ref, true),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
}
