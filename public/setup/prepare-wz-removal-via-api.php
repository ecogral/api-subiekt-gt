<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$orderRef = $argv[1] ?? 'ZK 3651/07/2026';
$issueRef = $argv[2] ?? 'WZ 3249/07/2026';

$c = new Config(CONFIG_INI_FILE);
$c->load();
$apiKey = $c->getApiKey();
$apiBase = getenv('API_TEST_BASE') ?: 'http://127.0.0.1:82/api-subiekt-gt/public/api/index.php';
$url = rtrim($apiBase, '/') . '?c=order/prepareIssueForRemoval';
$payload = json_encode([
    'api_key' => $apiKey,
    'data' => [
        'order_ref' => $orderRef,
        'issue_ref' => $issueRef,
    ],
], JSON_UNESCAPED_UNICODE);

MSSql::getInstance([
    'UID' => $c->getDbUser(),
    'PWD' => $c->getDbUserPass(),
    'Database' => $c->getDatabase(),
], $c->getServer());

echo "=== PRZED ===\n";
print_r(Order::diagnoseIssueForInvoicingSql($issueRef));

$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 180,
        'ignore_errors' => true,
    ],
]);

echo "\n=== API ===\n";
echo file_get_contents($url, false, $ctx);

echo "\n=== PO ===\n";
print_r(Order::diagnoseIssueForInvoicingSql($issueRef));
