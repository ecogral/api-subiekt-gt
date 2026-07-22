<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;
$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());
foreach (array('ZK 3766/07/2026','ZK 3767/07/2026','ZK 3768/07/2026','ZK 3771/07/2026') as $r) {
    $s = str_replace("'", "''", $r);
    $rows = MSSql::getInstance()->query(
        "SELECT dok_Id,dok_NrPelny,dok_NrPelnyOryg,dok_Status,dok_WartNetto FROM dok__Dokument WHERE dok_NrPelny='{$s}' AND dok_Typ=16"
    );
    if (!is_array($rows) || empty($rows)) {
        echo "{$r} NOT FOUND\n";
        continue;
    }
    $x = $rows[0];
    echo $r . ' oryg=' . ($x['dok_NrPelnyOryg'] ?? '') . ' st=' . ($x['dok_Status'] ?? '')
        . ' netto=' . ($x['dok_WartNetto'] ?? '') . "\n";
}
