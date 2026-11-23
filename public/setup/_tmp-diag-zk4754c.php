<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\Helper;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

header('Content-Type: text/plain; charset=utf-8');

$zkId = 73667;
$wzId = 73676;

foreach (array($zkId => 'ZK', $wzId => 'WZ') as $id => $label) {
    $r = MSSql::getInstance()->query(
        "SELECT dok_Id, dok_NrPelny, dok_NrPelnyOryg, dok_Typ, dok_Status, dok_StatusEx,
                dok_DoDokId, dok_DoDokNrPelny, dok_WartBrutto, dok_DataWyst, dok_DataZakonczenia
         FROM dok__Dokument WHERE dok_Id = {$id}"
    );
    if (empty($r)) {
        // fallback without unknown date col
        $r = MSSql::getInstance()->query(
            "SELECT dok_Id, dok_NrPelny, dok_NrPelnyOryg, dok_Typ, dok_Status, dok_StatusEx,
                    dok_DoDokId, dok_DoDokNrPelny, dok_WartBrutto, dok_DataWyst
             FROM dok__Dokument WHERE dok_Id = {$id}"
        );
    }
    $d = $r[0];
    echo "=== {$label} " . Helper::toUtf8($d['dok_NrPelny']) . " ===\n";
    echo "oryg=" . Helper::toUtf8((string)$d['dok_NrPelnyOryg']) . "\n";
    echo "status={$d['dok_Status']} statusEx={$d['dok_StatusEx']}\n";
    echo "doDok=" . Helper::toUtf8((string)$d['dok_DoDokNrPelny']) . " (id={$d['dok_DoDokId']})\n";
    echo "brutto={$d['dok_WartBrutto']}\n";
    $dw = $d['dok_DataWyst'];
    echo "wyst=" . (is_object($dw) ? $dw->format('Y-m-d H:i:s') : $dw) . "\n\n";
}

// discover position doc id column via existing helpers pattern in codebase
$sample = MSSql::getInstance()->query("SELECT TOP 1 * FROM dok_Pozycja");
$cols = array_keys($sample[0] ?? array());
echo "dok_Pozycja sample cols (first 30): " . implode(', ', array_slice($cols, 0, 30)) . "\n\n";

$docCol = null;
foreach (array('ob_DokHanId', 'ob_DokumentId', 'ob_DokId') as $c) {
    if (in_array($c, $cols, true)) {
        $docCol = $c;
        break;
    }
}
if ($docCol === null) {
    // try from struktura knowledge - often ob_DokHanId
    foreach ($cols as $c) {
        if (stripos($c, 'Dok') !== false && stripos($c, 'Id') !== false) {
            echo "candidate col: {$c}\n";
        }
    }
}

if ($docCol) {
    $pos = MSSql::getInstance()->query(
        "SELECT p.ob_Id, t.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag, p.ob_Zrealizowano, p.ob_DoId
         FROM dok_Pozycja p
         LEFT JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
         WHERE p.{$docCol} = {$zkId}
         ORDER BY p.ob_Id"
    );
    echo "pozycje ZK ({$docCol}, " . count($pos) . "):\n";
    foreach ($pos as $p) {
        echo "  " . Helper::toUtf8((string)($p['tw_Symbol'] ?? ''))
            . " ilosc={$p['ob_Ilosc']} mag={$p['ob_IloscMag']} zreal=" . var_export($p['ob_Zrealizowano'] ?? null, true)
            . " doId=" . ($p['ob_DoId'] ?? '') . "\n";
    }
    echo "\n";

    $posWz = MSSql::getInstance()->query(
        "SELECT p.ob_Id, t.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag, p.ob_DoId
         FROM dok_Pozycja p
         LEFT JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
         WHERE p.{$docCol} = {$wzId}
         ORDER BY p.ob_Id"
    );
    echo "pozycje WZ (" . count($posWz) . "):\n";
    foreach ($posWz as $p) {
        echo "  " . Helper::toUtf8((string)($p['tw_Symbol'] ?? ''))
            . " ilosc={$p['ob_Ilosc']} mag={$p['ob_IloscMag']} doId=" . ($p['ob_DoId'] ?? '') . "\n";
    }
}

echo "\nDONE\n";
