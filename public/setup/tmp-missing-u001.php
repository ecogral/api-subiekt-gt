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

$rows = MSSql::getInstance()->query(
    "SELECT zk.dok_Id, zk.dok_NrPelny, zk.dok_DoDokNrPelny
     FROM dok__Dokument zk
     WHERE zk.dok_Typ = 16 AND zk.dok_Status >= 0
       AND zk.dok_DataWyst >= '2026-07-07'
       AND ISNULL(zk.dok_DoDokNrPelny,'') LIKE 'WZ%'
     ORDER BY zk.dok_Id DESC"
);

$missingU001 = array();
foreach (is_array($rows) ? $rows : array() as $row) {
    $zkId = (int) $row['dok_Id'];
    $wzRef = trim((string) $row['dok_DoDokNrPelny']);
    $missing = Order::findMissingIssueServiceCodesSql($zkId, $wzRef);
    if (!empty($missing)) {
        $missingU001[] = array(
            'zk' => $row['dok_NrPelny'],
            'wz' => $wzRef,
            'missing' => $missing,
            'zk_services' => Order::getOrderServiceLinesFromSql($zkId),
        );
    }
}

echo "ZK with WZ but missing services: " . count($missingU001) . "\n";
echo json_encode(array_slice($missingU001, 0, 25), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// Also: ZK with U001 by tw_Rodzaj but ob_TowRodzaj wrong
$wrongRodzaj = MSSql::getInstance()->query(
    "SELECT TOP 15 zk.dok_NrPelny, t.tw_Symbol, zk.ob_TowRodzaj, t.tw_Rodzaj
     FROM dok_Pozycja zk
     INNER JOIN tw__Towar t ON t.tw_Id = zk.ob_TowId
     INNER JOIN dok__Dokument d ON d.dok_Id = zk.ob_DokHanId AND d.dok_Typ = 16
     WHERE t.tw_Symbol = 'U001' AND d.dok_DataWyst >= '2026-07-07'
       AND ISNULL(zk.ob_TowRodzaj, 1) <> 2"
);
echo "\n\nU001 with wrong ob_TowRodzaj:\n";
echo json_encode($wrongRodzaj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
