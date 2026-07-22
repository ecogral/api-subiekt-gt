<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

$rows = MSSql::getInstance()->query(
    "SELECT dok_Id, dok_NrPelny, dok_NrPelnyOryg, dok_Status, dok_DataWyst, dok_WartNetto
     FROM dok__Dokument
     WHERE dok_Typ = 16 AND dok_Status >= 0
       AND (dok_NrPelnyOryg = 'B2B-605/2026'
            OR dok_NrPelny IN ('ZK 3766/07/2026','ZK 3767/07/2026'))
     ORDER BY dok_Id"
);
echo 'count=' . (is_array($rows) ? count($rows) : 0) . "\n";
if (is_array($rows)) {
    foreach ($rows as $r) {
        echo ($r['dok_NrPelny'] ?? '') . ' | oryg=' . ($r['dok_NrPelnyOryg'] ?? '')
            . ' | st=' . ($r['dok_Status'] ?? '') . ' | netto=' . ($r['dok_WartNetto'] ?? '') . "\n";
    }
}
