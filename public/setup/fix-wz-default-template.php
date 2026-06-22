<?php
/**
 * Jednorazowa naprawa błędnego domyślnego wzorca WZ w wy_WzDomyslny (629 -> 1000004).
 * php public/setup/fix-wz-default-template.php
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();

MSSql::getInstance(array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
), $cfg->getServer());

$newTemplateId = 1000004; // WZ standard bez cen

$before = MSSql::getInstance()->query(
    "SELECT wzd.wzd_Id, wzd.wzd_WzorzecId, w.wzw_Nazwa
     FROM wy_WzDomyslny wzd
     INNER JOIN wy_Wzorzec w ON w.wzw_Id = wzd.wzd_WzorzecId
     WHERE wzd.wzd_Typ = 11"
);

echo "Przed:\n";
foreach ($before as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

$updated = MSSql::getInstance()->query(
    "UPDATE wy_WzDomyslny SET wzd_WzorzecId = {$newTemplateId}
     WHERE wzd_Typ = 11 AND wzd_WzorzecId <> {$newTemplateId};
     SELECT @@ROWCOUNT AS affected;"
);

echo "\nZaktualizowano wierszy: " . (isset($updated[0]['affected']) ? $updated[0]['affected'] : '?') . "\n";

$after = MSSql::getInstance()->query(
    "SELECT wzd.wzd_Id, wzd.wzd_WzorzecId, w.wzw_Nazwa
     FROM wy_WzDomyslny wzd
     INNER JOIN wy_Wzorzec w ON w.wzw_Id = wzd.wzd_WzorzecId
     WHERE wzd.wzd_Typ = 11"
);

echo "\nPo:\n";
foreach ($after as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}
