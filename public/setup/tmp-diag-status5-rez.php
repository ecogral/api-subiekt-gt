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

$db = MSSql::getInstance();

$count = $db->query(
    "SELECT COUNT(*) AS c
     FROM dok__Dokument d
     WHERE d.dok_Typ = 16 AND d.dok_Status = 5 AND d.dok_Status >= 0
       AND d.dok_DataWyst >= '2026-06-01'
       AND (d.dok_DoDokNrPelny IS NULL OR LTRIM(RTRIM(CAST(d.dok_DoDokNrPelny AS NVARCHAR(100)))) = '')"
);
echo "Open status5 without WZ since 2026-06: " . (int) ($count[0]['c'] ?? 0) . "\n\n";

echo "=== IW00AV2 related ZK since 2026-07 ===\n";
$av = $db->query(
    "SELECT d.dok_Id, d.dok_NrPelny, d.dok_Status, d.dok_StatusEx,
            CAST(ob.ob_Ilosc AS float) AS ilosc,
            CAST(ob.ob_IloscMag AS float) AS mag
     FROM dok__Dokument d
     INNER JOIN dok_Pozycja ob ON ob.ob_DokHanId = d.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = ob.ob_TowId
     WHERE t.tw_Symbol = 'IW00AV2' AND d.dok_Typ = 16 AND d.dok_Status >= 0
       AND d.dok_DataWyst >= '2026-07-01'
     ORDER BY d.dok_Id DESC"
);
foreach ((array) $av as $r) {
    echo sprintf(
        "  %s status=%s ex=%s ilosc=%s mag=%s label=%s\n",
        $r['dok_NrPelny'],
        $r['dok_Status'],
        $r['dok_StatusEx'],
        $r['ilosc'],
        $r['mag'],
        Order::getOrderStatusLabel((int) $r['dok_Status'])
    );
}

echo "\n=== Stock IW00AV1/AV2 ===\n";
print_r($db->query(
    "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez
     FROM tw__Towar t
     INNER JOIN tw_Stan s ON s.st_TowId = t.tw_Id AND s.st_MagId = 1
     WHERE t.tw_Symbol IN ('IW00AV1', 'IW00AV2')"
));

echo "\n=== Mismatch ===\n";
print_r(Order::findStockReservationMismatchesSql(1, array('IW00AV1', 'IW00AV2')));
