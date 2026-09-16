<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\Helper;
use APISubiektGT\DocumentCustomField;
use APISubiektGT\DocumentComments;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

header('Content-Type: text/html; charset=utf-8');

$docId = isset($_GET['id']) ? (int) $_GET['id'] : 71280;

$row = MSSql::getInstance()->query(
    "SELECT dok_Id, dok_NrPelny, dok_Uwagi, dok_UwagiExt FROM dok__Dokument WHERE dok_Id = {$docId}"
);
if (empty($row)) {
    die('brak dokumentu');
}

$commentsWin = DocumentComments::mergeWin(
    (string) $row[0]['dok_Uwagi'],
    DocumentComments::isExtEnabled($cfg) ? (string) ($row[0]['dok_UwagiExt'] ?? '') : ''
);

echo '<h3>Przed sync</h3><pre>';
echo 'doc=' . Helper::toUtf8($row[0]['dok_NrPelny']) . "\n";
echo 'comments len=' . strlen($commentsWin) . "\n";
echo 'enabled=' . (DocumentCustomField::isEnabled($cfg) ? '1' : '0') . "\n";
echo 'fields=' . count(DocumentCustomField::resolveTextFields($cfg)) . "\n";
echo "</pre>";

try {
    DocumentCustomField::syncToSqlIfEnabled($cfg, $docId, $commentsWin);
    echo '<p style="color:green">sync OK</p>';
} catch (Exception $e) {
    echo '<p style="color:red">ERR: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

$dane = MSSql::getInstance()->query(
    "SELECT pwd_Id, pwd_Tekst01 FROM pw_Dane WHERE pwd_IdObiektu = {$docId} AND pwd_TypObiektu = -8"
);
echo '<h3>Po sync</h3><pre>';
echo 'pw_Dane rows=' . count($dane) . "\n";
if (!empty($dane)) {
    echo 'pwd_Id=' . $dane[0]['pwd_Id'] . "\n";
    echo 'pwd_Tekst01=' . Helper::toUtf8((string) $dane[0]['pwd_Tekst01']) . "\n";
}
echo '</pre>';
