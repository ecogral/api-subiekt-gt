<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;
$c = new Config(CONFIG_INI_FILE); $c->load();
MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());
print_r(MSSql::getInstance()->query('SELECT dok_Id,dok_NrPelny,dok_DataWyst,dok_WartBrutto,dok_NrPelnyOryg,dok_Status FROM dok__Dokument WHERE dok_Id IN (69399,69402)'));
