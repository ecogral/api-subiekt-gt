<?php
/**
 * Naprawa ZK 3696: otwarcie SQL + sekwencje COM Rezerwacja.
 * php public/setup/tmp-com-fix-3696.php
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\Order;
use APISubiektGT\SubiektGT\OrderComWriter;

$ref = 'ZK 3696/07/2026';

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());
OrderComWriter::configure(false, true);
MSSql::setComWritesOnly(false, true);

$db = MSSql::getInstance();
$row = $db->query(
    "SELECT dok_Id, dok_Status, dok_StatusEx FROM dok__Dokument WHERE dok_Typ=16 AND dok_NrPelny='"
    . str_replace("'", "''", $ref) . "'"
);
$id = (int) ($row[0]['dok_Id'] ?? 0);
echo "SQL before: status={$row[0]['dok_Status']} ex={$row[0]['dok_StatusEx']} id={$id}\n";

$repair = Order::repairStuckOrderWithoutIssueSql($ref, 1, false, true);
echo "repairStuck: " . json_encode($repair, JSON_UNESCAPED_UNICODE) . "\n";

$row = $db->query("SELECT dok_Status, dok_StatusEx FROM dok__Dokument WHERE dok_Id={$id}");
echo "SQL after repair: status={$row[0]['dok_Status']} ex={$row[0]['dok_StatusEx']}\n";

$com = SubiektGT::getInstance($cfg)->connect();

$read = function ($label, $doc) use ($db, $id) {
    $rez = (bool) ($doc->Rezerwacja ?? false);
    $st = '?';
    foreach (array('Status', 'StatusDokumentu', 'StanDokumentu') as $p) {
        try {
            if (is_object($doc) && isset($doc->$p)) {
                $st = $doc->$p;
                break;
            }
        } catch (Throwable $e) {
        }
    }
    $sql = $db->query("SELECT dok_Status, dok_StatusEx FROM dok__Dokument WHERE dok_Id={$id}");
    echo "{$label}: COM.Status={$st} COM.Rez=" . ($rez ? '1' : '0')
        . " SQL.status={$sql[0]['dok_Status']} ex={$sql[0]['dok_StatusEx']}\n";
};

$loaded = OrderComWriter::loadOrderDocument($com, $id, $ref);
$doc = $loaded['doc'];
$read('LOAD', $doc);

// A: Status=5 + Rezerwacja=true, bez Przelicz
echo "\n=== A: Status=5 + Rezerwacja=true ===\n";
OrderComWriter::trySetProperty($doc, array('Status', 'StatusDokumentu', 'StanDokumentu'), 5);
$doc->Rezerwacja = true;
OrderComWriter::saveDocument($doc, 'fix3696-A');
$loaded = OrderComWriter::loadOrderDocument($com, $id, $ref);
$doc = $loaded['doc'];
$read('AFTER_A', $doc);

$sqlStatus = (int) ($db->query("SELECT dok_Status FROM dok__Dokument WHERE dok_Id={$id}")[0]['dok_Status'] ?? 0);
$comRez = (bool) ($doc->Rezerwacja ?? false);

if ($sqlStatus === 5 && $comRez) {
    echo "SUCCESS A\n";
    exit(0);
}

// B: 6 then 5+rez
echo "\n=== B: 6 → 5+rez ===\n";
Order::repairStuckOrderWithoutIssueSql($ref, 1, false, true);
$loaded = OrderComWriter::loadOrderDocument($com, $id, $ref);
$doc = $loaded['doc'];
$doc->Rezerwacja = false;
OrderComWriter::trySetProperty($doc, array('Status', 'StatusDokumentu', 'StanDokumentu'), 6);
OrderComWriter::saveDocument($doc, 'fix3696-B1');
$loaded = OrderComWriter::loadOrderDocument($com, $id, $ref);
$doc = $loaded['doc'];
$read('AFTER_B1', $doc);

$doc->Rezerwacja = true;
OrderComWriter::trySetProperty($doc, array('Status', 'StatusDokumentu', 'StanDokumentu'), 5);
OrderComWriter::saveDocument($doc, 'fix3696-B2');
$loaded = OrderComWriter::loadOrderDocument($com, $id, $ref);
$doc = $loaded['doc'];
$read('AFTER_B2', $doc);

$sqlStatus = (int) ($db->query("SELECT dok_Status FROM dok__Dokument WHERE dok_Id={$id}")[0]['dok_Status'] ?? 0);
$comRez = (bool) ($doc->Rezerwacja ?? false);
if ($sqlStatus === 5 && $comRez) {
    echo "SUCCESS B\n";
    exit(0);
}

// C: tylko Rezerwacja (moze flip na 7 — wtedy Informator dziala, lista R tez)
echo "\n=== C: tylko Rezerwacja=true (akceptuj status 7 jesli rez trzyma) ===\n";
Order::repairStuckOrderWithoutIssueSql($ref, 1, false, true);
$loaded = OrderComWriter::loadOrderDocument($com, $id, $ref);
$doc = $loaded['doc'];
$read('C_LOAD', $doc);
$doc->Rezerwacja = true;
OrderComWriter::saveDocument($doc, 'fix3696-C');
$loaded = OrderComWriter::loadOrderDocument($com, $id, $ref);
$doc = $loaded['doc'];
$read('AFTER_C', $doc);

echo "\n=== Positions ===\n";
print_r($db->query(
    "SELECT t.tw_Symbol, p.ob_Ilosc, ISNULL(p.ob_IloscMag,0) mag FROM dok_Pozycja p
     INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId WHERE p.ob_DokHanId={$id}"
));
