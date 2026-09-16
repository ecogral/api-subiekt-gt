<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

foreach (array('ZK 3807/07/2026', 'ZK 3761/07/2026', 'WZ 3392/07/2026') as $ref) {
    $s = str_replace("'", "''", $ref);
    $h = MSSql::getInstance()->query(
        "SELECT dok_Id, dok_NrPelny, dok_Typ, dok_Status, dok_DoDokNrPelny, dok_NrPelnyOryg
         FROM dok__Dokument WHERE dok_NrPelny='{$s}'"
    );
    if (!is_array($h) || empty($h)) { echo "$ref NOT FOUND\n"; continue; }
    $id = (int)$h[0]['dok_Id'];
    echo "\n=== " . ($h[0]['dok_NrPelny'] ?? '') . " typ=" . ($h[0]['dok_Typ'] ?? '')
        . " st=" . ($h[0]['dok_Status'] ?? '') . " do=" . ($h[0]['dok_DoDokNrPelny'] ?? '')
        . " oryg=" . ($h[0]['dok_NrPelnyOryg'] ?? '') . " ===\n";
    $p = MSSql::getInstance()->query(
        "SELECT t.tw_Symbol, CAST(p.ob_Ilosc AS float) AS q
         FROM dok_Pozycja p LEFT JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
         WHERE p.ob_DokHanId={$id} AND t.tw_Symbol IN ('EA00024','IW00AV1','IW00AV2')
         ORDER BY t.tw_Symbol"
    );
    if (is_array($p) && $p) {
        foreach ($p as $r) echo '  ' . ($r['tw_Symbol'] ?? '') . ' x ' . ($r['q'] ?? '') . "\n";
    } else {
        echo "  (brak EA00024/IW00AV1/IW00AV2)\n";
    }
}
