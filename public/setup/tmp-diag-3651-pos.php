<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;

$c = new Config(CONFIG_INI_FILE); $c->load();
$db = MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());

echo "=== WZ 3249 pozycje ===\n";
print_r($db->query(
    "SELECT wp.ob_Id, t.tw_Symbol, wp.ob_Ilosc, wp.ob_DoId, wp.ob_DokMagId
     FROM dok_Pozycja wp
     LEFT JOIN tw__Towar t ON t.tw_Id = wp.ob_TowId
     WHERE wp.ob_DokMagId = 69534"
));

echo "\n=== ZK 3651 pozycje ===\n";
print_r($db->query(
    "SELECT zp.ob_Id, t.tw_Symbol, zp.ob_Ilosc, ISNULL(zp.ob_IloscMag,0) AS wydano
     FROM dok_Pozycja zp
     LEFT JOIN tw__Towar t ON t.tw_Id = zp.ob_TowId
     WHERE zp.ob_DokHanId = 69533"
));
