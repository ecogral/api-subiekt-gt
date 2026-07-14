<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;

$c=new Config(CONFIG_INI_FILE); $c->load();
$db=MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()],$c->getServer());

$symbols = array('MF0162','KN00020','DY61','DY56','EP00100','EA00004');
$inList = implode(', ', array_map(fn($s)=>"'".str_replace("'","''",$s)."'", $symbols));

echo "=== Wszystkie ZK (dowolny status) z pozostalością — te symbole ===\n";
$all = $db->query(
    "SELECT d.dok_NrPelny, d.dok_Status, d.dok_DataWyst, t.tw_Symbol,
            SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag,0)) AS pozostalo
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id=p.ob_DokHanId AND d.dok_Typ=16 AND d.dok_Status>=0
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
     WHERE t.tw_Symbol IN ($inList) AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj<>2)
     GROUP BY d.dok_NrPelny,d.dok_Status,d.dok_DataWyst,t.tw_Symbol
     HAVING SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag,0)) > 0.00001
     ORDER BY d.dok_DataWyst DESC"
);
echo count($all) ? "" : "  (brak — żadne ZK nie ma pozostałości na tych towarach)\n";
foreach (array_slice($all?:[],0,20) as $r) print_r($r);

echo "\n=== Ostatnie ZK status 5 (dowolny towar, czy w ogóle są ZK 5?) ===\n";
$s5=$db->query("SELECT TOP 5 dok_NrPelny, dok_DataWyst FROM dok__Dokument WHERE dok_Typ=16 AND dok_Status=5 AND dok_Status>=0 ORDER BY dok_Id DESC");
print_r($s5);

echo "\n=== ZK 3621 MF0162 (40 szt lipiec) — szczegóły ===\n";
$row=$db->query("SELECT dok_Id, dok_Status, dok_StatusEx, dok_ZrealizowaneZRezerwacja, dok_DoDokNrPelny FROM dok__Dokument WHERE dok_NrPelny='ZK 3621/07/2026'");
print_r($row);
if (!empty($row[0]['dok_Id'])) {
    $id=(int)$row[0]['dok_Id'];
    print_r($db->query("SELECT t.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag, p.ob_Ilosc-p.ob_IloscMag AS poz FROM dok_Pozycja p INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId WHERE p.ob_DokHanId=$id"));
}

echo "\n=== Czy istnieje tabela rezerwacji pozycji (rez__*) ===\n";
$tables=$db->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME LIKE '%rez%' OR TABLE_NAME LIKE '%Rez%' ORDER BY TABLE_NAME");
foreach ($tables?:[] as $t) echo '  '.$t['TABLE_NAME']."\n";

echo "\n=== DY61: rez=12 > stan=10 — kto trzyma rezerwację? ===\n";
$dy=$db->query("SELECT t.tw_Id FROM tw__Towar t WHERE t.tw_Symbol='DY61'");
$tid=(int)($dy[0]['tw_Id']??0);
if ($tid) {
    print_r($db->query("SELECT * FROM tw_Stan WHERE st_TowId=$tid AND st_MagId=1"));
}

echo "\n=== Korelacja: suma zamówień MF0162 lipiec vs orphan 63 ===\n";
$mf=$db->query(
    "SELECT SUM(p.ob_Ilosc) AS total_ordered_july
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id=p.ob_DokHanId AND d.dok_Typ=16 AND d.dok_Status=8
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
     WHERE t.tw_Symbol='MF0162' AND d.dok_DataWyst>='2026-07-01'"
);
echo '  lipiec ZK8 ordered MF0162: '.($mf[0]['total_ordered_july']??0)."\n";
echo '  orphan rez MF0162: 63 (nie pokrywa się 1:1 z jednym ZK — to skumulowane śmieci)\n';
