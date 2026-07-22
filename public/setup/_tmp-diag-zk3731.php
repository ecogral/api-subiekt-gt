<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$db = MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

$refs = array('ZK 3731/07/2026', 'WZ 3334/07/2026');
foreach ($refs as $ref) {
    $safe = str_replace("'", "''", $ref);
    $hdr = $db->query("SELECT dok_Id, dok_NrPelny, dok_Typ, dok_Status, dok_DataWyst, dok_WartNetto, dok_WartBrutto, dok_NrPelnyOryg, dok_DoDokId, dok_DoDokNrPelny FROM dok__Dokument WHERE dok_NrPelny = '{$safe}'");
    echo "\n=== {$ref} ===\n" . json_encode($hdr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    if (empty($hdr[0]['dok_Id'])) {
        continue;
    }
    $id = (int) $hdr[0]['dok_Id'];
    $typ = (int) $hdr[0]['dok_Typ'];
    $magCol = ($typ === 11) ? 'ob_DokMagId' : 'ob_DokHanId';
    $pos = $db->query(
        "SELECT p.ob_Id, p.ob_DoId, tw.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag, p.ob_Jm,
                p.ob_CenaNetto, p.ob_CenaBrutto, p.ob_CenaMag, p.ob_WartNetto, p.ob_WartBrutto, p.ob_WartMag,
                p.ob_Rabat, p.ob_VatProc, ISNULL(p.ob_TowRodzaj,1) AS rodzaj
         FROM dok_Pozycja p
         LEFT JOIN tw__Towar tw ON tw.tw_Id = p.ob_TowId
         WHERE p.{$magCol} = {$id}
         ORDER BY p.ob_DokMagLp, p.ob_DokHanLp, p.ob_Id"
    );
    echo "Pozycje:\n" . json_encode($pos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

// Link WZ position -> ZK position
$wzId = $db->query("SELECT dok_Id FROM dok__Dokument WHERE dok_NrPelny = 'WZ 3334/07/2026'");
$zkId = $db->query("SELECT dok_Id FROM dok__Dokument WHERE dok_NrPelny = 'ZK 3731/07/2026'");
if (!empty($wzId[0]['dok_Id']) && !empty($zkId[0]['dok_Id'])) {
    $wid = (int) $wzId[0]['dok_Id'];
    $zid = (int) $zkId[0]['dok_Id'];
    $join = $db->query(
        "SELECT wp.ob_Id AS wz_ob, wp.ob_DoId, zp.ob_Id AS zk_ob, tw.tw_Symbol,
                zp.ob_CenaNetto AS zk_cena, zp.ob_Ilosc AS zk_ilosc, zp.ob_WartNetto AS zk_wart,
                wp.ob_CenaNetto AS wz_cena, wp.ob_Ilosc AS wz_ilosc, wp.ob_WartNetto AS wz_wart
         FROM dok_Pozycja wp
         LEFT JOIN dok_Pozycja zp ON zp.ob_Id = wp.ob_DoId
         LEFT JOIN tw__Towar tw ON tw.tw_Id = wp.ob_TowId
         WHERE wp.ob_DokMagId = {$wid}
         ORDER BY wp.ob_Id"
    );
    echo "\n=== Powiązanie WZ->ZK (ob_DoId) ===\n" . json_encode($join, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
