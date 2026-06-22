<?php
/**
 * Diagnostyka wzorców PDF — uruchom lokalnie na serwerze API (nie udostępniaj publicznie).
 * php public/setup/diagnose-pdf-templates.php
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();

$mssqlConnectionInfo = array(
    'UID' => $cfg->getDbUser(),
    'PWD' => $cfg->getDbUserPass(),
    'Database' => $cfg->getDatabase(),
);
MSSql::getInstance($mssqlConnectionInfo, $cfg->getServer());

$queries = array(
    'wy_WzDomyslny typ=11 (WZ)' => "SELECT wzd.wzd_Id, wzd.wzd_Typ, wzd.wzd_WzorzecId, wzd.wzd_NazwaKomputera,
        w.wzw_Nazwa, w.wzw_TypPliku, CASE w.wzw_TypPliku WHEN 0 THEN 'RPT/PDF' ELSE 'tekstowy' END AS typ_opis
        FROM wy_WzDomyslny wzd
        INNER JOIN wy_Wzorzec w ON w.wzw_Id = wzd.wzd_WzorzecId
        WHERE wzd.wzd_Typ = 11
        ORDER BY wzd.wzd_Id",
    'Wzorce WZ RPT (nazwa)' => "SELECT TOP 20 w.wzw_Id, w.wzw_Nazwa, w.wzw_TypPliku, w.wzw_Widoczny
        FROM wy_Wzorzec w
        WHERE w.wzw_Widoczny = 1 AND w.wzw_TypPliku = 0
        AND (w.wzw_Nazwa LIKE N'%WZ%' OR w.wzw_Nazwa LIKE N'%Wydanie%' OR w.wzw_Nazwa LIKE N'%zewn%')
        ORDER BY w.wzw_Id DESC",
    'Ostatnie WZ w dok__Dokument' => "SELECT TOP 5 dok_Id, dok_NrPelny, dok_DataWyst FROM dok__Dokument
        WHERE dok_Typ = 11 ORDER BY dok_Id DESC",
);

echo "=== Diagnostyka wzorców PDF WZ ===\n";
echo 'Host: ' . gethostname() . "\n\n";

foreach ($queries as $title => $sql) {
    echo "--- {$title} ---\n";
    try {
        $rows = MSSql::getInstance()->query($sql);
        if (empty($rows)) {
            echo "(brak wyników)\n\n";
            continue;
        }
        foreach ($rows as $row) {
            echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        }
    } catch (Exception $e) {
        echo 'BŁĄD: ' . $e->getMessage() . "\n";
    }
    echo "\n";
}
