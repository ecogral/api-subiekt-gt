<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

foreach (array('ZK 3766/07/2026', 'ZK 3767/07/2026', 'ZK 3773/07/2026') as $ref) {
    $safe = str_replace("'", "''", $ref);
    $id = MSSql::getInstance()->query("SELECT dok_Id, dok_WartNetto FROM dok__Dokument WHERE dok_NrPelny='{$safe}'");
    $oid = (int) ($id[0]['dok_Id'] ?? 0);
    echo "\n{$ref} netto=" . ($id[0]['dok_WartNetto'] ?? '') . "\n";
    $p = MSSql::getInstance()->query(
        "SELECT tw.tw_Symbol, p.ob_Ilosc FROM dok_Pozycja p
         LEFT JOIN tw__Towar tw ON tw.tw_Id=p.ob_TowId WHERE p.ob_DokHanId={$oid} ORDER BY p.ob_Id"
    );
    foreach (is_array($p) ? $p : array() as $r) {
        echo '  ' . ($r['tw_Symbol'] ?? '') . ' x ' . ($r['ob_Ilosc'] ?? '') . "\n";
    }
}
