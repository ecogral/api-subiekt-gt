<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

$searches = [
    'ZK 3734/07/2026' => ['DAMIAN MUSZYSKI', 'NIEBOROWO', 'B2B-594'],
    'ZK 3735/07/2026' => ['URSZULA GZUBICKA', 'RYSZEWO', 'B2B-595'],
    'ZK 3736/07/2026' => ['Ewa Tokarczyk', 'Pabianice', 'B2B-596'],
    'ZK 3737/07/2026' => ['Rafa', 'Juniewicze', 'B2B-597', 'Hornowski'],
];

foreach ($searches as $zkRef => $needles) {
    echo "\n=== $zkRef ===\n";
    $zk = Order::getOrderRowByRefSql($zkRef);
    $id = (int)($zk['dok_Id']??0);
    echo 'valid: '.implode(', ', Order::getValidIssueRefsForOrderSql($id, $zkRef))."\n";
    echo 'invalid: '.implode(', ', Order::getInvalidIssueRefsForOrderSql($id, $zkRef))."\n";
    foreach ($needles as $n) {
        $safe = str_replace("'", "''", $n);
        $rows = MSSql::getInstance()->query(
            "SELECT wz.dok_NrPelny, wz.dok_NrPelnyOryg, wz.dok_WartNetto, wz.dok_DataWyst,
                    LEFT(wz.dok_Uwagi, 100) AS u
             FROM dok__Dokument wz
             WHERE wz.dok_Typ=11 AND wz.dok_Status>=0
               AND wz.dok_DataWyst>='2026-07-15'
               AND wz.dok_Uwagi LIKE '%{$safe}%'
             ORDER BY wz.dok_Id"
        );
        if (!is_array($rows) || empty($rows)) {
            echo "  [$n] brak WZ\n";
            continue;
        }
        foreach ($rows as $r) {
            $ref = $r['dok_NrPelny'];
            $ok = Order::isIssueDocumentLinkedToOrder($ref, $id, $zkRef) ? 'TAK' : 'nie';
            echo "  [$n] => {$ref} oryg={$r['dok_NrPelnyOryg']} netto={$r['dok_WartNetto']} link={$ok}\n";
        }
    }
}
