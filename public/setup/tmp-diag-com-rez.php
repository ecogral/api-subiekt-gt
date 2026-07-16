<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$c = new Config(CONFIG_INI_FILE);
$c->load();
MSSql::getInstance(array(
    'UID' => $c->getDbUser(),
    'PWD' => $c->getDbUserPass(),
    'Database' => $c->getDatabase(),
), $c->getServer());
$db = MSSql::getInstance();

$refs = array('ZK 3696/07/2026', 'ZK 3695/07/2026', 'ZK 3683/07/2026', 'ZK 3521/07/2026');

echo "=== IW00AV2 stan ===\n";
print_r($db->query(
    "SELECT t.tw_Symbol, s.st_Stan, s.st_StanRez FROM tw__Towar t
     INNER JOIN tw_Stan s ON s.st_TowId=t.tw_Id AND s.st_MagId=1
     WHERE t.tw_Symbol IN ('IW00AV1','IW00AV2')"
));
print_r(Order::findStockReservationMismatchesSql(1, array('IW00AV1', 'IW00AV2')));

echo "\n=== ZK po SQL repair ===\n";
foreach ($refs as $ref) {
    $safe = str_replace("'", "''", $ref);
    $zk = $db->query(
        "SELECT dok_Id, dok_NrPelny, dok_Status, dok_StatusEx, dok_ZrealizowaneZRezerwacja,
                dok_DoDokNrPelny
         FROM dok__Dokument WHERE dok_NrPelny='{$safe}'"
    );
    if (empty($zk)) {
        echo "{$ref}: BRAK\n";
        continue;
    }
    $id = (int) $zk[0]['dok_Id'];
    $av = $db->query(
        "SELECT t.tw_Symbol, p.ob_Ilosc, ISNULL(p.ob_IloscMag,0) AS mag
         FROM dok_Pozycja p INNER JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
         WHERE p.ob_DokHanId={$id} AND t.tw_Symbol IN ('IW00AV1','IW00AV2','KN00070')
         ORDER BY t.tw_Symbol"
    );
    echo sprintf(
        "%s st=%s ex=%s rezFlag=%s do=%s | %s\n",
        $ref,
        $zk[0]['dok_Status'],
        $zk[0]['dok_StatusEx'],
        var_export($zk[0]['dok_ZrealizowaneZRezerwacja'], true),
        $zk[0]['dok_DoDokNrPelny'],
        json_encode($av, JSON_UNESCAPED_UNICODE)
    );
}

echo "\n=== Otwarte ZK status 5 bez WZ (lipiec) — ile ===\n";
print_r($db->query(
    "SELECT COUNT(*) AS cnt FROM dok__Dokument d
     WHERE d.dok_Typ=16 AND d.dok_Status=5 AND d.dok_DataWyst>='2026-07-01'
       AND (d.dok_DoDokNrPelny IS NULL OR d.dok_DoDokNrPelny='')"
));
