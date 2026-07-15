<?php
/**
 * Naprawa powiązania ZK↔WZ przez COM (nagłówek dok_DoDokNrPelny + pozycje ob_DoId).
 *
 *   php public/setup/link-zk-wz-com.php --order-ref="ZK 3651/07/2026" --issue-ref="WZ 3249/07/2026"
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\Order;

$options = getopt('', array('order-ref:', 'issue-ref:'));
$orderRef = trim((string) ($options['order-ref'] ?? ''));
$issueRef = trim((string) ($options['issue-ref'] ?? ''));

if ($orderRef === '' || $issueRef === '') {
    fwrite(STDERR, "Użycie: --order-ref=\"ZK ...\" --issue-ref=\"WZ ...\"\n");
    exit(1);
}

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$subiektGt = SubiektGT::getInstance($cfg)->connect();

$order = Order::loadExistingByRefVariants($subiektGt, array('order_ref' => $orderRef));
if ($order === null) {
    echo json_encode(array('state' => 'not_found', 'order_ref' => $orderRef), JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
$order->setCfg($cfg);

$before = Order::getOrderRowByRefSql($orderRef);
$result = $order->ensureOrderIssueDocumentLinksCom(array($issueRef));
$after = Order::getOrderRowByRefSql($orderRef);

echo json_encode(array(
    'state' => !empty($result['header_ok']) ? 'success' : 'partial',
    'order_ref' => $orderRef,
    'issue_ref' => $issueRef,
    'before' => $before,
    'after' => $after,
    'repair' => $result,
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
