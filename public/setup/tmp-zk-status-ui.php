<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql; use APISubiektGT\SubiektGT\Order;

$c = new Config(CONFIG_INI_FILE); $c->load();
MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());

$refs = array('ZK 3603/07/2026','ZK 3618/07/2026','ZK 3629/07/2026');
foreach ($refs as $r) {
    echo "\n=== $r ===\n";
    $row = Order::getOrderRowByRefSql($r);
    print_r($row);
    if ($row) {
        $id = (int)$row['dok_Id'];
        $cols = MSSql::getInstance()->query(
            "SELECT dok_Status, dok_StatusEx, dok_ZrealizowaneZRezerwacja,
                    dok_DoDokId, dok_DoDokNrPelny, dok_KatId, dok_Typ
             FROM dok__Dokument WHERE dok_Id = $id"
        );
        print_r($cols);
    }
}

// porównaj z poprawnym ZK 5 z rezerwacją (inny numer)
echo "\n=== PRZYKŁAD ZK status 5 z lipca (pierwsze 3) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT TOP 3 dok_NrPelny, dok_Status, dok_StatusEx, dok_ZrealizowaneZRezerwacja, dok_KatId
     FROM dok__Dokument
     WHERE dok_Typ=16 AND dok_Status=5 AND dok_DataWyst>='2026-07-01'
     ORDER BY dok_Id DESC"
));
