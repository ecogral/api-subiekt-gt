<?php
/**
 * Naprawa ZK 7 bez WZ (Avocado IW00AV1/2) przez API — COM + SQL.
 *
 *   php public/setup/repair-avocado-via-api.php
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$orderRefs = array(
    'ZK 3603/07/2026',
    'ZK 3618/07/2026',
    'ZK 3629/07/2026',
);

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$apiKey = $cfg->getApiKey();
$apiBase = getenv('API_TEST_BASE') ?: 'http://127.0.0.1:82/api-subiekt-gt/public/api/index.php';
$warehouseId = (int) $cfg->getWarehouse() ?: 1;

MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

echo "=== PRZED ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez, s.st_Stan - ISNULL(s.st_StanRez,0) AS dostepne
     FROM tw__Towar t INNER JOIN tw_Stan s ON s.st_TowId = t.tw_Id AND s.st_MagId = {$warehouseId}
     WHERE t.tw_Symbol IN ('IW00AV1','IW00AV2') ORDER BY t.tw_Symbol"
));

$results = array();
foreach ($orderRefs as $orderRef) {
    $url = rtrim($apiBase, '/') . '?c=order/repairStuckOrderWithoutIssue';
    $payload = json_encode(array(
        'api_key' => $apiKey,
        'data' => array('order_ref' => $orderRef),
    ), JSON_UNESCAPED_UNICODE);

    $ctx = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 180,
            'ignore_errors' => true,
        ),
    ));

    echo "\n=== API repair: {$orderRef} ===\n";
    $raw = @file_get_contents($url, false, $ctx);
    echo $raw !== false ? $raw : "HTTP failed\n";
    $results[] = json_decode((string) $raw, true);
}

echo "\n=== Sync st_StanRez (SQL) ===\n";
$syncResult = MSSql::withSqlWriteFallback(function () use ($warehouseId) {
    return Order::syncStockReservationsFromOrdersSql($warehouseId, false, array('IW00AV1', 'IW00AV2'));
});
print_r($syncResult);

echo "\n=== PO (stany) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez, s.st_Stan - ISNULL(s.st_StanRez,0) AS dostepne
     FROM tw__Towar t INNER JOIN tw_Stan s ON s.st_TowId = t.tw_Id AND s.st_MagId = {$warehouseId}
     WHERE t.tw_Symbol IN ('IW00AV1','IW00AV2') ORDER BY t.tw_Symbol"
));

echo "\n=== ZK po naprawie ===\n";
foreach ($orderRefs as $ref) {
    $zk = Order::getOrderRowByRefSql($ref);
    echo "{$ref} status=" . (int) ($zk['dok_Status'] ?? 0) . "\n";
}

echo "\n=== Mismatch rezerwacji ===\n";
print_r(Order::findStockReservationMismatchesSql($warehouseId, array('IW00AV1', 'IW00AV2')));
