<?php
/**
 * Co Informator uznaje za „z rezerwacją”? Porównanie status 5 vs 7 + COM.
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\Order;
use APISubiektGT\SubiektGT\OrderComWriter;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());
$db = MSSql::getInstance();

echo "=== dok_ObiektGT by status (2026-07) ===\n";
print_r($db->query(
    "SELECT dok_Status, dok_ObiektGT, COUNT(*) AS c
     FROM dok__Dokument
     WHERE dok_Typ=16 AND dok_DataWyst >= '2026-07-01' AND dok_Status >= 0
     GROUP BY dok_Status, dok_ObiektGT
     ORDER BY dok_Status, dok_ObiektGT"
));

echo "\n=== Views with Rezerw/Inform ===\n";
print_r($db->query(
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.VIEWS
     WHERE TABLE_NAME LIKE '%Rezer%' OR TABLE_NAME LIKE '%Inform%' OR TABLE_NAME LIKE '%Zamow%'
     ORDER BY TABLE_NAME"
));

echo "\n=== Tables tw_ / dok_ with rez ===\n";
print_r($db->query(
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME LIKE '%Rezer%' OR TABLE_NAME LIKE '%rezer%'
     ORDER BY TABLE_NAME"
));

// Przywróc 3696 do 5 i porównaj z 3696 jako 7 w kontekscie st_StanRez / expected
echo "\n=== Current 3696 ===\n";
print_r($db->query(
    "SELECT dok_Id, dok_NrPelny, dok_Status, dok_StatusEx, dok_ObiektGT, dok_ZrealizowaneZRezerwacja
     FROM dok__Dokument WHERE dok_NrPelny='ZK 3696/07/2026'"
));

// Szukaj ZK status 5 gdzie COM Rezerwacja=true — jesli zero, to w tej bazie nigdy nie wspolistnieja
OrderComWriter::configure(false, true);
$com = SubiektGT::getInstance($cfg)->connect();

$candidates = $db->query(
    "SELECT TOP 20 dok_NrPelny, dok_Status FROM dok__Dokument
     WHERE dok_Typ=16 AND dok_Status IN (5,7)
       AND dok_DataWyst >= '2026-07-01'
       AND (dok_DoDokNrPelny IS NULL OR LTRIM(RTRIM(CAST(dok_DoDokNrPelny AS NVARCHAR(100)))) = '')
     ORDER BY dok_Status, dok_Id DESC"
);

echo "\n=== COM scan status 5/7 bez WZ ===\n";
$stats = array('5_rez0' => 0, '5_rez1' => 0, '7_rez0' => 0, '7_rez1' => 0);
foreach ((array) $candidates as $c) {
    $ref = $c['dok_NrPelny'] ?? '';
    if ($ref === '') {
        continue;
    }
    try {
        $order = new Order($com, array('order_ref' => $ref));
        $order->setCfg($cfg);
        if (!$order->isExists()) {
            continue;
        }
        $ro = new ReflectionClass($order);
        $gt = $ro->getProperty('orderGt');
        $gt->setAccessible(true);
        $doc = $gt->getValue($order);
        $stP = $ro->getProperty('state');
        $stP->setAccessible(true);
        $st = (int) $stP->getValue($order);
        $rez = ($doc && ($doc->Rezerwacja ?? false)) ? 1 : 0;
        $key = $st . '_rez' . $rez;
        if (isset($stats[$key])) {
            $stats[$key]++;
        }
        echo "{$ref} status={$st} rez={$rez}\n";
    } catch (Throwable $e) {
        echo "{$ref} ERR\n";
    }
}
echo "STATS: ";
print_r($stats);

// Hipoteza: w Sferze „otwarte z rezerwacja” = status 7 + Rezerwacja + bez WZ + StatusEx bez bit4?
echo "\n=== Stuck 7 bez WZ (StatusEx) ===\n";
print_r($db->query(
    "SELECT dok_StatusEx, COUNT(*) c FROM dok__Dokument d
     WHERE dok_Typ=16 AND dok_Status=7 AND dok_DataWyst>='2026-07-01'
       AND (dok_DoDokNrPelny IS NULL OR LTRIM(RTRIM(CAST(dok_DoDokNrPelny AS NVARCHAR(100)))) = '')
     GROUP BY dok_StatusEx"
));
