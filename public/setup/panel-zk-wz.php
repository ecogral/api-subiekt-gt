<?php
/**
 * Panel SQL: diagnostyka / odpinanie WZ od ZK bez Sfery COM.
 * http://localhost/api-subiekt-gt/public/setup/panel-zk-wz.php
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();

function initDb($cfg)
{
    MSSql::getInstance(array(
        'UID' => $cfg->getDbUser(),
        'PWD' => $cfg->getDbUserPass(),
        'Database' => $cfg->getDatabase(),
    ), $cfg->getServer());
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function resolveOrderRef($zkInput, $month, $year)
{
    $zkInput = trim((string) $zkInput);
    if ($zkInput === '') {
        return '';
    }
    if (stripos($zkInput, 'ZK ') === 0) {
        return $zkInput;
    }
    return Order::buildOrderRefFromSequence($zkInput, (int) $month, (int) $year);
}

function resolveIssueRefs($wzInput, $orderRef, $orderId, $month, $year)
{
    $wzInput = trim((string) $wzInput);
    if ($wzInput === '') {
        return array();
    }
    return Order::resolveIssueRefsInput($orderRef, $orderId, array($wzInput), (int) $month, (int) $year);
}

$result = null;
$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
$zkInput = isset($_POST['zk']) ? trim((string) $_POST['zk']) : '';
$wzInput = isset($_POST['wz']) ? trim((string) $_POST['wz']) : '';
$month = isset($_POST['month']) ? (int) $_POST['month'] : (int) date('n');
$year = isset($_POST['year']) ? (int) $_POST['year'] : (int) date('Y');
$dateFrom = isset($_POST['date_from']) ? trim((string) $_POST['date_from']) : '2026-07-01';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    try {
        initDb($cfg);

        if ($action === 'repair_bulk_preview') {
            $dateFrom = isset($_POST['date_from']) ? trim((string) $_POST['date_from']) : '2026-07-01';
            $result = Order::repairStuckFulfilledOrdersBulkSql($dateFrom, true);
        } elseif ($action === 'repair_bulk_apply') {
            $dateFrom = isset($_POST['date_from']) ? trim((string) $_POST['date_from']) : '2026-07-01';
            $result = Order::repairStuckFulfilledOrdersBulkSql($dateFrom, false);
        } else {
            $orderRef = resolveOrderRef($zkInput, $month, $year);
            if ($orderRef === '') {
                throw new RuntimeException('Podaj numer ZK (np. 3396) lub pełny numer (ZK 3396/06/2026).');
            }

            $zkRow = Order::getOrderRowByRefSql($orderRef);
            $orderId = $zkRow !== null ? (int) ($zkRow['dok_Id'] ?? 0) : 0;
            $issueRefs = resolveIssueRefs($wzInput, $orderRef, $orderId, $month, $year);

            if ($action === 'diagnose') {
            $diagIssue = !empty($issueRefs) ? $issueRefs[0] : ($wzInput !== '' ? $wzInput : null);
            $result = Order::diagnoseOrderIssueLinkSql($orderRef, $diagIssue);
        } elseif ($action === 'preview_unlink') {
            $zkRow = Order::getOrderRowByRefSql($orderRef);
            if ($zkRow === null) {
                throw new RuntimeException('Nie znaleziono ZK: ' . $orderRef);
            }
            $orderId = (int) ($zkRow['dok_Id'] ?? 0);
            $allIssues = !empty($issueRefs)
                ? $issueRefs
                : Order::getIssueRefsForOrder($orderRef, $orderId);
            $preview = array();
            foreach ($allIssues as $ref) {
                $wzRow = Order::getIssueDocumentRowByRef($ref);
                $wzId = $wzRow !== null ? (int) ($wzRow['dok_Id'] ?? 0) : 0;
                $preview[] = array(
                    'issue_ref' => $ref,
                    'wz_status' => $wzRow !== null ? (int) ($wzRow['dok_Status'] ?? -1) : null,
                    'blocking_documents' => $wzId > 0
                        ? Order::findBlockingDocumentsForIssueSql($wzId, $orderId)
                        : array(),
                );
            }
            $result = array(
                'state' => 'preview',
                'order_ref' => $orderRef,
                'state_before' => (int) ($zkRow['dok_Status'] ?? 0),
                'status_label' => Order::getOrderStatusLabel((int) ($zkRow['dok_Status'] ?? 0)),
                'would_unlink' => $allIssues,
                'issues' => $preview,
                'message' => 'Podgląd — nic nie zostało zmienione.',
            );
        } elseif ($action === 'apply_unlink') {
            $result = Order::resetIssuesAndReopenOrderSql($orderRef, $issueRefs);
        } elseif ($action === 'prepare_invoice') {
            $result = Order::prepareIssueForInvoicingSql($orderRef, $issueRefs);
        } elseif ($action === 'withdraw_wz') {
            if ($wzInput === '') {
                throw new RuntimeException('Podaj numer WZ do wycofania.');
            }
            $withdrawRef = !empty($issueRefs) ? $issueRefs[0] : Order::buildIssueRefFromSequence($wzInput, $month, $year);
            $withdrawn = Order::withdrawIssueDocumentSql($withdrawRef);
            $result = array(
                'state' => $withdrawn ? 'success' : 'error',
                'order_ref' => $orderRef,
                'issue_ref' => $withdrawRef,
                'withdrawn' => $withdrawn,
                'message' => $withdrawn
                    ? 'WZ wycofane (status 0) w SQL. Odśwież listę dokumentów w GT.'
                    : 'Nie udało się wycofać WZ — sprawdź numer lub czy dokument istnieje.',
            );
        } elseif ($action === 'sync_prices') {
            $zkRow = Order::getOrderRowByRefSql($orderRef);
            if ($zkRow === null) {
                throw new RuntimeException('Nie znaleziono ZK: ' . $orderRef);
            }
            $orderId = (int) ($zkRow['dok_Id'] ?? 0);
            $refs = !empty($issueRefs)
                ? $issueRefs
                : Order::getIssueRefsForOrder($orderRef, $orderId);
            if (empty($refs)) {
                throw new RuntimeException('Brak WZ do synchronizacji cen.');
            }
            $synced = Order::syncIssuePricesFromOrderSql($orderId, $refs);
            $result = array(
                'state' => 'success',
                'order_ref' => $orderRef,
                'issue_refs' => $refs,
                'prices_synced' => $synced,
                'message' => 'Ceny skopiowane z ZK na WZ (SQL). Odśwież WZ w GT.',
            );
        } elseif ($action === 'repair_links') {
            $zkRow = Order::getOrderRowByRefSql($orderRef);
            if ($zkRow === null) {
                throw new RuntimeException('Nie znaleziono ZK: ' . $orderRef);
            }
            $orderId = (int) ($zkRow['dok_Id'] ?? 0);
            $refs = !empty($issueRefs)
                ? $issueRefs
                : Order::getIssueRefsForOrder($orderRef, $orderId);
            $repair = Order::repairWzToOrderPositionLinksSql($orderId, $refs, $orderRef);
            $result = array(
                'state' => 'success',
                'order_ref' => $orderRef,
                'issue_refs' => $refs,
                'repair' => $repair,
                'zk_do_dok_nr' => trim((string) ($zkRow['dok_DoDokNrPelny'] ?? '')),
                'message' => 'Naprawiono powiązania pozycji WZ↔ZK (ob_DoId, ceny). '
                    . 'Nagłówek ZK (kolumna WZ na liście) NIE jest zmieniany — użyj „Powiąż WZ na ZK”.',
            );
        } elseif ($action === 'link_zk_header') {
            if (empty($issueRefs)) {
                throw new RuntimeException('Podaj numer WZ — bez niego nie ustawisz nagłówka ZK.');
            }
            $result = Order::linkOrderHeaderToIssueForOrderSql($orderRef, $issueRefs, false);
        } elseif ($action === 'link_zk_header_close') {
            if (empty($issueRefs)) {
                throw new RuntimeException('Podaj numer WZ.');
            }
            $result = Order::linkOrderHeaderToIssueForOrderSql($orderRef, $issueRefs, true);
        } elseif ($action === 'repair_checkmark') {
            $result = Order::repairOrderFulfilledCheckmarkSql($orderRef);
        } else {
            throw new RuntimeException('Nieznana akcja.');
        }
        }
    } catch (Throwable $e) {
        $result = array(
            'state' => 'error',
            'message' => $e->getMessage(),
        );
    }
}

$jsonResult = $result !== null
    ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    : '';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ZK ↔ WZ — panel SQL (bez Sfery)</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Segoe UI, Arial, sans-serif; margin: 0; background: #f4f6f8; color: #1a1a1a; }
        .wrap { max-width: 920px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 1.35rem; margin: 0 0 8px; }
        .sub { color: #555; margin-bottom: 20px; font-size: 0.95rem; }
        .card { background: #fff; border: 1px solid #dde3ea; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9rem; }
        input[type=text], input[type=number] {
            width: 100%; padding: 10px 12px; border: 1px solid #c5cdd8; border-radius: 6px;
            font-size: 1rem; margin-bottom: 14px;
        }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 600px) { .row { grid-template-columns: 1fr; } }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }
        button {
            border: 0; border-radius: 6px; padding: 10px 14px; font-size: 0.92rem;
            cursor: pointer; font-weight: 600;
        }
        .btn-secondary { background: #e8edf2; color: #223; }
        .btn-info { background: #2563eb; color: #fff; }
        .btn-warn { background: #d97706; color: #fff; }
        .btn-danger { background: #b91c1c; color: #fff; }
        .btn-ok { background: #15803d; color: #fff; }
        pre {
            background: #0f172a; color: #e2e8f0; padding: 16px; border-radius: 8px;
            overflow: auto; font-size: 0.82rem; line-height: 1.45; margin: 0;
        }
        .note { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; padding: 12px 14px; font-size: 0.9rem; }
        .note strong { color: #92400e; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>ZK ↔ WZ — panel SQL</h1>
    <p class="sub">Działa <strong>bez Sfery COM</strong> — nie zużywa licencji stanowiska. Po odpięciu usuń WZ ręcznie w Subiekcie GT.
        <a href="panel-zk-wz-stale.php">Panel błędnych nagłówków ZK→WZ (Sfera)</a>
        · <a href="panel-fs-kfs.php">Panel FS → KFS (korekta faktury)</a>
        · <a href="panel-wz-invoice.php">Panel WZ → FS (dokument automatyczny)</a>
        · <a href="panel-stany-rezerwacja.php">Panel rezerwacji magazynowych</a></p>

    <div class="note">
        <strong>Odpięcie WZ:</strong> czyści powiązania w bazie (nagłówek ZK, <code>ob_DoId</code>, itd.)
        i otwiera ZK (status 5/6). <strong>Zamknij okna ZK i WZ w GT</strong> (wyjdź z dokumentu / F5), potem usuń WZ.
        <strong>Napraw powiązania</strong> = tylko pozycje (<code>ob_DoId</code>, ceny) — <em>nie</em> ustawia numeru WZ na liście ZK.
        <strong>Powiąż WZ na ZK</strong> = ustawia <code>dok_DoDokNrPelny</code> (kolumna WZ przy ZK w GT).<br>
        <strong>Faktura do WZ:</strong> jeśli GT pisze „dokument wystawiony automatycznie” — użyj
        <strong>Przygotuj do faktury</strong>, potem w GT: Magazyn → WZ → Operacje → <em>Wypisz fakturę zwykłą</em>
        (nie „Zrealizuj jako FV” z poziomu ZK).
    </div>

    <form method="post" class="card">
        <label for="zk">Numer ZK</label>
        <input type="text" id="zk" name="zk" value="<?= h($zkInput) ?>"
               placeholder="np. 3396 lub ZK 3396/06/2026">

        <label for="wz">Numer WZ (opcjonalnie — puste = wszystkie powiązane)</label>
        <input type="text" id="wz" name="wz" value="<?= h($wzInput) ?>"
               placeholder="np. 3013 lub WZ 3013/06/2026">

        <div class="row">
            <div>
                <label for="month">Miesiąc (gdy podajesz sam numer ZK)</label>
                <input type="number" id="month" name="month" min="1" max="12" value="<?= (int) $month ?>">
            </div>
            <div>
                <label for="year">Rok</label>
                <input type="number" id="year" name="year" min="2020" max="2099" value="<?= (int) $year ?>">
            </div>
        </div>

        <div class="actions">
            <button type="submit" name="action" value="diagnose" class="btn-info">Diagnostyka</button>
            <button type="submit" name="action" value="preview_unlink" class="btn-secondary">Podgląd odpięcia</button>
            <button type="submit" name="action" value="apply_unlink" class="btn-danger"
                    onclick="return confirm('Odpiąć WZ od ZK w bazie SQL?');">Odepnij WZ (SQL)</button>
            <button type="submit" name="action" value="withdraw_wz" class="btn-warn"
                    onclick="return confirm('Wycofać WZ w SQL (status 0)? Najpierw odepnij od ZK.');">Wycofaj WZ</button>
            <button type="submit" name="action" value="prepare_invoice" class="btn-ok">Przygotuj do faktury</button>
            <button type="submit" name="action" value="sync_prices" class="btn-ok">Uzupełnij ceny WZ z ZK</button>
            <button type="submit" name="action" value="repair_links" class="btn-warn">Napraw powiązania (pozycje)</button>
            <button type="submit" name="action" value="link_zk_header" class="btn-ok">Powiąż WZ na ZK</button>
            <button type="submit" name="action" value="link_zk_header_close" class="btn-info"
                    onclick="return confirm('Powiązać WZ i ustawić ZK jako zrealizowane (7/8)?');">Powiąż + domknij ZK</button>
            <button type="submit" name="action" value="repair_checkmark" class="btn-ok"
                    title="Status 7/8 ale szary ptaszek — ustaw StatusEx bit 4">Napraw ptaszek ZK</button>
        </div>

        <label for="date_from" style="margin-top:16px">Masowa naprawa ZK 7→8 (data od)</label>
        <input type="text" id="date_from" name="date_from" value="<?= h($dateFrom) ?>"
               placeholder="2026-07-01">
        <div class="actions">
            <button type="submit" name="action" value="repair_bulk_preview" class="btn-secondary"
                    formnovalidate>Podgląd masowej naprawy (7→8)</button>
            <button type="submit" name="action" value="repair_bulk_apply" class="btn-ok"
                    formnovalidate
                    onclick="return confirm('Promować wszystkie ZK 7→8 z pełnym WZ od podanej daty?');">
                Napraw masowo (7→8)
            </button>
        </div>
    </form>

    <?php if ($jsonResult !== ''): ?>
    <div class="card">
        <label>Wynik</label>
        <pre><?= h($jsonResult) ?></pre>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
