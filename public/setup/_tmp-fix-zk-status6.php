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

$preview = Order::repairStuckFulfilledOrdersBulkSql('2026-07-01', true);
echo "PREVIEW candidates=" . ($preview['candidate_count'] ?? 0) . "\n";
echo "message=" . ($preview['message'] ?? '') . "\n";
if (!empty($preview['candidates'])) {
    echo "first 10: " . implode(', ', array_slice($preview['candidates'], 0, 10)) . "\n";
}

// Napraw jeden przykładowy (3766)
$one = Order::repairOrderFulfilledCheckmarkSql('ZK 3766/07/2026');
echo "\nrepair 3766: " . json_encode($one, JSON_UNESCAPED_UNICODE) . "\n";
