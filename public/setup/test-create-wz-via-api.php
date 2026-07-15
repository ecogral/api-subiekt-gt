<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;

$orderRef = $argv[1] ?? 'ZK 3652/07/2026';

$c = new Config(CONFIG_INI_FILE);
$c->load();
$apiKey = $c->getApiKey();
$apiBase = getenv('API_TEST_BASE') ?: 'http://127.0.0.1:82/api-subiekt-gt/public/api/index.php';
$url = rtrim($apiBase, '/') . '?c=document/createIssueFromOrder';
$payload = json_encode([
    'api_key' => $apiKey,
    'data' => [
        'order_ref' => $orderRef,
        'close_order' => true,
    ],
], JSON_UNESCAPED_UNICODE);

$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 180,
        'ignore_errors' => true,
    ],
]);

echo "POST {$url}\n";
echo file_get_contents($url, false, $ctx);
