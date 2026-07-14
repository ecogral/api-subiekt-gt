<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;
$c=new Config(CONFIG_INI_FILE); $c->load();
$db=MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()],$c->getServer());
$r=$db->query("SELECT TOP 2 zk.dok_Id, zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx,
       zk.dok_ZrealizowaneZRezerwacja, zk.dok_DoDokId, zk.dok_DoDokNrPelny, zk.dok_DataWyst
     FROM dok__Dokument zk
     WHERE zk.dok_Typ = 16 AND zk.dok_Status >= 0 AND zk.dok_Status IN (5, 6, 7)
       AND zk.dok_DataWyst >= '2026-07-01' AND zk.dok_DataWyst <= '2026-07-31 23:59:59'
     ORDER BY zk.dok_Id ASC");
var_export($r);
