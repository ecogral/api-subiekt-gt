<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql; use APISubiektGT\SubiektGT\Order;
$c = new Config(CONFIG_INI_FILE); $c->load();
MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());
foreach (array('ZK 3614/07/2026', 'ZK 3615/07/2026') as $r) {
    $row = Order::getOrderRowByRefSql($r);
    print_r($row);
    $id = (int) ($row['dok_Id'] ?? 0);
    echo "stuck=" . (Order::isOrderStuckWithoutIssueSql($id, $r) ? 'tak' : 'nie') . "\n";
    print_r(Order::getIssueRefsForOrder($r, $id));
}
