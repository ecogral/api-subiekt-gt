<?php
/**
 * Hybryda COM+SQL dla ZK Avocado (status 5 + Rezerwacja).
 * php public/setup/tmp-com-fix-rez-needed.php
 * Pełny zakres: php tmp-com-fix-rez-needed.php --all
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\Order;
use APISubiektGT\SubiektGT\OrderComWriter;

$all = in_array('--all', $argv ?? array(), true);

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());
OrderComWriter::configure(false, true);
MSSql::setComWritesOnly(false, true);

if ($all) {
    $sql = "SELECT d.dok_Id, d.dok_NrPelny
            FROM dok__Dokument d
            WHERE d.dok_Typ = 16 AND d.dok_Status = 5 AND d.dok_Status >= 0
              AND d.dok_DataWyst >= '2026-06-01'
              AND (d.dok_DoDokNrPelny IS NULL OR LTRIM(RTRIM(CAST(d.dok_DoDokNrPelny AS NVARCHAR(100)))) = '')
            ORDER BY d.dok_Id DESC";
} else {
    $sql = "SELECT DISTINCT d.dok_Id, d.dok_NrPelny
            FROM dok__Dokument d
            INNER JOIN dok_Pozycja p ON p.ob_DokHanId = d.dok_Id
            INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
            WHERE d.dok_Typ = 16 AND d.dok_Status IN (5, 7) AND d.dok_Status >= 0
              AND t.tw_Symbol IN ('IW00AV1', 'IW00AV2')
              AND (p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) > 0.00001
              AND (d.dok_DoDokNrPelny IS NULL OR LTRIM(RTRIM(CAST(d.dok_DoDokNrPelny AS NVARCHAR(100)))) = '')
            ORDER BY d.dok_Id DESC";
}

$rows = MSSql::getInstance()->query($sql);
echo ($all ? 'ALL' : 'AV') . ' count=' . count((array) $rows) . "\n";

$com = SubiektGT::getInstance($cfg)->connect();
if (!$com) {
    fwrite(STDERR, "Brak COM\n");
    exit(1);
}

$ok = 0;
$fail = 0;
foreach ((array) $rows as $r) {
    $ref = trim((string) ($r['dok_NrPelny'] ?? ''));
    $id = (int) ($r['dok_Id'] ?? 0);
    if ($ref === '') {
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
            echo "  not loaded\n";
            $fail++;
            continue;
        }
        $res = $order->reserve();
        Order::syncStockReservationsForOrderProductsSql($id, 1);
        echo '  ' . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";

        $ro = new ReflectionClass($order);
        $gt = $ro->getProperty('orderGt');
        $gt->setAccessible(true);
        $doc = $gt->getValue($order);
        $sqlSt = MSSql::getInstance()->query(
            "SELECT dok_Status FROM dok__Dokument WHERE dok_Id={$id}"
        );
        $comRez = ($doc && ($doc->Rezerwacja ?? false)) ? 1 : 0;
        $st = (int) ($sqlSt[0]['dok_Status'] ?? 0);
        echo "  verify SQL.status={$st} COM.Rez={$comRez}\n";
        if ($st === 5 && $comRez === 1) {
            $ok++;
        } else {
            $fail++;
        }
    } catch (Throwable $e) {
        echo '  ERR: ' . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "\nok={$ok} fail={$fail}\n";
print_r(MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez FROM tw__Towar t
     INNER JOIN tw_Stan s ON s.st_TowId=t.tw_Id AND s.st_MagId=1
     WHERE t.tw_Symbol IN ('IW00AV1','IW00AV2')"
));
