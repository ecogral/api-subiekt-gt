<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql; use APISubiektGT\SubiektGT\Order;

$c = new Config(CONFIG_INI_FILE); $c->load();
$db = MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());

$orderRef = 'ZK 3651/07/2026';
$zk = Order::getOrderRowByRefSql($orderRef);
echo "=== ZK ===\n"; print_r($zk);
$orderId = (int)($zk['dok_Id'] ?? 0);

echo "\n=== WZ z pozycjami powiązanymi do ZK ===\n";
print_r($db->query(
    "SELECT DISTINCT wz.dok_NrPelny, wz.dok_Id, wz.dok_DoDokId, wz.dok_NrPelnyOryg,
            COUNT(wp.ob_Id) AS pozycji, SUM(CASE WHEN wp.ob_DoId > 0 THEN 1 ELSE 0 END) AS z_do_id
     FROM dok_Pozycja zk_p
     INNER JOIN dok_Pozycja wp ON wp.ob_DoId = zk_p.ob_Id
     INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
     WHERE zk_p.ob_DokHanId = {$orderId}
     GROUP BY wz.dok_NrPelny, wz.dok_Id, wz.dok_DoDokId, wz.dok_NrPelnyOryg"
));

echo "\n=== WZ tego dnia obok ID ZK (orphan) ===\n";
print_r($db->query(
    "SELECT wz.dok_NrPelny, wz.dok_Id, wz.dok_DoDokId, wz.dok_NrPelnyOryg
     FROM dok__Dokument wz
     WHERE wz.dok_Typ = 11 AND wz.dok_Status >= 0
       AND wz.dok_Id BETWEEN {$orderId} AND {$orderId} + 20
     ORDER BY wz.dok_Id"
));
