<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$c = new Config(CONFIG_INI_FILE);
$c->load();
MSSql::getInstance([
    'UID' => $c->getDbUser(),
    'PWD' => $c->getDbUserPass(),
    'Database' => $c->getDatabase(),
], $c->getServer());

$refs = array('ZK 3643/07/2026', 'ZK 3603/07/2026', 'ZK 3618/07/2026', 'ZK 3629/07/2026');
$in = "'" . implode("','", $refs) . "'";

echo "=== Nagłówki ZK ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT dok_NrPelny, dok_Status, dok_StatusEx, dok_ZrealizowaneZRezerwacja,
            dok_ObiektGT, dok_KatId, dok_DoDokNrPelny
     FROM dok__Dokument WHERE dok_NrPelny IN ({$in}) ORDER BY dok_NrPelny"
));

echo "\n=== Pozycje IW00AV1 ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT zk.dok_NrPelny, zk.dok_Status, t.tw_Symbol, p.ob_Ilosc,
            ISNULL(p.ob_IloscMag,0) AS wydano,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) AS pozostalo
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE zk.dok_NrPelny IN ({$in}) AND t.tw_Symbol = 'IW00AV1'
     ORDER BY zk.dok_NrPelny"
));
