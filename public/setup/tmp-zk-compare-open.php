<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;

$c = new Config(CONFIG_INI_FILE); $c->load();
MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());

// Otwarte ZK lipiec z rezerwacją towaru (pozycje z pozostalo>0, status 5 lub 6)
echo "=== Otwarte ZK lipiec (status 5/6) z rezerwacją w st_Stan ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT TOP 8 zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_ZrealizowaneZRezerwacja,
            SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag,0)) AS pozostalo
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     WHERE zk.dok_Typ=16 AND zk.dok_Status IN (5,6,7)
       AND zk.dok_DataWyst>='2026-07-01'
       AND zk.dok_NrPelny NOT LIKE '%3629%'
     GROUP BY zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_ZrealizowaneZRezerwacja
     HAVING SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag,0)) > 0
     ORDER BY zk.dok_Status, zk.dok_NrPelny DESC"
));
