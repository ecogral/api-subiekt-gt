<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$sym = 'EMS001';

$c = new Config(CONFIG_INI_FILE);
$c->load();
$wh = (int) $c->getWarehouse() ?: 1;
MSSql::getInstance([
    'UID' => $c->getDbUser(),
    'PWD' => $c->getDbUserPass(),
    'Database' => $c->getDatabase(),
], $c->getServer());

echo "=== Stan magazynowy {$sym} (mag {$wh}) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez, s.st_Stan - s.st_StanRez AS wolne
     FROM tw__Towar t
     LEFT JOIN tw_Stan s ON s.st_TowId = t.tw_Id AND s.st_MagId = {$wh}
     WHERE t.tw_Symbol = '{$sym}'"
));

echo "\n=== Otwarte ZK (status 5) z {$sym} ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT d.dok_NrPelny, d.dok_Status, p.ob_Ilosc,
            ISNULL(p.ob_IloscMag, 0) AS wydano,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) AS pozostalo
     FROM dok__Dokument d
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = d.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE d.dok_Typ = 16 AND d.dok_Status = 5 AND t.tw_Symbol = '{$sym}'
     ORDER BY d.dok_NrPelny"
));

echo "\n=== ZK status 6 (bez rezerwacji SQL) z {$sym} ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT d.dok_NrPelny, d.dok_Status, p.ob_Ilosc,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) AS pozostalo
     FROM dok__Dokument d
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = d.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE d.dok_Typ = 16 AND d.dok_Status = 6 AND t.tw_Symbol = '{$sym}'
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
     ORDER BY d.dok_NrPelny"
));

echo "\n=== ZK zamknięte 7/8 z {$sym} (pozycja nie w pełni wydana) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT TOP 15 d.dok_NrPelny, d.dok_Status, p.ob_Ilosc,
            ISNULL(p.ob_IloscMag, 0) AS wydano,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) AS pozostalo
     FROM dok__Dokument d
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = d.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE d.dok_Typ = 16 AND d.dok_Status IN (7, 8) AND t.tw_Symbol = '{$sym}'
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
     ORDER BY d.dok_DataWyst DESC"
));

echo "\n=== findStockReservationMismatchesSql ===\n";
print_r(Order::findStockReservationMismatchesSql($wh, array($sym)));

$expectedFromStatus5 = MSSql::getInstance()->query(
    "SELECT SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS expected
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16 AND d.dok_Status = 5
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = '{$sym}'"
);
echo "\nOczekiwane st_StanRez (tylko ZK status 5): "
    . (float) ($expectedFromStatus5[0]['expected'] ?? 0) . "\n";

echo "\n=== Wszystkie ZK z {$sym} (ostatnie 25) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT TOP 25 d.dok_NrPelny, d.dok_Status, d.dok_DataWyst,
            p.ob_Ilosc, ISNULL(p.ob_IloscMag, 0) AS wydano,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) AS pozostalo
     FROM dok__Dokument d
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = d.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE d.dok_Typ = 16 AND t.tw_Symbol = '{$sym}'
     ORDER BY d.dok_Id DESC"
));

echo "\n=== ZK 5/6/7 z EMS001 i pozostalo>0 (kandydaci COM ghost) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT d.dok_NrPelny, d.dok_Status, p.ob_Ilosc,
            ISNULL(p.ob_IloscMag, 0) AS wydano,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) AS pozostalo
     FROM dok__Dokument d
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = d.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE d.dok_Typ = 16 AND d.dok_Status IN (5, 6, 7)
       AND t.tw_Symbol = '{$sym}'
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
     ORDER BY d.dok_Status, d.dok_NrPelny"
));
