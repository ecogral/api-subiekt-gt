<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

$refs = array('WZ 3013/06/2026', 'WZ 3011/06/2026', 'WZ 3014/06/2026');
$in = implode(', ', array_map(function ($r) {
    return "'" . str_replace("'", "''", $r) . "'";
}, $refs));

$rows = MSSql::getInstance()->query(
    "SELECT dok_Id, dok_NrPelny, dok_Typ, dok_Podtyp, dok_Status, dok_NrPelnyOryg,
            dok_DoDokId, dok_Algorytm, dok_KatId, dok_Tytul, dok_Podtytul,
            dok_JestHOP, dok_JestTylkoDoOdczytu, dok_StatusEx, dok_Wystawil
     FROM dok__Dokument WHERE dok_NrPelny IN ({$in}) ORDER BY dok_NrPelny"
);
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// ZK for 3013
$zk = MSSql::getInstance()->query(
    "SELECT dok_Id, dok_NrPelny, dok_Status, dok_Podtyp, dok_Algorytm, dok_DoDokId, dok_DoDokNrPelny
     FROM dok__Dokument WHERE dok_NrPelny = 'ZK 3394/06/2026'"
);
echo "\nZK 3394:\n" . json_encode($zk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
