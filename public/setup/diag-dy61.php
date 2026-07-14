<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$wh = (int) $cfg->getWarehouse();
if ($wh <= 0) {
    $wh = 1;
}

$db = MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

echo "=== DY61 — stan magazynowy ===\n";
print_r($db->query(
    "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez, s.st_Stan - ISNULL(s.st_StanRez, 0) AS dostepne
     FROM tw_Stan s
     INNER JOIN tw__Towar t ON t.tw_Id = s.st_TowId
     WHERE t.tw_Symbol = 'DY61' AND s.st_MagId = {$wh}"
));

$m = Order::findStockReservationMismatchesSql($wh, array('DY61'));
echo "\n=== Rozjazd rezerwacji ===\n";
foreach ($m as $row) {
    printf(
        "rez=%.0f oczekiwane=%.0f orphan=%+.0f\n",
        $row['current_rez'],
        $row['expected_rez'],
        $row['orphan_rez']
    );
}

echo "\n=== ZK z pozostalo > 0 (DY61, cala baza) ===\n";
$remaining = $db->query(
    "SELECT d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_DataWyst, d.dok_DoDokNrPelny,
            SUM(p.ob_Ilosc) AS zam, SUM(ISNULL(p.ob_IloscMag, 0)) AS wyd,
            SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS pozostalo
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16 AND d.dok_Status >= 0
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = 'DY61' AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)
     GROUP BY d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_DataWyst, d.dok_DoDokNrPelny
     HAVING SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) > 0.00001
     ORDER BY d.dok_DataWyst DESC"
);
if (empty($remaining)) {
    echo "  (brak — zadne ZK nie trzyma pozostalosci na DY61)\n";
} else {
    foreach ($remaining as $r) {
        print_r($r);
    }
}

echo "\n=== Ostatnie 20 ZK z DY61 ===\n";
$recent = $db->query(
    "SELECT TOP 20 d.dok_NrPelny, d.dok_Status, d.dok_DataWyst, d.dok_DoDokNrPelny,
            SUM(p.ob_Ilosc) AS zam, SUM(ISNULL(p.ob_IloscMag, 0)) AS wyd
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16 AND d.dok_Status >= 0
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = 'DY61' AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)
     GROUP BY d.dok_NrPelny, d.dok_Status, d.dok_DataWyst, d.dok_DoDokNrPelny, d.dok_Id
     ORDER BY d.dok_Id DESC"
);
foreach ($recent ?: array() as $r) {
    $dataWyst = $r['dok_DataWyst'] ?? '';
    if ($dataWyst instanceof DateTimeInterface) {
        $dataWyst = $dataWyst->format('Y-m-d');
    }
    printf(
        "  %s | st=%s | %s | zam=%s wyd=%s | WZ=%s\n",
        $r['dok_NrPelny'] ?? '',
        $r['dok_Status'] ?? '',
        $dataWyst,
        $r['zam'] ?? '',
        $r['wyd'] ?? '',
        trim((string) ($r['dok_DoDokNrPelny'] ?? ''))
    );
}

echo "\n=== ZK status 7/8 z niepelnym wydaniem DY61 ===\n";
$stuck = $db->query(
    "SELECT d.dok_NrPelny, d.dok_Status, d.dok_DataWyst, d.dok_DoDokNrPelny,
            SUM(p.ob_Ilosc) AS zam, SUM(ISNULL(p.ob_IloscMag, 0)) AS wyd,
            SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS pozostalo
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16
         AND d.dok_Status IN (7, 8) AND d.dok_Status >= 0
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = 'DY61' AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)
     GROUP BY d.dok_NrPelny, d.dok_Status, d.dok_DataWyst, d.dok_DoDokNrPelny
     HAVING SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) > 0.00001
     ORDER BY d.dok_DataWyst DESC"
);
if (empty($stuck)) {
    echo "  (brak)\n";
} else {
    foreach ($stuck as $r) {
        print_r($r);
    }
}

echo "\n=== Suma DY61 po statusie ZK (czerwiec 2026+) ===\n";
$byStatus = $db->query(
    "SELECT d.dok_Status,
            SUM(p.ob_Ilosc) AS zam,
            SUM(ISNULL(p.ob_IloscMag, 0)) AS wyd,
            SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS pozostalo
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16 AND d.dok_Status >= 0
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = 'DY61' AND d.dok_DataWyst >= '2026-06-01'
       AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)
     GROUP BY d.dok_Status
     ORDER BY d.dok_Status"
);
foreach ($byStatus ?: array() as $r) {
    printf(
        "  status %s: zam=%s wyd=%s pozostalo=%s\n",
        $r['dok_Status'],
        $r['zam'],
        $r['wyd'],
        $r['pozostalo']
    );
}

echo "\n=== Wnioski ===\n";
echo "- expected=0: brak otwartego ZK status 5 z DY61 (to NIE jest jak ZK 3513)\n";
echo "- orphan=+12: st_StanRez=12 bez dokumentu, ktory by to uzasadnial\n";
