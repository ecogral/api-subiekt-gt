<?php
/**
 * Naprawa powiązania ZK↔WZ przez endpoint API (używa sesji COM API, nie osobnej Sfery).
 *
 *   php public/setup/link-zk-wz-via-api.php
 *   php public/setup/link-zk-wz-via-api.php "ZK 3651/07/2026" "WZ 3249/07/2026"
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$orderRef = $argv[1] ?? 'ZK 3651/07/2026';
$issueRef = $argv[2] ?? 'WZ 3249/07/2026';

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$apiKey = $cfg->getAPIKey();

$apiBase = getenv('API_TEST_BASE') ?: 'http://127.0.0.1:82/api-subiekt-gt/public/api/index.php';
$url = rtrim($apiBase, '/') . '?c=order/repairIssueLinks';
$payload = json_encode(array(
    'api_key' => $apiKey,
    'data' => array(
        'order_ref' => $orderRef,
        'issue_ref' => $issueRef,
        'close_order' => true,
    ),
), JSON_UNESCAPED_UNICODE);

MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

echo "=== PRZED ===\n";
print_r(Order::getOrderRowByRefSql($orderRef));

$ctx = stream_context_create(array(
    'http' => array(
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 180,
        'ignore_errors' => true,
    ),
));

echo "\n=== API: {$url} ===\n";
$raw = @file_get_contents($url, false, $ctx);
echo $raw !== false ? $raw : "HTTP request failed\n";

echo "\n=== PO ===\n";
print_r(Order::getOrderRowByRefSql($orderRef));
