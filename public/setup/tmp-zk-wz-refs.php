<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql; use APISubiektGT\SubiektGT\Order;

$c=new Config(CONFIG_INI_FILE); $c->load();
MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());

foreach (['ZK 3651/07/2026','ZK 3652/07/2026'] as $ref) {
    $zk=Order::getOrderRowByRefSql($ref);
    $id=(int)($zk['dok_Id']??0);
    echo "\n=== {$ref} id={$id} ===\n";
    print_r($zk);
    echo "getIssueRefsForOrder:\n";
    print_r(Order::getIssueRefsForOrder($ref, $id));
    echo "findIssueRefsLinkedFromOrderDocumentSql:\n";
    print_r(Order::findIssueRefsLinkedFromOrderDocumentSql($id));
}
