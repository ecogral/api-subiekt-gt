<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;

$c = new Config(CONFIG_INI_FILE); $c->load();
$db = MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());

echo "=== ob_Powiazane WZ 3249 ===\n";
print_r($db->query("SELECT * FROM ob_Powiazane WHERE ob_DokMagId=69534 OR ob_DokHanId=69533"));

echo "\n=== WZ pozycje po API repair ===\n";
print_r($db->query("SELECT wp.ob_Id, t.tw_Symbol, wp.ob_DoId, wp.ob_Ilosc FROM dok_Pozycja wp LEFT JOIN tw__Towar t ON t.tw_Id=wp.ob_TowId WHERE wp.ob_DokMagId=69534"));

echo "\n=== ZK nagłówek ===\n";
print_r($db->query("SELECT dok_DoDokId, dok_DoDokNrPelny, dok_Status, dok_StatusEx FROM dok__Dokument WHERE dok_Id=69533"));
