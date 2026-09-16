<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

$symbols = array('EA00024', 'IW00AV1', 'IW00AV2');
$in = "'" . implode("','", $symbols) . "'";

echo "=== 1) Otwarte ZK st=7 (expected) per symbol ===\n";
$rows = MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, d.dok_Id, d.dok_NrPelny, d.dok_Status, d.dok_StatusEx,
            d.dok_DoDokNrPelny, d.dok_NrPelnyOryg, d.dok_DataWyst,
            CAST(p.ob_Ilosc AS float) AS ilosc,
            CAST(ISNULL(p.ob_IloscMag,0) AS float) AS mag
     FROM dok__Dokument d
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = d.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE d.dok_Typ = 16 AND d.dok_Status = 7 AND d.dok_Status >= 0
       AND t.tw_Symbol IN ({$in})
       AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)
       AND (
            d.dok_DoDokNrPelny IS NULL
            OR LTRIM(RTRIM(CAST(d.dok_DoDokNrPelny AS NVARCHAR(100)))) = ''
            OR d.dok_DoDokNrPelny NOT LIKE 'WZ %'
       )
       AND NOT EXISTS (
            SELECT 1 FROM dok__Dokument wz
            WHERE wz.dok_Typ = 11 AND wz.dok_Status >= 0
              AND (wz.dok_DoDokId = d.dok_Id OR wz.dok_NrPelnyOryg = d.dok_NrPelny
                   OR (ISNULL(d.dok_DoDokNrPelny,'') <> '' AND wz.dok_NrPelny = d.dok_DoDokNrPelny))
       )
     ORDER BY t.tw_Symbol, d.dok_Id"
);
$sum = array();
if (is_array($rows)) {
    foreach ($rows as $r) {
        $sym = $r['tw_Symbol'];
        if (!isset($sum[$sym])) $sum[$sym] = 0;
        $sum[$sym] += (float)$r['ilosc'];
        $data = $r['dok_DataWyst'];
        if (is_object($data) && method_exists($data, 'format')) $data = $data->format('Y-m-d H:i');
        echo $sym . ' | ' . ($r['dok_NrPelny'] ?? '') . ' oryg=' . ($r['dok_NrPelnyOryg'] ?? '')
            . ' | ilosc=' . ($r['ilosc'] ?? '') . ' mag=' . ($r['mag'] ?? '')
            . ' | data=' . $data . "\n";
    }
}
echo "SUM expected: ";
foreach ($sum as $s => $q) echo "$s=$q ";
echo "\n";

echo "\n=== 2) st_StanRez aktualny ===\n";
$stan = MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, CAST(s.st_StanRez AS float) AS rez, CAST(s.st_Stan AS float) AS stan
     FROM tw_Stan s INNER JOIN tw__Towar t ON t.tw_Id=s.st_TowId
     WHERE s.st_MagId=1 AND t.tw_Symbol IN ({$in}) ORDER BY t.tw_Symbol"
);
if (is_array($stan)) {
    foreach ($stan as $r) {
        echo ($r['tw_Symbol'] ?? '') . ' rez=' . ($r['rez'] ?? '') . ' stan=' . ($r['stan'] ?? '') . "\n";
    }
}

echo "\n=== 3) Ostatnie WZ z tymi towarami (dziś/wczoraj) ===\n";
$wz = MSSql::getInstance()->query(
    "SELECT TOP 40 t.tw_Symbol, wz.dok_NrPelny AS wz, wz.dok_NrPelnyOryg AS wz_oryg,
            wz.dok_DataWyst, CAST(p.ob_Ilosc AS float) AS ilosc,
            zk.dok_NrPelny AS zk, zk.dok_Status AS zk_st
     FROM dok__Dokument wz
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = wz.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     LEFT JOIN dok__Dokument zk ON zk.dok_Typ=16 AND (
         zk.dok_NrPelny = wz.dok_NrPelnyOryg OR zk.dok_DoDokNrPelny = wz.dok_NrPelny OR wz.dok_DoDokId = zk.dok_Id
     )
     WHERE wz.dok_Typ=11 AND wz.dok_Status>=0
       AND t.tw_Symbol IN ({$in})
       AND wz.dok_DataWyst >= DATEADD(day, -2, CAST(GETDATE() AS date))
     ORDER BY wz.dok_Id DESC, t.tw_Symbol"
);
if (is_array($wz)) {
    foreach ($wz as $r) {
        $data = $r['dok_DataWyst'];
        if (is_object($data) && method_exists($data, 'format')) $data = $data->format('Y-m-d H:i');
        echo ($r['tw_Symbol'] ?? '') . ' | ' . ($r['wz'] ?? '') . ' oryg=' . ($r['wz_oryg'] ?? '')
            . ' | zk=' . ($r['zk'] ?? '-') . ' zk_st=' . ($r['zk_st'] ?? '-')
            . ' | qty=' . ($r['ilosc'] ?? '') . ' | ' . $data . "\n";
    }
}

echo "\n=== 4) Ostatnie ZK z tymi towarami (status dowolny, 2 dni) ===\n";
$zkAll = MSSql::getInstance()->query(
    "SELECT TOP 40 t.tw_Symbol, d.dok_NrPelny, d.dok_Status, d.dok_DoDokNrPelny, d.dok_NrPelnyOryg,
            CAST(p.ob_Ilosc AS float) AS ilosc, d.dok_DataWyst
     FROM dok__Dokument d
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId=d.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
     WHERE d.dok_Typ=16 AND d.dok_Status>=0 AND t.tw_Symbol IN ({$in})
       AND d.dok_DataWyst >= DATEADD(day, -2, CAST(GETDATE() AS date))
     ORDER BY d.dok_Id DESC, t.tw_Symbol"
);
if (is_array($zkAll)) {
    foreach ($zkAll as $r) {
        $data = $r['dok_DataWyst'];
        if (is_object($data) && method_exists($data, 'format')) $data = $data->format('Y-m-d H:i');
        echo ($r['tw_Symbol'] ?? '') . ' | ' . ($r['dok_NrPelny'] ?? '') . ' st=' . ($r['dok_Status'] ?? '')
            . ' do=' . ($r['dok_DoDokNrPelny'] ?? '-') . ' oryg=' . ($r['dok_NrPelnyOryg'] ?? '')
            . ' qty=' . ($r['ilosc'] ?? '') . ' | ' . $data . "\n";
    }
}

echo "\n=== 5) API mismatch teraz ===\n";
$m = Order::findStockReservationMismatchesSql(1, $symbols);
foreach ($m as $row) {
    echo ($row['symbol'] ?? '') . ' cur=' . ($row['current_rez'] ?? '')
        . ' exp=' . ($row['expected_rez'] ?? '') . ' orphan=' . ($row['orphan_rez'] ?? '') . "\n";
}
