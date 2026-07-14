<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE); $cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

echo "=== WSZYSTKIE ZK status 7 BEZ WZ (lipiec 2026) ===\n";
$all = MSSql::getInstance()->query(
    "SELECT COUNT(*) AS cnt FROM dok__Dokument zk
     WHERE zk.dok_Typ=16 AND zk.dok_Status=7
       AND ISNULL(zk.dok_DoDokNrPelny,'')=''
       AND zk.dok_DataWyst>='2026-07-01'"
);
print_r($all);

echo "\n=== TOP 10 ZK 7 bez WZ ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT TOP 10 zk.dok_NrPelny, zk.dok_StatusEx, zk.dok_DataWyst
     FROM dok__Dokument zk
     WHERE zk.dok_Typ=16 AND zk.dok_Status=7 AND ISNULL(zk.dok_DoDokNrPelny,'')=''
       AND zk.dok_DataWyst>='2026-07-01'
     ORDER BY zk.dok_Id DESC"
));
