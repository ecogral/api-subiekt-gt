<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

$oryg = 'B2B-606/2026';
$safe = str_replace("'", "''", $oryg);
echo "=== ZK dla {$oryg} ===\n";
$rows = MSSql::getInstance()->query(
    "SELECT d.dok_Id, d.dok_NrPelny, d.dok_NrPelnyOryg, d.dok_Status, d.dok_DataWyst, d.dok_WartNetto
     FROM dok__Dokument d
     WHERE d.dok_Typ = 16 AND d.dok_Status >= 0
       AND (LTRIM(RTRIM(ISNULL(d.dok_NrPelnyOryg, ''))) = '{$safe}'
            OR d.dok_NrPelny LIKE 'ZK 37%/07/2026')
     ORDER BY d.dok_Id"
);

$zkFor606 = array();
if (is_array($rows)) {
    foreach ($rows as $r) {
        $o = trim((string)($r['dok_NrPelnyOryg'] ?? ''));
        if ($o === $oryg) {
            $zkFor606[] = $r;
        }
    }
}
if (empty($zkFor606)) {
    echo "Brak ZK z oryg={$oryg}, szukam po LIKE B2B-606...\n";
    $rows2 = MSSql::getInstance()->query(
        "SELECT dok_Id, dok_NrPelny, dok_NrPelnyOryg, dok_Status, dok_DataWyst, dok_WartNetto
         FROM dok__Dokument WHERE dok_Typ=16 AND dok_Status>=0 AND dok_NrPelnyOryg LIKE 'B2B-606%'
         ORDER BY dok_Id"
    );
    $zkFor606 = is_array($rows2) ? $rows2 : array();
}

echo 'count=' . count($zkFor606) . "\n";
foreach ($zkFor606 as $r) {
    $id = (int)($r['dok_Id'] ?? 0);
    echo "\n" . ($r['dok_NrPelny'] ?? '') . " | oryg=" . ($r['dok_NrPelnyOryg'] ?? '')
        . " | st=" . ($r['dok_Status'] ?? '') . " | netto=" . ($r['dok_WartNetto'] ?? '') . "\n";
    $p = MSSql::getInstance()->query(
        "SELECT t.tw_Symbol, CAST(p.ob_Ilosc AS float) AS qty, CAST(p.ob_WartNetto AS float) AS net
         FROM dok_Pozycja p LEFT JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
         WHERE p.ob_DokHanId={$id} ORDER BY t.tw_Symbol"
    );
    if (is_array($p)) {
        foreach ($p as $line) {
            echo '  ' . ($line['tw_Symbol'] ?? '') . " x " . ($line['qty'] ?? '') . " net=" . ($line['net'] ?? '') . "\n";
        }
    }
}

// Podobne oryg B2B-606% kolizje
echo "\n=== Wszystkie B2B-606* w lipcu ===\n";
$all = MSSql::getInstance()->query(
    "SELECT dok_NrPelny, dok_NrPelnyOryg, dok_WartNetto, dok_Status FROM dok__Dokument
     WHERE dok_Typ=16 AND dok_Status>=0 AND dok_NrPelnyOryg LIKE 'B2B-606%'
     AND dok_DataWyst >= '2026-07-01' ORDER BY dok_Id"
);
if (is_array($all)) {
    foreach ($all as $a) {
        echo ($a['dok_NrPelny'] ?? '') . ' | ' . ($a['dok_NrPelnyOryg'] ?? '') . ' | netto=' . ($a['dok_WartNetto'] ?? '') . "\n";
    }
}
