<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql; use APISubiektGT\SubiektGT\Order;

$c=new Config(CONFIG_INI_FILE); $c->load();
$db=MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()],$c->getServer());

echo "=== ZK status 7 lipiec — podział ===\n";
$rows=$db->query("SELECT dok_Id, dok_NrPelny, dok_StatusEx, dok_DoDokNrPelny FROM dok__Dokument WHERE dok_Typ=16 AND dok_Status=7 AND dok_DataWyst>='2026-07-01' ORDER BY dok_Id");
$withWz=0; $withoutWz=0; $stuck=array();
foreach ($rows as $r) {
    $id=(int)$r['dok_Id'];
    $ref=$r['dok_NrPelny'];
    $refs=Order::getIssueRefsForOrder($ref,$id);
    $validRefs=array();
    foreach ($refs as $wz) {
        if (Order::isIssueDocumentLinkedToOrder($wz,$id,$ref)) $validRefs[]=$wz;
    }
    $cov=Order::isOrderFullyCoveredByLinkedIssuesSql($id,$ref);
    $rem=Order::getOrderRemainingQtyFromSql($id,$ref,true);
    if (!empty($validRefs)) { $withWz++; } else { $withoutWz++; }
    if ($rem>0.00001 || (!$cov && empty($validRefs))) {
        $stuck[]=['zk'=>$ref,'valid_wz'=>$validRefs,'coverage'=>$cov,'remaining_goods'=>$rem,'header'=>trim($r['dok_DoDokNrPelny']??'')];
    }
}
echo "status 7 total: ".count($rows).", z valid WZ: $withWz, bez valid WZ: $withoutWz\n";
echo "podejrzane (remaining>0 lub brak WZ): ".count($stuck)."\n";
foreach (array_slice($stuck,0,15) as $s) echo '  '.json_encode($s,JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== ZK 6 lipiec — tylko usługi? ===\n";
$s6=$db->query("SELECT dok_Id, dok_NrPelny FROM dok__Dokument WHERE dok_Typ=16 AND dok_Status=6 AND dok_DataWyst>='2026-07-01'");
foreach ($s6 as $r) {
    $id=(int)$r['dok_Id'];
    $goods=$db->query("SELECT COUNT(*) c FROM dok_Pozycja p WHERE p.ob_DokHanId=$id AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj<>2)");
    $gc=(int)($goods[0]['c']??0);
    echo $r['dok_NrPelny']." towary=$gc remaining_goods=".Order::getOrderRemainingQtyFromSql($id,$r['dok_NrPelny'],true)."\n";
}
