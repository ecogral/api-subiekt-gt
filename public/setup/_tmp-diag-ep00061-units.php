<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

echo "=== EP00061 towar ===\n";
$tw = MSSql::getInstance()->query("SELECT tw_Id, tw_Symbol, tw_Nazwa, tw_JednMiary FROM tw__Towar WHERE tw_Symbol='EP00061'");
print_r($tw);
$tid = (int)($tw[0]['tw_Id'] ?? 0);

echo "\n=== tw_JednMiary ===\n";
$jm = MSSql::getInstance()->query("SELECT * FROM tw_JednMiary WHERE jm_IdTowar={$tid}");
print_r($jm);

echo "\n=== ZK 3830 pozycja ===\n";
$p = MSSql::getInstance()->query(
    "SELECT d.dok_NrPelny, d.dok_Status, p.ob_Ilosc, p.ob_IloscMag, p.ob_Jm
     FROM dok__Dokument d
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId=d.dok_Id
     WHERE d.dok_NrPelny='ZK 3830/07/2026' AND p.ob_TowId={$tid}"
);
print_r($p);

echo "\n=== st_Stan ===\n";
$s = MSSql::getInstance()->query(
    "SELECT CAST(st_Stan AS float) AS stan, CAST(st_StanRez AS float) AS rez
     FROM tw_Stan WHERE st_TowId={$tid} AND st_MagId=1"
);
print_r($s);
