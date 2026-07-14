<?php
/**
 * Audyt ZK/WZ/rezerwacji za bieżący miesiąc (read-only SQL + Order helpers).
 * Uruchom: php public/setup/audit-month-zk.php [month] [year]
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$month = isset($argv[1]) ? (int) $argv[1] : (int) date('n');
$year = isset($argv[2]) ? (int) $argv[2] : (int) date('Y');
$month = max(1, min(12, $month));

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$warehouseId = (int) $cfg->getWarehouse();
if ($warehouseId <= 0) {
    $warehouseId = 1;
}

MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

$dateFrom = sprintf('%04d-%02d-01', $year, $month);
$dateTo = date('Y-m-t', strtotime($dateFrom));

echo "=== AUDYT ZK/WZ/REZERWACJE {$month}/{$year} ({$dateFrom} .. {$dateTo}) ===\n\n";

$db = MSSql::getInstance();

// --- 1. Otwarte ZK w miesiącu ---
$openRows = $db->query(
    "SELECT zk.dok_Id, zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx,
            zk.dok_ZrealizowaneZRezerwacja, zk.dok_DoDokId, zk.dok_DoDokNrPelny, zk.dok_DataWyst
     FROM dok__Dokument zk
     WHERE zk.dok_Typ = 16
       AND zk.dok_Status >= 0
       AND zk.dok_Status IN (5, 6, 7)
       AND zk.dok_DataWyst >= '{$dateFrom}'
       AND zk.dok_DataWyst <= '{$dateTo} 23:59:59'
     ORDER BY zk.dok_Id ASC"
);
if (!is_array($openRows) || (isset($openRows[0]['SQLSTATE']))) {
    echo "Błąd SQL (open ZK): " . json_encode($openRows, JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

$problems = array(
    'open_with_wz_not_closed' => array(),
    'open_full_coverage_not_closed' => array(),
    'open_with_orphan_wz' => array(),
    'open_wz_mismatch' => array(),
    'open_status6_reservation' => array(),
    'closed_with_reservation_flag' => array(),
    'open_no_wz_with_goods_remaining' => array(),
);

$stats = array(
    'open_total' => count($openRows),
    'open_status_5' => 0,
    'open_status_6' => 0,
    'open_status_7' => 0,
);

foreach ($openRows as $row) {
    $orderId = (int) ($row['dok_Id'] ?? 0);
    $ref = trim((string) ($row['dok_NrPelny'] ?? ''));
    $status = (int) ($row['dok_Status'] ?? 0);
    $statusEx = (int) ($row['dok_StatusEx'] ?? 0);
    $rezFlag = !empty($row['dok_ZrealizowaneZRezerwacja']);
    $headerWz = trim((string) ($row['dok_DoDokNrPelny'] ?? ''));

    if ($status === 5) {
        $stats['open_status_5']++;
    } elseif ($status === 6) {
        $stats['open_status_6']++;
    } elseif ($status === 7) {
        $stats['open_status_7']++;
    }

    $allIssues = Order::getIssueRefsForOrder($ref, $orderId);
    $coverage = Order::isOrderFullyCoveredByLinkedIssuesSql($orderId, $ref);
    $validIssues = array();
    foreach ($allIssues as $issueRef) {
        if (Order::isIssueDocumentLinkedToOrder($issueRef, $orderId, $ref)) {
            $validIssues[] = $issueRef;
        }
    }

    // Orphan WZ (bez ob_DoId, pełne ilości)
    $orphanSql = "SELECT wz.dok_NrPelny, wz.dok_Id
                  FROM dok__Dokument wz
                  WHERE wz.dok_Typ = 11 AND wz.dok_Status >= 0
                    AND wz.dok_Id BETWEEN {$orderId} AND {$orderId} + 15
                    AND EXISTS (SELECT 1 FROM dok_Pozycja wp WHERE wp.ob_DokMagId = wz.dok_Id)
                    AND NOT EXISTS (
                        SELECT 1 FROM dok_Pozycja wp
                        INNER JOIN dok_Pozycja zk ON zk.ob_Id = wp.ob_DoId AND zk.ob_DokHanId = {$orderId}
                        WHERE wp.ob_DokMagId = wz.dok_Id
                    )";
    $orphanCandidates = $db->query($orphanSql);
    $orphans = array();
    if (is_array($orphanCandidates)) {
        foreach ($orphanCandidates as $oc) {
            $wzRef = trim((string) ($oc['dok_NrPelny'] ?? ''));
            $wzId = (int) ($oc['dok_Id'] ?? 0);
            if ($wzRef !== '' && Order::isSingleIssueCoveringOrderSql($orderId, $wzId)) {
                $orphans[] = $wzRef;
            }
        }
    }

    $remainingGoods = Order::getOrderRemainingQtyFromSql($orderId, $ref, true);
    $entry = array(
        'zk' => $ref,
        'status' => $status,
        'status_label' => Order::getOrderStatusLabel($status),
        'status_ex' => $statusEx,
        'header_wz' => $headerWz,
        'rez_flag' => $rezFlag,
        'all_wz' => $allIssues,
        'valid_wz' => $validIssues,
        'coverage' => $coverage,
        'remaining_goods' => $remainingGoods,
        'orphan_wz' => $orphans,
    );

    if (!empty($allIssues) && empty($validIssues)) {
        $problems['open_wz_mismatch'][] = $entry;
    }
    if (!empty($orphans)) {
        $problems['open_with_orphan_wz'][] = $entry;
    }
    if ($coverage && !Order::isOrderStatusFulfilled($status)) {
        $problems['open_full_coverage_not_closed'][] = $entry;
    }
    if (!empty($validIssues) && !Order::isOrderStatusFulfilled($status)) {
        $problems['open_with_wz_not_closed'][] = $entry;
    }
    if ($status === 6 || $rezFlag) {
        $problems['open_status6_reservation'][] = $entry;
    }
    if (empty($allIssues) && empty($orphans) && $remainingGoods > 0.00001 && $status === 5) {
        $problems['open_no_wz_with_goods_remaining'][] = $entry;
    }
}

// --- 2. Zamknięte ZK z flagą rezerwacji (status 7/8 + flaga rezerwacji) ---
$closedRezRows = $db->query(
    "SELECT zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_ZrealizowaneZRezerwacja
     FROM dok__Dokument zk
     WHERE zk.dok_Typ = 16 AND zk.dok_Status >= 0
       AND zk.dok_Status IN (7, 8)
       AND zk.dok_DataWyst >= '{$dateFrom}' AND zk.dok_DataWyst <= '{$dateTo} 23:59:59'
       AND ISNULL(zk.dok_ZrealizowaneZRezerwacja, 0) = 1
     ORDER BY zk.dok_Id ASC"
);
if (is_array($closedRezRows) && !isset($closedRezRows[0]['SQLSTATE'])) {
    foreach ($closedRezRows as $row) {
        $problems['closed_with_reservation_flag'][] = array(
            'zk' => (string) ($row['dok_NrPelny'] ?? ''),
            'status' => (int) ($row['dok_Status'] ?? 0),
            'rez_flag' => !empty($row['dok_ZrealizowaneZRezerwacja']),
        );
    }
}

// --- 3. ZK 7 bez pełnego ptaszka (StatusEx bit 4) ale z WZ ---
$stuck7 = $db->query(
    "SELECT zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_DoDokNrPelny
     FROM dok__Dokument zk
     WHERE zk.dok_Typ = 16 AND zk.dok_Status = 7
       AND (zk.dok_StatusEx & 4) = 0
       AND zk.dok_DataWyst >= '{$dateFrom}' AND zk.dok_DataWyst <= '{$dateTo} 23:59:59'
       AND ISNULL(zk.dok_DoDokNrPelny, '') <> ''
     ORDER BY zk.dok_Id ASC"
);

// --- 4. Globalne rozjazdy rezerwacji magazynowych ---
$rezMismatches = Order::findStockReservationMismatchesSql($warehouseId);
$rezOnJulyProducts = array();
if (!empty($rezMismatches)) {
    $symbolsInJuly = $db->query(
        "SELECT DISTINCT t.tw_Symbol
         FROM dok__Dokument zk
         INNER JOIN dok_Pozycja p ON p.ob_DokHanId = zk.dok_Id
         INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
         WHERE zk.dok_Typ = 16 AND zk.dok_Status IN (5, 6)
           AND zk.dok_DataWyst >= '{$dateFrom}' AND zk.dok_DataWyst <= '{$dateTo} 23:59:59'"
    );
    $julySymbols = array();
    if (is_array($symbolsInJuly)) {
        foreach ($symbolsInJuly as $sr) {
            $sym = trim((string) ($sr['tw_Symbol'] ?? ''));
            if ($sym !== '') {
                $julySymbols[$sym] = true;
            }
        }
    }
    foreach ($rezMismatches as $m) {
        if (!empty($julySymbols[$m['symbol'] ?? ''])) {
            $rezOnJulyProducts[] = $m;
        }
    }
}

// --- 5. Podsumowanie domkniętych lipca ---
$closedStats = $db->query(
    "SELECT dok_Status, COUNT(*) AS cnt
     FROM dok__Dokument
     WHERE dok_Typ = 16 AND dok_Status >= 0
       AND dok_DataWyst >= '{$dateFrom}' AND dok_DataWyst <= '{$dateTo} 23:59:59'
     GROUP BY dok_Status ORDER BY dok_Status"
);
if (!is_array($closedStats) || (isset($closedStats[0]['SQLSTATE']))) {
    echo "Błąd SQL (statystyki): " . json_encode($closedStats, JSON_UNESCAPED_UNICODE) . "\n";
    $closedStats = array();
}

echo "--- Statystyki ZK w miesiącu ---\n";
print_r($closedStats);

echo "\n--- Otwarte ZK (5/6/7): {$stats['open_total']} ";
echo "(5={$stats['open_status_5']}, 6={$stats['open_status_6']}, 7={$stats['open_status_7']})\n";

function print_problem_section($title, array $items, $limit = 30)
{
    echo "\n=== {$title} (" . count($items) . ") ===\n";
    if (empty($items)) {
        echo "  (brak)\n";
        return;
    }
    $shown = array_slice($items, 0, $limit);
    foreach ($shown as $item) {
        echo '  - ' . json_encode($item, JSON_UNESCAPED_UNICODE) . "\n";
    }
    if (count($items) > $limit) {
        echo '  ... +' . (count($items) - $limit) . " więcej\n";
    }
}

print_problem_section(
    'KRYTYCZNE: otwarte ZK + pełne pokrycie WZ, ale nie domknięte',
    $problems['open_full_coverage_not_closed']
);
print_problem_section(
    'KRYTYCZNE: otwarte ZK + osierocony WZ (brak ob_DoId)',
    $problems['open_with_orphan_wz']
);
print_problem_section(
    'OTWARTE ZK + WZ powiązany, ale status nie 7/8',
    $problems['open_with_wz_not_closed'],
    20
);
print_problem_section(
    'WZ istnieje, ale nie pasuje do ZK (mismatch)',
    $problems['open_wz_mismatch']
);
print_problem_section(
    'Status 6 / flaga rezerwacji na otwartym ZK',
    $problems['open_status6_reservation'],
    15
);
print_problem_section(
    'Otwarte ZK (5) bez WZ, pozostałość towaru',
    $problems['open_no_wz_with_goods_remaining'],
    15
);

echo "\n=== ZK status 7 bez ptaszka StatusEx (bit 4) + WZ na nagłówku (" . (is_array($stuck7) ? count($stuck7) : 0) . ") ===\n";
if (is_array($stuck7) && !empty($stuck7)) {
    foreach (array_slice($stuck7, 0, 20) as $row) {
        echo '  - ' . ($row['dok_NrPelny'] ?? '') . ' WZ=' . ($row['dok_DoDokNrPelny'] ?? '') . "\n";
    }
} else {
    echo "  (brak)\n";
}

echo "\n=== Rozjazdy rezerwacji magazynowych (produkty z otwartych ZK lipca): " . count($rezOnJulyProducts) . " ===\n";
foreach (array_slice($rezOnJulyProducts, 0, 25) as $m) {
    echo sprintf(
        "  - %s: stan_rez=%.2f oczekiwane=%.2f orphan=%.2f\n",
        $m['symbol'],
        $m['current_rez'],
        $m['expected_rez'],
        $m['orphan_rez']
    );
}

$totalIssues = count($problems['open_full_coverage_not_closed'])
    + count($problems['open_with_orphan_wz'])
    + count($problems['open_wz_mismatch']);

echo "\n--- PODSUMOWANIE PROBLEMÓW DO NAPRAWY: {$totalIssues} ZK z błędami WZ/linków ---\n";
echo "Rezerwacje magazynowe (produkty lipca): " . count($rezOnJulyProducts) . " symboli z rozjazdem\n";
