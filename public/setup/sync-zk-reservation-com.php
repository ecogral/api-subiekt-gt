<?php
/**
 * Synchronizacja rezerwacji ZK w COM (Informator produktu + dropdown w GT).
 * Wymaga wolnej licencji Sfery.
 *
 *   php public/setup/sync-zk-reservation-com.php --dry-run
 *   php public/setup/sync-zk-reservation-com.php --apply
 *   php public/setup/sync-zk-reservation-com.php --apply --order-ref="ZK 3603/07/2026"
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\Order;

$options = getopt('', array('dry-run', 'apply', 'order-ref:'));
$apply = isset($options['apply']);
$dryRun = isset($options['dry-run']) || !$apply;
$singleRef = isset($options['order-ref']) ? trim((string) $options['order-ref']) : '';

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

if ($singleRef !== '') {
    $orderRefs = array($singleRef);
} else {
    $rows = MSSql::getInstance()->query(
        "SELECT DISTINCT zk.dok_NrPelny AS order_ref
         FROM dok__Dokument zk
         INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
         WHERE zk.dok_Typ = 16
           AND zk.dok_Status = 5
           AND p.ob_TowId > 0
           AND p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
         ORDER BY zk.dok_NrPelny"
    );
    $orderRefs = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $ref = trim((string) ($row['order_ref'] ?? ''));
            if ($ref !== '') {
                $orderRefs[] = $ref;
            }
        }
    }
}

$sqlPreview = array();
foreach ($orderRefs as $orderRef) {
    $zk = Order::getOrderRowByRefSql($orderRef);
    $sqlPreview[] = array(
        'order_ref' => $orderRef,
        'dok_Status' => (int) ($zk['dok_Status'] ?? 0),
        'needs_com_sync' => Order::orderHasActiveReservationSql(
            (int) ($zk['dok_Id'] ?? 0),
            $orderRef
        ),
    );
}

$results = array();
if ($dryRun) {
    echo json_encode(array(
        'dry_run' => true,
        'orders_sql' => $sqlPreview,
        'message' => 'Podgląd — uruchom z --apply aby zapisać rezerwację przez COM (Informator).',
    ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

try {
    $subiektGt = SubiektGT::getInstance($cfg);
    $com = $subiektGt->connect();
} catch (\Throwable $e) {
    echo json_encode(array(
        'state' => 'com_unavailable',
        'orders_sql' => $sqlPreview,
        'error' => $e->getMessage(),
        'message' => 'Sfera COM niedostępna — zamknij Subiekta na innych stanowiskach i spróbuj ponownie.',
    ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

foreach ($orderRefs as $orderRef) {
    $order = Order::loadExistingByRefVariants($com, array('order_ref' => $orderRef));
    if ($order === null) {
        $results[] = array('order_ref' => $orderRef, 'state' => 'not_found');
        continue;
    }
    $order->setCfg($cfg);
    $results[] = array_merge(
        array('order_ref' => $orderRef),
        $order->syncOrderReservationInCom(true)
    );
}

echo json_encode(array(
    'dry_run' => false,
    'orders' => $results,
    'message' => 'Po zapisie odśwież Informator produktu (F5).',
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
