<?php
/**
 * Wymusza status 8 (Zrealizowano) + StatusEx=4 + bez flagi z rez.
 * na ZK, które użytkownik ręcznie przestawił na „Rezerwuj stany”.
 */
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::setComWritesOnly(false, true);
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

$refs = array(
    'ZK 3770/07/2026',
    'ZK 3771/07/2026',
    'ZK 3772/07/2026',
    'ZK 3773/07/2026',
);

// Plus wszystkie ZK lipiec: mają WZ w nagłówku i StatusEx całkowicie, ale st≠8
$extra = MSSql::getInstance()->query(
    "SELECT dok_NrPelny FROM dok__Dokument
     WHERE dok_Typ=16 AND dok_Status>=0 AND dok_DataWyst>='2026-07-01'
       AND ISNULL(dok_DoDokNrPelny,'') LIKE 'WZ%'
       AND (ISNULL(dok_StatusEx,0) & 4) <> 0
       AND dok_Status <> 8
     ORDER BY dok_Id"
);
if (is_array($extra)) {
    foreach ($extra as $r) {
        $nr = trim((string)($r['dok_NrPelny'] ?? ''));
        if ($nr !== '' && !in_array($nr, $refs, true)) {
            $refs[] = $nr;
        }
    }
}

echo "Do naprawy: " . count($refs) . "\n";
foreach ($refs as $ref) {
    $before = Order::getOrderRowByRefSql($ref);
    $ok = false;
    if ($before !== null) {
        $ok = Order::applyOrderFulfilledStatusInSql((int)$before['dok_Id'], 8, false);
    }
    $after = Order::getOrderRowByRefSql($ref);
    echo $ref
        . ' before_st=' . ($before['dok_Status'] ?? '?')
        . ' after_st=' . ($after['dok_Status'] ?? '?')
        . ' after_ex=' . ($after['dok_StatusEx'] ?? '?')
        . ' ok=' . ($ok ? 'tak' : 'nie') . "\n";
}

echo "\nGotowe. W Subiekcie: zamknij listę ZK i otwórz ponownie (albo F5).\n";
echo "Kłódka w kolumnie rezerwacji powinna zniknąć przy statusie 8.\n";
