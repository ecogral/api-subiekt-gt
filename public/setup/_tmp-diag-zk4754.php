<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\Helper;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

header('Content-Type: text/html; charset=utf-8');
echo '<pre>';

$ref = '4754';
$rows = MSSql::getInstance()->query(
    "SELECT TOP 5 d.dok_Id, d.dok_NrPelny, d.dok_NrPelnyOryg, d.dok_Typ, d.dok_Status, d.dok_StatusEx,
            d.dok_DoDokId, d.dok_DoDokNrPelny, d.dok_WartBrutto, d.dok_DataWyst,
            LEN(d.dok_Uwagi) AS len_u, LEN(ISNULL(d.dok_UwagiExt,'')) AS len_e
     FROM dok__Dokument d
     WHERE d.dok_NrPelny LIKE '%{$ref}%/09/2026%'
        OR d.dok_NrPelnyOryg LIKE '%B2B-972%'
     ORDER BY d.dok_Id DESC"
);

foreach ($rows as $r) {
    echo "=== " . Helper::toUtf8($r['dok_NrPelny']) . " (id={$r['dok_Id']}, typ={$r['dok_Typ']}) ===\n";
    echo "oryg=" . Helper::toUtf8($r['dok_NrPelnyOryg']) . "\n";
    echo "status={$r['dok_Status']} statusEx={$r['dok_StatusEx']}\n";
    echo "doDokId={$r['dok_DoDokId']} doDokNr=" . Helper::toUtf8((string)$r['dok_DoDokNrPelny']) . "\n";
    echo "brutto={$r['dok_WartBrutto']} uwagi={$r['len_u']}+{$r['len_e']}\n\n";

    $id = (int)$r['dok_Id'];
    $pos = MSSql::getInstance()->query(
        "SELECT COUNT(*) AS cnt,
                SUM(CASE WHEN ob_DoId IS NOT NULL AND ob_DoId > 0 THEN 1 ELSE 0 END) AS linked,
                SUM(ob_Ilosc) AS qty,
                SUM(ISNULL(ob_Zrealizowano,0)) AS zreal
         FROM dok_Pozycja WHERE ob_DokId = {$id}"
    );
    if (!empty($pos[0])) {
        echo "pozycje: cnt={$pos[0]['cnt']} linked={$pos[0]['linked']} qty={$pos[0]['qty']} zreal={$pos[0]['zreal']}\n";
    }

    // linked WZ via positions
    $wz = MSSql::getInstance()->query(
        "SELECT DISTINCT w.dok_Id, w.dok_NrPelny, w.dok_Status, w.dok_NrPelnyOryg
         FROM dok_Pozycja p
         JOIN dok_Pozycja pw ON pw.ob_Id = p.ob_DoId OR p.ob_Id = pw.ob_DoId
         JOIN dok__Dokument w ON w.dok_Id = pw.ob_DokId AND w.dok_Typ = 11
         WHERE p.ob_DokId = {$id}"
    );
    if (empty($wz)) {
        $wz = MSSql::getInstance()->query(
            "SELECT dok_Id, dok_NrPelny, dok_Status, dok_NrPelnyOryg
             FROM dok__Dokument
             WHERE dok_Typ = 11 AND (dok_DoDokId = {$id} OR dok_NrPelnyOryg LIKE '%" . str_replace("'", "''", Helper::toWin((string)$r['dok_NrPelny'])) . "%'
                OR dok_NrPelnyOryg LIKE '%B2B-972%')"
        );
    }
    echo "powiązane WZ:\n";
    if (empty($wz)) {
        echo "  (brak)\n";
    } else {
        foreach ($wz as $w) {
            echo "  " . Helper::toUtf8($w['dok_NrPelny']) . " id={$w['dok_Id']} status={$w['dok_Status']} oryg=" . Helper::toUtf8((string)$w['dok_NrPelnyOryg']) . "\n";
        }
    }
    echo "\n";
}

echo '</pre>';
