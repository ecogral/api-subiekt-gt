<?php
/**
 * Panel SQL/COM: przygotowanie FS do korekty KFS + dopisanie transportu na WZ.
 * http://localhost/api-subiekt-gt/public/setup/panel-fs-kfs.php
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT;
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

function createOrderApi($cfg)
{
    $subiektGt = SubiektGT::getInstance($cfg);
    $com = $subiektGt->connect();
    $orderApi = new Order($com, array());
    $orderApi->setCfg($cfg);

    return $orderApi;
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function repairOptionsFromPost()
{
    return array(
        'append_services' => !empty($_POST['append_services']),
        'prepare_kfs' => !empty($_POST['prepare_kfs']),
    );
}

$result = null;
$scanTable = null;
$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
$fsInput = isset($_POST['fs']) ? trim((string) $_POST['fs']) : '';
$wzInput = isset($_POST['wz']) ? trim((string) $_POST['wz']) : '';
$month = isset($_POST['month']) ? (int) $_POST['month'] : (int) date('n');
$year = isset($_POST['year']) ? (int) $_POST['year'] : (int) date('Y');
$scanMonth = isset($_POST['scan_month']) ? (int) $_POST['scan_month'] : $month;
$scanYear = isset($_POST['scan_year']) ? (int) $_POST['scan_year'] : $year;
$onlyNeedsFix = !empty($_POST['only_needs_fix']);
$onlyWithWz = !empty($_POST['only_with_wz']);
$appendServices = !isset($_POST['append_services']) || !empty($_POST['append_services']);
$prepareKfs = !isset($_POST['prepare_kfs']) || !empty($_POST['prepare_kfs']);

$monthActions = array('scan_month', 'preview_fix_month', 'apply_fix_month');
$repairActions = array('preview_fix', 'apply_fix', 'preview_fix_month', 'apply_fix_month');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    try {
        initDb($cfg);
        $repairOptions = array(
            'append_services' => $appendServices,
            'prepare_kfs' => $prepareKfs,
        );

        $orderApi = null;
        if (in_array($action, $repairActions, true) && $appendServices) {
            $orderApi = createOrderApi($cfg);
        }

        if (in_array($action, $monthActions, true)) {
            $scanOptions = array(
                'only_needs_fix' => $onlyNeedsFix,
                'only_with_wz' => $onlyWithWz,
            );

            if ($action === 'scan_month') {
                $result = Order::scanSalesInvoicesMonthSql($scanMonth, $scanYear, $scanOptions);
                $scanTable = isset($result['invoices']) ? $result['invoices'] : null;
            } elseif ($action === 'preview_fix_month') {
                $batchOptions = array_merge($repairOptions, array(
                    'only_needs_fix' => $onlyNeedsFix,
                    'only_with_wz' => $onlyWithWz,
                ));
                $result = Order::batchRepairSalesInvoicesMonthSql(
                    $scanMonth,
                    $scanYear,
                    false,
                    $batchOptions,
                    $orderApi
                );
            } elseif ($action === 'apply_fix_month') {
                $batchOptions = array_merge($repairOptions, array(
                    'only_needs_fix' => $onlyNeedsFix,
                    'only_with_wz' => $onlyWithWz,
                ));
                $result = Order::batchRepairSalesInvoicesMonthSql(
                    $scanMonth,
                    $scanYear,
                    true,
                    $batchOptions,
                    $orderApi
                );
                $after = Order::scanSalesInvoicesMonthSql($scanMonth, $scanYear, $scanOptions);
                $scanTable = isset($after['invoices']) ? $after['invoices'] : null;
            }
        } else {
            $invoiceRef = Order::resolveSalesInvoiceRefInput($fsInput, $month, $year, $wzInput);
            if ($invoiceRef === '' && $fsInput === '' && $wzInput !== '') {
                $invoiceRef = Order::resolveSalesInvoiceRefInput('', $month, $year, $wzInput);
            }
            if ($invoiceRef === '') {
                throw new RuntimeException(
                    'Nie znaleziono FS — podaj numer FS (np. 2645), pełny FS 2645/06/2026 lub powiązany WZ (np. 2737).'
                );
            }

            if ($action === 'diagnose') {
                $result = Order::diagnoseSalesInvoiceForCorrectionSql($invoiceRef);
                $svc = Order::findMissingServicesForInvoiceSql($invoiceRef);
                $result['services'] = $svc;
            } elseif ($action === 'preview_fix') {
                $result = Order::repairSalesInvoiceSql(
                    $invoiceRef,
                    array_merge($repairOptions, array('apply' => false)),
                    $orderApi
                );
            } elseif ($action === 'apply_fix') {
                $result = Order::repairSalesInvoiceSql(
                    $invoiceRef,
                    array_merge($repairOptions, array('apply' => true)),
                    $orderApi
                );
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
    <title>FS → KFS — panel SQL</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Segoe UI, Arial, sans-serif; margin: 0; background: #f4f6f8; color: #1a1a1a; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 1.35rem; margin: 0 0 8px; }
        h2 { font-size: 1.1rem; margin: 0 0 12px; }
        .sub { color: #555; margin-bottom: 12px; font-size: 0.95rem; }
        .nav { margin-bottom: 20px; font-size: 0.9rem; }
        .nav a { color: #2563eb; }
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
        .btn-ok { background: #15803d; color: #fff; }
        .btn-warn { background: #d97706; color: #fff; }
        pre {
            background: #0f172a; color: #e2e8f0; padding: 16px; border-radius: 8px;
            overflow: auto; font-size: 0.82rem; line-height: 1.45; margin: 0;
        }
        .note { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; padding: 12px 14px; font-size: 0.9rem; margin-bottom: 16px; }
        .note strong { color: #92400e; }
        .repair-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; }
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
        tr.no_wz { background: #fafafa; }
        .tag { display: inline-block; background: #fee2e2; color: #991b1b; border-radius: 4px; padding: 2px 6px; margin: 1px 2px 1px 0; font-size: 0.78rem; }
        .tag.svc { background: #dbeafe; color: #1e40af; }
        .tag.info { background: #e0e7ff; color: #3730a3; }
        .checkline { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; font-size: 0.9rem; }
        .checkline input { width: auto; margin: 0; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>FS → KFS — naprawa WZ i faktur</h1>
    <p class="sub">Dopisuje brakujący transport (U001) na WZ jak na FS oraz czyści powiązania ZK blokujące korektę.</p>
    <p class="nav">
        <a href="panel-zk-wz.php">← Panel ZK ↔ WZ</a>
        <a href="panel-wz-invoice.php">Panel WZ → FS</a>
    </p>

    <div class="note">
        <strong>Naprawa obejmuje (do wyboru poniżej):</strong><br>
        1. <strong>Transport na WZ</strong> — kopiuje usługi z ZK (np. U001) na WZ przez <strong>Sferę COM</strong> (zużywa licencję GT). Wartość WZ zrównuje się z FS.<br>
        2. <strong>Przygotowanie pod KFS</strong> — SQL: czyści <code>ob_DoId→ZK</code> i <code>WZ.dok_NrPelnyOryg</code> (bez Sfery).<br>
        <strong>Zalecane:</strong> obie opcje włączone. Po naprawie zamknij dokumenty w GT (F5).
    </div>

    <div class="repair-box">
        <div class="checkline">
            <input type="checkbox" id="append_services" name="append_services" value="1" form="form-single" <?= $appendServices ? 'checked' : '' ?>>
            <label for="append_services" style="margin:0;font-weight:600">Dopisz brakujące usługi/transport na WZ (Sfera COM)</label>
        </div>
        <div class="checkline">
            <input type="checkbox" id="prepare_kfs" name="prepare_kfs" value="1" form="form-single" <?= $prepareKfs ? 'checked' : '' ?>>
            <label for="prepare_kfs" style="margin:0;font-weight:600">Przygotuj pod korektę KFS (SQL — czyść powiązania ZK)</label>
        </div>
    </div>

    <form method="post" class="card" id="form-single">
        <h2>Pojedyncza faktura</h2>
        <label for="fs">Numer FS</label>
        <input type="text" id="fs" name="fs" value="<?= h($fsInput) ?>"
               placeholder="np. 2645 lub FS 2645/06/2026">

        <label for="wz">Numer WZ (alternatywa)</label>
        <input type="text" id="wz" name="wz" value="<?= h($wzInput) ?>"
               placeholder="np. 2737 lub WZ 2737/06/2026">

        <div class="row">
            <div>
                <label for="month">Miesiąc (gdy podajesz sam numer)</label>
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
                    onclick="return confirm('Naprawić FS/WZ według zaznaczonych opcji?');">
                Napraw (SQL / COM)
            </button>
        </div>
    </form>

    <form method="post" class="card" id="form-month">
        <input type="hidden" name="append_services" value="<?= $appendServices ? '1' : '' ?>" id="append_services_month">
        <input type="hidden" name="prepare_kfs" value="<?= $prepareKfs ? '1' : '' ?>" id="prepare_kfs_month">

        <h2>Skan całego miesiąca</h2>
        <p class="sub" style="margin-top:0">Lista FS z WZ, brakującym transportem i powiązaniami ZK.</p>

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
            <input type="checkbox" id="only_needs_fix" name="only_needs_fix" value="1" <?= $onlyNeedsFix ? 'checked' : '' ?>>
            <label for="only_needs_fix" style="margin:0;font-weight:500">Tylko wymagające naprawy</label>
        </div>
        <div class="checkline">
            <input type="checkbox" id="only_with_wz" name="only_with_wz" value="1" <?= $onlyWithWz ? 'checked' : '' ?>>
            <label for="only_with_wz" style="margin:0;font-weight:500">Tylko z powiązanym WZ</label>
        </div>

        <div class="actions">
            <button type="submit" name="action" value="scan_month" class="btn-info">Skanuj miesiąc</button>
            <button type="submit" name="action" value="preview_fix_month" class="btn-secondary">Podgląd naprawy miesiąca</button>
            <button type="submit" name="action" value="apply_fix_month" class="btn-warn"
                    onclick="return confirm('Naprawić WSZYSTKIE FS wymagające naprawy w tym miesiącu?');">
                Napraw wszystkie wymagające
            </button>
        </div>
    </form>

    <?php if (is_array($scanTable) && is_array($result) && !empty($result['summary'])): ?>
    <div class="card">
        <h2>Wynik skanu — <?= h(sprintf('%02d/%d', (int) ($result['month'] ?? $scanMonth), (int) ($result['year'] ?? $scanYear))) ?></h2>
        <?php $s = $result['summary']; ?>
        <div class="summary">
            <span class="pill">FS razem: <strong><?= (int) ($s['total_fs'] ?? 0) ?></strong></span>
            <span class="pill">z WZ: <strong><?= (int) ($s['with_wz'] ?? 0) ?></strong></span>
            <span class="pill warn">wymaga naprawy: <strong><?= (int) ($s['needs_fix'] ?? 0) ?></strong></span>
            <span class="pill warn">brak usług: <strong><?= (int) ($s['needs_service_fix'] ?? 0) ?></strong></span>
            <span class="pill warn">KFS linki: <strong><?= (int) ($s['needs_kfs_fix'] ?? 0) ?></strong></span>
            <span class="pill ok">gotowe KFS: <strong><?= (int) ($s['ready_for_kfs'] ?? 0) ?></strong></span>
            <span class="pill">ma KFS: <strong><?= (int) ($s['has_kfs'] ?? 0) ?></strong></span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>FS</th>
                    <th>WZ</th>
                    <th>FS netto</th>
                    <th>Brak na WZ</th>
                    <th>ZK na WZ</th>
                    <th>ob_DoId→ZK</th>
                    <th>KFS</th>
                    <th>Status</th>
                    <th>Uwagi</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($scanTable as $row): ?>
                    <?php
                    $status = (string) ($row['status'] ?? '');
                    $statusLabel = array(
                        'needs_fix' => 'wymaga naprawy',
                        'ready' => 'OK',
                        'no_wz' => 'brak WZ',
                    );
                    ?>
                    <tr class="<?= h($status) ?>">
                        <td><?= h($row['fs_ref'] ?? '') ?></td>
                        <td><?= h(!empty($row['wz_refs']) ? implode(', ', $row['wz_refs']) : '—') ?></td>
                        <td><?= h($row['fs_netto'] ?? '') ?></td>
                        <td><?= h(!empty($row['missing_services']) ? implode(', ', $row['missing_services']) : '—') ?></td>
                        <td><?= h(!empty($row['wz_zk_oryg']) ? implode(', ', $row['wz_zk_oryg']) : '—') ?></td>
                        <td><?= (int) ($row['zk_linked_positions'] ?? 0) ?></td>
                        <td><?= h(!empty($row['kfs_ref']) ? $row['kfs_ref'] : '—') ?></td>
                        <td><?= h($statusLabel[$status] ?? $status) ?></td>
                        <td>
                            <?php if (!empty($row['flags'])): ?>
                                <?php foreach ($row['flags'] as $flag): ?>
                                    <?php
                                    $cls = 'tag';
                                    if (strpos($flag, 'brak usług') === 0) {
                                        $cls .= ' svc';
                                    } elseif ($flag === 'FS > WZ (wartość)') {
                                        $cls .= ' info';
                                    }
                                    ?>
                                    <span class="<?= $cls ?>"><?= h($flag) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
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
<script>
(function () {
    function syncRepairHidden() {
        var append = document.getElementById('append_services');
        var prepare = document.getElementById('prepare_kfs');
        var appendMonth = document.getElementById('append_services_month');
        var prepareMonth = document.getElementById('prepare_kfs_month');
        if (append && appendMonth) {
            appendMonth.value = append.checked ? '1' : '';
        }
        if (prepare && prepareMonth) {
            prepareMonth.value = prepare.checked ? '1' : '';
        }
    }
    ['append_services', 'prepare_kfs'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('change', syncRepairHidden);
        }
    });
    syncRepairHidden();
})();
</script>
</body>
</html>
