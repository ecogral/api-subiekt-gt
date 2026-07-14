<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;

$c = new Config(CONFIG_INI_FILE); $c->load();
MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());

echo "=== ZK 5 z dok_ZrealizowaneZRezerwacja=1 ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT TOP 5 dok_NrPelny, dok_Status, dok_ZrealizowaneZRezerwacja, dok_KatId
     FROM dok__Dokument WHERE dok_Typ=16 AND dok_Status=5 AND dok_ZrealizowaneZRezerwacja=1
     ORDER BY dok_Id DESC"
));

echo "\n=== kategorie (sl_Kategoria) ===\n";
print_r(MSSql::getInstance()->query("SELECT TOP 20 kat_Id, kat_Nazwa FROM sl_Kategoria ORDER BY kat_Id"));

echo "\n=== avocado ZK teraz ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT dok_NrPelny, dok_Status, dok_ZrealizowaneZRezerwacja, dok_KatId
     FROM dok__Dokument WHERE dok_NrPelny IN ('ZK 3603/07/2026','ZK 3618/07/2026','ZK 3629/07/2026')"
));
