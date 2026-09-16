<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\Helper;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

header('Content-Type: text/html; charset=utf-8');

echo "<h3>pw_Pole — wszystkie</h3><pre>";
$poles = MSSql::getInstance()->query(
    "SELECT pwp_Id, pwp_TypObiektu, pwp_Pole, pwp_Typ, pwp_Nazwa
     FROM pw_Pole
     ORDER BY pwp_Id DESC"
);
foreach ($poles as $p) {
    echo Helper::toUtf8(json_encode($p, JSON_UNESCAPED_UNICODE)) . "\n";
}

echo "\n<h3>gt__Obiekt — zamówienie</h3>";
$objs = MSSql::getInstance()->query(
    "SELECT gto_Id, gto_Nazwa FROM gt__Obiekt ORDER BY gto_Id"
);
foreach ($objs as $o) {
    echo Helper::toUtf8($o['gto_Id'] . ' | ' . $o['gto_Nazwa']) . "\n";
}

$docId = 71269; // ZK 4118
echo "\n<h3>pw_Dane dla dok_Id={$docId}</h3>";
$dane = MSSql::getInstance()->query("SELECT * FROM pw_Dane WHERE pwd_IdObiektu = {$docId} AND pwd_IdPozycji = 0");
echo Helper::toUtf8(json_encode($dane, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo '</pre>';
