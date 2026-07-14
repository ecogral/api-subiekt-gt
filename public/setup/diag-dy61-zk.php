<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$db = MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

foreach (array('ZK 3638/07/2026', 'ZK 3648/07/2026') as $ref) {
    echo "\n=== {$ref} — diagnostyka WZ ===\n";
    $diag = Order::diagnoseOrderIssueLinkSql($ref, null);
    echo json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

echo "\n=== WZ z DY61 lipiec (ostatnie) ===\n";
$wz = $db->query(
    "SELECT d.dok_NrPelny, d.dok_Status, d.dok_DataWyst, p.ob_Ilosc, p.ob_DoId,
            zk.dok_NrPelny AS zk_ref
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokMagId AND d.dok_Typ = 11
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     LEFT JOIN dok_Pozycja zp ON zp.ob_Id = p.ob_DoId
     LEFT JOIN dok__Dokument zk ON zk.dok_Id = zp.ob_DokHanId AND zk.dok_Typ = 16
     WHERE t.tw_Symbol = 'DY61' AND d.dok_DataWyst >= '2026-07-10'
     ORDER BY d.dok_Id DESC"
);
foreach ($wz ?: array() as $r) {
    printf(
        "  %s | qty=%s | ob_DoId=%s | ZK=%s\n",
        $r['dok_NrPelny'] ?? '',
        $r['ob_Ilosc'] ?? '',
        $r['ob_DoId'] ?? 'NULL',
        $r['zk_ref'] ?? '(brak)'
    );
}

echo "\n=== Hipoteza: skad 12 szt rez? ===\n";
echo "3638 ma 10 szt DY61 (st=7, bez WZ w naglowku)\n";
echo "3648 ma 1 szt DY61 (st=7, bez WZ w naglowku)\n";
echo "Razem 11 szt — blisko orphan=12\n";
