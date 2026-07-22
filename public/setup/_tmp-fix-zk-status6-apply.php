<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;
use APISubiektGT\SubiektGT\OrderComWriter;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$comWritesOnly = !isset($cfg->use_com_writes_only) || (string) $cfg->use_com_writes_only !== '0';
$allowSqlFallback = isset($cfg->allow_sql_write_fallback) && (string) $cfg->allow_sql_write_fallback === '1';
OrderComWriter::configure($comWritesOnly, $allowSqlFallback);
MSSql::setComWritesOnly($comWritesOnly, $allowSqlFallback);
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

$result = Order::repairStuckFulfilledOrdersBulkSql('2026-07-01', false);
echo json_encode(array(
    'fixed_count' => $result['fixed_count'] ?? 0,
    'skipped_count' => $result['skipped_count'] ?? 0,
    'message' => $result['message'] ?? '',
    'fixed_sample' => array_slice($result['fixed'] ?? array(), 0, 15),
    'skipped_sample' => array_slice($result['skipped'] ?? array(), 0, 10),
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
