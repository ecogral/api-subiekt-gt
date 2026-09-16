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

echo "<h3>pw_Pole — wszystkie rekordy</h3><pre>";
$all = MSSql::getInstance()->query(
    'SELECT pwp_Id, pwp_TypObiektu, pwp_Pole, pwp_Typ, pwp_Nazwa, LEN(pwp_Nazwa) AS len_n
     FROM pw_Pole ORDER BY pwp_Id'
);
foreach ($all as $row) {
    $raw = $row['pwp_Nazwa'];
    echo 'ID=' . $row['pwp_Id']
        . ' typOb=' . $row['pwp_TypObiektu']
        . ' pole=' . $row['pwp_Pole']
        . ' typ=' . $row['pwp_Typ']
        . ' len=' . $row['len_n']
        . "\n  UTF8: " . Helper::toUtf8($raw)
        . "\n  HEX: " . strtoupper(bin2hex($raw))
        . "\n\n";
}

$prefixUtf8 = 'Pełne uwagi';
$prefixWin = Helper::toWin($prefixUtf8);
$safeWin = str_replace("'", "''", $prefixWin);

echo "</pre><h3>Szukanie: Pełne uwagi (UTF-8 → Win-1250)</h3><pre>";
echo 'Win hex: ' . strtoupper(bin2hex($prefixWin)) . "\n\n";

$q1 = MSSql::getInstance()->query(
    "SELECT pwp_Id, pwp_TypObiektu, pwp_Nazwa FROM pw_Pole
     WHERE pwp_TypObiektu = -8 AND pwp_Typ = 3
       AND pwp_Nazwa = '{$safeWin}'"
);
echo "Exact match typ=-8: " . count($q1) . "\n";

$q2 = MSSql::getInstance()->query(
    "SELECT pwp_Id, pwp_TypObiektu, pwp_Nazwa FROM pw_Pole
     WHERE pwp_Typ = 3 AND pwp_Nazwa = '{$safeWin}'"
);
echo "Exact match any object: " . count($q2) . "\n";
foreach ($q2 as $r) {
    echo '  ' . Helper::toUtf8($r['pwp_Nazwa']) . ' (typ=' . $r['pwp_TypObiektu'] . ")\n";
}

$q3 = MSSql::getInstance()->query(
    "SELECT pwp_Id, pwp_TypObiektu, pwp_Nazwa FROM pw_Pole WHERE pwp_Typ = 3"
);
echo "\nAll text fields (typ=3):\n";
foreach ($q3 as $r) {
    echo '  typOb=' . $r['pwp_TypObiektu'] . ' nazwa=' . Helper::toUtf8($r['pwp_Nazwa']) . "\n";
}

echo '</pre>';

$cfg->setCommentsCustomFieldName('Pełne uwagi');
$cfg->setUseCommentsCustomField(true);
$found = DocumentCustomField::resolveTextFields($cfg);
echo '<h3>resolveTextFields() po poprawce</h3><pre>';
echo 'Znaleziono: ' . count($found) . "\n";
echo json_encode(array_map(function ($f) {
    return array(
        'pole' => $f['pwp_Pole'],
        'nazwa' => $f['pwp_NazwaUtf8'],
    );
}, $found), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
echo '</pre>';
