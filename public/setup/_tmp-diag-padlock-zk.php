<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

$refs = array(
    'ZK 3770/07/2026','ZK 3771/07/2026','ZK 3772/07/2026','ZK 3773/07/2026',
    'ZK 3757/07/2026','ZK 3766/07/2026','ZK 3769/07/2026','ZK 3775/07/2026',
);

echo "=== Wybrane ZK (status / StatusEx / WZ / rez) ===\n";
foreach ($refs as $ref) {
    $s = str_replace("'", "''", $ref);
    $rows = MSSql::getInstance()->query(
        "SELECT dok_Id, dok_NrPelny, dok_Status, dok_StatusEx, dok_DoDokNrPelny,
                dok_ZrealizowaneZRezerwacja, dok_NrPelnyOryg
         FROM dok__Dokument WHERE dok_NrPelny='{$s}' AND dok_Typ=16"
    );
    if (!is_array($rows) || empty($rows)) {
        echo "$ref NOT FOUND\n";
        continue;
    }
    $r = $rows[0];
    echo ($r['dok_NrPelny'] ?? '')
        . ' | st=' . ($r['dok_Status'] ?? '')
        . ' ex=' . ($r['dok_StatusEx'] ?? '')
        . ' | do=' . ($r['dok_DoDokNrPelny'] ?? '-')
        . ' | zrez=' . ($r['dok_ZrealizowaneZRezerwacja'] ?? '')
        . ' | oryg=' . ($r['dok_NrPelnyOryg'] ?? '') . "\n";
}

echo "\n=== Wszystkie ZK lipiec: status 7 + nagłówek WZ (kłódka = rezerwacja otwarta) ===\n";
$rows2 = MSSql::getInstance()->query(
    "SELECT dok_NrPelny, dok_Status, dok_StatusEx, dok_DoDokNrPelny, dok_NrPelnyOryg
     FROM dok__Dokument
     WHERE dok_Typ=16 AND dok_Status=7 AND dok_Status>=0
       AND dok_DataWyst >= '2026-07-01'
       AND ISNULL(dok_DoDokNrPelny,'') LIKE 'WZ%'
     ORDER BY dok_Id DESC"
);
$n = 0;
if (is_array($rows2)) {
    foreach ($rows2 as $r) {
        $n++;
        echo ($r['dok_NrPelny'] ?? '')
            . ' | ex=' . ($r['dok_StatusEx'] ?? '')
            . ' | do=' . ($r['dok_DoDokNrPelny'] ?? '')
            . ' | oryg=' . ($r['dok_NrPelnyOryg'] ?? '') . "\n";
    }
}
echo "count=$n\n";
