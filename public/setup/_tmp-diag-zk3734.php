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

$refs = array(
    'ZK 3734/07/2026', 'ZK 3735/07/2026', 'ZK 3736/07/2026', 'ZK 3737/07/2026',
    'WZ 3327/07/2026',
);
foreach ($refs as $ref) {
    $safe = str_replace("'", "''", $ref);
    $hdr = $db->query(
        "SELECT d.dok_Id, d.dok_NrPelny, d.dok_Typ, d.dok_Status, d.dok_PlatnikId,
                d.dok_DoDokId, d.dok_DoDokNrPelny, d.dok_NrPelnyOryg, d.dok_WartNetto,
                d.dok_DataWyst
         FROM dok__Dokument d WHERE d.dok_NrPelny = '{$safe}'"
    );
    echo "\n=== {$ref} ===\n" . json_encode($hdr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

$wzSafe = "WZ 3327/07/2026";
$allZk = $db->query(
    "SELECT dok_Id, dok_NrPelny, dok_NrPelnyOryg, dok_Status, dok_DoDokId, dok_DoDokNrPelny, dok_WartNetto
     FROM dok__Dokument
     WHERE dok_Typ = 16 AND dok_DoDokNrPelny = '{$wzSafe}'
     ORDER BY dok_NrPelny"
);
echo "\n=== Wszystkie ZK wskazujące na WZ 3327 ===\n" . json_encode($allZk, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

$wzId = $db->query("SELECT dok_Id FROM dok__Dokument WHERE dok_NrPelny = '{$wzSafe}'");
if (!empty($wzId[0]['dok_Id'])) {
    $wid = (int) $wzId[0]['dok_Id'];
    $pos = $db->query(
        "SELECT p.ob_Id, p.ob_DoId, tw.tw_Symbol, p.ob_Ilosc, p.ob_WartNetto,
                zk.dok_NrPelny AS zk_from_doid
         FROM dok_Pozycja p
         LEFT JOIN tw__Towar tw ON tw.tw_Id = p.ob_TowId
         LEFT JOIN dok_Pozycja zp ON zp.ob_Id = p.ob_DoId
         LEFT JOIN dok__Dokument zk ON zk.dok_Id = zp.ob_DokHanId AND zk.dok_Typ = 16
         WHERE p.ob_DokMagId = {$wid}
         ORDER BY p.ob_Id"
    );
    echo "\n=== Pozycje WZ 3327 (ob_DoId -> ZK) ===\n" . json_encode($pos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

foreach (array('ZK 3734/07/2026', 'ZK 3735/07/2026', 'ZK 3736/07/2026', 'ZK 3737/07/2026') as $zkRef) {
    $issues = Order::getIssueRefsForOrder($zkRef);
    echo "\ngetIssueRefsForOrder({$zkRef}): " . json_encode($issues, JSON_UNESCAPED_UNICODE) . "\n";
}
