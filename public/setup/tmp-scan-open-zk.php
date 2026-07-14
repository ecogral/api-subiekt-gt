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
    "SELECT zk.dok_Id, zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx,
            zk.dok_ZrealizowaneZRezerwacja, zk.dok_DoDokNrPelny, zk.dok_DataWyst
     FROM dok__Dokument zk
     WHERE zk.dok_Typ = 16
       AND zk.dok_Status >= 0
       AND zk.dok_DataWyst >= '2026-07-10'
       AND ISNULL(zk.dok_DoDokNrPelny, '') LIKE 'WZ%'
     ORDER BY zk.dok_Id DESC"
);

$problems = array();
$ok = array();
foreach (is_array($rows) ? $rows : array() as $row) {
    $status = (int) ($row['dok_Status'] ?? 0);
    $statusEx = (int) ($row['dok_StatusEx'] ?? 0);
    $ref = (string) ($row['dok_NrPelny'] ?? '');
    $orderId = (int) ($row['dok_Id'] ?? 0);
    $fullCheck = $status === 8 && ($statusEx & 4) !== 0;
    $partial = in_array($status, array(5, 6, 7), true) || ($statusEx & 4) === 0;
    $coverage = Order::isOrderFullyCoveredByLinkedIssuesSql($orderId, $ref);
    $entry = array(
        'zk' => $ref,
        'status' => $status,
        'status_ex' => $statusEx,
        'status_label' => Order::getOrderStatusLabel($status),
        'status_ex_label' => Order::getOrderStatusExLabel($statusEx),
        'wz' => trim((string) ($row['dok_DoDokNrPelny'] ?? '')),
        'coverage' => $coverage,
    );
    if (!$fullCheck && $coverage) {
        $problems[] = $entry;
    } elseif ($fullCheck) {
        $ok[] = $ref;
    }
}

echo "=== PROBLEMY (WZ pokrywa ZK, brak status 8 + StatusEx 4) ===\n";
echo json_encode($problems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
echo "OK count (8+bit4): " . count($ok) . "\n";
echo "Problems count: " . count($problems) . "\n";
