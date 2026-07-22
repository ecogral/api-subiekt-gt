<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

$oryg = 'B2B-605/2026';
$safe = str_replace("'", "''", $oryg);

$zks = MSSql::getInstance()->query(
    "SELECT dok_Id, dok_NrPelny, dok_NrPelnyOryg, dok_Status, dok_StatusEx,
            dok_DoDokId, dok_DoDokNrPelny, dok_DataWyst, dok_WartNetto, dok_PlatnikId,
            LEFT(ISNULL(dok_Uwagi, ''), 120) AS uwagi
     FROM dok__Dokument
     WHERE dok_Typ = 16 AND dok_Status >= 0
       AND dok_NrPelnyOryg = '{$safe}'
     ORDER BY dok_Id"
);

$byNr = MSSql::getInstance()->query(
    "SELECT dok_Id, dok_NrPelny, dok_NrPelnyOryg, dok_Status, dok_DataWyst, dok_WartNetto
     FROM dok__Dokument
     WHERE dok_Typ = 16 AND dok_Status >= 0
       AND dok_NrPelny IN ('ZK 3766/07/2026', 'ZK 3767/07/2026')
     ORDER BY dok_Id"
);

$out = array(
    'oryg' => $oryg,
    'zk_by_oryg' => $zks,
    'zk_3766_3767' => $byNr,
    'details' => array(),
);

foreach (is_array($byNr) ? $byNr : array() as $row) {
    $ref = trim((string) ($row['dok_NrPelny'] ?? ''));
    $id = (int) ($row['dok_Id'] ?? 0);
    if ($ref === '' || $id <= 0) {
        continue;
    }
    $pos = MSSql::getInstance()->query(
        "SELECT COUNT(*) AS cnt, SUM(ob_WartNetto) AS netto
         FROM dok_Pozycja WHERE ob_DokHanId = {$id}"
    );
    $out['details'][$ref] = array(
        'valid_wz' => Order::getValidIssueRefsForOrderSql($id, $ref),
        'all_wz' => Order::getIssueRefsForOrder($ref, $id),
        'positions' => MSSql::getInstance()->query(
            "SELECT tw.tw_Symbol, p.ob_Ilosc, p.ob_CenaNetto
             FROM dok_Pozycja p
             LEFT JOIN tw__Towar tw ON tw.tw_Id = p.ob_TowId
             WHERE p.ob_DokHanId = {$id}
             ORDER BY p.ob_Id"
        ),
        'pos_summary' => $pos,
    );
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
