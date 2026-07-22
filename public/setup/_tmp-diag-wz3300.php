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

$refs = array('WZ 3300/07/2026', 'WZ 3306/07/2026');
foreach ($refs as $ref) {
    echo "\n========== {$ref} ==========\n";
    $safe = str_replace("'", "''", $ref);
    $hdr = $db->query(
        "SELECT d.dok_Id, d.dok_NrPelny, d.dok_Podtyp, d.dok_Status, d.dok_PlatnikId, d.dok_OdbiorcaId,
                d.dok_DoDokId, d.dok_DoDokNrPelny, d.dok_NrPelnyOryg, d.dok_WartNetto, d.dok_WartBrutto,
                k.kh_Symbol, k.kh_Id
         FROM dok__Dokument d
         LEFT JOIN kh__Kontrahent k ON k.kh_Id = d.dok_PlatnikId
         WHERE d.dok_NrPelny = '{$safe}'"
    );
    echo json_encode($hdr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    if (empty($hdr[0]['dok_Id'])) {
        continue;
    }
    $wzId = (int) $hdr[0]['dok_Id'];
    $pos = $db->query(
        "SELECT tw.tw_Symbol, p.ob_Ilosc, p.ob_CenaNetto, p.ob_WartNetto, p.ob_TowRodzaj
         FROM dok_Pozycja p LEFT JOIN tw__Towar tw ON tw.tw_Id = p.ob_TowId
         WHERE p.ob_DokMagId = {$wzId} ORDER BY p.ob_Id"
    );
    echo 'Pozycje (' . count($pos) . "):\n" . json_encode($pos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

    $doDok = (int) ($hdr[0]['dok_DoDokId'] ?? 0);
    if ($doDok > 0) {
        $fs = $db->query(
            "SELECT dok_Id, dok_NrPelny, dok_Podtyp, dok_PlatnikId, dok_WartNetto, dok_Status
             FROM dok__Dokument WHERE dok_Id = {$doDok}"
        );
        echo "Powiązana FS (WZ.dok_DoDokId):\n" . json_encode($fs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        if (!empty($fs[0]['dok_Id'])) {
            $fid = (int) $fs[0]['dok_Id'];
            $fp = $db->query(
                "SELECT tw.tw_Symbol, p.ob_Ilosc, p.ob_WartNetto FROM dok_Pozycja p
                 LEFT JOIN tw__Towar tw ON tw.tw_Id = p.ob_TowId WHERE p.ob_DokHanId = {$fid}"
            );
            echo "Pozycje FS (" . count($fp) . "):\n" . json_encode($fp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        }
    }

    echo 'DIAG: ' . json_encode(Order::diagnoseIssueForInvoicingSql($ref), JSON_UNESCAPED_UNICODE) . "\n";
}

// FS 3179, 3180 from screenshot
foreach (array('FS 3179/07/2026', 'FS 3180/07/2026') as $fsn) {
    $safe = str_replace("'", "''", $fsn);
    $row = $db->query(
        "SELECT d.dok_Id, d.dok_NrPelny, d.dok_Podtyp, d.dok_PlatnikId, d.dok_DoDokId, d.dok_DoDokNrPelny, d.dok_WartNetto,
                k.kh_Symbol
         FROM dok__Dokument d LEFT JOIN kh__Kontrahent k ON k.kh_Id = d.dok_PlatnikId
         WHERE d.dok_NrPelny = '{$safe}'"
    );
    echo "\n--- {$fsn} ---\n" . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

// WZ pointing to same FS?
$home = $db->query(
    "SELECT d.dok_NrPelny, d.dok_PlatnikId, d.dok_DoDokNrPelny, d.dok_WartNetto, k.kh_Symbol
     FROM dok__Dokument d
     LEFT JOIN kh__Kontrahent k ON k.kh_Id = d.dok_PlatnikId
     WHERE d.dok_Typ = 11 AND d.dok_NrPelny IN ('WZ 3300/07/2026', 'WZ 3306/07/2026')"
);
echo "\nPorównanie płatników:\n" . json_encode($home, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
