<?php
/**
 * Bezpośredni COM: Rezerwacja=true dla otwartych ZK status 5 bez WZ.
 * php public/setup/tmp-com-reserve-direct.php
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\Order;
use APISubiektGT\SubiektGT\OrderComWriter;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();

MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

OrderComWriter::configure(false, true);
MSSql::setComWritesOnly(false, true);

echo "Laczenie z Subiekt GT COM...\n";
$subiekt = SubiektGT::getInstance($cfg);
$com = $subiekt->connect();
if (!$com) {
    fwrite(STDERR, "Brak COM Subiekt\n");
    exit(1);
}
echo "COM OK\n";

$rows = MSSql::getInstance()->query(
    "SELECT d.dok_Id, d.dok_NrPelny
     FROM dok__Dokument d
     WHERE d.dok_Typ = 16 AND d.dok_Status = 5 AND d.dok_Status >= 0
       AND d.dok_DataWyst >= '2026-06-01'
       AND (d.dok_DoDokNrPelny IS NULL OR LTRIM(RTRIM(CAST(d.dok_DoDokNrPelny AS NVARCHAR(100)))) = '')
     ORDER BY d.dok_Id DESC"
);

$ok = 0;
$fail = 0;
foreach ((array) $rows as $r) {
    $ref = trim((string) ($r['dok_NrPelny'] ?? ''));
    $id = (int) ($r['dok_Id'] ?? 0);
    if ($ref === '' || $id <= 0) {
        continue;
    }

    echo "=== {$ref} ===\n";
    try {
        $order = new Order($com, array(
            'order_ref' => $ref,
            'reservation' => true,
            'force_resync' => true,
        ));
        $order->setCfg($cfg);
        if (!$order->isExists()) {
            echo "  not exists / load fail\n";
            $fail++;
            continue;
        }
        $res = $order->reserve();
        Order::syncStockReservationsForOrderProductsSql($id, 1);
        echo '  ' . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
        if (!empty($res['reservation']) || !empty($res['synced']) || ($res['skipped'] ?? '') !== '') {
            $ok++;
        } else {
            $fail++;
        }
    } catch (Throwable $e) {
        echo '  ERR: ' . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "\nok={$ok} fail={$fail} total=" . count((array) $rows) . "\n";

echo "\n=== IW00AV2 / mismatch ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez FROM tw__Towar t
     INNER JOIN tw_Stan s ON s.st_TowId=t.tw_Id AND s.st_MagId=1
     WHERE t.tw_Symbol IN ('IW00AV1','IW00AV2')"
));
print_r(Order::findStockReservationMismatchesSql(1, array('IW00AV1', 'IW00AV2')));
