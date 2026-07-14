<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql; use APISubiektGT\SubiektGT\Order;

$c = new Config(CONFIG_INI_FILE); $c->load();
MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());

// ZK status 5 lipiec — porównaj rezerwacje towarów
$refs = array('ZK 3603/07/2026','ZK 3618/07/2026','ZK 3629/07/2026');
foreach ($refs as $r) {
    $row = Order::getOrderRowByRefSql($r);
    $id = (int)($row['dok_Id'] ?? 0);
    echo "\n=== $r (id=$id) ===\n";
    print_r(MSSql::getInstance()->query(
        "SELECT p.ob_Id, t.tw_Symbol, p.ob_Ilosc, ISNULL(p.ob_IloscMag,0) AS wydano,
                p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) AS pozostalo
         FROM dok_Pozycja p
         INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
         WHERE p.ob_DokHanId = $id AND p.ob_TowId > 0
         ORDER BY p.ob_Id"
    ));
}

// Inne ZK 5 z lipca (nie Avocado repair) — czy mają ten sam wzorzec?
echo "\n=== Inne ZK 5 lipiec (losowe 5, bez 3603/3618/3629) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT TOP 5 zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_ZrealizowaneZRezerwacja,
            k.kat_Nazwa, SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag,0)) AS pozostalo
     FROM dok__Dokument zk
     LEFT JOIN sl_Kategoria k ON k.kat_Id = zk.dok_KatId
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     WHERE zk.dok_Typ=16 AND zk.dok_Status=5 AND zk.dok_DataWyst>='2026-07-01'
       AND zk.dok_NrPelny NOT IN ('ZK 3603/07/2026','ZK 3618/07/2026','ZK 3629/07/2026')
     GROUP BY zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_ZrealizowaneZRezerwacja, k.kat_Nazwa
     HAVING SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag,0)) > 0
     ORDER BY zk.dok_NrPelny DESC"
));

// Stany IW00AV po naprawie
echo "\n=== Stany Avocado ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez, s.st_Stan - ISNULL(s.st_StanRez,0) AS dostepne
     FROM tw__Towar t
     INNER JOIN tw_Stan s ON s.st_TowId=t.tw_Id AND s.st_MagId=1
     WHERE t.tw_Symbol IN ('IW00AV1','IW00AV2')"
));
