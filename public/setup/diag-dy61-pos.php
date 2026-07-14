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

foreach (array('ZK 3638/07/2026', 'ZK 3648/07/2026') as $ref) {
    echo "\n=== {$ref} — pozycje ===\n";
    print_r($db->query(
        "SELECT d.dok_Id, d.dok_Status, d.dok_StatusEx, d.dok_DoDokNrPelny,
                t.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag,
                p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) AS pozostalo,
                p.ob_TowRodzaj
         FROM dok__Dokument d
         INNER JOIN dok_Pozycja p ON p.ob_DokHanId = d.dok_Id
         INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
         WHERE d.dok_NrPelny = '" . str_replace("'", "''", $ref) . "'"
    ));

    $idRow = $db->query(
        "SELECT dok_Id FROM dok__Dokument WHERE dok_NrPelny = '" . str_replace("'", "''", $ref) . "'"
    );
    $orderId = (int) ($idRow[0]['dok_Id'] ?? 0);
    if ($orderId <= 0) {
        continue;
    }

    echo "=== WZ powiazane z tym ZK (ob_DoId / ob_DokHanId) ===\n";
    print_r($db->query(
        "SELECT wz.dok_NrPelny, wz.dok_Status, t.tw_Symbol, wp.ob_Ilosc, wp.ob_DoId
         FROM dok_Pozycja wp
         INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
         INNER JOIN tw__Towar t ON t.tw_Id = wp.ob_TowId
         LEFT JOIN dok_Pozycja zp ON zp.ob_Id = wp.ob_DoId
         WHERE zp.ob_DokHanId = {$orderId} OR wp.ob_DokHanId = {$orderId}
         ORDER BY wz.dok_NrPelny, t.tw_Symbol"
    ));

    echo "=== WZ z DY61 bez linku, ten sam dzien co ZK ===\n";
    print_r($db->query(
        "SELECT wz.dok_NrPelny, wz.dok_DataWyst, wp.ob_Ilosc, wp.ob_DoId
         FROM dok_Pozycja wp
         INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
         INNER JOIN tw__Towar t ON t.tw_Id = wp.ob_TowId
         INNER JOIN dok__Dokument zk ON zk.dok_Id = {$orderId}
         WHERE t.tw_Symbol = 'DY61'
           AND wz.dok_DataWyst = zk.dok_DataWyst
           AND (wp.ob_DoId IS NULL OR wp.ob_DoId = 0)"
    ));
}

echo "\n=== Suma pozostalo DY61 ze status 7 (3638+3648+inne) ===\n";
print_r($db->query(
    "SELECT d.dok_NrPelny, d.dok_Status,
            SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS pozostalo
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = 'DY61' AND d.dok_Status = 7
     GROUP BY d.dok_NrPelny, d.dok_Status
     HAVING SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) > 0.00001"
));
