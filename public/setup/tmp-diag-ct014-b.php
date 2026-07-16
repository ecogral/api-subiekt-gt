<?php
/**
 * Kontynuacja audytu: statusy ZK, stuck 7, skad wzielo sie 3 na CT014-1.
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$c = new Config(CONFIG_INI_FILE);
$c->load();
MSSql::getInstance(array(
    'UID' => $c->getDbUser(),
    'PWD' => $c->getDbUserPass(),
    'Database' => $c->getDatabase(),
), $c->getServer());
$db = MSSql::getInstance();

echo "=== ZK by status (2026) ===\n";
print_r($db->query(
    "SELECT dok_Status, COUNT(*) AS cnt
     FROM dok__Dokument
     WHERE dok_Typ = 16 AND dok_Status >= 0 AND dok_DataWyst >= '2026-01-01'
     GROUP BY dok_Status
     ORDER BY dok_Status"
));

echo "\n=== ZK status 5 ANY (all time) ===\n";
print_r($db->query("SELECT COUNT(*) AS cnt FROM dok__Dokument WHERE dok_Typ = 16 AND dok_Status = 5"));

echo "\n=== ZK status 6 with pozost ===\n";
print_r($db->query(
    "SELECT d.dok_NrPelny, d.dok_StatusEx, d.dok_ZrealizowaneZRezerwacja,
            SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS pozost
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16 AND d.dok_Status = 6
     WHERE p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
     GROUP BY d.dok_NrPelny, d.dok_StatusEx, d.dok_ZrealizowaneZRezerwacja"
));

echo "\n=== Stuck ZK status 7 without WZ (2026) ===\n";
$rows = $db->query(
    "SELECT DISTINCT d.dok_Id, d.dok_NrPelny, d.dok_Status, d.dok_DataWyst
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16 AND d.dok_Status = 7
     WHERE p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
       AND d.dok_DataWyst >= '2026-01-01'
     ORDER BY d.dok_DataWyst DESC"
);
$stuck = 0;
$ok = 0;
foreach ((array) $rows as $r) {
    $id = (int) $r['dok_Id'];
    $ref = (string) $r['dok_NrPelny'];
    if (Order::isOrderStuckWithoutIssueSql($id, $ref)) {
        $stuck++;
        $pos = $db->query(
            "SELECT t.tw_Symbol, p.ob_Ilosc, ISNULL(p.ob_IloscMag, 0) AS wyd,
                    p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) AS pozost
             FROM dok_Pozycja p
             INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
             WHERE p.ob_DokHanId = {$id}
               AND p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001"
        );
        $parts = array();
        foreach ((array) $pos as $p) {
            $parts[] = $p['tw_Symbol'] . '=' . $p['pozost'];
        }
        echo "STUCK {$ref} | " . implode(', ', $parts) . "\n";
    } else {
        $ok++;
    }
}
echo "stuck7={$stuck} with_wz={$ok} total7_pozost=" . count((array) $rows) . "\n";

echo "\n=== ile produktow z st_StanRez>0 mag1 ===\n";
print_r($db->query(
    "SELECT COUNT(*) AS cnt, SUM(st_StanRez) AS suma
     FROM tw_Stan WHERE st_MagId = 1 AND st_StanRez > 0.00001"
));

echo "\n=== Historia CT014-1 lipiec: ZK + WZ (skad moglo byc rez=3) ===\n";
$hist = $db->query(
    "SELECT d.dok_NrPelny, d.dok_Typ, d.dok_Status, d.dok_StatusEx,
            d.dok_ZrealizowaneZRezerwacja, d.dok_DataWyst, d.dok_DoDokNrPelny,
            p.ob_Ilosc, ISNULL(p.ob_IloscMag, 0) AS wyd
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON (d.dok_Id = p.ob_DokHanId OR d.dok_Id = p.ob_DokMagId)
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = 'CT014-1'
       AND d.dok_DataWyst >= '2026-07-01'
       AND d.dok_Typ IN (11, 16)
       AND d.dok_Status >= 0
     ORDER BY d.dok_DataWyst, d.dok_Typ, d.dok_NrPelny"
);
foreach ((array) $hist as $h) {
    $typ = ((int) $h['dok_Typ'] === 16) ? 'ZK' : 'WZ';
    echo sprintf(
        "%s %s status=%s rezFlag=%s qty=%s wyd=%s do=%s data=%s\n",
        $typ,
        $h['dok_NrPelny'],
        $h['dok_Status'],
        $h['dok_ZrealizowaneZRezerwacja'] === null || $h['dok_ZrealizowaneZRezerwacja'] === ''
            ? '-'
            : $h['dok_ZrealizowaneZRezerwacja'],
        $h['ob_Ilosc'],
        $h['wyd'],
        $h['dok_DoDokNrPelny'],
        is_object($h['dok_DataWyst']) ? $h['dok_DataWyst']->format('Y-m-d') : $h['dok_DataWyst']
    );
}

echo "\n=== WZ CT014-1 lipiec (czy towar wyszedl) ===\n";
print_r($db->query(
    "SELECT d.dok_NrPelny, d.dok_Status, d.dok_DataWyst, d.dok_DoDokNrPelny,
            p.ob_Ilosc, p.ob_IloscMag
     FROM dok_Pozycja p
     INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokMagId AND d.dok_Typ = 11
     INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
     WHERE t.tw_Symbol = 'CT014-1' AND d.dok_DataWyst >= '2026-07-01'
     ORDER BY d.dok_DataWyst"
));

echo "\n=== Mismatch vs expected gdyby liczyc tez stuck ZK7 bez WZ ===\n";
// expected = status 5 + status 7 stuck without WZ
$alt = $db->query(
    "SELECT t.tw_Symbol,
            ISNULL(s.st_StanRez, 0) AS current_rez,
            ISNULL(e.expected_rez, 0) AS expected_status5,
            ISNULL(s7.expected7, 0) AS expected_stuck7,
            ISNULL(s.st_StanRez, 0) - ISNULL(e.expected_rez, 0) AS orphan_vs5,
            ISNULL(s.st_StanRez, 0) - ISNULL(e.expected_rez, 0) - ISNULL(s7.expected7, 0) AS orphan_vs5and7
     FROM tw_Stan s
     INNER JOIN tw__Towar t ON t.tw_Id = s.st_TowId
     LEFT JOIN (
         SELECT p.ob_TowId AS product_id,
                SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS expected_rez
         FROM dok_Pozycja p
         INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16 AND d.dok_Status = 5
         WHERE p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
           AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)
         GROUP BY p.ob_TowId
     ) e ON e.product_id = s.st_TowId
     LEFT JOIN (
         SELECT p.ob_TowId AS product_id,
                SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS expected7
         FROM dok_Pozycja p
         INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId AND d.dok_Typ = 16 AND d.dok_Status = 7
         WHERE p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
           AND (p.ob_TowRodzaj IS NULL OR p.ob_TowRodzaj <> 2)
           AND NOT EXISTS (
               SELECT 1 FROM dok__Dokument wz
               WHERE wz.dok_Typ = 11 AND wz.dok_Status >= 0
                 AND (wz.dok_DoDokId = d.dok_Id OR wz.dok_DoDokNrPelny = d.dok_NrPelny)
           )
         GROUP BY p.ob_TowId
     ) s7 ON s7.product_id = s.st_TowId
     WHERE s.st_MagId = 1
       AND ABS(ISNULL(s.st_StanRez, 0) - ISNULL(e.expected_rez, 0)) > 0.00001
     ORDER BY orphan_vs5 DESC"
);

$stillOrphan = 0;
$explainedBy7 = 0;
$sumStill = 0.0;
foreach ((array) $alt as $a) {
    $o7 = (float) $a['orphan_vs5and7'];
    if (abs($o7) > 0.00001) {
        $stillOrphan++;
        $sumStill += $o7;
    } else {
        $explainedBy7++;
    }
}
echo "mismatches_vs_status5=" . count((array) $alt) . "\n";
echo "wyjasnione_przez_stuck7={$explainedBy7}\n";
echo "nadal_orphan_po_stuck7={$stillOrphan} suma_orphan={$sumStill}\n";

echo "\nPrzyklady nadmiarowych (orphan nawet po stuck7):\n";
$shown = 0;
foreach ((array) $alt as $a) {
    if (abs((float) $a['orphan_vs5and7']) <= 0.00001) {
        continue;
    }
    printf(
        "%s rez=%.2f exp5=%.2f stuck7=%.2f orphan_final=%+.2f\n",
        $a['tw_Symbol'],
        (float) $a['current_rez'],
        (float) $a['expected_status5'],
        (float) $a['expected_stuck7'],
        (float) $a['orphan_vs5and7']
    );
    if (++$shown >= 25) {
        break;
    }
}

echo "\nPrzyklady wyjasnione stuck7:\n";
$shown = 0;
foreach ((array) $alt as $a) {
    if (abs((float) $a['orphan_vs5and7']) > 0.00001) {
        continue;
    }
    if ((float) $a['expected_stuck7'] <= 0) {
        continue;
    }
    printf(
        "%s rez=%.2f stuck7=%.2f (OK jesli biznesowo rezerwujemy stuck7)\n",
        $a['tw_Symbol'],
        (float) $a['current_rez'],
        (float) $a['expected_stuck7']
    );
    if (++$shown >= 15) {
        break;
    }
}

echo "\nDONE\n";
