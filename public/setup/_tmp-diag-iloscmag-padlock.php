<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
MSSql::setComWritesOnly(false, true);
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

function dumpZk($ref) {
    $s = str_replace("'", "''", $ref);
    $h = MSSql::getInstance()->query(
        "SELECT dok_Id, dok_Status, dok_StatusEx, dok_DoDokNrPelny FROM dok__Dokument
         WHERE dok_NrPelny='{$s}' AND dok_Typ=16"
    );
    if (!is_array($h) || empty($h)) {
        echo "$ref NOT FOUND\n";
        return;
    }
    $id = (int)$h[0]['dok_Id'];
    $p = MSSql::getInstance()->query(
        "SELECT t.tw_Symbol,
                CAST(p.ob_Ilosc AS float) AS ilosc,
                CAST(ISNULL(p.ob_IloscMag,0) AS float) AS mag
         FROM dok_Pozycja p
         LEFT JOIN tw__Towar t ON t.tw_Id=p.ob_TowId
         WHERE p.ob_DokHanId={$id}
         ORDER BY p.ob_Id"
    );
    echo "\n=== $ref st=" . $h[0]['dok_Status'] . " ex=" . $h[0]['dok_StatusEx']
        . " do=" . ($h[0]['dok_DoDokNrPelny'] ?? '') . " ===\n";
    $sumMag = 0;
    if (is_array($p)) {
        foreach ($p as $r) {
            $mag = (float)($r['mag'] ?? 0);
            $sumMag += $mag;
            echo '  ' . ($r['tw_Symbol'] ?? '')
                . ' ilosc=' . ($r['ilosc'] ?? 0)
                . ' mag=' . $mag . "\n";
        }
    }
    echo "  SUM IloscMag=$sumMag\n";
}

// problematyczne (kłódka)
foreach (array('ZK 3770/07/2026','ZK 3771/07/2026','ZK 3773/07/2026') as $r) {
    dumpZk($r);
}

// porównaj ze „zdrowym” starszym ZK z WZ
echo "\n--- porownanie ze starszym ZK ---\n";
$old = MSSql::getInstance()->query(
    "SELECT TOP 1 dok_NrPelny FROM dok__Dokument
     WHERE dok_Typ=16 AND dok_Status=8 AND dok_StatusEx & 4 <> 0
       AND dok_DoDokNrPelny LIKE 'WZ%'
       AND dok_DataWyst >= '2026-07-01'
       AND TRY_CAST(REPLACE(REPLACE(dok_NrPelny,'ZK ',''),'/07/2026','') AS int) < 3750
     ORDER BY dok_Id DESC"
);
if (is_array($old) && !empty($old)) {
    dumpZk($old[0]['dok_NrPelny']);
}
