<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;
$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$db = MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());
echo json_encode($db->query("SELECT kh_Id, kh_Symbol FROM kh__Kontrahent WHERE kh_Id IN (1442, 2511)"), JSON_UNESCAPED_UNICODE);
