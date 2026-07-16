<?php
/**
 * Przebudowa rezerwacji COM + test WZ dla ZK 3520.
 *   php public/setup/rebuild-zk3520-com.php
 *   php public/setup/rebuild-zk3520-com.php --create-wz
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;

$createWz = in_array('--create-wz', $argv, true);
$ref = 'ZK 3520/07/2026';

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$apiKey = $cfg->getApiKey();
$apiBase = getenv('API_TEST_BASE') ?: 'http://127.0.0.1:82/api-subiekt-gt/public/api/index.php';

function apiPost($base, $controller, array $data, $apiKey)
{
    $payload = json_encode([
        'api_key' => $apiKey,
        'data' => $data,
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
    $raw = @file_get_contents(rtrim($base, '/') . '?c=' . $controller, false, $ctx);
    return json_decode((string) $raw, true);
}

echo "=== order/get przed ===\n";
print_r(apiPost($apiBase, 'order/get', ['order_ref' => $ref], $apiKey));

echo "\n=== order/checkIssueStock ===\n";
print_r(apiPost($apiBase, 'order/checkIssueStock', ['order_ref' => $ref], $apiKey));

if ($createWz) {
    echo "\n=== document/createIssueFromOrder ===\n";
    print_r(apiPost($apiBase, 'document/createIssueFromOrder', [
        'order_ref' => $ref,
        'close_order' => true,
    ], $apiKey));
}
