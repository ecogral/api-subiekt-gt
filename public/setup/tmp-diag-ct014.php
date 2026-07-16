<?php
/**
 * Diagnostyka CT014-1 + audyt rozjazdów rezerwacji.
 * php public/setup/tmp-diag-ct014.php
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$c = new Config(CONFIG_INI_FILE);
$c->load();
MSSql::getInstance(array(
    'UID' => $c->getDbUser(),
    'PWD' => $c->getDbUserPass(),
    'Database' => $c->getDatabase(),
), $c->getServer());
$db = MSSql::getInstance();

echo "DB=" . $c->getDatabase() . " server=" . $c->getServer() . "\n\n";

echo "=== CT014-1 wszystkie magazyny ===\n";
print_r($db->query(
    "SELECT t.tw_Symbol, s.st_MagId, s.st_Stan, s.st_StanRez,
            s.st_Stan - ISNULL(s.st_StanRez, 0) AS dost
     FROM tw__Towar t
     INNER JOIN tw_Stan s ON s.st_TowId = t.tw_Id
     WHERE t.tw_Symbol = 'CT014-1'
     ORDER BY s.st_MagId"
));

echo "\n=== vwTowar CT014-1 ===\n";
print_r($db->query(
    "SELECT tw_Symbol, st_MagId, Stan, Rezerwacja, Dostepne
     FROM vwTowar WHERE tw_Symbol = 'CT014-1'"
));

echo "\n=== ZK 3689 szczegoly ===\n";
$zk = $db->query(
    "SELECT dok_Id, dok_NrPelny, dok_Status, dok_StatusEx, dok_ZrealizowaneZRezerwacja,
            dok_DataWyst, dok_DoDokId, dok_DoDokNrPelny, dok_KwWartosc
     FROM dok__Dokument WHERE dok_NrPelny = 'ZK 3689/07/2026'"
);
print_r($zk);
$zkId = !empty($zk[0]['dok_Id']) ? (int) $zk[0]['dok_Id'] : 0;

echo "\n=== Pozycje ZK 3689 ===\n";
print_r($db->query(
    "SELECT p.ob_Id, t.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag, p.ob_MagId, p.ob_TowRodzaj
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId
     LEFT JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE d.dok_NrPelny = 'ZK 3689/07/2026'"
));

echo "\n=== WZ powiazane z ZK 3689 ===\n";
print_r(Order::getIssueRefsForOrder('ZK 3689/07/2026', $zkId));
print_r($db->query(
    "SELECT dok_Id, dok_NrPelny, dok_Typ, dok_Status, dok_DataWyst, dok_DoDokNrPelny, dok_DoDokId
     FROM dok__Dokument
     WHERE dok_DoDokNrPelny = 'ZK 3689/07/2026'
        OR dok_DoDokId = {$zkId}
     ORDER BY dok_Id DESC"
));

echo "\n=== Otwarte/czesciowe ZK z CT014-1 (status 5/6/7) ===\n";
print_r($db->query(
    "SELECT d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_ZrealizowaneZRezerwacja,
            d.dok_DataWyst, p.ob_Ilosc, ISNULL(p.ob_IloscMag, 0) AS wyd,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) AS pozost
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = 'CT014-1'
       AND d.dok_Status IN (5, 6, 7)
       AND d.dok_Status >= 0
     ORDER BY d.dok_DataWyst DESC"
));

echo "\n=== Ostatnie ZK CT014-1 z pozostalo>0 (dowolny status) ===\n";
print_r($db->query(
    "SELECT d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_ZrealizowaneZRezerwacja,
            d.dok_DataWyst, p.ob_Ilosc, ISNULL(p.ob_IloscMag, 0) AS wyd,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) AS pozost
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = 'CT014-1'
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
     ORDER BY d.dok_DataWyst DESC"
));

echo "\n=== Produkty z rez>0 w st_Stan mag=1 (TOP 50) ===\n";
$rez = $db->query(
    "SELECT TOP 50 t.tw_Symbol, s.st_Stan, s.st_StanRez,
            s.st_Stan - ISNULL(s.st_StanRez, 0) AS dost
     FROM tw_Stan s
     INNER JOIN tw__Towar t ON t.tw_Id = s.st_TowId
     WHERE s.st_MagId = 1 AND ISNULL(s.st_StanRez, 0) > 0.00001
     ORDER BY s.st_StanRez DESC"
);
foreach (is_array($rez) ? $rez : array() as $r) {
    printf(
        "%s stan=%.3f rez=%.3f dost=%.3f\n",
        $r['tw_Symbol'],
        (float) $r['st_Stan'],
        (float) $r['st_StanRez'],
        (float) $r['dost']
    );
}
echo 'razem_z_rez=' . (is_array($rez) ? count($rez) : 0) . "\n";

echo "\n=== ROZJAZDY WSZYSTKICH PRODUKTOW (mag 1) ===\n";
$mm = Order::findStockReservationMismatchesSql(1, null);
echo 'count=' . count($mm) . "\n";
$orphanPos = 0;
$orphanNeg = 0;
$sumOrphan = 0.0;
$sumAbs = 0.0;
foreach ($mm as $m) {
    $o = (float) $m['orphan_rez'];
    $sumOrphan += $o;
    $sumAbs += abs($o);
    if ($o > 0) {
        $orphanPos++;
    } else {
        $orphanNeg++;
    }
}
echo "za_duzo_rez (orphan>0 / st_StanRez > ZK status 5): {$orphanPos}\n";
echo "za_malo_rez (orphan<0 / st_StanRez < ZK status 5): {$orphanNeg}\n";
echo "suma_orphan={$sumOrphan} suma_abs={$sumAbs}\n";

echo "\nTOP 40 orphan:\n";
foreach (array_slice($mm, 0, 40) as $m) {
    printf(
        "%s stan=%.2f rez=%.2f expected=%.2f orphan=%+.2f\n",
        $m['symbol'],
        $m['on_store'],
        $m['current_rez'],
        $m['expected_rez'],
        $m['orphan_rez']
    );
}

echo "\n=== ZK status 5 z pozostalo (ile prawdziwych rezerwacji) ===\n";
$open5 = $db->query(
    "SELECT COUNT(DISTINCT d.dok_Id) AS zk_cnt,
            COUNT(*) AS pos_cnt,
            SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS qty
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId
         AND d.dok_Typ = 16 AND d.dok_Status = 5
     WHERE p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
       AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)"
);
print_r($open5);

echo "\n=== ZK status 7 z pozostalo>0 BEZ WZ (stuck — rezerwacja 'zniknieta' z Informatora) ===\n";
$stuck = $db->query(
    "SELECT TOP 50 d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_DataWyst,
            t.tw_Symbol,
            SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS pozostalo
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId
         AND d.dok_Typ = 16 AND d.dok_Status IN (7, 8)
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
       AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)
       AND d.dok_DataWyst >= '2026-01-01'
     GROUP BY d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_DataWyst, t.tw_Symbol
     ORDER BY d.dok_DataWyst DESC"
);
$stuckCnt = 0;
foreach (is_array($stuck) ? $stuck : array() as $r) {
    $ref = (string) $r['dok_NrPelny'];
    // szybki check bez WZ przez DoDok
    $wz = $db->query(
        "SELECT TOP 1 dok_NrPelny FROM dok__Dokument
         WHERE dok_Typ = 11 AND dok_Status >= 0
           AND (dok_DoDokNrPelny = '" . str_replace("'", "''", $ref) . "')"
    );
    if (empty($wz)) {
        $stuckCnt++;
        echo "  {$ref} status={$r['dok_Status']} {$r['tw_Symbol']} pozost={$r['pozostalo']}\n";
    }
}
echo "stuck_bez_WZ_w_probce=" . $stuckCnt . " (z TOP50 pozycji)\n";

echo "\n=== ZK status 6 z pozostalo (otwarte BEZ rezerwacji w expected) ===\n";
$st6 = $db->query(
    "SELECT COUNT(DISTINCT d.dok_Id) AS zk_cnt,
            SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS qty
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId
         AND d.dok_Typ = 16 AND d.dok_Status = 6
     WHERE p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
       AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)"
);
print_r($st6);

echo "\nDONE\n";
