<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$c = new Config(CONFIG_INI_FILE);
$c->load();
MSSql::getInstance(array(
    'UID' => $c->getDbUser(),
    'PWD' => $c->getDbUserPass(),
    'Database' => $c->getDatabase(),
), $c->getServer());
$db = MSSql::getInstance();

echo "=== Jedyny ZK status 5 ===\n";
print_r($db->query(
    "SELECT dok_Id, dok_NrPelny, dok_Status, dok_StatusEx, dok_ZrealizowaneZRezerwacja, dok_DataWyst
     FROM dok__Dokument WHERE dok_Typ = 16 AND dok_Status = 5"
));

echo "\n=== Avocado vs stuck7 qty ===\n";
print_r($db->query(
    "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez
     FROM tw__Towar t INNER JOIN tw_Stan s ON s.st_TowId=t.tw_Id AND s.st_MagId=1
     WHERE t.tw_Symbol IN ('IW00AV1','IW00AV2','EA00024','EP00060','EP00061','U001')"
));

echo "\n=== CT014-1: ZK 3600 szczegoly (kandydat orphan +2) ===\n";
print_r($db->query(
    "SELECT dok_NrPelny, dok_Status, dok_StatusEx, dok_ZrealizowaneZRezerwacja,
            dok_DataWyst, dok_DoDokNrPelny
     FROM dok__Dokument WHERE dok_NrPelny = 'ZK 3600/07/2026'"
));
