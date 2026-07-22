<?php
/**
 * Symulacja: co sync zwróci jako new_orders gdy CRM ma tylko ZK 3766.
 */
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

$existing = array(
    array(
        'order_ref' => 'ZK 3766/07/2026',
        'reference' => 'B2B-605/2026',
        'customer' => array('caretaker' => 'Sonia'),
    ),
);

$order = new Order(false, array());
$order->setCfg($cfg);
$result = $order->getCurrentMonthOrdersWithCaretakerSync(500, $existing);
$data = $result['data'] ?? array();
$new = $data['new_orders'] ?? array();
$coll = $data['reference_collisions'] ?? array();

echo "new_orders count=" . count($new) . "\n";
foreach ($new as $o) {
    if (stripos((string)($o['reference'] ?? ''), 'B2B-605') !== false
        || stripos((string)($o['order_ref'] ?? ''), '376') !== false) {
        echo '  NEW: ' . ($o['order_ref'] ?? '') . ' ref=' . ($o['reference'] ?? '(pusty)')
            . ' netto=' . ($o['amount_net'] ?? '') . "\n";
    }
}
echo "reference_collisions:\n";
foreach ($coll as $c) {
    echo '  ' . ($c['reference'] ?? '') . ' => ' . implode(', ', $c['order_refs'] ?? array()) . "\n";
}

// Case 2: CRM wysyła tylko order_ref bez reference
$existing2 = array(array('order_ref' => 'ZK 3766/07/2026', 'customer' => array('caretaker' => 'Sonia')));
$result2 = $order->getCurrentMonthOrdersWithCaretakerSync(500, $existing2);
$new2 = $result2['data']['new_orders'] ?? array();
echo "\nBez reference w existing_orders, new_orders count=" . count($new2) . "\n";
foreach ($new2 as $o) {
    $ref = trim((string)($o['reference'] ?? ''));
    $nr = $o['order_ref'] ?? '';
    if ($ref === '' || stripos($ref, 'B2B') !== false || preg_match('/376[678]/', $nr)) {
        echo '  NEW: ' . $nr . ' ref=' . ($ref === '' ? '(pusty)' : $ref)
            . ' netto=' . ($o['amount_net'] ?? '') . "\n";
    }
}
