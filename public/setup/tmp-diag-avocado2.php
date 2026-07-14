<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql; use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE); $cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

$codes = "'IW00AV1','IW00AV2'";

echo "=== ZK status 5 (powinny trzymać rezerwację) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_DoDokNrPelny,
            t.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag, p.ob_Ilosc-ISNULL(p.ob_IloscMag,0) AS pozostalo
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId=zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
     WHERE zk.dok_Typ=16 AND zk.dok_Status=5 AND t.tw_Symbol IN ($codes)
     ORDER BY zk.dok_Id DESC"
));

echo "\n=== ZK status 7 BEZ WZ (niefinalne, StatusEx=0) ===\n";
$stuck = MSSql::getInstance()->query(
    "SELECT zk.dok_Id, zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_DoDokNrPelny,
            SUM(CASE WHEN t.tw_Symbol='IW00AV1' THEN p.ob_Ilosc ELSE 0 END) AS av1,
            SUM(CASE WHEN t.tw_Symbol='IW00AV2' THEN p.ob_Ilosc ELSE 0 END) AS av2
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId=zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
     WHERE zk.dok_Typ=16 AND zk.dok_Status=7
       AND ISNULL(zk.dok_DoDokNrPelny,'')=''
       AND t.tw_Symbol IN ($codes)
     GROUP BY zk.dok_Id, zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_DoDokNrPelny
     ORDER BY zk.dok_Id DESC"
);
print_r($stuck);

echo "\n=== SUMA ZK 7 bez WZ (powinno być zarezerwowane biznesowo) ===\n";
$sum = MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, SUM(p.ob_Ilosc) AS suma_ilosc, SUM(ISNULL(p.ob_IloscMag,0)) AS suma_mag
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId=zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
     WHERE zk.dok_Typ=16 AND zk.dok_Status=7 AND ISNULL(zk.dok_DoDokNrPelny,'')=''
       AND t.tw_Symbol IN ($codes)
     GROUP BY t.tw_Symbol"
);
print_r($sum);

echo "\n=== CZY pozycje mają WZ przez ob_DoId? ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT TOP 10 zk.dok_NrPelny, t.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag,
            wz.dok_NrPelny AS wz_z_pozycji
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId=zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
     LEFT JOIN dok_Pozycja wp ON wp.ob_Id = p.ob_DoId
     LEFT JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId
     WHERE zk.dok_Typ=16 AND zk.dok_Status=7 AND ISNULL(zk.dok_DoDokNrPelny,'')=''
       AND t.tw_Symbol IN ($codes)
     ORDER BY zk.dok_Id DESC"
));

echo "\n=== REZERWACJA oczekiwana gdyby ZK 7-bez-WZ liczyły się jako otwarte ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, SUM(p.ob_Ilosc) AS expected_if_open
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId=zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
     WHERE zk.dok_Typ=16 AND zk.dok_Status=7 AND ISNULL(zk.dok_DoDokNrPelny,'')=''
       AND t.tw_Symbol IN ($codes)
     GROUP BY t.tw_Symbol"
));
