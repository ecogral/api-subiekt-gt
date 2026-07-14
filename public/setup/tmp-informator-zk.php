<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;

$c = new Config(CONFIG_INI_FILE); $c->load();
MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());

// Towar Avocado (IW00AV1 / IW00AV2)
$symbols = array('IW00AV1','IW00AV2');
foreach ($symbols as $sym) {
    echo "\n=== ZK z pozycją $sym (niezrealizowane) ===\n";
    print_r(MSSql::getInstance()->query(
        "SELECT zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_ZrealizowaneZRezerwacja,
                zk.dok_DataWyst, p.ob_Ilosc, ISNULL(p.ob_IloscMag,0) AS wydano,
                p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) AS pozostalo
         FROM dok__Dokument zk
         INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
         INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
         WHERE zk.dok_Typ = 16
           AND zk.dok_Status IN (5, 6, 7)
           AND t.tw_Symbol = '{$sym}'
           AND p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) > 0.00001
         ORDER BY zk.dok_DataWyst DESC"
    ));
}

echo "\n=== Podsumowanie statusów ZK z IW00AV1/2 (lipiec+) ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT zk.dok_Status, COUNT(DISTINCT zk.dok_Id) AS cnt
     FROM dok__Dokument zk
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE zk.dok_Typ=16 AND t.tw_Symbol IN ('IW00AV1','IW00AV2')
       AND zk.dok_DataWyst >= '2026-01-01'
       AND p.ob_Ilosc - ISNULL(p.ob_IloscMag,0) > 0
     GROUP BY zk.dok_Status
     ORDER BY zk.dok_Status"
));

// ZK z screena (3603)
echo "\n=== ZK 3603/07/2026 pełny nagłówek ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT dok_Id, dok_NrPelny, dok_Status, dok_StatusEx, dok_ZrealizowaneZRezerwacja,
            dok_ObiektGT, dok_DataWyst
     FROM dok__Dokument WHERE dok_NrPelny = 'ZK 3603/07/2026'"
));
