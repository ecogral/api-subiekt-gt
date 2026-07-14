<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

// ZK lipiec z WZ gdzie liczba pozycji towarowych na WZ < na ZK
echo "=== ZK z brakującymi pozycjami towarowymi na WZ (lipiec 2026) ===\n";
$rows = MSSql::getInstance()->query(
    "SELECT zk.dok_NrPelny AS zk_ref, wz.dok_NrPelny AS wz_ref,
            zk.dok_Status, zk_cnt.cnt AS zk_towary, wz_cnt.cnt AS wz_towary
     FROM dok__Dokument zk
     INNER JOIN dok__Dokument wz ON wz.dok_Id = zk.dok_DoDokId AND wz.dok_Typ = 11
     CROSS APPLY (
         SELECT COUNT(*) AS cnt FROM dok_Pozycja p
         INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
         WHERE p.ob_DokHanId = zk.dok_Id AND ISNULL(p.ob_TowRodzaj, t.tw_Rodzaj) = 1
     ) zk_cnt
     CROSS APPLY (
         SELECT COUNT(DISTINCT p.ob_TowId) AS cnt FROM dok_Pozycja p
         INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
         WHERE p.ob_DokMagId = wz.dok_Id AND ISNULL(p.ob_TowRodzaj, t.tw_Rodzaj) = 1
     ) wz_cnt
     WHERE zk.dok_Typ = 16 AND zk.dok_Status >= 0
       AND zk.dok_DataWyst >= '2026-07-01'
       AND ISNULL(zk.dok_DoDokNrPelny, '') LIKE 'WZ%'
       AND wz_cnt.cnt < zk_cnt.cnt
     ORDER BY zk.dok_Id DESC"
);
print_r($rows);

echo "\n=== ZK z brakiem ilości na WZ (symbol po symbolu) ===\n";
$qtyMismatch = MSSql::getInstance()->query(
    "SELECT TOP 20 zk.dok_NrPelny, wz.dok_NrPelny, t.tw_Symbol,
            zk_p.ob_Ilosc AS zk_ilosc, ISNULL(wz_sum.wz_ilosc, 0) AS wz_ilosc,
            zk_p.ob_Ilosc - ISNULL(wz_sum.wz_ilosc, 0) AS brakuje
     FROM dok__Dokument zk
     INNER JOIN dok__Dokument wz ON wz.dok_Id = zk.dok_DoDokId AND wz.dok_Typ = 11
     INNER JOIN dok_Pozycja zk_p ON zk_p.ob_DokHanId = zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = zk_p.ob_TowId
     LEFT JOIN (
         SELECT wp.ob_DokMagId, wp.ob_TowId, SUM(wp.ob_Ilosc) AS wz_ilosc
         FROM dok_Pozycja wp GROUP BY wp.ob_DokMagId, wp.ob_TowId
     ) wz_sum ON wz_sum.ob_DokMagId = wz.dok_Id AND wz_sum.ob_TowId = zk_p.ob_TowId
     WHERE zk.dok_Typ = 16 AND zk.dok_DataWyst >= '2026-07-01'
       AND ISNULL(zk_p.ob_TowRodzaj, t.tw_Rodzaj) = 1
       AND zk_p.ob_Ilosc - ISNULL(wz_sum.wz_ilosc, 0) > 0.00001
     ORDER BY zk.dok_Id DESC"
);
print_r($qtyMismatch);
