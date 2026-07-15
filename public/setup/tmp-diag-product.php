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

$symbol = isset($argv[1]) ? trim((string) $argv[1]) : 'DY62C';

echo "=== DIAG: {$symbol} ===\n\n";

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

echo "\n=== Mismatch rezerwacji ===\n";
print_r(Order::findStockReservationMismatchesSql(1, array($symbol)));

echo "\n=== ZK z pozostałością (2026) ===\n";
$rows = MSSql::getInstance()->query(
    "SELECT zk.dok_Id, zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx,
            zk.dok_ZrealizowaneZRezerwacja, zk.dok_DoDokNrPelny,
            p.ob_Ilosc, ISNULL(p.ob_IloscMag,0) AS wydano,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) AS pozostalo
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE zk.dok_Typ = 16 AND t.tw_Symbol = '{$symbol}'
       AND zk.dok_DataWyst >= '2026-01-01'
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) > 0.00001
     ORDER BY zk.dok_DataWyst DESC, zk.dok_Id DESC"
);
foreach (is_array($rows) ? $rows : array() as $row) {
    $ref = (string) ($row['dok_NrPelny'] ?? '');
    $id = (int) ($row['dok_Id'] ?? 0);
    $stuck = Order::isOrderStuckWithoutIssueSql($id, $ref) ? 'TAK' : 'nie';
    $hasRez = Order::orderHasActiveReservationSql($id, $ref) ? 'TAK' : 'nie';
    $issueRefs = Order::getIssueRefsForOrder($ref, $id);
    echo "{$ref} | status={$row['dok_Status']} | pozost={$row['pozostalo']} | stuck7bezWZ={$stuck} | rezSQL={$hasRez} | WZ=" . implode(',', $issueRefs) . "\n";
}

echo "\n=== ZK status 7/8 bez WZ (stuck) ===\n";
if (is_array($rows)) {
    foreach ($rows as $row) {
        $ref = (string) ($row['dok_NrPelny'] ?? '');
        $id = (int) ($row['dok_Id'] ?? 0);
        if (Order::isOrderStuckWithoutIssueSql($id, $ref)) {
            echo "  {$ref}\n";
        }
    }
}

echo "\n=== Oczekiwana rezerwacja (status 5) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag,0)) AS expected_rez_status5
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument zk ON zk.dok_Id = p.ob_DokHanId AND zk.dok_Typ = 16
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = '{$symbol}' AND zk.dok_Status = 5
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) > 0.00001"
));

echo "\n=== ZK 7 z pozostalo>0 (błędne) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT zk.dok_NrPelny, zk.dok_Status, p.ob_Ilosc, ISNULL(p.ob_IloscMag,0) AS wydano,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) AS pozostalo
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE zk.dok_Typ = 16 AND t.tw_Symbol = '{$symbol}'
       AND zk.dok_Status IN (7, 8)
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) > 0.00001
     ORDER BY zk.dok_NrPelny"
));

echo "\n=== Wszystkie ZK z {$symbol} (status >= 0) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_DataWyst,
            p.ob_Ilosc, ISNULL(p.ob_IloscMag,0) AS wydano,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) AS pozostalo
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE zk.dok_Typ = 16 AND t.tw_Symbol = '{$symbol}' AND zk.dok_Status >= 0
     ORDER BY zk.dok_DataWyst DESC"
));

echo "\n=== ZK status 5/6 z {$symbol} (Informator) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT zk.dok_NrPelny, zk.dok_Status, p.ob_Ilosc, ISNULL(p.ob_IloscMag,0) AS wydano,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) AS pozostalo
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE zk.dok_Typ = 16 AND t.tw_Symbol = '{$symbol}'
       AND zk.dok_Status IN (5, 6)
     ORDER BY zk.dok_DataWyst DESC"
));
