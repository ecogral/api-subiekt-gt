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

foreach (array('WZ 3202/07/2026', 'WZ 3207/07/2026', 'WZ 3182/07/2026') as $wzRef) {
    echo $wzRef . " codes: " . implode(', ', Order::getIssueProductCodesSql($wzRef)) . "\n";
    echo "  missing: " . implode(', ', Order::findMissingIssueServiceCodesSql(
        (int) Order::resolveOrderIdForIssueSql($wzRef),
        $wzRef
    )) . "\n\n";
}
