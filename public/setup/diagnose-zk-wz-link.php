<?php
/**
 * Diagnostyka powiązań ZK ↔ WZ (wszystkie znane ścieżki w bazie GT).
 * php public/setup/diagnose-zk-wz-link.php "ZK 2866/06/2026" "WZ 2646/06/2026"
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;

$orderRef = isset($argv[1]) ? trim($argv[1]) : 'ZK 2866/06/2026';
$issueRef = isset($argv[2]) ? trim($argv[2]) : 'WZ 2646/06/2026';

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();

MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

function q($sql)
{
    $rows = MSSql::getInstance()->query($sql);
    return is_array($rows) ? $rows : array();
}

function section($title, $rows)
{
    echo "\n=== {$title} ===\n";
    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

$safeOrder = str_replace("'", "''", $orderRef);
$safeIssue = str_replace("'", "''", $issueRef);

$zk = q("SELECT dok_Id, dok_NrPelny, dok_Status, dok_StatusEx, dok_DoDokId, dok_DoDokNrPelny, dok_Podtyp
         FROM dok__Dokument WHERE dok_NrPelny = '{$safeOrder}' AND dok_Typ = 16");
section('ZK', $zk);

$orderId = !empty($zk[0]['dok_Id']) ? (int) $zk[0]['dok_Id'] : 0;

$wz = q("SELECT dok_Id, dok_NrPelny, dok_Status, dok_NrPelnyOryg, dok_DoDokId, dok_DoDokNrPelny, dok_Podtyp
         FROM dok__Dokument WHERE dok_NrPelny = '{$safeIssue}' AND dok_Typ = 11");
section('WZ (podany numer)', $wz);

if ($orderId > 0) {
    section('WZ: dok_DoDokId = ZK.dok_Id', q(
        "SELECT dok_Id, dok_NrPelny, dok_DoDokId, dok_NrPelnyOryg FROM dok__Dokument
         WHERE dok_Typ = 11 AND dok_DoDokId = {$orderId}"
    ));
    section('WZ: dok_NrPelnyOryg = ZK', q(
        "SELECT dok_Id, dok_NrPelny, dok_DoDokId, dok_NrPelnyOryg FROM dok__Dokument
         WHERE dok_Typ = 11 AND dok_NrPelnyOryg = '{$safeOrder}'"
    ));
    section('WZ z ZK.dok_DoDokId / dok_DoDokNrPelny', q(
        "SELECT zk.dok_DoDokId, zk.dok_DoDokNrPelny, wz.dok_Id AS wz_id, wz.dok_NrPelny AS wz_ref
         FROM dok__Dokument zk
         LEFT JOIN dok__Dokument wz ON wz.dok_Id = zk.dok_DoDokId AND wz.dok_Typ = 11
         WHERE zk.dok_Id = {$orderId}"
    ));
    section('WZ przez ob_DoId → pozycje ZK', q(
        "SELECT DISTINCT wz.dok_Id, wz.dok_NrPelny
         FROM dok_Pozycja zk
         INNER JOIN dok_Pozycja wz_p ON wz_p.ob_DoId = zk.ob_Id
         INNER JOIN dok__Dokument wz ON wz.dok_Id = wz_p.ob_DokMagId AND wz.dok_Typ = 11
         WHERE zk.ob_DokHanId = {$orderId}"
    ));
    section('WZ przez ob_DokHanId na pozycjach WZ', q(
        "SELECT DISTINCT wz.dok_Id, wz.dok_NrPelny
         FROM dok_Pozycja wp
         INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
         WHERE wp.ob_DokHanId = {$orderId}"
    ));
    section('ob_Powiazane (ZK↔WZ)', q(
        "SELECT op.op_Id, op.op_TypOb, op.op_IdOb, op.op_TypWskazywanego, op.op_IdWskazywanego, op.op_OpisPowiazania
         FROM ob_Powiazane op
         WHERE (op.op_TypOb IN (921, 922) AND op.op_IdOb = {$orderId})
            OR (op.op_TypWskazywanego IN (921, 922) AND op.op_IdWskazywanego = {$orderId})"
    ));
}

if (!empty($wz[0]['dok_Id'])) {
    $wzId = (int) $wz[0]['dok_Id'];
    section('Pozycje WZ (ob_DoId, ob_DokHanId)', q(
        "SELECT TOP 20 ob_Id, ob_DoId, ob_DokHanId, ob_DokMagId, ob_TowId, ob_Ilosc
         FROM dok_Pozycja WHERE ob_DokMagId = {$wzId}"
    ));
}
