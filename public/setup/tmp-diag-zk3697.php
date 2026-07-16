<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\Order;
use APISubiektGT\SubiektGT\OrderComWriter;

$ref = 'ZK 3697/07/2026';
$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());
$db = MSSql::getInstance();
$safe = str_replace("'", "''", $ref);

$zk = $db->query(
    "SELECT dok_Id, dok_NrPelny, dok_Status, dok_StatusEx, dok_ZrealizowaneZRezerwacja,
            dok_DoDokNrPelny, dok_ObiektGT
     FROM dok__Dokument WHERE dok_Typ=16 AND dok_NrPelny='{$safe}'"
);
$id = (int) $zk[0]['dok_Id'];

echo "=== SQL ===\n";
echo sprintf(
    "status=%s (%s) ex=%s (%s) zrez=%s doDok=%s\n",
    $zk[0]['dok_Status'],
    Order::getOrderStatusLabel((int) $zk[0]['dok_Status']),
    $zk[0]['dok_StatusEx'],
    Order::getOrderStatusExLabel((int) $zk[0]['dok_StatusEx']),
    var_export($zk[0]['dok_ZrealizowaneZRezerwacja'], true),
    var_export($zk[0]['dok_DoDokNrPelny'], true)
);

echo "\n=== Pozycje ===\n";
$pos = $db->query(
    "SELECT t.tw_Symbol, p.ob_Ilosc, ISNULL(p.ob_IloscMag,0) AS mag,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) AS pozostalo
     FROM dok_Pozycja p
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE p.ob_DokHanId = {$id}"
);
print_r($pos);

echo "\n=== COM ===\n";
OrderComWriter::configure(false, true);
$com = SubiektGT::getInstance($cfg)->connect();
$order = new Order($com, array('order_ref' => $ref));
$order->setCfg($cfg);
if (!$order->isExists()) {
    echo "nie wczytano\n";
    exit(1);
}
$ro = new ReflectionClass($order);
$p = $ro->getProperty('orderGt');
$p->setAccessible(true);
$doc = $p->getValue($order);
$comRez = (bool) ($doc->Rezerwacja ?? false);
$comSt = null;
foreach (array('Status', 'StatusDokumentu', 'StanDokumentu') as $prop) {
    try {
        if (isset($doc->$prop)) {
            $comSt = $doc->$prop;
            break;
        }
    } catch (Throwable $e) {
    }
}
echo 'COM.Status=' . var_export($comSt, true) . "\n";
echo 'COM.Rezerwacja=' . ($comRez ? 'true' : 'false') . "\n";
echo 'needsSync=' . ($order->orderNeedsComReservationSync() ? 'TAK' : 'nie') . "\n";

echo "\n=== Porownanie 3697 (reczne) vs 3696 (nasza hybryda) ===\n";
foreach (array('ZK 3697/07/2026', 'ZK 3696/07/2026') as $r) {
    $s = str_replace("'", "''", $r);
    $row = $db->query(
        "SELECT dok_Status, dok_StatusEx FROM dok__Dokument WHERE dok_Typ=16 AND dok_NrPelny='{$s}'"
    );
    $o = new Order($com, array('order_ref' => $r));
    $o->setCfg($cfg);
    $rez = '?';
    if ($o->isExists()) {
        $ro2 = new ReflectionClass($o);
        $pp = $ro2->getProperty('orderGt');
        $pp->setAccessible(true);
        $d = $pp->getValue($o);
        $rez = ($d && ($d->Rezerwacja ?? false)) ? 'true' : 'false';
    }
    echo sprintf(
        "%s SQL=%s/%s COM.Rez=%s label=%s\n",
        $r,
        $row[0]['dok_Status'],
        $row[0]['dok_StatusEx'],
        $rez,
        Order::getOrderStatusLabel((int) $row[0]['dok_Status'])
    );
}
