<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;

$c = new Config(CONFIG_INI_FILE); $c->load();
MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());

// Porównaj dok_ObiektGT między ZK 5 a 6
echo "=== dok_ObiektGT dla ZK status 5 vs 6 (losowe) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT TOP 5 dok_Status, dok_ObiektGT, dok_NrPelny
     FROM dok__Dokument WHERE dok_Typ=16 AND dok_Status=5 ORDER BY dok_Id DESC"
));
print_r(MSSql::getInstance()->query(
    "SELECT TOP 5 dok_Status, dok_ObiektGT, dok_NrPelny
     FROM dok__Dokument WHERE dok_Typ=16 AND dok_Status=6 ORDER BY dok_Id DESC"
));

// zs_Rezerwacja — czy ma dane?
echo "\n=== zs_Rezerwacja (TOP 5) ===\n";
print_r(MSSql::getInstance()->query("SELECT TOP 5 * FROM zs_Rezerwacja"));

// Wszystkie niezrealizowane ZK z Avocado na liście informatora
echo "\n=== Wszystkie ZK 5/6 z IW00AV2 (bez filtra pozostalo) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT DISTINCT zk.dok_NrPelny, zk.dok_Status, zk.dok_DataWyst,
            MAX(p.ob_Ilosc) AS max_ilosc, MAX(ISNULL(p.ob_IloscMag,0)) AS max_wydano
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId AND t.tw_Symbol = 'IW00AV2'
     WHERE zk.dok_Typ=16 AND zk.dok_Status IN (5,6)
     GROUP BY zk.dok_NrPelny, zk.dok_Status, zk.dok_DataWyst
     ORDER BY zk.dok_DataWyst DESC"
));
