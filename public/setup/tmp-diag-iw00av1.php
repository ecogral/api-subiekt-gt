<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$c = new Config(CONFIG_INI_FILE);
$c->load();
MSSql::getInstance([
    'UID' => $c->getDbUser(),
    'PWD' => $c->getDbUserPass(),
    'Database' => $c->getDatabase(),
], $c->getServer());

$symbol = 'IW00AV1';

echo "=== vwTowar (jak lista GT) mag=1 ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT tw_Symbol, Stan, Rezerwacja, Dostepne, st_MagId
     FROM vwTowar WHERE tw_Symbol = '{$symbol}' AND st_MagId = 1"
));

echo "\n=== st_Stan mag=1 ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez, s.st_Stan - ISNULL(s.st_StanRez,0) AS dostepne
     FROM tw__Towar t INNER JOIN tw_Stan s ON s.st_TowId = t.tw_Id
     WHERE t.tw_Symbol = '{$symbol}' AND s.st_MagId = 1"
));

echo "\n=== ZK z pozostałością IW00AV1 (lipiec 2026) ===\n";
$rows = MSSql::getInstance()->query(
    "SELECT zk.dok_Id, zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx,
            zk.dok_DoDokNrPelny, p.ob_Ilosc, ISNULL(p.ob_IloscMag,0) AS wydano,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) AS pozostalo
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE zk.dok_Typ = 16 AND t.tw_Symbol = '{$symbol}'
       AND zk.dok_DataWyst >= '2026-07-01'
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) > 0.00001
     ORDER BY zk.dok_Id"
);
foreach (is_array($rows) ? $rows : array() as $row) {
    $ref = (string) ($row['dok_NrPelny'] ?? '');
    $id = (int) ($row['dok_Id'] ?? 0);
    $stuck = Order::isOrderStuckWithoutIssueSql($id, $ref) ? 'TAK' : 'nie';
    $hasRez = Order::orderHasActiveReservationSql($id, $ref) ? 'TAK' : 'nie';
    $issueRefs = Order::getIssueRefsForOrder($ref, $id);
    echo "{$ref} | status={$row['dok_Status']} | pozost={$row['pozostalo']} | stuck7bezWZ={$stuck} | rezSQL={$hasRez} | WZ=" . implode(',', $issueRefs) . "\n";
}

echo "\n=== Oczekiwana rezerwacja (tylko status 5) ===\n";
print_r(Order::findStockReservationMismatchesSql(1, array($symbol)));

echo "\n=== Oczekiwana rezerwacja (status 5+7 bez WZ) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag,0)) AS powinno_byc_rezerwacji
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument zk ON zk.dok_Id = p.ob_DokHanId AND zk.dok_Typ = 16
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = '{$symbol}'
       AND zk.dok_Status IN (5, 7)
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) > 0.00001
       AND NOT EXISTS (
           SELECT 1 FROM dok_Pozycja wp
           INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
           WHERE wp.ob_DoId = p.ob_Id
       )"
));
