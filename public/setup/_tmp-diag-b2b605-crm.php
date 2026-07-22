<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

$refs = array('B2B-605/2026', 'B2B-608/2026');
foreach ($refs as $oryg) {
    $safe = str_replace("'", "''", $oryg);
    echo "\n=== {$oryg} ===\n";
    $rows = MSSql::getInstance()->query(
        "SELECT d.dok_Id, d.dok_NrPelny, d.dok_NrPelnyOryg, d.dok_Status, d.dok_DataWyst,
                d.dok_WartNetto, d.dok_WartBrutto, k.adr_NIP
         FROM dok__Dokument d
         LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id
         WHERE d.dok_Typ = 16 AND d.dok_Status >= 0
           AND LTRIM(RTRIM(ISNULL(d.dok_NrPelnyOryg, ''))) = '{$safe}'
         ORDER BY d.dok_Id"
    );
    if (!is_array($rows) || empty($rows)) {
        echo "  (brak ZK)\n";
        continue;
    }
    echo '  ZK count=' . count($rows) . "\n";
    foreach ($rows as $r) {
        $zk = $r['dok_NrPelny'] ?? '';
        $wz = MSSql::getInstance()->query(
            "SELECT dok_NrPelny, dok_Status FROM dok__Dokument
             WHERE dok_Typ = 11 AND dok_Status >= 0
               AND LTRIM(RTRIM(ISNULL(dok_NrPelnyOryg, ''))) = '" . str_replace("'", "''", $zk) . "'"
        );
        $wzList = array();
        if (is_array($wz)) {
            foreach ($wz as $w) {
                $wzList[] = ($w['dok_NrPelny'] ?? '') . '(st=' . ($w['dok_Status'] ?? '') . ')';
            }
        }
        echo '  ' . $zk . ' | id=' . ($r['dok_Id'] ?? '')
            . ' | st=' . ($r['dok_Status'] ?? '')
            . ' | netto=' . ($r['dok_WartNetto'] ?? '')
            . ' | data=' . (is_object($r['dok_DataWyst'] ?? null) ? $r['dok_DataWyst']->format('Y-m-d') : ($r['dok_DataWyst'] ?? ''))
            . ' | WZ: ' . (empty($wzList) ? '-' : implode(', ', $wzList)) . "\n";
    }
}

// Duplikaty oryg w lipcu 2026
echo "\n=== Kolizje reference (lipiec 2026, TOP 20) ===\n";
$dup = MSSql::getInstance()->query(
    "SELECT d.dok_NrPelnyOryg AS reference, COUNT(*) AS cnt,
            MIN(d.dok_NrPelny) AS first_zk, MAX(d.dok_NrPelny) AS last_zk
     FROM dok__Dokument d
     WHERE d.dok_Typ = 16 AND d.dok_Status >= 0
       AND d.dok_DataWyst >= '2026-07-01' AND d.dok_DataWyst < '2026-08-01'
       AND LTRIM(RTRIM(ISNULL(d.dok_NrPelnyOryg, ''))) <> ''
       AND d.dok_NrPelnyOryg LIKE 'B2B-%'
     GROUP BY d.dok_NrPelnyOryg
     HAVING COUNT(*) > 1
     ORDER BY COUNT(*) DESC"
);
if (is_array($dup)) {
    foreach ($dup as $d) {
        echo '  ' . ($d['reference'] ?? '') . ' => ' . ($d['cnt'] ?? '') . ' ZK (' . ($d['first_zk'] ?? '') . ' .. ' . ($d['last_zk'] ?? '') . ")\n";
    }
}
