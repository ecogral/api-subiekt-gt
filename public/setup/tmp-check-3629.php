<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql; use APISubiektGT\SubiektGT\Order;

$c = new Config(CONFIG_INI_FILE); $c->load();
MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());

$refs = array('ZK 3603/07/2026','ZK 3614/07/2026','ZK 3615/07/2026','ZK 3618/07/2026','ZK 3620/07/2026','ZK 3629/07/2026');
foreach ($refs as $r) {
    $row = Order::getOrderRowByRefSql($r);
    echo "\n=== $r ===\n";
    print_r($row);
    if ($row) {
        $id = (int)$row['dok_Id'];
        echo 'stuck=' . (Order::isOrderStuckWithoutIssueSql($id, $r) ? 'tak' : 'nie') . "\n";
        print_r(MSSql::getInstance()->query(
            "SELECT t.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag, p.ob_Ilosc-ISNULL(p.ob_IloscMag,0) AS pozostalo
             FROM dok_Pozycja p INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
             WHERE p.ob_DokHanId=$id AND t.tw_Symbol IN ('IW00AV1','IW00AV2','U001')"
        ));
    }
}
print_r(MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez FROM tw__Towar t
     INNER JOIN tw_Stan s ON s.st_TowId=t.tw_Id AND s.st_MagId=1
     WHERE t.tw_Symbol IN ('IW00AV1','IW00AV2')"
));
