<?php
/**
 * Test integracyjny COM-only + endpointów używanych przez ecogral-cennik (CRM :8002).
 * Uruchom: php public/setup/integration-test-com.php
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\OrderComWriter;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$apiKey = $cfg->getAPIKey();
$apiBase = getenv('API_TEST_BASE') ?: 'http://127.0.0.1/api-subiekt-gt/public/api/index.php';

MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

$results = array('passed' => 0, 'failed' => 0, 'skipped' => 0, 'tests' => array());

function test_assert(&$results, $name, $ok, $detail = '')
{
    $results['tests'][] = array(
        'name' => $name,
        'ok' => (bool) $ok,
        'detail' => $detail,
    );
    if ($ok) {
        $results['passed']++;
        echo "[OK] {$name}\n";
    } else {
        $results['failed']++;
        echo "[FAIL] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function api_call($base, $endpoint, $apiKey, array $data = array(), $timeout = 120)
{
    $url = rtrim($base, '/') . '?c=' . ltrim($endpoint, '/');
    $payload = json_encode(array(
        'api_key' => $apiKey,
        'data' => $data,
    ));
    $ctx = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ),
    ));
    $raw = @file_get_contents($url, false, $ctx);
    $decoded = $raw !== false ? json_decode($raw, true) : null;

    return array(
        'raw' => $raw,
        'json' => is_array($decoded) ? $decoded : null,
        'url' => $url,
    );
}

echo "=== integration-test-com ===\n";
echo 'COM writes only: ' . (OrderComWriter::comWritesOnly() ? 'yes' : 'no') . "\n";
echo "API base: {$apiBase}\n\n";

// 1. COM guard — UPDATE must throw
$sqlBlocked = false;
try {
    MSSql::getInstance()->query("UPDATE dok__Dokument SET dok_Uwagi = dok_Uwagi WHERE dok_Id = 0");
} catch (Exception $e) {
    $sqlBlocked = stripos($e->getMessage(), 'SQL writes are disabled') !== false;
}
test_assert($results, 'MSSql blocks UPDATE when COM-only', $sqlBlocked);

// 2. product/get
$r = api_call($apiBase, 'product/get', $apiKey, array('code' => '___nonexistent___'));
test_assert(
    $results,
    'product/get responds success',
    ($r['json']['state'] ?? '') === 'success',
    $r['raw'] !== false ? '' : 'HTTP/request failed'
);

// 3. Find sample ZK refs from DB (read-only)
$openRows = MSSql::getInstance()->query(
    "SELECT TOP 3 dok_NrPelny, dok_Status FROM dok__Dokument
     WHERE dok_Typ = 16 AND dok_Status IN (5, 6) AND dok_Status >= 0
     ORDER BY dok_Id DESC"
);
$fulfilledRows = MSSql::getInstance()->query(
    "SELECT TOP 2 dok_NrPelny, dok_Status, dok_StatusEx FROM dok__Dokument
     WHERE dok_Typ = 16 AND dok_Status IN (7, 8) AND dok_Status >= 0
     ORDER BY dok_Id DESC"
);

$openRef = is_array($openRows) && !empty($openRows)
    ? trim((string) $openRows[0]['dok_NrPelny']) : '';
$fulfilledRef = is_array($fulfilledRows) && !empty($fulfilledRows)
    ? trim((string) $fulfilledRows[0]['dok_NrPelny']) : '';

if ($openRef !== '') {
    echo "\n--- Open ZK: {$openRef} ---\n";

    $r = api_call($apiBase, 'order/get', $apiKey, array('order_ref' => $openRef));
    $order = is_array($r['json']['data'] ?? null) ? $r['json']['data'] : array();
    test_assert(
        $results,
        'order/get open ZK',
        ($r['json']['state'] ?? '') === 'success' && !empty($order['order_ref']),
        'state=' . ($r['json']['state'] ?? 'null')
    );

    $r = api_call($apiBase, 'order/getState', $apiKey, array('order_ref' => $openRef));
    test_assert(
        $results,
        'order/getState open ZK',
        ($r['json']['state'] ?? '') === 'success',
        json_encode($r['json']['data'] ?? array(), JSON_UNESCAPED_UNICODE)
    );

    $r = api_call($apiBase, 'order/checkIssueStock', $apiKey, array(
        'order_ref' => $openRef,
        'full_realization' => true,
    ));
    $check = is_array($r['json']['data'] ?? null) ? $r['json']['data'] : array();
    test_assert(
        $results,
        'order/checkIssueStock (symulacja, bez zapisu)',
        ($r['json']['state'] ?? '') === 'success' && array_key_exists('ready', $check),
        isset($check['shortages']) ? 'shortages=' . count((array) $check['shortages']) : ($r['json']['message'] ?? '')
    );
} else {
    $results['skipped']++;
    echo "[SKIP] Brak otwartych ZK (5/6) do testów read-only\n";
}

if ($fulfilledRef !== '') {
    echo "\n--- Fulfilled ZK: {$fulfilledRef} ---\n";

    $r = api_call($apiBase, 'order/fulfill', $apiKey, array('order_ref' => $fulfilledRef));
    test_assert(
        $results,
        'order/fulfill on closed/already fulfilled ZK (no crash)',
        ($r['json']['state'] ?? '') === 'success' || ($r['json']['state'] ?? '') === 'fail',
        substr((string) ($r['json']['message'] ?? json_encode($r['json']['data'] ?? '')), 0, 200)
    );

    $r = api_call($apiBase, 'document/createIssueFromOrder', $apiKey, array(
        'order_ref' => $fulfilledRef,
        'full_realization' => true,
        'realize_all' => true,
    ));
    $state = $r['json']['state'] ?? '';
    $msg = (string) ($r['json']['message'] ?? '');
    test_assert(
        $results,
        'createIssueFromOrder rejects already fulfilled ZK',
        $state === 'fail' && (stripos($msg, 'zrealiz') !== false || stripos($msg, 'realiz') !== false),
        "state={$state}, msg=" . substr($msg, 0, 120)
    );
} else {
    $results['skipped']++;
    echo "[SKIP] Brak zamkniętych ZK (7/8) do testu duplikatu WZ\n";
}

// Document list (CRM sync)
$r = api_call($apiBase, 'Document/getRecentDocuments', $apiKey, array('limit' => 5));
test_assert(
    $results,
    'Document/getRecentDocuments',
    ($r['json']['state'] ?? '') === 'success',
    is_array($r['json']['data'] ?? null) ? 'items=' . count($r['json']['data']) : ($r['json']['message'] ?? '')
);

// CRM app health
$crmOk = false;
$crmCode = 0;
$ctx = stream_context_create(array('http' => array('timeout' => 15, 'ignore_errors' => true)));
$crmBody = @file_get_contents('http://127.0.0.1:8002/', false, $ctx);
if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
    $crmCode = (int) $m[1];
}
$crmOk = $crmCode === 200 && $crmBody !== false && stripos($crmBody, 'ecoGRAL') !== false;
test_assert($results, 'CRM ecogral-cennik :8002 HTTP 200', $crmOk, 'code=' . $crmCode);

echo "\n=== PODSUMOWANIE ===\n";
echo 'Passed: ' . $results['passed'] . "\n";
echo 'Failed: ' . $results['failed'] . "\n";
echo 'Skipped: ' . $results['skipped'] . "\n";

exit($results['failed'] > 0 ? 1 : 0);
