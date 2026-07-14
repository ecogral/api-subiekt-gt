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

echo "Missing U001: ";
print_r(Order::findMissingIssueServiceCodesSql(69399, 'WZ 3212/07/2026'));
echo "\nZK services: ";
print_r(Order::getOrderServiceLinesFromSql(69399));
echo "\nWZ product codes: ";
print_r(Order::getIssueProductCodesSql('WZ 3212/07/2026'));
echo "\nWZ dok row: ";
print_r(MSSql::getInstance()->query("SELECT dok_Id,dok_NrPelny,dok_DataWyst,dok_WartBrutto,dok_NrPelnyOryg,dok_OsobaId FROM dok__Dokument WHERE dok_Id=69402"));
echo "\nZK status now: ";
print_r(MSSql::getInstance()->query("SELECT dok_Status,dok_StatusEx,dok_DoDokNrPelny FROM dok__Dokument WHERE dok_Id=69399"));
