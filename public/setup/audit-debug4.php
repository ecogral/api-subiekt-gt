<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql; use APISubiektGT\SubiektGT\Order;

$c=new Config(CONFIG_INI_FILE); $c->load();
$db=MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()],$c->getServer());

$rows=$db->query("SELECT dok_Id, dok_NrPelny, dok_Status FROM dok__Dokument WHERE dok_Typ=16 AND dok_Status IN (5,6,7) AND dok_DataWyst>='2026-07-01' AND dok_DataWyst<='2026-07-31 23:59:59' ORDER BY dok_Id");
$n=0;
foreach ($rows as $row) {
    $n++;
    $id=(int)$row['dok_Id'];
    $ref=$row['dok_NrPelny'];
    echo "[$n] $ref ... ";
    flush();
    try {
        Order::getIssueRefsForOrder($ref, $id);
        Order::isOrderFullyCoveredByLinkedIssuesSql($id, $ref);
        foreach (Order::getIssueRefsForOrder($ref, $id) as $issueRef) {
            Order::isIssueDocumentLinkedToOrder($issueRef, $id, $ref);
        }
        $orphanSql = "SELECT wz.dok_NrPelny, wz.dok_Id FROM dok__Dokument wz WHERE wz.dok_Typ=11 AND wz.dok_Status>=0 AND wz.dok_Id BETWEEN {$id} AND {$id}+15 AND EXISTS (SELECT 1 FROM dok_Pozycja wp WHERE wp.ob_DokMagId=wz.dok_Id) AND NOT EXISTS (SELECT 1 FROM dok_Pozycja wp INNER JOIN dok_Pozycja zk ON zk.ob_Id=wp.ob_DoId AND zk.ob_DokHanId={$id} WHERE wp.ob_DokMagId=wz.dok_Id)";
        $oc = $db->query($orphanSql);
        if (is_array($oc)) {
            foreach ($oc as $o) {
                Order::isSingleIssueCoveringOrderSql($id, (int)$o['dok_Id']);
            }
        }
        Order::getOrderRemainingQtyFromSql($id, $ref, true);
        echo "OK\n";
    } catch (Throwable $e) {
        echo "ERR: " . $e->getMessage() . "\n";
        break;
    }
}
echo "done $n\n";
