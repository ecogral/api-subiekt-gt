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

echo "=== Wszystkie WZ lipiec z DY61 (qty, link) ===\n";
$wz = $db->query(
    "SELECT d.dok_NrPelny, d.dok_DataWyst, d.dok_Status, p.ob_Ilosc, p.ob_DoId,
            zk.dok_NrPelny AS zk_via_doid
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokMagId AND d.dok_Typ = 11
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     LEFT JOIN dok_Pozycja zp ON zp.ob_Id = p.ob_DoId
     LEFT JOIN dok__Dokument zk ON zk.dok_Id = zp.ob_DokHanId AND zk.dok_Typ = 16
     WHERE t.tw_Symbol = 'DY61' AND d.dok_DataWyst >= '2026-07-01' AND d.dok_Status >= 0
     ORDER BY d.dok_DataWyst DESC, d.dok_NrPelny"
);
$linked = 0;
$orphan = 0;
foreach ($wz ?: array() as $r) {
    $qty = (float) ($r['ob_Ilosc'] ?? 0);
    if (!empty($r['ob_DoId'])) {
        $linked += $qty;
    } else {
        $orphan += $qty;
    }
    $dw = $r['dok_DataWyst'];
    if ($dw instanceof DateTimeInterface) {
        $dw = $dw->format('Y-m-d');
    }
    printf(
        "  %s | %s | qty=%.0f | DoId=%s | ZK=%s\n",
        $r['dok_NrPelny'],
        $dw,
        $qty,
        $r['ob_DoId'] !== null && $r['ob_DoId'] !== '' ? $r['ob_DoId'] : 'NULL',
        $r['zk_via_doid'] ?? '(brak)'
    );
}
echo "Suma WZ DY61 lipiec: linked={$linked}, orphan_wz={$orphan}\n";

echo "\n=== ZK 3638 — czy jest WZ z 10 szt DY61 w okolicy? ===\n";
print_r($db->query(
    "SELECT d.dok_NrPelny, d.dok_DataWyst, p.ob_Ilosc, p.ob_DoId
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokMagId AND d.dok_Typ = 11
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = 'DY61' AND p.ob_Ilosc >= 9
       AND d.dok_DataWyst BETWEEN '2026-07-13' AND '2026-07-15'"
));

echo "\n=== ZK status 7 bez WZ w naglowku, z DY61 ===\n";
print_r($db->query(
    "SELECT d.dok_NrPelny, d.dok_Status, d.dok_DataWyst,
            SUM(CASE WHEN t.tw_Symbol='DY61' THEN p.ob_Ilosc ELSE 0 END) AS dy61_zam,
            SUM(CASE WHEN t.tw_Symbol='DY61' THEN ISNULL(p.ob_IloscMag,0) ELSE 0 END) AS dy61_wyd
     FROM dok__Dokument d
     INNER JOIN dok_Pozycja p ON p.ob_DokHanId = d.dok_Id
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE d.dok_Typ = 16 AND d.dok_Status = 7
       AND (d.dok_DoDokNrPelny IS NULL OR d.dok_DoDokNrPelny = '')
       AND EXISTS (
           SELECT 1 FROM dok_Pozycja px
           INNER JOIN tw__Towar tx ON tx.tw_Id = px.ob_TowId
           WHERE px.ob_DokHanId = d.dok_Id AND tx.tw_Symbol = 'DY61'
       )
     GROUP BY d.dok_NrPelny, d.dok_Status, d.dok_DataWyst
     ORDER BY d.dok_DataWyst DESC"
));
