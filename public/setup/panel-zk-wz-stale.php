<?php
/**
 * Panel: błędne nagłówki ZK→WZ (cudzy WZ) + odpięcie + własne WZ przez Sferę.
 * http://localhost/api-subiekt-gt/public/setup/panel-zk-wz-stale.php
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

    return array($orderApi, $com);
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

$result = null;
$scanTable = null;
$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
$zkInput = isset($_POST['zk']) ? trim((string) $_POST['zk']) : '';
$zkList = isset($_POST['zk_list']) ? trim((string) $_POST['zk_list']) : '';
$month = isset($_POST['month']) ? (int) $_POST['month'] : (int) date('n');
$year = isset($_POST['year']) ? (int) $_POST['year'] : (int) date('Y');
$scanMonth = isset($_POST['scan_month']) ? (int) $_POST['scan_month'] : $month;
$scanYear = isset($_POST['scan_year']) ? (int) $_POST['scan_year'] : $year;
$onlyStale = !isset($_POST['only_stale']) || !empty($_POST['only_stale']);
$createWz = !isset($_POST['create_wz']) || !empty($_POST['create_wz']);
$closeOrder = !isset($_POST['close_order']) || !empty($_POST['close_order']);
$fullRealization = !isset($_POST['full_realization']) || !empty($_POST['full_realization']);

$comActions = array(
    'apply_unlink',
    'apply_list_unlink',
    'apply_fix_month',
    'reopen_for_wz',
    'reopen_list_for_wz',
    'link_own_header',
    'repair_phantom_oryg',
    'preview_phantom_oryg',
    'apply_fix_create_wz',
    'apply_list_create_wz',
    'apply_fix_month_create_wz',
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    try {
        initDb($cfg);

        $com = null;
        if (in_array($action, $comActions, true)) {
            list($orderApi, $com) = createOrderApi($cfg);
            unset($orderApi);
        }

        if ($action === 'scan_month' || $action === 'preview_fix_month' || $action === 'apply_fix_month'
            || $action === 'apply_fix_month_create_wz') {
            $scanOptions = array('only_stale' => $onlyStale);

            if ($action === 'scan_month') {
                $result = Order::scanStaleOrderIssueHeaderLinksMonthSql($scanMonth, $scanYear, $scanOptions);
                $scanTable = isset($result['orders']) ? $result['orders'] : null;
            } elseif ($action === 'preview_fix_month') {
                $result = Order::batchRepairStaleOrderIssueHeaderLinksMonthSql(
                    $scanMonth,
                    $scanYear,
                    false,
                    $scanOptions
                );
                $scanTable = isset($result['orders']) ? $result['orders'] : null;
            } elseif ($action === 'apply_fix_month') {
                $result = Order::batchRepairStaleOrderIssueHeaderLinksMonthSql(
                    $scanMonth,
                    $scanYear,
                    true,
                    $scanOptions,
                    $com
                );
                $after = Order::scanStaleOrderIssueHeaderLinksMonthSql($scanMonth, $scanYear, $scanOptions);
                $scanTable = isset($after['orders']) ? $after['orders'] : null;
            } else {
                $preview = Order::batchRepairStaleOrderIssueHeaderLinksMonthSql(
                    $scanMonth,
                    $scanYear,
                    false,
                    array('only_stale' => true)
                );
                $targets = isset($preview['orders']) && is_array($preview['orders']) ? $preview['orders'] : array();
                $results = array();
                $ok = 0;
                $partial = 0;
                $fail = 0;
                foreach ($targets as $item) {
                    $ref = trim((string) ($item['order_ref'] ?? ''));
                    if ($ref === '') {
                        continue;
                    }
                    $one = Order::repairStaleOrderIssueHeaderAndCreateWz($com, $ref, array(
                        'create_wz' => $createWz,
                        'close_order' => $closeOrder,
                        'full_realization' => $fullRealization,
                    ));
                    $results[] = $one;
                    $st = (string) ($one['state'] ?? '');
                    if ($st === 'success') {
                        $ok++;
                    } elseif ($st === 'partial') {
                        $partial++;
                    } else {
                        $fail++;
                    }
                }
                $after = Order::scanStaleOrderIssueHeaderLinksMonthSql(
                    $scanMonth,
                    $scanYear,
                    array('only_stale' => true)
                );
                $scanTable = isset($after['orders']) ? $after['orders'] : null;
                $result = array(
                    'state' => 'success',
                    'month' => $scanMonth,
                    'year' => $scanYear,
                    'ok' => $ok,
                    'partial' => $partial,
                    'failed' => $fail,
                    'results' => $results,
                    'summary_after' => $after['summary'] ?? array(),
                    'message' => 'Sfera: OK=' . $ok . ', częściowe=' . $partial . ', błędy=' . $fail
                        . '. Pozostało błędnych: ' . (int) ($after['summary']['stale'] ?? 0) . '.',
                );
            }
        } elseif ($action === 'apply_list_unlink' || $action === 'apply_list_create_wz') {
            $refs = array();
            if ($zkList !== '') {
                foreach (preg_split('/[\s,;]+/', $zkList) as $part) {
                    $ref = resolveOrderRef($part, $month, $year);
                    if ($ref !== '') {
                        $refs[] = $ref;
                    }
                }
            }
            $refs = array_values(array_unique($refs));
            if (empty($refs)) {
                throw new RuntimeException('Podaj listę numerów ZK (np. 3734, 3735, 3736, 3737).');
            }

            $results = array();
            $ok = 0;
            $partial = 0;
            $fail = 0;
            foreach ($refs as $ref) {
                if ($action === 'apply_list_unlink') {
                    $one = Order::repairStaleOrderIssueHeaderLinkSql($ref, true, $com);
                } else {
                    $one = Order::repairStaleOrderIssueHeaderAndCreateWz($com, $ref, array(
                        'create_wz' => $createWz,
                        'close_order' => $closeOrder,
                        'full_realization' => $fullRealization,
                    ));
                }
                $results[] = $one;
                $st = (string) ($one['state'] ?? '');
                if ($st === 'success' || $st === 'noop') {
                    $ok++;
                } elseif ($st === 'partial') {
                    $partial++;
                } else {
                    $fail++;
                }
            }

            $result = array(
                'state' => $fail > 0 && $ok === 0 ? 'error' : 'success',
                'ok' => $ok,
                'partial' => $partial,
                'failed' => $fail,
                'results' => $results,
                'message' => 'Przetworzono ' . count($refs) . ' ZK — OK/noop=' . $ok
                    . ', częściowe=' . $partial . ', błędy=' . $fail . '.',
            );
        } elseif ($action === 'reopen_list_for_wz') {
            $refs = array();
            if ($zkList !== '') {
                foreach (preg_split('/[\s,;]+/', $zkList) as $part) {
                    $ref = resolveOrderRef($part, $month, $year);
                    if ($ref !== '') {
                        $refs[] = $ref;
                    }
                }
            }
            $refs = array_values(array_unique($refs));
            if (empty($refs)) {
                throw new RuntimeException('Podaj listę numerów ZK.');
            }
            $results = array();
            $ok = 0;
            $fail = 0;
            foreach ($refs as $ref) {
                $zkRow = Order::getOrderRowByRefSql($ref);
                if ($zkRow === null) {
                    $results[] = array('order_ref' => $ref, 'state' => 'error', 'message' => 'Nie znaleziono ZK.');
                    $fail++;
                    continue;
                }
                $one = Order::reopenOrderAfterStaleHeaderUnlink(
                    (int) ($zkRow['dok_Id'] ?? 0),
                    $ref,
                    $com
                );
                $results[] = $one;
                if (!empty($one['reopened']) || ($one['state'] ?? '') === 'noop') {
                    $ok++;
                } else {
                    $fail++;
                }
            }
            $result = array(
                'state' => $fail > 0 && $ok === 0 ? 'error' : 'success',
                'ok' => $ok,
                'failed' => $fail,
                'results' => $results,
                'message' => 'Otwieranie ZK do WZ: OK/noop=' . $ok . ', błędy=' . $fail . '.',
            );
        } else {
            $orderRef = resolveOrderRef($zkInput, $month, $year);
            if ($orderRef === '') {
                throw new RuntimeException('Podaj numer ZK (np. 3734) lub pełny ZK 3734/07/2026.');
            }

            if ($action === 'diagnose') {
                $result = Order::diagnoseStaleOrderIssueHeaderLinkSql($orderRef);
            } elseif ($action === 'preview_fix') {
                $result = Order::repairStaleOrderIssueHeaderLinkSql($orderRef, false);
            } elseif ($action === 'apply_unlink') {
                $result = Order::repairStaleOrderIssueHeaderLinkSql($orderRef, true, $com);
            } elseif ($action === 'apply_fix_create_wz') {
                $result = Order::repairStaleOrderIssueHeaderAndCreateWz($com, $orderRef, array(
                    'create_wz' => $createWz,
                    'close_order' => $closeOrder,
                    'full_realization' => $fullRealization,
                ));
            } elseif ($action === 'reopen_for_wz') {
                $zkRow = Order::getOrderRowByRefSql($orderRef);
                if ($zkRow === null) {
                    throw new RuntimeException('Nie znaleziono ZK: ' . $orderRef);
                }
                $result = Order::reopenOrderAfterStaleHeaderUnlink(
                    (int) ($zkRow['dok_Id'] ?? 0),
                    $orderRef,
                    $com
                );
            } elseif ($action === 'link_own_header') {
                $result = Order::linkOwnIssueHeaderForOrder($orderRef, $com);
            } elseif ($action === 'preview_phantom_oryg') {
                $result = Order::repairPhantomIssueOrygClaimsForOrderSql($orderRef, false, $com);
            } elseif ($action === 'repair_phantom_oryg') {
                $result = Order::repairPhantomIssueOrygClaimsForOrderSql($orderRef, true, $com);
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
    <title>ZK → WZ — błędne nagłówki (Sfera)</title>
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
        input[type=text], input[type=number], textarea {
            width: 100%; padding: 10px 12px; border: 1px solid #c5cdd8; border-radius: 6px;
            font-size: 1rem; margin-bottom: 14px;
        }
        textarea { min-height: 72px; font-family: Consolas, monospace; }
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
        .btn-danger { background: #b91c1c; color: #fff; }
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
        tr.stale { background: #fff7ed; }
        tr.ok { background: #f8fffb; }
        .tag { display: inline-block; background: #fee2e2; color: #991b1b; border-radius: 4px; padding: 2px 6px; margin: 1px 2px 1px 0; font-size: 0.78rem; }
        .checkline { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; font-size: 0.9rem; }
        .checkline input { width: auto; margin: 0; }
        .checkline label { margin: 0; font-weight: 600; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>ZK → WZ — błędne nagłówki (cudzy WZ)</h1>
    <p class="sub">
        Wykrywa ZK, które mają w kolumnie „Dokument powiąz” <strong>cudze WZ</strong>
        (np. kilka zamówień GAMA wskazujących na jedno WZ). Odpina nagłówek przez <strong>Sferę COM</strong>
        (przy włączonym <code>use_com_writes_only</code> bezpośredni SQL jest zablokowany) i opcjonalnie
        wystawia <strong>własne WZ przez Sferę COM</strong>.
    </p>
    <p class="nav">
        <a href="panel-zk-wz.php">← Panel ZK ↔ WZ</a>
        <a href="panel-wz-invoice.php">Panel WZ → FS</a>
        <a href="panel-fs-kfs.php">Panel FS → KFS</a>
    </p>

    <div class="note">
        <strong>Typowy problem:</strong> API brało sąsiednie WZ po <code>dok_Id</code> i ustawiało
        <code>ZK.dok_DoDokNrPelny</code> na dokument innego zamówienia (jak 3734–3737 → WZ 3327).<br>
        <strong>Sfera:</strong> akcje z „wystaw WZ” zużywają licencję stanowiska GT — zamknij zbędne okna Subiekta.
        Po odpięciu zamknij dokumenty w GT (F5).<br>
        <strong>Po samym odpięciu</strong> ZK może zostać ze statusem „zrealizowane” — użyj
        <em>Otwórz ZK do WZ (Sfera)</em> lub <em>Odepnij + wystaw WZ</em>.
    </div>

    <div class="repair-box">
        <div class="checkline">
            <input type="checkbox" id="create_wz" name="create_wz" value="1" form="form-single" <?= $createWz ? 'checked' : '' ?>>
            <label for="create_wz">Po odpięciu wystaw własne WZ (Sfera COM)</label>
        </div>
        <div class="checkline">
            <input type="checkbox" id="full_realization" name="full_realization" value="1" form="form-single" <?= $fullRealization ? 'checked' : '' ?>>
            <label for="full_realization">Pełna realizacja (ilości + ceny z ZK)</label>
        </div>
        <div class="checkline">
            <input type="checkbox" id="close_order" name="close_order" value="1" form="form-single" <?= $closeOrder ? 'checked' : '' ?>>
            <label for="close_order">Domknij ZK po WZ (status 7/8)</label>
        </div>
    </div>

    <form method="post" class="card" id="form-single">
        <h2>Pojedyncze ZK</h2>
        <label for="zk">Numer ZK</label>
        <input type="text" id="zk" name="zk" value="<?= h($zkInput) ?>"
               placeholder="np. 3734 lub ZK 3734/07/2026">

        <div class="row">
            <div>
                <label for="month">Miesiąc (gdy sam numer)</label>
                <input type="number" id="month" name="month" min="1" max="12" value="<?= (int) $month ?>">
            </div>
            <div>
                <label for="year">Rok</label>
                <input type="number" id="year" name="year" min="2020" max="2099" value="<?= (int) $year ?>">
            </div>
        </div>

        <div class="actions">
            <button type="submit" name="action" value="diagnose" class="btn-info">Diagnostyka</button>
            <button type="submit" name="action" value="preview_fix" class="btn-secondary">Podgląd odpięcia</button>
            <button type="submit" name="action" value="apply_unlink" class="btn-danger"
                    onclick="return confirm('Odpiąć błędny WZ od tego ZK (Sfera)?');">
                Odepnij nagłówek (Sfera)
            </button>
            <button type="submit" name="action" value="apply_fix_create_wz" class="btn-ok"
                    onclick="return confirm('Odpiąć błędny WZ i wystawić własne WZ przez Sferę?');">
                Odepnij + wystaw WZ (Sfera)
            </button>
            <button type="submit" name="action" value="reopen_for_wz" class="btn-warn"
                    onclick="return confirm('Cofnąć status „zrealizowane” i otworzyć ZK do nowego WZ (Sfera)?');">
                Otwórz ZK do WZ (Sfera)
            </button>
            <button type="submit" name="action" value="link_own_header" class="btn-info"
                    onclick="return confirm('Ustawić nagłówek ZK na własne WZ (Sfera)?');">
                Powiąż nagłówek do WZ (Sfera)
            </button>
            <button type="submit" name="action" value="preview_phantom_oryg" class="btn-secondary">
                Podgląd fałszywego oryg
            </button>
            <button type="submit" name="action" value="repair_phantom_oryg" class="btn-warn"
                    onclick="return confirm('Przywrócić NrPelnyOryg na cudzym WZ i otworzyć ZK (Sfera)?');">
                Napraw fałszywe oryg WZ (Sfera)
            </button>
        </div>
    </form>

    <form method="post" class="card" id="form-list">
        <input type="hidden" name="create_wz" value="<?= $createWz ? '1' : '' ?>">
        <input type="hidden" name="full_realization" value="<?= $fullRealization ? '1' : '' ?>">
        <input type="hidden" name="close_order" value="<?= $closeOrder ? '1' : '' ?>">
        <input type="hidden" name="month" value="<?= (int) $month ?>">
        <input type="hidden" name="year" value="<?= (int) $year ?>">

        <h2>Lista ZK (np. GAMA 3734–3737)</h2>
        <label for="zk_list">Numery ZK (przecinek / spacja / nowa linia)</label>
        <textarea id="zk_list" name="zk_list" placeholder="3734, 3735, 3736, 3737"><?= h($zkList) ?></textarea>

        <div class="actions">
            <button type="submit" name="action" value="apply_list_unlink" class="btn-danger"
                    onclick="return confirm('Odpiąć błędne nagłówki dla listy ZK (Sfera)?');">
                Odepnij listę (Sfera)
            </button>
            <button type="submit" name="action" value="apply_list_create_wz" class="btn-ok"
                    onclick="return confirm('Dla każdego ZK: odpiąć + wystawić WZ przez Sferę? To zajmie chwilę.');">
                Odepnij listę + WZ (Sfera)
            </button>
            <button type="submit" name="action" value="reopen_list_for_wz" class="btn-warn"
                    onclick="return confirm('Dla każdego ZK: cofnąć status zrealizowane i otworzyć do WZ?');">
                Otwórz listę do WZ (Sfera)
            </button>
        </div>
    </form>

    <form method="post" class="card" id="form-month">
        <input type="hidden" name="create_wz" value="<?= $createWz ? '1' : '' ?>">
        <input type="hidden" name="full_realization" value="<?= $fullRealization ? '1' : '' ?>">
        <input type="hidden" name="close_order" value="<?= $closeOrder ? '1' : '' ?>">

        <h2>Skan miesiąca</h2>
        <p class="sub" style="margin-top:0">Lista ZK z nagłówkiem do WZ — zaznacz „tylko błędne”.</p>

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
            <input type="checkbox" id="only_stale" name="only_stale" value="1" <?= $onlyStale ? 'checked' : '' ?>>
            <label for="only_stale">Tylko błędne nagłówki</label>
        </div>

        <div class="actions">
            <button type="submit" name="action" value="scan_month" class="btn-info">Skanuj miesiąc</button>
            <button type="submit" name="action" value="preview_fix_month" class="btn-secondary">Podgląd odpięcia</button>
            <button type="submit" name="action" value="apply_fix_month" class="btn-danger"
                    onclick="return confirm('Odpiąć wszystkie błędne nagłówki w miesiącu (Sfera)?');">
                Odepnij miesiąc (Sfera)
            </button>
            <button type="submit" name="action" value="apply_fix_month_create_wz" class="btn-ok"
                    onclick="return confirm('Dla wszystkich błędnych: odpiąć + wystawić WZ przez Sferę?');">
                Odepnij miesiąc + WZ (Sfera)
            </button>
        </div>
    </form>

    <?php if ($result !== null): ?>
        <div class="card">
            <h2>Wynik</h2>
            <?php if (!empty($result['summary']) || !empty($result['summary_after'])): ?>
                <?php $s = $result['summary_after'] ?? $result['summary']; ?>
                <div class="summary">
                    <span class="pill">nagłówki: <strong><?= (int) ($s['total_with_header'] ?? 0) ?></strong></span>
                    <span class="pill warn">błędne: <strong><?= (int) ($s['stale'] ?? 0) ?></strong></span>
                    <span class="pill ok">OK: <strong><?= (int) ($s['ok'] ?? 0) ?></strong></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($result['message'])): ?>
                <p class="sub"><?= h($result['message']) ?></p>
            <?php endif; ?>
            <pre><?= h($jsonResult) ?></pre>
        </div>
    <?php endif; ?>

    <?php if (is_array($scanTable) && !empty($scanTable)): ?>
        <div class="card">
            <h2>Tabela skanu</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>ZK</th>
                        <th>Status</th>
                        <th>Kontrahent</th>
                        <th>Nagłówek WZ</th>
                        <th>Właściciel WZ</th>
                        <th>Netto ZK / WZ</th>
                        <th>Flagi</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($scanTable as $row): ?>
                        <tr class="<?= !empty($row['stale']) ? 'stale' : 'ok' ?>">
                            <td>
                                <strong><?= h($row['order_ref'] ?? '') ?></strong><br>
                                <small><?= h($row['order_oryg'] ?? '') ?></small>
                            </td>
                            <td><?= h($row['order_status_label'] ?? '') ?></td>
                            <td><?= h($row['customer_symbol'] ?? '') ?></td>
                            <td><?= h($row['issue_ref'] ?? '') ?><br>
                                <small>oryg: <?= h($row['issue_oryg'] ?? '') ?></small>
                            </td>
                            <td><?= h($row['owner_zk'] ?? '') ?></td>
                            <td>
                                <?= number_format((float) ($row['order_netto'] ?? 0), 2, ',', ' ') ?>
                                /
                                <?= number_format((float) ($row['issue_netto'] ?? 0), 2, ',', ' ') ?>
                            </td>
                            <td>
                                <?php foreach (($row['flags'] ?? array()) as $flag): ?>
                                    <span class="tag"><?= h($flag) ?></span>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
    function syncChecks() {
        var map = {
            create_wz: ['form-list', 'form-month'],
            full_realization: ['form-list', 'form-month'],
            close_order: ['form-list', 'form-month']
        };
        Object.keys(map).forEach(function (id) {
            var src = document.getElementById(id);
            if (!src) return;
            map[id].forEach(function (formId) {
                var form = document.getElementById(formId);
                if (!form) return;
                var hidden = form.querySelector('input[name="' + id + '"]');
                if (hidden) hidden.value = src.checked ? '1' : '';
            });
        });
    }
    ['create_wz', 'full_realization', 'close_order'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', syncChecks);
    });
    syncChecks();
})();
</script>
</body>
</html>
