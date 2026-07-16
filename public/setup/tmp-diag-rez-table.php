<?php
/**
 * Porównanie zdrowych ZK status5 vs naprawionych SQL + tabela zs_Rezerwacja.
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
$db = MSSql::getInstance();

echo "=== zs_Rezerwacja schema ===\n";
print_r($db->query(
    "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_NAME = 'zs_Rezerwacja' ORDER BY ORDINAL_POSITION"
));

echo "\n=== zs_Rezerwacja count / sample ===\n";
print_r($db->query("SELECT COUNT(*) AS c FROM zs_Rezerwacja"));
print_r($db->query("SELECT TOP 5 * FROM zs_Rezerwacja"));

echo "\n=== Rezerwacje dla IW00AV2 (jesli sa kolumny towar/dok) ===\n";
$cols = $db->query(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'zs_Rezerwacja'"
);
$colNames = array();
foreach ((array) $cols as $c) {
    if (!empty($c['COLUMN_NAME'])) {
        $colNames[] = $c['COLUMN_NAME'];
    }
}
echo 'cols: ' . implode(', ', $colNames) . "\n";

// Znajdz ZK status 5 bez WZ - porownaj te z R w GT (starsze) vs 3696
echo "\n=== Status 5 bez WZ (TOP 15 recent) ===\n";
$rows = $db->query(
    "SELECT TOP 15 d.dok_Id, d.dok_NrPelny, d.dok_Status, d.dok_StatusEx,
            d.dok_ZrealizowaneZRezerwacja, d.dok_DataWyst, d.dok_ObiektGT
     FROM dok__Dokument d
     WHERE d.dok_Typ = 16 AND d.dok_Status = 5
       AND (d.dok_DoDokNrPelny IS NULL OR LTRIM(RTRIM(CAST(d.dok_DoDokNrPelny AS NVARCHAR(100)))) = '')
     ORDER BY d.dok_Id DESC"
);
print_r($rows);

// COM read for a few
OrderComWriter::configure(false, true);
$com = SubiektGT::getInstance($cfg)->connect();
$refs = array();
foreach ((array) $rows as $r) {
    if (!empty($r['dok_NrPelny'])) {
        $refs[] = $r['dok_NrPelny'];
    }
    if (count($refs) >= 5) {
        break;
    }
}
$refs[] = 'ZK 3696/07/2026';

echo "\n=== COM Rezerwacja vs SQL ===\n";
foreach (array_unique($refs) as $ref) {
    try {
        $order = new Order($com, array('order_ref' => $ref));
        $order->setCfg($cfg);
        if (!$order->isExists()) {
            echo "{$ref}: not loaded\n";
            continue;
        }
        $ro = new ReflectionClass($order);
        $gt = $ro->getProperty('orderGt');
        $gt->setAccessible(true);
        $doc = $gt->getValue($order);
        $st = $ro->getProperty('state');
        $st->setAccessible(true);
        echo sprintf(
            "%s COM.status=%s Rez=%s needsSync=%s\n",
            $ref,
            (int) $st->getValue($order),
            ($doc && ($doc->Rezerwacja ?? false)) ? '1' : '0',
            $order->orderNeedsComReservationSync() ? '1' : '0'
        );
    } catch (Throwable $e) {
        echo "{$ref}: ERR " . $e->getMessage() . "\n";
    }
}

// zs rows for dok 3696
$id = (int) ($db->query("SELECT dok_Id FROM dok__Dokument WHERE dok_NrPelny='ZK 3696/07/2026' AND dok_Typ=16")[0]['dok_Id'] ?? 0);
echo "\n=== zs_Rezerwacja for dok_Id={$id} (try common cols) ===\n";
foreach (array('zs_DokId', 'rez_DokId', 'dok_Id', 'DokId', 'IdDokumentu') as $col) {
    if (in_array($col, $colNames, true)) {
        print_r($db->query("SELECT TOP 20 * FROM zs_Rezerwacja WHERE {$col} = {$id}"));
    }
}
