<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\Helper;
use APISubiektGT\DocumentCustomField;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

header('Content-Type: text/html; charset=utf-8');

$ref = isset($_GET['ref']) ? trim((string) $_GET['ref']) : '4121';

echo '<h3>Config</h3><pre>';
echo 'use_comments_custom_field=' . ($cfg->useCommentsCustomField() ? '1' : '0') . "\n";
echo 'comments_custom_field_name=' . $cfg->getCommentsCustomFieldName() . "\n";
echo 'use_comments_ext=' . ($cfg->useCommentsExt() ? '1' : '0') . "\n";
$fields = DocumentCustomField::resolveTextFields($cfg);
echo 'resolveTextFields count=' . count($fields) . "\n";
foreach ($fields as $f) {
    echo '  ' . $f['pwp_Pole'] . ' => ' . $f['pwp_NazwaUtf8'] . "\n";
}
echo "</pre>";

$rows = MSSql::getInstance()->query(
    "SELECT TOP 5 dok_Id, dok_NrPelny, dok_NrPelnyOryg,
            LEN(dok_Uwagi) AS len_u, LEN(dok_UwagiExt) AS len_e,
            dok_Uwagi, dok_UwagiExt
     FROM dok__Dokument
     WHERE dok_NrPelny LIKE '%{$ref}%' OR dok_NrPelnyOryg LIKE '%{$ref}%' OR dok_NrPelnyOryg LIKE '%B2B-741%'
     ORDER BY dok_Id DESC"
);

echo '<h3>Dokumenty</h3><pre>';
foreach ($rows as $row) {
    $id = (int) $row['dok_Id'];
    echo 'ID=' . $id . ' Nr=' . Helper::toUtf8($row['dok_NrPelny']) . ' Oryg=' . Helper::toUtf8($row['dok_NrPelnyOryg']) . "\n";
    echo 'len uwagi=' . $row['len_u'] . ' len ext=' . $row['len_e'] . "\n";
    echo "--- uwagi ---\n" . Helper::toUtf8($row['dok_Uwagi']) . "\n";
    echo "--- ext ---\n" . Helper::toUtf8($row['dok_UwagiExt']) . "\n";

    $dane = MSSql::getInstance()->query(
        "SELECT * FROM pw_Dane WHERE pwd_IdObiektu = {$id} AND pwd_TypObiektu = -8"
    );
    echo "--- pw_Dane count=" . count($dane) . " ---\n";
    if (!empty($dane)) {
        foreach ($dane[0] as $k => $v) {
            if (strpos($k, 'pwd_Tekst') === 0 && $v !== null && $v !== '') {
                echo $k . '=' . Helper::toUtf8((string) $v) . "\n";
            }
        }
        echo json_encode(array_keys($dane[0]), JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo str_repeat('=', 60) . "\n";
}
echo '</pre>';
