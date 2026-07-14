<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;

$c = new Config(CONFIG_INI_FILE); $c->load();
MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());

echo "=== Otwarte ZK status 5 z pozostałością towaru (COM sync potrzebny) ===\n";
$status5 = MSSql::getInstance()->query(
    "SELECT zk.dok_NrPelny, zk.dok_Status, zk.dok_DataWyst,
            COUNT(DISTINCT p.ob_TowId) AS towary,
            SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag,0)) AS pozostalo
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     WHERE zk.dok_Typ = 16 AND zk.dok_Status = 5 AND p.ob_TowId > 0
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) > 0.00001
     GROUP BY zk.dok_NrPelny, zk.dok_Status, zk.dok_DataWyst
     ORDER BY zk.dok_DataWyst DESC"
);
print_r($status5);
echo "Razem status 5: " . (is_array($status5) ? count($status5) : 0) . "\n";

echo "\n=== Otwarte ZK status 6 z pozostałością (API tworzyło bez rezerwacji?) ===\n";
$status6 = MSSql::getInstance()->query(
    "SELECT TOP 20 zk.dok_NrPelny, zk.dok_Status, zk.dok_DataWyst,
            SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag,0)) AS pozostalo
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     WHERE zk.dok_Typ = 16 AND zk.dok_Status = 6 AND p.ob_TowId > 0
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) > 0.00001
     GROUP BY zk.dok_NrPelny, zk.dok_Status, zk.dok_DataWyst
     ORDER BY zk.dok_DataWyst DESC"
);
print_r($status6);
$cnt6 = MSSql::getInstance()->query(
    "SELECT COUNT(DISTINCT zk.dok_Id) AS cnt
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     WHERE zk.dok_Typ = 16 AND zk.dok_Status = 6 AND p.ob_TowId > 0
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) > 0.00001"
);
echo "Razem status 6 z pozostalo: " . ($cnt6[0]['cnt'] ?? 0) . "\n";

echo "\n=== ZK 7 bez WZ (utkniete — SQL repair, potem COM) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT TOP 15 zk.dok_NrPelny, zk.dok_Status, zk.dok_DataWyst
     FROM dok__Dokument zk
     WHERE zk.dok_Typ = 16 AND zk.dok_Status = 7
       AND ISNULL(zk.dok_DoDokId,0) = 0
       AND NOT EXISTS (
           SELECT 1 FROM dok_Pozycja wz_p
           INNER JOIN dok_Pozycja zk_p ON zk_p.ob_Id = wz_p.ob_DoId
           INNER JOIN dok__Dokument wz ON wz.dok_Id = wz_p.ob_DokMagId AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
           WHERE zk_p.ob_DokHanId = zk.dok_Id
       )
     ORDER BY zk.dok_DataWyst DESC"
));
