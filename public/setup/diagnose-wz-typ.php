<?php
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

$sqls = array(
    'wzorce_RPT_z_wy_Typ' => "SELECT w.wzw_Id, w.wzw_Nazwa, w.wzw_Typ, w.wzw_TypPliku, t.wtp_Nazwa
        FROM wy_Wzorzec w
        LEFT JOIN wy_Typ t ON t.wtp_Id = w.wzw_Typ
        WHERE w.wzw_Widoczny = 1 AND w.wzw_TypPliku = 0
        AND (w.wzw_Nazwa LIKE N'%WZ%' OR w.wzw_Nazwa LIKE N'%Wydanie%')
        ORDER BY w.wzw_Id DESC",
    'wy_WzDomyslny_11' => "SELECT wzd.wzd_Id, wzd.wzd_WzorzecId, w.wzw_Nazwa, w.wzw_Typ, t.wtp_Nazwa
        FROM wy_WzDomyslny wzd
        JOIN wy_Wzorzec w ON w.wzw_Id = wzd.wzd_WzorzecId
        LEFT JOIN wy_Typ t ON t.wtp_Id = w.wzw_Typ
        WHERE wzd.wzd_Typ = 11",
    'kompatybilne_z_domyslnym' => "SELECT w.wzw_Id, w.wzw_Nazwa, w.wzw_Typ
        FROM wy_Wzorzec w
        WHERE w.wzw_Widoczny = 1 AND w.wzw_TypPliku = 0
        AND w.wzw_Typ = (
            SELECT TOP 1 w0.wzw_Typ FROM wy_WzDomyslny z
            JOIN wy_Wzorzec w0 ON w0.wzw_Id = z.wzd_WzorzecId
            WHERE z.wzd_Typ = 11
        )
        AND (w.wzw_Nazwa LIKE N'%WZ%' OR w.wzw_Nazwa LIKE N'%standard%')
        ORDER BY w.wzw_Id DESC",
    'dok_WZ_2821' => "SELECT dok_Id, dok_NrPelny, dok_Status, dok_Typ FROM dok__Dokument
        WHERE dok_NrPelny = N'WZ 2821/06/2026'",
    'testowane_ids' => "SELECT w.wzw_Id, w.wzw_Nazwa, w.wzw_Typ, t.wtp_Nazwa
        FROM wy_Wzorzec w LEFT JOIN wy_Typ t ON t.wtp_Id = w.wzw_Typ
        WHERE w.wzw_Id IN (1000004, 1000019, 1000007, 605, 448, 447)",
    'typ_grupy_WZ' => "SELECT TOP 1 w0.wzw_Typ AS grupa_typ, t.wtp_Nazwa
        FROM wy_WzDomyslny z
        JOIN wy_Wzorzec w0 ON w0.wzw_Id = z.wzd_WzorzecId
        LEFT JOIN wy_Typ t ON t.wtp_Id = w0.wzw_Typ
        WHERE z.wzd_Typ = 11",
);

foreach ($sqls as $name => $sql) {
    echo "--- {$name} ---\n";
    try {
        foreach (MSSql::getInstance()->query($sql) as $row) {
            echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        }
    } catch (Exception $e) {
        echo 'ERR: ' . $e->getMessage() . "\n";
    }
    echo "\n";
}
