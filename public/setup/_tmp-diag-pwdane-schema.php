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
echo '<pre>';

$sample = MSSql::getInstance()->query(
    'SELECT TOP 3 * FROM pw_Dane WHERE pwd_TypObiektu = -4 AND pwd_Tekst01 IS NOT NULL AND pwd_Tekst01 <> \'\''
);
echo "Sample WZ pw_Dane:\n";
foreach ($sample as $row) {
    echo 'pwd_Id=' . $row['pwd_Id'] . ' typ=' . $row['pwd_TypObiektu'] . ' obj=' . $row['pwd_IdObiektu']
        . ' poz=' . var_export($row['pwd_IdPozycji'], true)
        . ' tekst01=' . Helper::toUtf8((string)$row['pwd_Tekst01']) . "\n";
}

$cols = MSSql::getInstance()->query(
    "SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE, COLUMN_DEFAULT
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_NAME = 'pw_Dane'
     ORDER BY ORDINAL_POSITION"
);
echo "\npw_Dane columns:\n";
foreach ($cols as $c) {
    echo $c['COLUMN_NAME'] . ' ' . $c['DATA_TYPE'] . ' null=' . $c['IS_NULLABLE'] . ' def=' . var_export($c['COLUMN_DEFAULT'], true) . "\n";
}

echo "\nIdentity?\n";
$id = MSSql::getInstance()->query(
    "SELECT name, is_identity FROM sys.columns WHERE object_id = OBJECT_ID('pw_Dane')"
);
foreach ($id as $r) {
    if ($r['is_identity']) {
        echo 'IDENTITY: ' . $r['name'] . "\n";
    }
}

echo '</pre>';
