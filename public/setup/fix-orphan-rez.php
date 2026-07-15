<?php
/**
 * Naprawa osieroconej rezerwacji st_StanRez + sync COM Informator.
 *
 *   php public/setup/fix-orphan-rez.php DY62C
 *   php public/setup/fix-orphan-rez.php DY62C IW00AV1
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$symbols = array_slice($argv, 1);
if (empty($symbols)) {
    fwrite(STDERR, "Podaj symbol(e) towaru, np. DY62C\n");
    exit(1);
}

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$wh = (int) $cfg->getWarehouse() ?: 1;
MSSql::getInstance([
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
], $cfg->getServer());

echo "=== PRZED ===\n";
foreach ($symbols as $sym) {
    $m = Order::findStockReservationMismatchesSql($wh, array($sym));
    if (empty($m)) {
        echo "{$sym}: OK (brak rozjazdu)\n";
    } else {
        foreach ($m as $row) {
            printf(
                "%s: stan=%.0f rez=%.0f oczekiwane=%.0f orphan=%+.0f\n",
                $row['symbol'],
                $row['on_store'],
                $row['current_rez'],
                $row['expected_rez'],
                $row['orphan_rez']
            );
        }
    }
}

$sqlResult = MSSql::withSqlWriteFallback(function () use ($wh, $symbols) {
    return Order::syncStockReservationsFromOrdersSql($wh, false, $symbols);
});
echo "\n=== SQL sync st_StanRez ===\n";
print_r($sqlResult);

$apiKey = $cfg->getApiKey();
$apiBase = getenv('API_TEST_BASE') ?: 'http://127.0.0.1:82/api-subiekt-gt/public/api/index.php';

// COM: zwolnij rezerwację na zamkniętych ZK (status 7) z towarem — duchy w Informatorze
$ghostRows = MSSql::getInstance()->query(
    "SELECT DISTINCT zk.dok_NrPelny AS order_ref, zk.dok_Status
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE zk.dok_Typ = 16
       AND zk.dok_Status IN (6, 7)
       AND t.tw_Symbol IN ('" . implode("','", array_map(function ($s) {
           return str_replace("'", "''", $s);
       }, $symbols)) . "')
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) <= 0.00001
     ORDER BY zk.dok_NrPelny DESC"
);
$comResults = array();
$limit = 30;
$n = 0;
if (is_array($ghostRows)) {
    foreach ($ghostRows as $row) {
        if ($n >= $limit) {
            break;
        }
        $ref = trim((string) ($row['order_ref'] ?? ''));
        if ($ref === '') {
            continue;
        }
        $payload = json_encode([
            'api_key' => $apiKey,
            'data' => ['order_ref' => $ref, 'reservation' => false],
        ], JSON_UNESCAPED_UNICODE);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 120,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents(rtrim($apiBase, '/') . '?c=order/reserve', false, $ctx);
        $comResults[] = array(
            'order_ref' => $ref,
            'status' => (int) ($row['dok_Status'] ?? 0),
            'response' => json_decode((string) $raw, true),
        );
        $n++;
    }
}

echo "\n=== COM: zwolnienie rezerwacji na ZK 6/7 bez pozostałości (max {$limit}) ===\n";
echo json_encode($comResults, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

echo "\n=== PO ===\n";
foreach ($symbols as $sym) {
    $r = MSSql::getInstance()->query(
        "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez, s.st_Stan - ISNULL(s.st_StanRez,0) AS dostepne
         FROM tw__Towar t INNER JOIN tw_Stan s ON s.st_TowId = t.tw_Id AND s.st_MagId = {$wh}
         WHERE t.tw_Symbol = '" . str_replace("'", "''", $sym) . "'"
    );
    print_r($r);
}
