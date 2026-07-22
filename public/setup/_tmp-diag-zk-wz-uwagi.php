<?php
/**
 * ZK GAMA 3734-3737: WZ po uwagach / adresie, stany, powiązania.
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

$zkNums = array(3734, 3735, 3736, 3737);
$month = 7;
$year = 2026;
$warehouseId = 1;

function normText($s)
{
    $s = trim((string) $s);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/\s+/u', ' ', $s);
    return mb_strtolower($s, 'UTF-8');
}

function excerpt($s, $len = 120)
{
    $s = utf8_sanitize($s);
    $s = trim((string) $s);
    if (mb_strlen($s, 'UTF-8') <= $len) {
        return $s;
    }
    return mb_substr($s, 0, $len, 'UTF-8') . '…';
}

function utf8_sanitize($s)
{
    $s = (string) $s;
    if ($s === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        if ($fixed !== false) {
            return $fixed;
        }
        $fixed = @iconv('Windows-1250', 'UTF-8//IGNORE', $s);
        if ($fixed !== false) {
            return $fixed;
        }
    }

    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
}

function array_utf8_deep($data)
{
    if (is_array($data)) {
        $out = array();
        foreach ($data as $k => $v) {
            $out[is_string($k) ? utf8_sanitize($k) : $k] = array_utf8_deep($v);
        }

        return $out;
    }
    if (is_string($data)) {
        return utf8_sanitize($data);
    }

    return $data;
}

function row_utf8($row)
{
    return array_utf8_deep($row);
}

$report = array('orders' => array(), 'wz_by_uwagi_hits' => array());

foreach ($zkNums as $num) {
    $orderRef = Order::buildOrderRefFromSequence((string) $num, $month, $year);
    $zk = Order::getOrderRowByRefSql($orderRef);
    if ($zk === null) {
        $report['orders'][$orderRef] = array('error' => 'not_found');
        continue;
    }

    $orderId = (int) ($zk['dok_Id'] ?? 0);
    $fullZk = MSSql::getInstance()->query(
        "SELECT dok_Id, dok_NrPelny, dok_NrPelnyOryg, dok_Status, dok_StatusEx,
                dok_DoDokId, dok_DoDokNrPelny, dok_WartNetto, dok_PlatnikId,
                dok_Uwagi, dok_UwagiExt, dok_DataWyst
         FROM dok__Dokument WHERE dok_Id = {$orderId}"
    );
    $zkRow = is_array($fullZk) && !empty($fullZk) ? $fullZk[0] : $zk;

    $positions = MSSql::getInstance()->query(
        "SELECT p.ob_Id, p.ob_TowId, tw.tw_Symbol, p.ob_Ilosc, p.ob_IloscMag,
                p.ob_CenaNetto, p.ob_WartNetto
         FROM dok_Pozycja p
         LEFT JOIN tw__Towar tw ON tw.tw_Id = p.ob_TowId
         WHERE p.ob_DokHanId = {$orderId}
         ORDER BY p.ob_Id"
    );

    $issuedFromPos = MSSql::getInstance()->query(
        "SELECT DISTINCT wz.dok_NrPelny, wz.dok_Id, wz.dok_NrPelnyOryg, wz.dok_Status,
                wz.dok_Uwagi, wz.dok_DataWyst, SUM(wp.ob_Ilosc) AS qty_linked
         FROM dok_Pozycja zk
         INNER JOIN dok_Pozycja wp ON wp.ob_DoId = zk.ob_Id
         INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
         WHERE zk.ob_DokHanId = {$orderId}
         GROUP BY wz.dok_NrPelny, wz.dok_Id, wz.dok_NrPelnyOryg, wz.dok_Status,
                  wz.dok_Uwagi, wz.dok_DataWyst
         ORDER BY wz.dok_Id"
    );

    $valid = Order::getValidIssueRefsForOrderSql($orderId, $orderRef);
    $invalid = Order::getInvalidIssueRefsForOrderSql($orderId, $orderRef);
    $allRefs = Order::getIssueRefsForOrder($orderRef, $orderId);

    $uwagi = utf8_sanitize(trim((string) ($zkRow['dok_Uwagi'] ?? '')));
    $uwagiExt = utf8_sanitize(trim((string) ($zkRow['dok_UwagiExt'] ?? '')));
    $searchBlob = normText($uwagi . ' ' . $uwagiExt);

    // Tokeny adresu (min. 8 znaków, bez samych cyfr)
    $tokens = array();
    $parts = preg_split('/[\s,;]+/u', $uwagi . ' ' . $uwagiExt);
    if (!is_array($parts)) {
        $parts = preg_split('/[\s,;]+/', $uwagi . ' ' . $uwagiExt);
    }
    if (!is_array($parts)) {
        $parts = array();
    }
    foreach ($parts as $part) {
        $part = trim($part);
        if (mb_strlen($part, 'UTF-8') < 8) {
            continue;
        }
        if (preg_match('/^\d+$/', $part)) {
            continue;
        }
        $tokens[] = $part;
    }
    $tokens = array_values(array_unique(array_slice($tokens, 0, 8)));

    $wzHits = array();
    foreach ($tokens as $token) {
        $safe = str_replace("'", "''", $token);
        $like = str_replace(array('%', '_'), array('[%]', '[_]'), $safe);
        $rows = MSSql::getInstance()->query(
            "SELECT TOP 15 wz.dok_Id, wz.dok_NrPelny, wz.dok_NrPelnyOryg, wz.dok_Status,
                    wz.dok_DataWyst, wz.dok_Uwagi, wz.dok_WartNetto
             FROM dok__Dokument wz
             WHERE wz.dok_Typ = 11 AND wz.dok_Status >= 0
               AND wz.dok_DataWyst >= '2026-07-01' AND wz.dok_DataWyst < '2026-08-01'
               AND wz.dok_Uwagi LIKE '%{$like}%'
             ORDER BY wz.dok_Id DESC"
        );
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $ref = trim((string) ($r['dok_NrPelny'] ?? ''));
                if ($ref === '') {
                    continue;
                }
                if (!isset($wzHits[$ref])) {
                    $wzHits[$ref] = array(
                        'wz' => row_utf8($r),
                        'matched_tokens' => array(),
                        'linked_to_this_zk' => Order::isIssueDocumentLinkedToOrder($ref, $orderId, $orderRef),
                    );
                }
                $wzHits[$ref]['matched_tokens'][] = $token;
            }
        }
    }

    // B2B w oryginale ZK (np. B2B-595/2026)
    $orygZk = trim((string) ($zkRow['dok_NrPelnyOryg'] ?? ''));
    if ($orygZk !== '') {
        $safeO = str_replace("'", "''", $orygZk);
        $rows = MSSql::getInstance()->query(
            "SELECT wz.dok_Id, wz.dok_NrPelny, wz.dok_NrPelnyOryg, wz.dok_Status,
                    wz.dok_Uwagi, wz.dok_DataWyst
             FROM dok__Dokument wz
             WHERE wz.dok_Typ = 11 AND wz.dok_Status >= 0
               AND (wz.dok_NrPelnyOryg = '{$safeO}'
                    OR wz.dok_Uwagi LIKE '%{$safeO}%')
             ORDER BY wz.dok_Id"
        );
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $ref = trim((string) ($r['dok_NrPelny'] ?? ''));
                if ($ref === '') {
                    continue;
                }
                if (!isset($wzHits[$ref])) {
                    $wzHits[$ref] = array(
                        'wz' => row_utf8($r),
                        'matched_tokens' => array('oryg_zk:' . $orygZk),
                        'linked_to_this_zk' => Order::isIssueDocumentLinkedToOrder($ref, $orderId, $orderRef),
                    );
                }
            }
        }
    }

    $stock = array();
    if (is_array($positions)) {
        foreach ($positions as $p) {
            $towId = (int) ($p['ob_TowId'] ?? 0);
            if ($towId <= 0) {
                continue;
            }
            $st = MSSql::getInstance()->query(
                "SELECT st_Stan, st_StanRez FROM tw_Stan
                 WHERE st_TowId = {$towId} AND st_MagId = {$warehouseId}"
            );
            $stock[] = array(
                'symbol' => $p['tw_Symbol'] ?? '',
                'ordered' => (float) ($p['ob_Ilosc'] ?? 0),
                'ilosc_mag_zk' => (float) ($p['ob_IloscMag'] ?? 0),
                'stan' => is_array($st) && !empty($st) ? (float) ($st[0]['st_Stan'] ?? 0) : null,
                'rez' => is_array($st) && !empty($st) ? (float) ($st[0]['st_StanRez'] ?? 0) : null,
            );
        }
    }

    $report['orders'][$orderRef] = array(
        'zk' => array(
            'id' => $orderId,
            'status' => (int) ($zkRow['dok_Status'] ?? 0),
            'status_label' => Order::getOrderStatusLabel((int) ($zkRow['dok_Status'] ?? 0)),
            'status_ex' => (int) ($zkRow['dok_StatusEx'] ?? 0),
            'oryg' => $orygZk,
            'netto' => (float) ($zkRow['dok_WartNetto'] ?? 0),
            'header_wz' => trim((string) ($zkRow['dok_DoDokNrPelny'] ?? '')),
            'uwagi' => excerpt($uwagi, 200),
            'uwagi_ext' => excerpt($uwagiExt, 200),
        ),
        'positions' => is_array($positions) ? array_map(function ($p) {
            return array(
                'symbol' => $p['tw_Symbol'] ?? '',
                'qty' => (float) ($p['ob_Ilosc'] ?? 0),
                'ilosc_mag' => (float) ($p['ob_IloscMag'] ?? 0),
            );
        }, $positions) : array(),
        'stock_snapshot' => $stock,
        'wz_linked_positions' => $issuedFromPos,
        'wz_refs_all' => $allRefs,
        'wz_valid' => $valid,
        'wz_invalid' => $invalid,
        'wz_found_by_uwagi_tokens' => array_values($wzHits),
        'diag' => Order::diagnoseStaleOrderIssueHeaderLinkSql($orderRef),
    );
}

// Wszystkie lipcowe WZ GAMA (kh_Symbol) z podobnym kontrahentem
$gamaWz = MSSql::getInstance()->query(
    "SELECT wz.dok_Id, wz.dok_NrPelny, wz.dok_NrPelnyOryg, wz.dok_Status,
            wz.dok_WartNetto, wz.dok_DataWyst,
            LEFT(ISNULL(wz.dok_Uwagi, ''), 160) AS uwagi_short,
            k.kh_Symbol
     FROM dok__Dokument wz
     INNER JOIN kh__Kontrahent k ON k.kh_Id = wz.dok_PlatnikId
     WHERE wz.dok_Typ = 11 AND wz.dok_Status >= 0
       AND wz.dok_DataWyst >= '2026-07-01' AND wz.dok_DataWyst < '2026-08-01'
       AND k.kh_Symbol LIKE 'GAMA%'
     ORDER BY wz.dok_Id"
);

$report['gama_wz_july_2026'] = array_map('row_utf8', is_array($gamaWz) ? $gamaWz : array());
$report['summary'] = array(
    'message' => 'Porównaj wz_valid (własne WZ) vs wz_found_by_uwagi (po adresie w uwagach).',
);

$report = array_utf8_deep($report);
$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
$json = json_encode($report, $flags);
if ($json === false) {
    echo json_encode(array('error' => json_last_error_msg()));
    exit(1);
}
echo $json;
