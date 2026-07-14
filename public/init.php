<?php	
use APISubiektGT\Logger;

DEFINE('CONFIG_INI_FILE',dirname(__FILE__).'/../config/api-subiekt-gt.ini');
DEFINE('LOG_DIR',dirname(__FILE__).'/../log/');	

include_once(dirname(__FILE__).'/../src/autoload.php');
Logger::getInstance(LOG_DIR);

if (defined('CONFIG_INI_FILE') && file_exists(CONFIG_INI_FILE)) {
	$iniBootstrap = @parse_ini_file(CONFIG_INI_FILE);
	if (is_array($iniBootstrap)) {
		$comWritesOnly = !isset($iniBootstrap['use_com_writes_only'])
			|| (string) $iniBootstrap['use_com_writes_only'] !== '0';
		$allowSqlFallback = isset($iniBootstrap['allow_sql_write_fallback'])
			&& (string) $iniBootstrap['allow_sql_write_fallback'] === '1';
		APISubiektGT\SubiektGT\OrderComWriter::configure($comWritesOnly, $allowSqlFallback);
		APISubiektGT\MSSql::setComWritesOnly($comWritesOnly, $allowSqlFallback);
	}
}