<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

foreach (array('ZK 3766/07/2026', 'ZK 3768/07/2026', 'ZK 3767/07/2026') as $ref) {
    $s = str_replace("'", "''", $ref);
    echo "\n======== {$ref} ========\n";
    $h = MSSql::getInstance()->query(
        "SELECT d.dok_Id, d.dok_NrPelny, d.dok_NrPelnyOryg, d.dok_Tytul, d.dok_Status,
                d.dok_WartNetto, d.dok_WartBrutto, d.dok_PlatnikId, d.dok_DataWyst,
                k.adr_NazwaPelna, k.adr_NIP
         FROM dok__Dokument d
         LEFT JOIN vwKlienci k ON k.kh_Id = d.dok_PlatnikId
         WHERE d.dok_NrPelny = '{$s}' AND d.dok_Typ = 16"
    );
    if (!is_array($h) || empty($h)) {
        echo "NOT FOUND\n";
        continue;
    }
    $r = $h[0];
    echo 'oryg=' . ($r['dok_NrPelnyOryg'] ?? '') . ' tytul=' . ($r['dok_Tytul'] ?? '') . "\n";
    echo 'kontrahent=' . ($r['adr_NazwaPelna'] ?? '') . ' NIP=' . ($r['adr_NIP'] ?? '') . "\n";
    echo 'netto=' . ($r['dok_WartNetto'] ?? '') . ' brutto=' . ($r['dok_WartBrutto'] ?? '') . "\n";
}

// B2B-605 w GT
echo "\n======== Wszystkie ZK z oryg B2B-605/2026 ========\n";
$rows = MSSql::getInstance()->query(
    "SELECT d.dok_NrPelny, k.adr_NazwaPelna, d.dok_WartNetto, d.dok_WartBrutto
     FROM dok__Dokument d LEFT JOIN vwKlienci k ON k.kh_Id = d.dok_PlatnikId
     WHERE d.dok_Typ=16 AND d.dok_Status>=0 AND d.dok_NrPelnyOryg='B2B-605/2026'"
);
if (is_array($rows)) {
    foreach ($rows as $r) {
        echo ($r['dok_NrPelny'] ?? '') . ' | ' . ($r['adr_NazwaPelna'] ?? '') . ' | netto=' . ($r['dok_WartNetto'] ?? '') . "\n";
    }
}

// SUNTRADO ZK lipiec ~18649
echo "\n======== ZK SUNTRADO ~18649 netto ========\n";
$rows2 = MSSql::getInstance()->query(
    "SELECT TOP 5 d.dok_NrPelny, d.dok_NrPelnyOryg, d.dok_WartNetto, k.adr_NazwaPelna
     FROM dok__Dokument d
     INNER JOIN vwKlienci k ON k.kh_Id = d.dok_PlatnikId
     WHERE d.dok_Typ=16 AND d.dok_Status>=0
       AND k.adr_NazwaPelna LIKE '%SUNTRADO%'
       AND ABS(d.dok_WartNetto - 18649.17) < 1
     ORDER BY d.dok_Id DESC"
);
if (is_array($rows2)) {
    foreach ($rows2 as $r) {
        echo ($r['dok_NrPelny'] ?? '') . ' | oryg=' . ($r['dok_NrPelnyOryg'] ?? '') . ' | ' . ($r['adr_NazwaPelna'] ?? '') . "\n";
    }
}
