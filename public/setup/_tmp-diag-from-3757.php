<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

echo "=== ZK od 3750 wzwyz (lipiec) — status vs WZ ===\n";
$rows = MSSql::getInstance()->query(
    "SELECT d.dok_NrPelny, d.dok_Status, d.dok_StatusEx, d.dok_DoDokNrPelny, d.dok_NrPelnyOryg,
            d.dok_WartNetto, d.dok_DataWyst
     FROM dok__Dokument d
     WHERE d.dok_Typ = 16 AND d.dok_Status >= 0
       AND d.dok_NrPelny LIKE 'ZK 37%/07/2026'
       AND TRY_CAST(REPLACE(REPLACE(d.dok_NrPelny, 'ZK ', ''), '/07/2026', '') AS int) >= 3750
     ORDER BY TRY_CAST(REPLACE(REPLACE(d.dok_NrPelny, 'ZK ', ''), '/07/2026', '') AS int)"
);

$byStatus = array(5=>0,6=>0,7=>0,8=>0);
if (is_array($rows)) {
    foreach ($rows as $r) {
        $st = (int)($r['dok_Status'] ?? 0);
        if (isset($byStatus[$st])) $byStatus[$st]++;
        $nr = (int) preg_replace('/\D/', '', explode('/', $r['dok_NrPelny'] ?? '')[0] ?? '');
        $mark = '';
        if ($nr == 3757) $mark = ' <<<< START PROBLEM';
        if ($st === 6 && stripos((string)($r['dok_DoDokNrPelny'] ?? ''), 'WZ') === 0) {
            $mark .= ' [BUG: WZ+status6]';
        }
        if ($nr >= 3750 && $nr <= 3790) {
            echo ($r['dok_NrPelny'] ?? '')
                . ' | st=' . $st
                . ' ex=' . ($r['dok_StatusEx'] ?? '')
                . ' | do=' . ($r['dok_DoDokNrPelny'] ?? '-')
                . ' | oryg=' . ($r['dok_NrPelnyOryg'] ?? '')
                . $mark . "\n";
        }
    }
}
echo "\nPodsumowanie statusów od ZK 3750+:\n";
foreach ($byStatus as $s => $c) {
    echo "  status $s: $c\n";
}

echo "\n=== Bezpośrednio przed 3757 (3740-3756) vs od 3757 ===\n";
foreach (array(
    array(3740, 3756, 'PRZED'),
    array(3757, 3799, 'OD 3757'),
) as $range) {
    list($a, $b, $lab) = $range;
    $stats = MSSql::getInstance()->query(
        "SELECT d.dok_Status, COUNT(*) AS cnt
         FROM dok__Dokument d
         WHERE d.dok_Typ=16 AND d.dok_Status>=0
           AND d.dok_NrPelny LIKE 'ZK %/07/2026'
           AND TRY_CAST(REPLACE(REPLACE(d.dok_NrPelny, 'ZK ', ''), '/07/2026', '') AS int) BETWEEN {$a} AND {$b}
           AND ISNULL(d.dok_DoDokNrPelny,'') LIKE 'WZ%'
         GROUP BY d.dok_Status ORDER BY d.dok_Status"
    );
    echo "$lab (ZK $a-$b z WZ w nagłówku):\n";
    if (is_array($stats)) {
        foreach ($stats as $s) {
            echo '  st=' . ($s['dok_Status'] ?? '') . ' => ' . ($s['cnt'] ?? 0) . "\n";
        }
    }
}
