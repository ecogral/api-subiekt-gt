<?php
/**
 * Tylko odczyt COM: Status + Rezerwacja dla wskazanych ZK.
 * php public/setup/tmp-com-read-rez.php
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

$refs = array(
    'ZK 3696/07/2026',
    'ZK 3694/07/2026',
    'ZK 3690/07/2026', // z WZ / R w liscie (kontrolny)
);

echo "Laczenie COM...\n";
$subiekt = SubiektGT::getInstance($cfg);
$com = $subiekt->connect();
if (!$com) {
    fwrite(STDERR, "Brak COM\n");
    exit(1);
}
echo "COM OK\n\n";

foreach ($refs as $ref) {
    $safe = str_replace("'", "''", $ref);
    $sql = MSSql::getInstance()->query(
        "SELECT dok_Id, dok_NrPelny, dok_Status, dok_StatusEx, dok_DoDokNrPelny,
                dok_ZrealizowaneZRezerwacja
         FROM dok__Dokument WHERE dok_Typ = 16 AND dok_NrPelny = '{$safe}'"
    );
    $row = $sql[0] ?? null;
    echo "=== {$ref} ===\n";
    if (!$row || isset($row['SQLSTATE'])) {
        echo "  SQL: brak\n\n";
        continue;
    }
    echo sprintf(
        "  SQL: status=%s (%s) ex=%s zrez=%s doDok=%s\n",
        $row['dok_Status'],
        Order::getOrderStatusLabel((int) $row['dok_Status']),
        $row['dok_StatusEx'],
        $row['dok_ZrealizowaneZRezerwacja'],
        trim((string) ($row['dok_DoDokNrPelny'] ?? ''))
    );

    try {
        $order = new Order($com, array('order_ref' => $ref));
        $order->setCfg($cfg);
        if (!$order->isExists()) {
            echo "  COM: nie wczytano\n\n";
            continue;
        }
        // getGtObject jest w konstruktorze/load — odczyt przez reflection na orderGt
        $refObj = new ReflectionClass($order);
        $prop = $refObj->getProperty('orderGt');
        $prop->setAccessible(true);
        $gt = $prop->getValue($order);
        $stateProp = $refObj->getProperty('state');
        $stateProp->setAccessible(true);
        $comState = (int) $stateProp->getValue($order);
        $comRez = null;
        if ($gt) {
            $comRez = (bool) ($gt->Rezerwacja ?? false);
        }
        echo sprintf(
            "  COM: status=%s (%s) Rezerwacja=%s needsSync=%s\n",
            $comState,
            Order::getOrderStatusLabel($comState),
            $comRez === null ? '?' : ($comRez ? 'true' : 'false'),
            $order->orderNeedsComReservationSync() ? 'TAK' : 'nie'
        );
    } catch (Throwable $e) {
        echo '  COM ERR: ' . $e->getMessage() . "\n";
    }
    echo "\n";
}

echo "=== Dokumentacja statusow (z kodu / InsERT) ===\n";
foreach (array(5, 6, 7, 8) as $s) {
    echo "  {$s} = " . Order::getOrderStatusLabel($s) . "\n";
}
echo "StatusEx: 0=nie zrealizowane, bit1=czesciowo, bit2=roznicowo, bit4=calkowicie\n";
