<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql; use APISubiektGT\SubiektGT\Order;

$c=new Config(CONFIG_INI_FILE); $c->load();
$db=MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()],$c->getServer());
$id=68789; // guess
$row=Order::getOrderRowByRefSql('ZK 3485/07/2026');
$id=(int)($row['dok_Id']??0);
$ref='ZK 3485/07/2026';
echo "id=$id\n";
echo "refs: "; print_r(Order::getIssueRefsForOrder($ref,$id));
echo "coverage: ";
try { echo Order::isOrderFullyCoveredByLinkedIssuesSql($id,$ref)?'Y':'N'; } catch(Throwable $e){ echo $e->getMessage(); }
echo "\n";
$oc=$db->query("SELECT wz.dok_NrPelny,wz.dok_Id FROM dok__Dokument wz WHERE wz.dok_Typ=11 AND wz.dok_Status>=0 AND wz.dok_Id BETWEEN {$id} AND {$id}+15");
print_r($oc);
foreach ($oc as $o) {
    echo "single cover wz {$o['dok_NrPelny']}: ";
    try { echo Order::isSingleIssueCoveringOrderSql($id,(int)$o['dok_Id'])?'Y':'N'; } catch(Throwable $e){ echo $e->getMessage(); }
    echo "\n";
}
