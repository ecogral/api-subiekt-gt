<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

echo "=== ZK status 6 (Nie rezerwuj) z WZ w nagłówku / oryg — lipiec 2026 ===\n";
$rows = MSSql::getInstance()->query(
    "SELECT TOP 30 d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_DoDokNrPelny,
            d.dok_WartNetto, k.adr_NazwaPelna
     FROM dok__Dokument d
     LEFT JOIN vwKlienci k ON k.kh_Id = d.dok_PlatnikId
     WHERE d.dok_Typ = 16 AND d.dok_Status = 6 AND d.dok_Status >= 0
       AND d.dok_DataWyst >= '2026-07-01'
       AND (
         LTRIM(RTRIM(ISNULL(d.dok_DoDokNrPelny, ''))) LIKE 'WZ %'
         OR EXISTS (
           SELECT 1 FROM dok__Dokument wz
           WHERE wz.dok_Typ = 11 AND wz.dok_Status >= 0
             AND (wz.dok_NrPelnyOryg = d.dok_NrPelny OR wz.dok_Id = d.dok_DoDokId)
         )
       )
     ORDER BY d.dok_Id DESC"
);
if (!is_array($rows) || empty($rows)) {
    echo "(brak)\n";
} else {
    foreach ($rows as $r) {
        echo ($r['dok_NrPelny'] ?? '') . ' | st=' . ($r['dok_Status'] ?? '')
            . ' ex=' . ($r['dok_StatusEx'] ?? '')
            . ' | do=' . ($r['dok_DoDokNrPelny'] ?? '')
            . ' | ' . ($r['adr_NazwaPelna'] ?? '')
            . ' | netto=' . ($r['dok_WartNetto'] ?? '') . "\n";
    }
}
