<?php
/**
 * Panel SQL: WZ → FS — skan i naprawa błędu „dokument automatyczny”.
 * http://localhost/api-subiekt-gt/public/setup/panel-wz-invoice.php
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

function resolveWzRef($input, $month, $year)
{
    $input = trim((string) $input);
    if ($input === '') {
        return '';
    }
    if (stripos($input, 'WZ ') === 0 && strpos($input, '/') !== false) {
        return $input;
    }
    $built = Order::buildIssueRefFromSequence($input, (int) $month, (int) $year);
    if ($built !== '' && Order::getIssueDocumentRowByRef($built) !== null) {
        return $built;
    }
    $num = preg_replace('/\D+/', '', $input);
    if ($num !== '') {
        $rows = MSSql::getInstance()->query(
            "SELECT TOP 1 dok_NrPelny FROM dok__Dokument
             WHERE dok_Typ = 11 AND dok_Status >= 0 AND dok_NrPelny LIKE 'WZ {$num}/%'
             ORDER BY dok_DataWyst DESC"
        );
        if (is_array($rows) && !empty($rows[0]['dok_NrPelny'])) {
            return (string) $rows[0]['dok_NrPelny'];
        }
    }

    return $built;
}

$result = null;
$scanTable = null;
$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
$wzInput = isset($_POST['wz']) ? trim((string) $_POST['wz']) : '';
$month = isset($_POST['month']) ? (int) $_POST['month'] : (int) date('n');
$year = isset($_POST['year']) ? (int) $_POST['year'] : (int) date('Y');
$scanMonth = isset($_POST['scan_month']) ? (int) $_POST['scan_month'] : $month;
$scanYear = isset($_POST['scan_year']) ? (int) $_POST['scan_year'] : $year;
$onlyNeedsPrepare = !empty($_POST['only_needs_prepare']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    try {
        initDb($cfg);

        if ($action === 'scan_month' || $action === 'preview_fix_month' || $action === 'apply_fix_month') {
            if ($action === 'scan_month') {
                $result = Order::scanIssuesForInvoicingMonthSql($scanMonth, $scanYear, array(
                    'only_needs_prepare' => $onlyNeedsPrepare,
                ));
                $scanTable = isset($result['issues']) ? $result['issues'] : null;
            } elseif ($action === 'preview_fix_month') {
                $result = Order::batchRepairIssuesForInvoicingMonthSql($scanMonth, $scanYear, false);
            } elseif ($action === 'apply_fix_month') {
                $result = Order::batchRepairIssuesForInvoicingMonthSql($scanMonth, $scanYear, true);
                $after = Order::scanIssuesForInvoicingMonthSql($scanMonth, $scanYear, array(
                    'only_needs_prepare' => $onlyNeedsPrepare,
                ));
                $scanTable = isset($after['issues']) ? $after['issues'] : null;
                $result['summary'] = $after['summary'] ?? ($result['summary_after'] ?? array());
                $result['month'] = $scanMonth;
                $result['year'] = $scanYear;
            }
        } else {
            $issueRef = resolveWzRef($wzInput, $month, $year);
            if ($issueRef === '' || Order::getIssueDocumentRowByRef($issueRef) === null) {
                throw new RuntimeException(
                    'Nie znaleziono WZ — podaj numer (np. 3017) lub pełny WZ 3017/06/2026.'
                );
            }

            if ($action === 'diagnose') {
                $result = Order::diagnoseIssueForInvoicingSql($issueRef);
            } elseif ($action === 'preview_fix') {
                $result = Order::repairIssueForInvoicingSql($issueRef, false);
            } elseif ($action === 'apply_fix') {
                $result = Order::repairIssueForInvoicingSql($issueRef, true);
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
    <title>WZ → FS — panel fakturowania</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Segoe UI, Arial, sans-serif; margin: 0; background: #f4f6f8; color: #1a1a1a; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 1.35rem; margin: 0 0 8px; }
        h2 { font-size: 1.1rem; margin: 0 0 12px; }
        .sub { color: #555; margin-bottom: 12px; font-size: 0.95rem; }
        .nav { margin-bottom: 20px; font-size: 0.9rem; }
        .nav a { color: #2563eb; margin-right: 12px; }
        .card { background: #fff; border: 1px solid #dde3ea; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9rem; }
        input[type=text], input[type=number] {
            width: 100%; padding: 10px 12px; border: 1px solid #c5cdd8; border-radius: 6px;
            font-size: 1rem; margin-bottom: 14px;
        }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }
        button { border: 0; border-radius: 6px; padding: 10px 14px; font-size: 0.92rem; cursor: pointer; font-weight: 600; }
        .btn-secondary { background: #e8edf2; color: #223; }
        .btn-info { background: #2563eb; color: #fff; }
        .btn-ok { background: #15803d; color: #fff; }
        .btn-warn { background: #d97706; color: #fff; }
        pre { background: #0f172a; color: #e2e8f0; padding: 16px; border-radius: 8px; overflow: auto; font-size: 0.82rem; line-height: 1.45; margin: 0; }
        .note { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; padding: 12px 14px; font-size: 0.9rem; margin-bottom: 16px; }
        .note strong { color: #92400e; }
        .summary { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
        .pill { background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 999px; padding: 6px 12px; font-size: 0.85rem; }
        .pill.warn { background: #fff7ed; border-color: #fdba74; }
        .pill.ok { background: #ecfdf5; border-color: #6ee7b7; }
        .table-wrap { overflow: auto; border: 1px solid #dde3ea; border-radius: 8px; max-height: 520px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th, td { padding: 8px 10px; border-bottom: 1px solid #eef2f7; text-align: left; vertical-align: top; }
        th { background: #f8fafc; position: sticky; top: 0; z-index: 1; }
        tr.needs_fix { background: #fff7ed; }
        tr.ready { background: #f8fffb; }
        .tag { display: inline-block; background: #fee2e2; color: #991b1b; border-radius: 4px; padding: 2px 6px; margin: 1px 2px 1px 0; font-size: 0.78rem; }
        .checkline { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; font-size: 0.9rem; }
        .checkline input { width: auto; margin: 0; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>WZ → FS — „dokument automatyczny”</h1>
    <p class="sub">Skan i naprawa WZ, do których GT nie pozwala wystawić faktury (błąd: <em>nie można wystawić faktury do dokumentu automatycznego</em>).</p>
    <p class="nav">
        <a href="panel-zk-wz.php">Panel ZK ↔ WZ</a>
        <a href="panel-fs-kfs.php">Panel FS → KFS</a>
    </p>

    <div class="note">
        <strong>Przyczyna:</strong> po WZ z API nagłówek ZK nadal wskazuje WZ (<code>ZK.dok_DoDokId</code>)
        lub WZ wskazuje ZK (<code>WZ.dok_DoDokId</code>). GT traktuje taki WZ jako automatyczny.<br>
        <strong>Naprawa (SQL, bez Sfery):</strong> otwiera ZK i czyści powiązania nagłówkowe — to samo co
        „Przygotuj do faktury” w panelu ZK. Pozycje (<code>ob_DoId</code>) zostają.<br>
        <strong>Potem w GT:</strong> zamknij okna ZK/WZ (F5) → Magazyn → WZ → Operacje → <em>Wypisz fakturę zwykłą</em>
        (nie „Zrealizuj jako FV” z ZK).
    </div>

    <form method="post" class="card">
        <h2>Pojedyncze WZ</h2>
        <label for="wz">Numer WZ</label>
        <input type="text" id="wz" name="wz" value="<?= h($wzInput) ?>"
               placeholder="np. 3017 lub WZ 3017/06/2026">

        <div class="row">
            <div>
                <label for="month">Miesiąc</label>
                <input type="number" id="month" name="month" min="1" max="12" value="<?= (int) $month ?>">
            </div>
            <div>
                <label for="year">Rok</label>
                <input type="number" id="year" name="year" min="2020" max="2099" value="<?= (int) $year ?>">
            </div>
        </div>

        <div class="actions">
            <button type="submit" name="action" value="diagnose" class="btn-info">Diagnostyka</button>
            <button type="submit" name="action" value="preview_fix" class="btn-secondary">Podgląd naprawy</button>
            <button type="submit" name="action" value="apply_fix" class="btn-ok"
                    onclick="return confirm('Przygotować to WZ do faktury (SQL)?');">
                Przygotuj do faktury (SQL)
            </button>
        </div>
    </form>

    <form method="post" class="card">
        <h2>Skan miesiąca — WZ bez FS</h2>
        <p class="sub" style="margin-top:0">Lista WZ oczekujących na fakturę, które mają blokujące powiązanie ZK.</p>

        <div class="row">
            <div>
                <label for="scan_month">Miesiąc</label>
                <input type="number" id="scan_month" name="scan_month" min="1" max="12" value="<?= (int) $scanMonth ?>">
            </div>
            <div>
                <label for="scan_year">Rok</label>
                <input type="number" id="scan_year" name="scan_year" min="2020" max="2099" value="<?= (int) $scanYear ?>">
            </div>
        </div>

        <div class="checkline">
            <input type="checkbox" id="only_needs_prepare" name="only_needs_prepare" value="1" <?= $onlyNeedsPrepare ? 'checked' : '' ?>>
            <label for="only_needs_prepare" style="margin:0;font-weight:500">Tylko blokujące fakturę</label>
        </div>

        <div class="actions">
            <button type="submit" name="action" value="scan_month" class="btn-info">Skanuj miesiąc</button>
            <button type="submit" name="action" value="preview_fix_month" class="btn-secondary">Podgląd naprawy</button>
            <button type="submit" name="action" value="apply_fix_month" class="btn-warn"
                    onclick="return confirm('Przygotować WSZYSTKIE blokujące WZ w tym miesiącu?');">
                Napraw wszystkie (SQL)
            </button>
        </div>
    </form>

    <?php if (is_array($scanTable) && is_array($result) && !empty($result['summary'])): ?>
    <div class="card">
        <h2>Wynik skanu — <?= h(sprintf('%02d/%d', (int) ($result['month'] ?? $scanMonth), (int) ($result['year'] ?? $scanYear))) ?></h2>
        <?php $s = $result['summary']; ?>
        <div class="summary">
            <span class="pill">WZ bez FS: <strong><?= (int) ($s['total_wz'] ?? 0) ?></strong></span>
            <span class="pill warn">blokuje fakturę: <strong><?= (int) ($s['needs_prepare'] ?? 0) ?></strong></span>
            <span class="pill ok">gotowe: <strong><?= (int) ($s['ready'] ?? 0) ?></strong></span>
            <span class="pill">WZa podtyp: <strong><?= (int) ($s['wza_podtyp'] ?? 0) ?></strong></span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>WZ</th>
                    <th>ZK</th>
                    <th>ZK status</th>
                    <th>WZ→</th>
                    <th>WZ oryg</th>
                    <th>Status</th>
                    <th>Flagi</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($scanTable as $row): ?>
                    <tr class="<?= !empty($row['needs_prepare']) ? 'needs_fix' : 'ready' ?>">
                        <td><?= h($row['wz_ref'] ?? '') ?></td>
                        <td><?= h($row['zk_ref'] !== '' ? $row['zk_ref'] : '—') ?></td>
                        <td><?= h($row['zk_status'] ?? '—') ?></td>
                        <td><?= h($row['wz_do_ref'] !== '' ? $row['wz_do_ref'] : '—') ?></td>
                        <td><?= h($row['wz_oryg'] !== '' ? $row['wz_oryg'] : '—') ?></td>
                        <td><?= !empty($row['needs_prepare']) ? 'blokuje' : 'OK' ?></td>
                        <td>
                            <?php if (!empty($row['flags'])): ?>
                                <?php foreach ($row['flags'] as $flag): ?>
                                    <span class="tag"><?= h($flag) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($jsonResult !== ''): ?>
    <div class="card">
        <label>Szczegóły (JSON)</label>
        <pre><?= h($jsonResult) ?></pre>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
