<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$db = MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

echo "=== ZK NOWA ELEKTRO ~49000 netto, lipiec 2026 ===\n";
$rows = $db->query(
    "SELECT TOP 15 d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_DataWyst,
            d.dok_DoDokNrPelny, d.dok_WartNetto, d.dok_WartBrutto
     FROM dok__Dokument d
     WHERE d.dok_Typ = 16 AND d.dok_Status >= 0
       AND d.dok_DataWyst >= '2026-07-07' AND d.dok_DataWyst <= '2026-07-09'
       AND ABS(d.dok_WartNetto - 49000) < 500
     ORDER BY d.dok_DataWyst DESC"
);
foreach ($rows ?: array() as $r) {
    $dw = $r['dok_DataWyst'];
    if ($dw instanceof DateTimeInterface) {
        $dw = $dw->format('Y-m-d');
    }
    printf(
        "  %s | st=%s | %s | net=%s | WZ=%s\n",
        $r['dok_NrPelny'],
        $r['dok_Status'],
        $dw,
        $r['dok_WartNetto'],
        trim((string) ($r['dok_DoDokNrPelny'] ?? ''))
    );
}
$open = $db->query(
    "SELECT d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_DataWyst, d.dok_DoDokNrPelny,
            SUM(p.ob_Ilosc) AS zam, SUM(ISNULL(p.ob_IloscMag, 0)) AS wyd,
            SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS pozostalo
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16 AND d.dok_Status >= 0
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = 'DY61' AND d.dok_Status NOT IN (8, -1)
       AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)
     GROUP BY d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_DataWyst, d.dok_DoDokNrPelny
     ORDER BY d.dok_DataWyst DESC"
);
foreach ($open ?: array() as $r) {
    print_r($r);
}

echo "\n=== DY61: co liczy sie do expected (status 5) vs co trzyma rez w GT ===\n";
$m = Order::findStockReservationMismatchesSql(1, array('DY61'));
print_r($m);

echo "\n=== ZK status 6 z DY61 (UI: niezrealizowane?) ===\n";
print_r($db->query(
    "SELECT d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_DataWyst, d.dok_DoDokNrPelny,
            p.ob_Ilosc, p.ob_IloscMag
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = 'DY61' AND d.dok_Status = 6
     ORDER BY d.dok_DataWyst DESC"
));

echo "\n=== ZK 3638 — jak GT widzi status (N = niezrealizowane?) ===\n";
print_r($db->query(
    "SELECT dok_NrPelny, dok_Status, dok_StatusEx, dok_ZrealizowaneZRezerwacja, dok_DoDokNrPelny
     FROM dok__Dokument WHERE dok_NrPelny IN ('ZK 3638/07/2026', 'ZK 3648/07/2026')"
));
