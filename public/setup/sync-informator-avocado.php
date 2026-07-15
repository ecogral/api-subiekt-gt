<?php
/**
 * Synchronizacja Informatora: COM rezerwacja dla ZK Avocado + zwolnienie duha 3643.
 *
 *   php public/setup/sync-informator-avocado.php
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$apiKey = $cfg->getApiKey();
$apiBase = getenv('API_TEST_BASE') ?: 'http://127.0.0.1:82/api-subiekt-gt/public/api/index.php';

function apiPost($url, $payload)
{
    $ctx = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => 180,
            'ignore_errors' => true,
        ),
    ));
    $raw = @file_get_contents($url, false, $ctx);

    return $raw !== false ? $raw : "HTTP failed\n";
}

$calls = array(
    array('c' => 'order/reserve', 'data' => array('order_ref' => 'ZK 3603/07/2026', 'force_resync' => true)),
    array('c' => 'order/reserve', 'data' => array('order_ref' => 'ZK 3618/07/2026', 'force_resync' => true)),
    array('c' => 'order/reserve', 'data' => array('order_ref' => 'ZK 3629/07/2026', 'force_resync' => true)),
    array('c' => 'order/reserve', 'data' => array('order_ref' => 'ZK 3643/07/2026', 'reservation' => false)),
);

foreach ($calls as $call) {
    $url = rtrim($apiBase, '/') . '?c=' . $call['c'];
    $payload = array('api_key' => $apiKey, 'data' => $call['data']);
    echo "\n=== {$call['c']} {$call['data']['order_ref']} ===\n";
    echo apiPost($url, $payload);
}

echo "\n";
