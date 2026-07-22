<?php
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

$apply = in_array('--apply', $argv ?? array(), true);
$refs = array('ZK 3734/07/2026', 'ZK 3735/07/2026', 'ZK 3736/07/2026', 'ZK 3737/07/2026');

foreach ($refs as $orderRef) {
    $row = Order::getOrderRowByRefSql($orderRef);
    if ($row === null) {
        echo "{$orderRef}: brak ZK\n";
        continue;
    }
    $orderId = (int) $row['dok_Id'];
    $linked = trim((string) ($row['dok_DoDokNrPelny'] ?? ''));
    $wouldClear = !Order::isIssueDocumentIdLinkedToOrder(
        (int) ($row['dok_DoDokId'] ?? 0),
        $orderId,
        $orderRef
    );
    echo "{$orderRef}: dok_DoDokNrPelny={$linked}, stale=" . ($wouldClear ? 'tak' : 'nie') . "\n";
    if ($apply && $wouldClear && $orderId > 0) {
        $cleared = Order::clearStaleOrderHeaderIssueLinkSql($orderId, $orderRef);
        echo "  -> cleared=" . ($cleared ? 'tak' : 'nie') . "\n";
    }
}

if (!$apply) {
    echo "\nDry-run. Użyj --apply aby odpiąć błędne nagłówki ZK→WZ.\n";
}
