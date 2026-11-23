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

header('Content-Type: text/html; charset=utf-8');
echo '<pre>';

$zkId = 73667;
$wzId = 73676;

foreach (array($zkId => 'ZK', $wzId => 'WZ') as $id => $label) {
    $r = MSSql::getInstance()->query(
        "SELECT dok_Id, dok_NrPelny, dok_NrPelnyOryg, dok_Typ, dok_Status, dok_StatusEx,
                dok_DoDokId, dok_DoDokNrPelny, dok_WartBrutto, dok_DataWyst, dok_DataZamkniecia
         FROM dok__Dokument WHERE dok_Id = {$id}"
    );
    $d = $r[0];
    echo "=== {$label} " . Helper::toUtf8($d['dok_NrPelny']) . " ===\n";
    echo "status={$d['dok_Status']} statusEx={$d['dok_StatusEx']}\n";
    echo "doDok=" . Helper::toUtf8((string)$d['dok_DoDokNrPelny']) . " (id={$d['dok_DoDokId']})\n";
    echo "brutto={$d['dok_WartBrutto']}\n";
    echo "wyst=" . (is_object($d['dok_DataWyst']) ? $d['dok_DataWyst']->format('Y-m-d H:i:s') : $d['dok_DataWyst']) . "\n";
    if (!empty($d['dok_DataZamkniecia'])) {
        $dz = $d['dok_DataZamkniecia'];
        echo "zamk=" . (is_object($dz) ? $dz->format('Y-m-d H:i:s') : $dz) . "\n";
    }

    $pos = MSSql::getInstance()->query(
        "SELECT p.ob_Id, t.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag, p.ob_Zrealizowano, p.ob_DoId, p.ob_DoIdZamowienia
         FROM dok_Pozycja p
         LEFT JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
         WHERE p.ob_DokHanId = {$id} OR p.ob_DokMagId = {$id}
         ORDER BY p.ob_Id"
    );
    // try common column names
    if (empty($pos)) {
        $pos = MSSql::getInstance()->query(
            "SELECT TOP 1 * FROM dok_Pozycja"
        );
        echo "sample cols: " . implode(', ', array_keys($pos[0] ?? array())) . "\n";
    } else {
        echo "pozycje (" . count($pos) . "):\n";
        foreach ($pos as $p) {
            echo "  " . Helper::toUtf8((string)($p['tw_Symbol'] ?? ''))
                . " ilosc={$p['ob_Ilosc']} mag={$p['ob_IloscMag']} zreal=" . ($p['ob_Zrealizowano'] ?? '?')
                . " doId=" . ($p['ob_DoId'] ?? '') . "\n";
        }
    }
    echo "\n";
}

// reservation leftovers for products on this ZK
$rez = MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez, s.st_MagId
     FROM dok_Pozycja p
     JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     JOIN tw_Stan s ON s.st_TowId = t.tw_Id AND s.st_MagId = 1
     WHERE p.ob_DokHanId = {$zkId}
     ORDER BY t.tw_Symbol"
);
if (empty($rez)) {
    // discover dok id column
    $sample = MSSql::getInstance()->query("SELECT TOP 1 * FROM dok_Pozycja WHERE ob_TowId IS NOT NULL");
    echo "dok_Pozycja keys: " . implode(', ', array_keys($sample[0] ?? array())) . "\n";
} else {
    echo "=== stany mag=1 dla towarów ZK ===\n";
    foreach ($rez as $s) {
        echo Helper::toUtf8($s['tw_Symbol']) . " stan={$s['st_Stan']} rez={$s['st_StanRez']}\n";
    }
}

echo '</pre>';
