<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

$symbols = array('DY60','DY226','KP00004A','MF0149','KN00084','KA00006','KN00080','KN00011','KN00031','KN00051','KN00070','MF0129');
$in = "'" . implode("','", array_map(function ($s) { return str_replace("'", "''", $s); }, $symbols)) . "'";

echo "=== Otwarte ZK (st 5/7) bez WZ z tymi towarami ===\n";
$rows = MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_DoDokNrPelny,
            CAST(p.ob_Ilosc AS float) AS ilosc,
            CAST(ISNULL(p.ob_IloscMag,0) AS float) AS mag
     FROM dok__Dokument d
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = d.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE d.dok_Typ = 16 AND d.dok_Status IN (5, 7) AND d.dok_Status >= 0
       AND t.tw_Symbol IN ({$in})
       AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)
     ORDER BY t.tw_Symbol, d.dok_Id"
);

$bySym = array();
if (is_array($rows)) {
    foreach ($rows as $r) {
        $sym = $r['tw_Symbol'] ?? '';
        $hasWz = trim((string)($r['dok_DoDokNrPelny'] ?? '')) !== '';
        $tag = $hasWz ? 'HAS_WZ_HEADER' : 'no_wz';
        echo ($sym) . ' | ' . ($r['dok_NrPelny'] ?? '') . ' st=' . ($r['dok_Status'] ?? '')
            . ' ex=' . ($r['dok_StatusEx'] ?? '') . ' | ' . $tag
            . ' | ilosc=' . ($r['ilosc'] ?? '') . ' mag=' . ($r['mag'] ?? '') . "\n";
        if (!isset($bySym[$sym])) $bySym[$sym] = array('qty'=>0,'with_wz'=>0,'no_wz'=>0);
        $bySym[$sym]['qty'] += (float)($r['ilosc'] ?? 0);
        if ($hasWz) $bySym[$sym]['with_wz'] += (float)($r['ilosc'] ?? 0);
        else $bySym[$sym]['no_wz'] += (float)($r['ilosc'] ?? 0);
    }
}

echo "\n=== Suma per symbol (status 5/7) ===\n";
foreach ($bySym as $sym => $a) {
    echo "$sym total={$a['qty']} no_wz={$a['no_wz']} with_wz_header={$a['with_wz']}\n";
}

echo "\n=== st_StanRez vs st_Stan ===\n";
$stan = MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, CAST(s.st_StanRez AS float) AS rez, CAST(s.st_Stan AS float) AS stan
     FROM tw_Stan s INNER JOIN tw__Towar t ON t.tw_Id=s.st_TowId
     WHERE s.st_MagId=1 AND t.tw_Symbol IN ({$in})
     ORDER BY t.tw_Symbol"
);
if (is_array($stan)) {
    foreach ($stan as $r) {
        echo ($r['tw_Symbol'] ?? '') . ' rez=' . ($r['rez'] ?? '') . ' stan=' . ($r['stan'] ?? '') . "\n";
    }
}

echo "\n=== Status 8 z IloscMag>0 (powinno byc 0 rez w expected) ===\n";
$rows8 = MSSql::getInstance()->query(
    "SELECT TOP 30 t.tw_Symbol, d.dok_NrPelny, d.dok_Status,
            CAST(p.ob_Ilosc AS float) AS ilosc, CAST(ISNULL(p.ob_IloscMag,0) AS float) AS mag
     FROM dok__Dokument d
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId=d.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
     WHERE d.dok_Typ=16 AND d.dok_Status=8 AND t.tw_Symbol IN ({$in})
       AND ABS(ISNULL(p.ob_IloscMag,0)) > 0.00001
       AND d.dok_DataWyst >= '2026-07-01'
     ORDER BY t.tw_Symbol, d.dok_Id DESC"
);
if (is_array($rows8)) {
    foreach ($rows8 as $r) {
        echo ($r['tw_Symbol'] ?? '') . ' | ' . ($r['dok_NrPelny'] ?? '')
            . ' mag=' . ($r['mag'] ?? '') . ' ilosc=' . ($r['ilosc'] ?? '') . "\n";
    }
}
