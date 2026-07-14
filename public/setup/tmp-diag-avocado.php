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

$codes = array('IW00AV2', 'IW00AV1');

echo "=== STANY I REZERWACJE (st_Stan) ===\n";
foreach ($codes as $code) {
    $safe = str_replace("'", "''", $code);
    $rows = MSSql::getInstance()->query(
        "SELECT t.tw_Id, t.tw_Symbol, t.tw_Nazwa, s.st_MagId, s.st_Stan, s.st_StanRez, s.st_Stan - ISNULL(s.st_StanRez,0) AS dostepne
         FROM tw__Towar t
         LEFT JOIN tw_Stan s ON s.st_TowId = t.tw_Id
         WHERE t.tw_Symbol = '{$safe}'"
    );
    print_r($rows);
}

echo "\n=== OTWARTE ZK Z TYMI TOWARAMI (status 5/6/7) ===\n";
$inList = "'" . implode("','", array_map(function ($c) {
    return str_replace("'", "''", $c);
}, $codes)) . "'";
$zkRows = MSSql::getInstance()->query(
    "SELECT zk.dok_Id, zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_DataWyst,
            t.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) AS pozostalo
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE zk.dok_Typ = 16 AND zk.dok_Status IN (5, 6, 7)
       AND t.tw_Symbol IN ({$inList})
     ORDER BY zk.dok_Id DESC, t.tw_Symbol"
);
print_r($zkRows);

echo "\n=== SUMA OCZEKIWANYCH REZERWACJI Z OTWARTYCH ZK ===\n";
$expected = MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS suma_pozostalo
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE zk.dok_Typ = 16 AND zk.dok_Status IN (5, 6, 7)
       AND ISNULL(p.ob_TowRodzaj, t.tw_Rodzaj) = 1
       AND t.tw_Symbol IN ({$inList})
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
     GROUP BY t.tw_Symbol"
);
print_r($expected);

echo "\n=== MISMATCH REZERWACJI (panel logic) ===\n";
$mismatches = Order::findStockReservationMismatchesSql($cfg->getWarehouse());
foreach (is_array($mismatches) ? $mismatches : array() as $row) {
    if (in_array($row['tw_Symbol'] ?? '', $codes, true)) {
        print_r($row);
    }
}

echo "\n=== WSZYSTKIE ZK (dowolny status) Z TYMI TOWARAMI - ostatnie 15 ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT TOP 15 zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_DoDokNrPelny,
            t.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE zk.dok_Typ = 16 AND zk.dok_Status >= 0
       AND t.tw_Symbol IN ({$inList})
     ORDER BY zk.dok_Id DESC"
));
