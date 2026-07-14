<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;

$c = new Config(CONFIG_INI_FILE); $c->load();
MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());

// Wszystkie niezrealizowane ZK z IW00AV2 (jak w Informatorze)
echo "=== Niezrealizowane ZK z IW00AV2 (status 5/6, pozostalo>0) ===\n";
$rows = MSSql::getInstance()->query(
    "SELECT zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_ZrealizowaneZRezerwacja,
            zk.dok_ObiektGT, zk.dok_DataWyst,
            p.ob_Ilosc, ISNULL(p.ob_IloscMag,0) AS wydano,
            p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) AS pozostalo
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE zk.dok_Typ = 16
       AND zk.dok_Status IN (5, 6)
       AND t.tw_Symbol = 'IW00AV2'
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) > 0.00001
     ORDER BY zk.dok_DataWyst DESC"
);
print_r($rows);

// Porównaj z ZK które mają status 5 ale może inny ObiektGT
echo "\n=== ZK 339/01/2026 i 985/03/2026 (ze screena) ===\n";
foreach (array('ZK 339/01/2026', 'ZK 985/03/2026', 'ZK 3603/07/2026') as $ref) {
    print_r(MSSql::getInstance()->query(
        "SELECT zk.dok_NrPelny, zk.dok_Status, zk.dok_ObiektGT, zk.dok_ZrealizowaneZRezerwacja,
                t.tw_Symbol, p.ob_Ilosc, ISNULL(p.ob_IloscMag,0) AS wydano
         FROM dok__Dokument zk
         LEFT JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
         LEFT JOIN tw__Towar t ON t.tw_Id = p.ob_TowId AND t.tw_Symbol IN ('IW00AV1','IW00AV2')
         WHERE zk.dok_NrPelny = '{$ref}'
         ORDER BY t.tw_Symbol"
    ));
}

// Czy jest tabela rezerwacji per dokument?
echo "\n=== Szukaj tabel z Rezerw w nazwie ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME LIKE '%Rezer%' OR TABLE_NAME LIKE '%rez%'
     ORDER BY TABLE_NAME"
));
