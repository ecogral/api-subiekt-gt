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

$orderRef = 'ZK 3649/07/2026';
$wzRefs = array('WZ 3249/07/2026', 'WZ 3250/07/2026');

echo "=== ZK 3649 ===\n";
$zk = $db->query("SELECT dok_Id, dok_NrPelny, dok_Status, dok_StatusEx, dok_DoDokId, dok_DoDokNrPelny, dok_WartNetto FROM dok__Dokument WHERE dok_NrPelny='{$orderRef}'");
print_r($zk);
$orderId = (int) ($zk[0]['dok_Id'] ?? 0);

foreach ($wzRefs as $wzRef) {
    echo "\n=== {$wzRef} ===\n";
    print_r($db->query(
        "SELECT dok_Id, dok_NrPelny, dok_Status, dok_DoDokId, dok_DoDokNrPelny, dok_WartNetto, dok_DataWyst
         FROM dok__Dokument WHERE dok_NrPelny='" . str_replace("'", "''", $wzRef) . "'"
    ));
    $wzRow = $db->query("SELECT dok_Id FROM dok__Dokument WHERE dok_NrPelny='" . str_replace("'", "''", $wzRef) . "'");
    $wzId = (int) ($wzRow[0]['dok_Id'] ?? 0);
    if ($wzId > 0) {
        print_r($db->query(
            "SELECT t.tw_Symbol, wp.ob_Ilosc, wp.ob_DoId, zk.dok_NrPelny AS zk_ref
             FROM dok_Pozycja wp
             INNER JOIN tw__Towar t ON t.tw_Id = wp.ob_TowId
             LEFT JOIN dok_Pozycja zp ON zp.ob_Id = wp.ob_DoId
             LEFT JOIN dok__Dokument zk ON zk.dok_Id = zp.ob_DokHanId
             WHERE wp.ob_DokMagId = {$wzId}"
        ));
    }
}

if ($orderId > 0) {
    echo "\n=== Orphan scan (dok_Id BETWEEN {$orderId} AND " . ($orderId + 15) . ") ===\n";
    print_r($db->query(
        "SELECT wz.dok_NrPelny, wz.dok_Id
         FROM dok__Dokument wz
         WHERE wz.dok_Typ = 11 AND wz.dok_Status >= 0
           AND wz.dok_Id BETWEEN {$orderId} AND {$orderId} + 15
           AND EXISTS (SELECT 1 FROM dok_Pozycja wp WHERE wp.ob_DokMagId = wz.dok_Id)
           AND NOT EXISTS (
               SELECT 1 FROM dok_Pozycja wp
               INNER JOIN dok_Pozycja zk ON zk.ob_Id = wp.ob_DoId AND zk.ob_DokHanId = {$orderId}
               WHERE wp.ob_DokMagId = wz.dok_Id
           )
         ORDER BY wz.dok_Id ASC"
    ));

    echo "\n=== Wszystkie WZ tego dnia dla kontrahenta (PROCOUNTECH) ===\n";
    print_r($db->query(
        "SELECT wz.dok_NrPelny, wz.dok_Id, wz.dok_DoDokNrPelny, wz.dok_WartNetto
         FROM dok__Dokument wz
         WHERE wz.dok_Typ = 11 AND wz.dok_NrPelny IN ('WZ 3249/07/2026', 'WZ 3250/07/2026')
         ORDER BY wz.dok_Id"
    ));
    echo "orderId={$orderId}, diff 3249=" . (isset($wzRow) ? '' : '');
}
