<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$apiKey = $cfg->getAPIKey();
$base = 'http://127.0.0.1/api-subiekt-gt/public/api/index.php';

function call_api($base, $endpoint, $apiKey, array $data)
{
    $url = $base . '?c=' . $endpoint;
    $payload = json_encode(['api_key' => $apiKey, 'data' => $data]);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 120,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($url, false, $ctx);
    return json_decode($raw, true);
}

$ref = 'ZK 599/07/2026';
echo "=== BEFORE ===\n";
print_r(call_api($base, 'order/getState', $apiKey, ['order_ref' => $ref]));

echo "\n=== createIssueFromOrder (retry — should repair orphan WZ 486) ===\n";
print_r(call_api($base, 'document/createIssueFromOrder', $apiKey, [
    'order_ref' => $ref,
    'close_order' => true,
    'full_realization' => true,
]));

echo "\n=== AFTER ===\n";
print_r(call_api($base, 'order/getState', $apiKey, ['order_ref' => $ref]));
