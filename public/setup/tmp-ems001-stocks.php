<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$apiKey = $cfg->getApiKey();
$apiBase = 'http://127.0.0.1:82/api-subiekt-gt/public/api/index.php';
$payload = json_encode(['api_key' => $apiKey, 'data' => ['code' => 'EMS001']]);
$ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\n",
    'content' => $payload,
    'timeout' => 60,
]]);
echo file_get_contents(rtrim($apiBase, '/') . '?c=product/getStocks', false, $ctx);
