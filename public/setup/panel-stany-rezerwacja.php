<?php
/**
 * Panel: osierocone rezerwacje magazynowe (st_StanRez vs otwarte ZK status 5).
 * http://localhost/api-subiekt-gt/public/setup/panel-stany-rezerwacja.php
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;
use APISubiektGT\SubiektGT\OrderComWriter;

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

$result = null;
$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
$symbolInput = isset($_POST['symbol']) ? trim((string) $_POST['symbol']) : '';
$warehouseId = isset($_POST['warehouse']) ? (int) $_POST['warehouse'] : (int) $cfg->getWarehouse();
if ($warehouseId <= 0) {
    $warehouseId = 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    // Panel celowo aktualizuje tw_Stan.st_StanRez w SQL — bez Sfery COM.
    $prevComWriterOnly = OrderComWriter::comWritesOnly();
    $prevComWriterFallback = OrderComWriter::allowSqlWriteFallback();
    $prevMsSqlComOnly = MSSql::isComWritesOnly();
    OrderComWriter::configure(false, true);
    MSSql::setComWritesOnly(false, true);
    try {
        initDb($cfg);
        $symbols = $symbolInput !== '' ? array($symbolInput) : null;

        if ($action === 'scan') {
            $items = Order::findStockReservationMismatchesSql($warehouseId, $symbols);
            $incompleteMag = Order::findIncompleteReservedOpenIloscMagSql($symbols);
            $result = array(
                'state' => 'scan',
                'warehouse_id' => $warehouseId,
                'count' => count($items),
                'total_orphan_rez' => array_sum(array_map(function ($row) {
                    return (float) ($row['orphan_rez'] ?? 0);
                }, $items)),
                'items' => array_slice($items, 0, 200),
                'truncated' => count($items) > 200,
                'incomplete_ilosc_mag' => array(
                    'count_positions' => count($incompleteMag),
                    'items' => array_slice($incompleteMag, 0, 100),
                    'message' => empty($incompleteMag)
                        ? 'IloscMag OK (Informator Ilość powinna być widoczna).'
                        : 'ZK status 7 z IloscMag < Ilosc — Informator pokazuje Ilość=0 mimo rezerwacji.',
                ),
                'message' => 'Rozjazd st_StanRez + kontrola IloscMag (Informator).',
            );
        } elseif ($action === 'preview_fix') {
            $result = array(
                'st_stan_rez' => Order::syncStockReservationsFromOrdersSql($warehouseId, true, $symbols),
                'ilosc_mag' => Order::repairIncompleteReservedOpenIloscMagSql($symbols, true),
            );
        } elseif ($action === 'apply_fix') {
            $mag = Order::repairIncompleteReservedOpenIloscMagSql($symbols, false);
            $rez = Order::syncStockReservationsFromOrdersSql($warehouseId, false, $symbols);
            $result = array(
                'state' => 'success',
                'ilosc_mag' => $mag,
                'st_stan_rez' => $rez,
                'message' => 'Najpierw IloscMag=Ilosc (Informator), potem sync st_StanRez. Odśwież GT (F5).',
            );
        } elseif ($action === 'fix_ilosc_mag') {
            $result = Order::repairIncompleteReservedOpenIloscMagSql($symbols, false);
        } else {
            throw new RuntimeException('Nieznana akcja.');
        }
    } catch (Throwable $e) {
        $result = array(
            'state' => 'error',
            'message' => $e->getMessage(),
        );
    } finally {
        OrderComWriter::configure($prevComWriterOnly, $prevComWriterFallback);
        MSSql::setComWritesOnly($prevMsSqlComOnly, false);
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
    <title>Rezerwacje magazynowe — panel SQL</title>
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
        .row { display: grid; grid-template-columns: 1fr 120px; gap: 12px; }
        @media (max-width: 600px) { .row { grid-template-columns: 1fr; } }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }
        button {
            border: 0; border-radius: 6px; padding: 10px 14px; font-size: 0.92rem;
            cursor: pointer; font-weight: 600;
        }
        .btn-secondary { background: #e8edf2; color: #223; }
        .btn-info { background: #2563eb; color: #fff; }
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
    <h1>Rezerwacje magazynowe — naprawa SQL</h1>
    <p class="sub">Działa <strong>bez Sfery COM</strong>. Naprawia <code>tw_Stan.st_StanRez</code>, gdy rezerwacja w stanie magazynowym nie zgadza się z ZK.
        <a href="panel-zk-wz.php">Panel ZK ↔ WZ</a></p>

    <div class="note">
        <strong>Jak liczone jest „oczekiwane”:</strong> suma z <strong>ZK status 7 bez WZ</strong>
        (otwarte z rezerwacją — jak ręczne ZK w GT) oraz legacy <strong>status 5 bez WZ</strong>.
        Po WZ status przechodzi na <strong>8</strong>.
        Dodatkowo skan wykrywa <strong>IloscMag &lt; Ilosc</strong> — wtedy Informator pokazuje
        <em>Ilość=0</em> mimo rezerwacji (ręczne ZK ma IloscMag=Ilosc).
    </div>

    <form method="post" class="card">
        <label for="symbol">Symbol towaru (opcjonalnie — puste = wszystkie)</label>
        <input type="text" id="symbol" name="symbol" value="<?= h($symbolInput) ?>"
               placeholder="np. MF0204">

        <div class="row">
            <div>
                <label for="warehouse">Magazyn ID</label>
                <input type="number" id="warehouse" name="warehouse" min="1" value="<?= (int) $warehouseId ?>">
            </div>
        </div>

        <div class="actions">
            <button type="submit" name="action" value="scan" class="btn-info">Skanuj rozjazdy</button>
            <button type="submit" name="action" value="preview_fix" class="btn-secondary">Podgląd naprawy</button>
            <button type="submit" name="action" value="fix_ilosc_mag" class="btn-secondary"
                    onclick="return confirm('Ustawić IloscMag=Ilosc na ZK status 7 bez WZ (Informator Ilość)?');">Napraw IloscMag (Informator)</button>
            <button type="submit" name="action" value="apply_fix" class="btn-ok"
                    onclick="return confirm('Najpierw IloscMag, potem st_StanRez?');">Napraw wszystko (SQL)</button>
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
