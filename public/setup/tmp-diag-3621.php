<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

$orderRef = 'ZK 3621/07/2026';
$issueRef = 'WZ 3212/07/2026';

echo "=== DIAGNOZA {$orderRef} ↔ {$issueRef} ===\n\n";
print_r(Order::diagnoseOrderIssueLinkSql($orderRef, $issueRef));

$orderId = (int) (Order::getOrderRowByRefSql($orderRef)['dok_Id'] ?? 0);

echo "\n=== POZYCJE ZK (Subiekt) ===\n";
$zkPos = MSSql::getInstance()->query(
    "SELECT p.ob_Id, t.tw_Symbol, t.tw_Nazwa, p.ob_Ilosc, p.ob_IloscMag,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) AS pozostalo,
            ISNULL(p.ob_TowRodzaj, t.tw_Rodzaj) AS rodzaj
     FROM dok_Pozycja p
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE p.ob_DokHanId = {$orderId}
     ORDER BY p.ob_Id"
);
print_r($zkPos);

echo "\n=== POZYCJE WZ (Subiekt) ===\n";
$wzPos = MSSql::getInstance()->query(
    "SELECT p.ob_Id, p.ob_DoId, t.tw_Symbol, t.tw_Nazwa, p.ob_Ilosc,
            p.ob_CenaNetto, p.ob_WartNetto
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument wz ON wz.dok_Id = p.ob_DokMagId AND wz.dok_Typ = 11
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE wz.dok_NrPelny = '" . str_replace("'", "''", $issueRef) . "'
     ORDER BY p.ob_Id"
);
print_r($wzPos);

echo "\n=== PORÓWNANIE: ZK vs WZ (towary) ===\n";
$compare = MSSql::getInstance()->query(
    "SELECT t.tw_Symbol,
            zk.ob_Ilosc AS zk_ilosc,
            ISNULL(wz_sum.wz_ilosc, 0) AS wz_ilosc,
            zk.ob_Ilosc - ISNULL(wz_sum.wz_ilosc, 0) AS brakuje_na_wz,
            zk.ob_IloscMag AS zk_zrealizowano
     FROM dok_Pozycja zk
     INNER JOIN tw__Towar t ON t.tw_Id = zk.ob_TowId
     LEFT JOIN (
         SELECT wp.ob_TowId, SUM(wp.ob_Ilosc) AS wz_ilosc
         FROM dok_Pozycja wp
         INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
         WHERE wz.dok_NrPelny = '" . str_replace("'", "''", $issueRef) . "'
         GROUP BY wp.ob_TowId
     ) wz_sum ON wz_sum.ob_TowId = zk.ob_TowId
     WHERE zk.ob_DokHanId = {$orderId}
       AND ISNULL(zk.ob_TowRodzaj, t.tw_Rodzaj) = 1
     ORDER BY t.tw_Symbol"
);
print_r($compare);

echo "\n=== POZYCJE ZK BEZ POKRYCIA NA WZ ===\n";
$missing = array();
foreach (is_array($compare) ? $compare : array() as $row) {
    if ((float) ($row['brakuje_na_wz'] ?? 0) > 0.00001) {
        $missing[] = $row;
    }
}
print_r($missing);

echo "\n=== POZYCJE WZ BEZ ODPOWIEDNIKA W ZK (ob_DoId) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, wp.ob_Ilosc, wp.ob_DoId
     FROM dok_Pozycja wp
     INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId
     INNER JOIN tw__Towar t ON t.tw_Id = wp.ob_TowId
     WHERE wz.dok_NrPelny = '" . str_replace("'", "''", $issueRef) . "'
       AND (wp.ob_DoId IS NULL OR wp.ob_DoId = 0
            OR NOT EXISTS (SELECT 1 FROM dok_Pozycja zk WHERE zk.ob_Id = wp.ob_DoId AND zk.ob_DokHanId = {$orderId}))"
));

echo "\nremaining_qty_goods: " . Order::getOrderRemainingQtyFromSql($orderId, $orderRef, true) . "\n";
echo "coverage_complete: " . (Order::isOrderFullyCoveredByLinkedIssuesSql($orderId, $orderRef) ? 'tak' : 'nie') . "\n";
