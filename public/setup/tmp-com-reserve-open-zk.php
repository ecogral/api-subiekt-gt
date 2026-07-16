<?php
/**
 * COM: włącz rezerwację (Informator + kolumna R) dla otwartych ZK status 5 bez WZ.
 * php public/setup/tmp-com-reserve-open-zk.php
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

$apiKey = $cfg->getApiKey();
$apiBase = getenv('API_TEST_BASE') ?: 'http://127.0.0.1:82/api-subiekt-gt/public/api/index.php';

$rows = MSSql::getInstance()->query(
    "SELECT d.dok_NrPelny
     FROM dok__Dokument d
     WHERE d.dok_Typ = 16 AND d.dok_Status = 5 AND d.dok_Status >= 0
       AND d.dok_DataWyst >= '2026-06-01'
       AND (d.dok_DoDokNrPelny IS NULL OR LTRIM(RTRIM(CAST(d.dok_DoDokNrPelny AS NVARCHAR(100)))) = '')
     ORDER BY d.dok_Id DESC"
);

function apiReserve($url, $apiKey, $orderRef)
{
    $payload = array(
        'api_key' => $apiKey,
        'data' => array(
            'order_ref' => $orderRef,
            'reservation' => true,
            'force_resync' => true,
        ),
    );
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
    return $raw !== false ? $raw : 'HTTP failed';
}

$url = rtrim($apiBase, '/') . '?c=order/reserve';
$ok = 0;
$fail = 0;
foreach ((array) $rows as $r) {
    $ref = trim((string) ($r['dok_NrPelny'] ?? ''));
    if ($ref === '') {
        continue;
    }
    echo "=== {$ref} ===\n";
    $resp = apiReserve($url, $apiKey, $ref);
    echo $resp . "\n";
    $decoded = json_decode($resp, true);
    if (is_array($decoded) && (($decoded['state'] ?? '') === 'success' || !empty($decoded['data']['reservation']) || !empty($decoded['data']['synced']))) {
        $ok++;
    } elseif (is_array($decoded) && ($decoded['state'] ?? '') === 'success') {
        $ok++;
    } else {
        // also count skipped already_reserved / synced as ok-ish
        $data = is_array($decoded) ? ($decoded['data'] ?? $decoded) : array();
        if (!empty($data['reservation']) || ($data['skipped'] ?? '') !== '') {
            $ok++;
        } else {
            $fail++;
        }
    }
}

echo "\nok_or_skip={$ok} fail={$fail} total=" . count((array) $rows) . "\n";
