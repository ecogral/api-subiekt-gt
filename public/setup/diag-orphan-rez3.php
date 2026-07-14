<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;

$c=new Config(CONFIG_INI_FILE); $c->load();
$db=MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()],$c->getServer());

$symbols = array('MF0162','KN00020','DY61','DY56','EP00100','EA00004');
$inList = implode(', ', array_map(fn($s)=>"'".str_replace("'","''",$s)."'", $symbols));

echo "=== Jedyne ZK status 5: ZK 3369/06/2026 ===\n";
$row=$db->query("SELECT dok_Id, dok_Status, dok_StatusEx, dok_ZrealizowaneZRezerwacja FROM dok__Dokument WHERE dok_NrPelny='ZK 3369/06/2026'");
print_r($row);
if (!empty($row[0]['dok_Id'])) {
    $id=(int)$row[0]['dok_Id'];
    print_r($db->query(
        "SELECT t.tw_Symbol, p.ob_Ilosc, ISNULL(p.ob_IloscMag,0) AS wydano,
                p.ob_Ilosc-ISNULL(p.ob_IloscMag,0) AS pozostalo
         FROM dok_Pozycja p INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
         WHERE p.ob_DokHanId=$id ORDER BY t.tw_Symbol"
    ));
}

echo "\n=== ZK 3369 expected contribution to rez (czy zawiera nasze symbole?) ===\n";
$exp=$db->query(
    "SELECT t.tw_Symbol, SUM(p.ob_Ilosc-ISNULL(p.ob_IloscMag,0)) AS exp_rez
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id=p.ob_DokHanId AND d.dok_Typ=16 AND d.dok_Status=5
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
     WHERE t.tw_Symbol IN ($inList) AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj<>2)
     GROUP BY t.tw_Symbol"
);
print_r($exp ?: array('brak'));

echo "\n=== DY56 — skąd 10 szt rez? (1 WZ lipiec = 10 szt) ===\n";
print_r($db->query(
    "SELECT d.dok_NrPelny, d.dok_Status, d.dok_DataWyst, p.ob_Ilosc, p.ob_IloscMag
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id=p.ob_DokHanId AND d.dok_Typ=16
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
     WHERE t.tw_Symbol='DY56' AND d.dok_DataWyst>='2026-06-01'
     ORDER BY d.dok_DataWyst DESC"
));
print_r($db->query(
    "SELECT d.dok_NrPelny, p.ob_Ilosc FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id=p.ob_DokMagId AND d.dok_Typ=11
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
     WHERE t.tw_Symbol='DY56' AND d.dok_DataWyst>='2026-06-01'"
));

echo "\n=== KN00020 orphan 22 vs ostatnie ZK ===\n";
print_r($db->query(
    "SELECT TOP 5 d.dok_NrPelny, d.dok_Status, p.ob_Ilosc, p.ob_IloscMag, d.dok_DataWyst
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id=p.ob_DokHanId AND d.dok_Typ=16
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
     WHERE t.tw_Symbol='KN00020'
     ORDER BY d.dok_Id DESC"
));

echo "\n=== Wniosek liczbowy: czy orphan = suma historyczna bez zwolnienia? ===\n";
echo "Sprawdzam ZK zamknięte 6/7/8 gdzie wydano=0 ale była rezerwacja (stary bug)...\n";
$stuck=$db->query(
    "SELECT TOP 15 d.dok_NrPelny, d.dok_Status, t.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id=p.ob_DokHanId AND d.dok_Typ=16 AND d.dok_Status IN (6,7,8)
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
     WHERE t.tw_Symbol IN ($inList)
       AND p.ob_IloscMag < p.ob_Ilosc - 0.00001
     ORDER BY d.dok_Id DESC"
);
echo count($stuck?:[])." pozycji z niewydaną ilością na zamkniętych ZK\n";
foreach (array_slice($stuck?:[],0,10) as $r) print_r($r);
