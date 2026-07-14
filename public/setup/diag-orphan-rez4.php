<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;

$c=new Config(CONFIG_INI_FILE); $c->load();
$db=MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()],$c->getServer());

$cases = array(
    array('symbol'=>'DY56', 'orphan'=>10, 'zk'=>'ZK 3513/07/2026'),
    array('symbol'=>'EP00100', 'orphan'=>3, 'zk'=>null),
    array('symbol'=>'EA00004', 'orphan'=>2, 'zk'=>null),
);

foreach ($cases as $case) {
    $sym = $case['symbol'];
    echo "\n=== {$sym} (orphan {$case['orphan']}) ===\n";
    if ($case['zk']) {
        $z=$db->query("SELECT dok_Id,dok_Status,dok_StatusEx,dok_ZrealizowaneZRezerwacja,dok_DoDokNrPelny FROM dok__Dokument WHERE dok_NrPelny='{$case['zk']}'");
        print_r($z);
        $id=(int)($z[0]['dok_Id']??0);
        if ($id) {
            print_r($db->query("SELECT t.tw_Symbol,p.ob_Ilosc,p.ob_IloscMag FROM dok_Pozycja p INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId WHERE p.ob_DokHanId=$id AND t.tw_Symbol='$sym'"));
        }
    }
    // ZK status 7 lipiec z dokładną ilością = orphan
    print_r($db->query(
        "SELECT d.dok_NrPelny, d.dok_Status, p.ob_Ilosc, p.ob_IloscMag, d.dok_DoDokNrPelny
         FROM dok_Pozycja p
         INNER JOIN dok__Dokument d ON d.dok_Id=p.ob_DokHanId AND d.dok_Typ=16
         INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
         WHERE t.tw_Symbol='$sym' AND p.ob_Ilosc={$case['orphan']} AND d.dok_DataWyst>='2026-07-01'
         ORDER BY d.dok_Id DESC"
    ));
}

echo "\n=== ZK 3513 pełny obraz (DY56 orphan=10) ===\n";
print_r($db->query("SELECT * FROM dok__Dokument WHERE dok_NrPelny='ZK 3513/07/2026'"));
print_r($db->query(
    "SELECT wz.dok_NrPelny, wp.ob_Ilosc, wp.ob_DoId
     FROM dok_Pozycja wp
     INNER JOIN dok__Dokument wz ON wz.dok_Id=wp.ob_DokMagId
     INNER JOIN tw__Towar t ON t.tw_Id=wp.ob_TowId
     WHERE wz.dok_NrPelny='WZ 3148/07/2026' AND t.tw_Symbol='DY56'"
));
