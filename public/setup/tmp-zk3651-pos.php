<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;

$c=new Config(CONFIG_INI_FILE); $c->load();
$db=MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());
$orderId=69533;
echo "ZK pozycje:\n";
print_r($db->query("SELECT p.ob_Id, t.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag FROM dok_Pozycja p LEFT JOIN tw__Towar t ON t.tw_Id=p.ob_TowId WHERE p.ob_DokHanId={$orderId}"));
echo "WZ obok ZK:\n";
print_r($db->query("SELECT dok_Id, dok_NrPelny, dok_NrPelnyOryg, dok_DoDokId, dok_Status FROM dok__Dokument WHERE dok_Typ=11 AND dok_Id BETWEEN {$orderId} AND {$orderId}+15"));
