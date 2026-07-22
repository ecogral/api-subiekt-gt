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

$refs = array('WZ 3000/06/2026', 'WZ 3006/06/2026');
foreach ($refs as $ref) {
    echo "\n========== {$ref} ==========\n";
    $safe = str_replace("'", "''", $ref);
    $hdr = $db->query(
        "SELECT dok_Id, dok_NrPelny, dok_Podtyp, dok_Status, dok_PlatnikId, dok_OdbiorcaId,
                dok_DoDokId, dok_DoDokNrPelny, dok_NrPelnyOryg, dok_WartNetto, dok_WartBrutto
         FROM dok__Dokument WHERE dok_NrPelny = '{$safe}'"
    );
    echo 'HEADER: ' . json_encode($hdr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    if (empty($hdr[0]['dok_Id'])) {
        continue;
    }
    $wzId = (int) $hdr[0]['dok_Id'];
    $pos = $db->query(
        "SELECT p.ob_Id, p.ob_TowId, tw.tw_Symbol, p.ob_Ilosc, p.ob_CenaNetto, p.ob_CenaBrutto,
                p.ob_WartNetto, p.ob_WartMag, p.ob_DoId, ISNULL(p.ob_TowRodzaj, 1) AS rodzaj
         FROM dok_Pozycja p
         LEFT JOIN tw__Towar tw ON tw.tw_Id = p.ob_TowId
         WHERE p.ob_DokMagId = {$wzId}
         ORDER BY p.ob_Id"
    );
    echo 'POSITIONS (' . count($pos) . "):\n" . json_encode($pos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

    $fs = $db->query(
        "SELECT dok_Id, dok_NrPelny, dok_Podtyp, dok_DoDokId FROM dok__Dokument WHERE dok_Typ = 2 AND dok_DoDokId = {$wzId}"
    );
    echo 'FS linked (dok_DoDokId=WZ): ' . json_encode($fs, JSON_UNESCAPED_UNICODE) . "\n";

    $zk = $db->query(
        "SELECT dok_Id, dok_NrPelny, dok_Status, dok_DoDokId, dok_DoDokNrPelny
         FROM dok__Dokument WHERE dok_Typ = 16 AND (dok_DoDokId = {$wzId} OR dok_DoDokNrPelny = '{$safe}')"
    );
    echo 'ZK pointing to WZ: ' . json_encode($zk, JSON_UNESCAPED_UNICODE) . "\n";

    $oryg = trim((string) ($hdr[0]['dok_NrPelnyOryg'] ?? ''));
    if ($oryg !== '') {
        $safeO = str_replace("'", "''", $oryg);
        $zk2 = $db->query(
            "SELECT dok_Id, dok_NrPelny, dok_Status, dok_DoDokId, dok_DoDokNrPelny FROM dok__Dokument WHERE dok_NrPelny = '{$safeO}'"
        );
        echo 'ZK from WZ.dok_NrPelnyOryg: ' . json_encode($zk2, JSON_UNESCAPED_UNICODE) . "\n";
    }

    $diag = Order::diagnoseIssueForInvoicingSql($ref);
    echo 'DIAG: ' . json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

$platnik = $db->query(
    "SELECT dok_PlatnikId FROM dok__Dokument WHERE dok_NrPelny IN ('WZ 3000/06/2026', 'WZ 3006/06/2026')"
);
$ids = array();
foreach ($platnik as $p) {
    if (!empty($p['dok_PlatnikId'])) {
        $ids[] = (int) $p['dok_PlatnikId'];
    }
}
$ids = array_unique($ids);
echo "\nPlatnik IDs: " . implode(', ', $ids) . "\n";

if (count($ids) === 1) {
    $pid = $ids[0];
    $recentFs = $db->query(
        "SELECT TOP 10 d.dok_Id, d.dok_NrPelny, d.dok_Podtyp, d.dok_DoDokId, d.dok_WartNetto, d.dok_DataWyst
         FROM dok__Dokument d
         WHERE d.dok_Typ = 2 AND d.dok_PlatnikId = {$pid} AND d.dok_DataWyst >= '2026-07-01'
         ORDER BY d.dok_Id DESC"
    );
    echo "Recent FS (July 2026):\n" . json_encode($recentFs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

// FS positions if user created draft
$fsRef = $db->query(
    "SELECT d.dok_Id, d.dok_NrPelny, d.dok_Podtyp, d.dok_Status
     FROM dok__Dokument d
     INNER JOIN dok__Dokument w ON w.dok_PlatnikId = d.dok_PlatnikId AND w.dok_NrPelny IN ('WZ 3000/06/2026', 'WZ 3006/06/2026')
     WHERE d.dok_Typ = 2 AND d.dok_DataWyst >= '2026-07-19' AND d.dok_Status >= 0
     ORDER BY d.dok_Id DESC"
);
echo "\nFS same payer recent:\n" . json_encode($fsRef, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

foreach (array(68429, 68467) as $wzId) {
    $posMag = $db->query(
        "SELECT tw.tw_Symbol, p.ob_Ilosc, p.ob_WartMag, p.ob_DoId, p.ob_TowRodzaj
         FROM dok_Pozycja p LEFT JOIN tw__Towar tw ON tw.tw_Id = p.ob_TowId
         WHERE p.ob_DokMagId = {$wzId} ORDER BY p.ob_Id"
    );
    echo "\nWZ id {$wzId} positions (ob_DokMagId): " . json_encode($posMag, JSON_UNESCAPED_UNICODE) . "\n";
}

$kh = $db->query(
    "SELECT k.kh_Id, k.kh_Symbol, a.adr_NazwaPelna, a.adr_NIP
     FROM kh__Kontrahent k
     LEFT JOIN adr__Ewid a ON a.adr_IdObiektu = k.kh_Id AND a.adr_TypAdresu = 1
     WHERE k.kh_Id IN (1442, 2511)"
);
echo "\nKontrahenci:\n" . json_encode($kh, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

foreach (array('FS 2889/06/2026', 'FS 2886/06/2026') as $fsn) {
    $safe = str_replace("'", "''", $fsn);
    $fr = $db->query("SELECT dok_Id, dok_NrPelny, dok_Podtyp, dok_PlatnikId, dok_WartNetto FROM dok__Dokument WHERE dok_NrPelny = '{$safe}'");
    echo "\nExisting {$fsn}: " . json_encode($fr, JSON_UNESCAPED_UNICODE) . "\n";
    if (!empty($fr[0]['dok_Id'])) {
        $fid = (int) $fr[0]['dok_Id'];
        $fp = $db->query(
            "SELECT tw.tw_Symbol, p.ob_Ilosc, p.ob_CenaNetto, p.ob_WartNetto
             FROM dok_Pozycja p LEFT JOIN tw__Towar tw ON tw.tw_Id = p.ob_TowId WHERE p.ob_DokHanId = {$fid}"
        );
        echo json_encode($fp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
}

// draft FS zbiorcza (podtyp 2)
$draft = $db->query(
    "SELECT TOP 5 dok_Id, dok_NrPelny, dok_Podtyp, dok_Status, dok_PlatnikId, dok_WartNetto
     FROM dok__Dokument WHERE dok_Typ = 2 AND dok_Podtyp = 2 AND dok_Status = 0 ORDER BY dok_Id DESC"
);
echo "\nDraft FSz (status 0):\n" . json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

foreach ($fsRef as $f) {
    $fsId = (int) $f['dok_Id'];
    $fpos = $db->query(
        "SELECT p.ob_Id, tw.tw_Symbol, p.ob_Ilosc, p.ob_CenaNetto, p.ob_WartNetto, p.ob_DoId
         FROM dok_Pozycja p LEFT JOIN tw__Towar tw ON tw.tw_Id = p.ob_TowId
         WHERE p.ob_DokHanId = {$fsId}"
    );
    echo "FS {$f['dok_NrPelny']} podtyp={$f['dok_Podtyp']} positions:\n" . json_encode($fpos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
