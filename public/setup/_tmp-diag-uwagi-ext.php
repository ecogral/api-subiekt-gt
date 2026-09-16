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

$ref = isset($_GET['ref']) ? trim((string) $_GET['ref']) : '4118';
$sql = "SELECT dok_Id, dok_NrPelny, dok_NrPelnyOryg,
        LEN(dok_Uwagi) AS len_uwagi,
        LEN(dok_UwagiExt) AS len_ext,
        dok_Uwagi, dok_UwagiExt
        FROM dok__Dokument
        WHERE dok_NrPelny LIKE '%{$ref}%'
           OR dok_NrPelnyOryg LIKE '%{$ref}%'
        ORDER BY dok_Id DESC";

$rows = MSSql::getInstance()->query($sql);
header('Content-Type: text/html; charset=utf-8');
echo '<h3>use_comments_ext=' . ($cfg->useCommentsExt() ? '1' : '0') . '</h3>';
echo '<pre>';
foreach ($rows as $row) {
    echo 'ID: ' . $row['dok_Id'] . "\n";
    echo 'Nr: ' . Helper::toUtf8($row['dok_NrPelny']) . "\n";
    echo 'Oryg: ' . Helper::toUtf8($row['dok_NrPelnyOryg']) . "\n";
    echo 'len(dok_Uwagi): ' . $row['len_uwagi'] . "\n";
    echo 'len(dok_UwagiExt): ' . $row['len_ext'] . "\n";
    echo "--- dok_Uwagi (UTF-8) ---\n";
    echo Helper::toUtf8($row['dok_Uwagi']) . "\n";
    echo "--- dok_UwagiExt (UTF-8) ---\n";
    echo Helper::toUtf8($row['dok_UwagiExt']) . "\n";
    echo str_repeat('=', 60) . "\n";
}
echo '</pre>';
