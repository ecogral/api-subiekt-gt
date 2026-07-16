<?php
/**
 * Diagnostyka osieroconych rezerwacji magazynowych
 * (st_StanRez vs ZK status 5 + stuck ZK 7 bez WZ).
 * php public/setup/diag-orphan-rez.php MF0162 KN00020
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$symbols = array_slice($argv, 1);
if (empty($symbols)) {
    $symbols = array('MF0162', 'KN00020', 'DY61', 'DY56', 'EP00100', 'EA00004');
}

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$wh = (int) $cfg->getWarehouse();
if ($wh <= 0) {
    $wh = 1;
}

MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

$db = MSSql::getInstance();
$inList = implode(', ', array_map(function ($s) {
    return "'" . str_replace("'", "''", $s) . "'";
}, $symbols));

echo "=== ROZJAZDY REZERWACJI (magazyn {$wh}) ===\n\n";
$mismatches = Order::findStockReservationMismatchesSql($wh, $symbols);
foreach ($mismatches as $m) {
    printf(
        "%s: stan=%.0f rez=%.0f oczekiwane=%.0f orphan=%+.0f dostepne=%.0f\n",
        $m['symbol'],
        $m['on_store'],
        $m['current_rez'],
        $m['expected_rez'],
        $m['orphan_rez'],
        $m['on_store'] - $m['current_rez']
    );
}

echo "\n=== Otwarte ZK status 5 (część expected_rez) ===\n";
$open5 = $db->query(
    "SELECT d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_ZrealizowaneZRezerwacja,
            t.tw_Symbol, SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS pozostalo
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16 AND d.dok_Status = 5
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol IN ({$inList})
       AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
     GROUP BY d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_ZrealizowaneZRezerwacja, t.tw_Symbol
     ORDER BY t.tw_Symbol, d.dok_NrPelny"
);
if (empty($open5)) {
    echo "  (brak — stąd expected=0 w audycie)\n";
} else {
    foreach ($open5 as $r) {
        echo '  ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "\n=== ZK status 6/7 z pozostałością (rezerwacja w GT, ale NIE w expected_rez) ===\n";
$open67 = $db->query(
    "SELECT d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_ZrealizowaneZRezerwacja, d.dok_DataWyst,
            t.tw_Symbol, SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS pozostalo,
            SUM(p.ob_Ilosc) AS zamowiono, SUM(ISNULL(p.ob_IloscMag, 0)) AS wydano
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16
         AND d.dok_Status IN (6, 7) AND d.dok_Status >= 0
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol IN ({$inList})
       AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
     GROUP BY d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_ZrealizowaneZRezerwacja, d.dok_DataWyst, t.tw_Symbol
     ORDER BY t.tw_Symbol, d.dok_DataWyst DESC"
);
if (empty($open67)) {
    echo "  (brak)\n";
} else {
    foreach (array_slice($open67, 0, 40) as $r) {
        echo '  ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
    if (count($open67) > 40) {
        echo '  ... +' . (count($open67) - 40) . " wierszy\n";
    }
}

echo "\n=== ZK status 8 (zamknięte) lipiec 2026 — ostatnie z tymi towarami (kandydaci: nie zwolniono rez) ===\n";
$closed = $db->query(
    "SELECT TOP 30 d.dok_NrPelny, d.dok_Status, d.dok_DataWyst, t.tw_Symbol,
            SUM(p.ob_Ilosc) AS zamowiono, SUM(ISNULL(p.ob_IloscMag, 0)) AS wydano
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16 AND d.dok_Status = 8
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol IN ({$inList})
       AND d.dok_DataWyst >= '2026-07-01'
       AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)
     GROUP BY d.dok_NrPelny, d.dok_Status, d.dok_DataWyst, t.tw_Symbol
     ORDER BY d.dok_DataWyst DESC, t.tw_Symbol"
);
foreach ($closed as $r) {
    echo '  ' . ($r['dok_NrPelny'] ?? '') . ' | ' . ($r['tw_Symbol'] ?? '')
        . ' | zam=' . ($r['zamowiono'] ?? '') . ' wyd=' . ($r['wydano'] ?? '') . "\n";
}

echo "\n=== Suma pozostałości po statusach ZK (lipiec+, te symbole) ===\n";
$byStatus = $db->query(
    "SELECT d.dok_Status, t.tw_Symbol,
            SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS pozostalo
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16 AND d.dok_Status >= 0
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol IN ({$inList})
       AND d.dok_DataWyst >= '2026-06-01'
       AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)
     GROUP BY d.dok_Status, t.tw_Symbol
     HAVING SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) > 0.00001
     ORDER BY t.tw_Symbol, d.dok_Status"
);
foreach ($byStatus as $r) {
    printf("  status %s %-10s pozostalo=%.2f\n", $r['dok_Status'], $r['tw_Symbol'], $r['pozostalo']);
}

echo "\n=== Ruchy magazynowe WZ lipiec (czy towar faktycznie wyszedł) ===\n";
$wzMoves = $db->query(
    "SELECT t.tw_Symbol, SUM(p.ob_Ilosc) AS wz_ilosc, COUNT(DISTINCT d.dok_Id) AS wz_cnt
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokMagId AND d.dok_Typ = 11 AND d.dok_Status >= 0
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol IN ({$inList}) AND d.dok_DataWyst >= '2026-07-01'
     GROUP BY t.tw_Symbol ORDER BY t.tw_Symbol"
);
foreach ($wzMoves as $r) {
    echo '  ' . ($r['tw_Symbol'] ?? '') . ': WZ qty=' . ($r['wz_ilosc'] ?? 0) . ' (' . ($r['wz_cnt'] ?? 0) . " dok.)\n";
}

echo "\n=== Interpretacja ===\n";
echo "expected_rez = ZK status 5 (pozostalo) + stuck ZK status 7 bez WZ (ob_Ilosc).\n";
echo "orphan = st_StanRez w magazynie minus to oczekiwanie.\n";
echo "Typowe przyczyny orphan>0: ZK domknięte 8 z WZ bez zwolnienia st_StanRez, lub ręczna rezerwacja w GT.\n";
echo "SB00EP11-style (status 7 bez WZ) NIE powinno wychodzić jako orphan.\n";
