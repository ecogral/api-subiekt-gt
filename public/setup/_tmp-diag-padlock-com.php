<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\OrderComWriter;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();
$comWritesOnly = !isset($cfg->use_com_writes_only) || (string) $cfg->use_com_writes_only !== '0';
OrderComWriter::configure($comWritesOnly, true);
MSSql::setComWritesOnly($comWritesOnly, true);
MSSql::getInstance(['UID'=>$cfg->getDbUser(),'PWD'=>$cfg->getDbUserPass(),'Database'=>$cfg->getDatabase()], $cfg->getServer());

$refs = array('ZK 3770/07/2026','ZK 3771/07/2026','ZK 3772/07/2026','ZK 3773/07/2026');

echo "=== SQL szczegoly ===\n";
foreach ($refs as $ref) {
    $s = str_replace("'", "''", $ref);
    $h = MSSql::getInstance()->query(
        "SELECT dok_Id, dok_Status, dok_StatusEx, dok_DoDokNrPelny, dok_ZrealizowaneZRezerwacja
         FROM dok__Dokument WHERE dok_NrPelny='{$s}' AND dok_Typ=16"
    );
    $id = (int)($h[0]['dok_Id'] ?? 0);
    $mag = MSSql::getInstance()->query(
        "SELECT COUNT(*) AS cnt,
                SUM(CASE WHEN ABS(ISNULL(ob_IloscMag,0))>0.00001 THEN 1 ELSE 0 END) AS with_mag,
                SUM(CAST(ISNULL(ob_IloscMag,0) AS float)) AS sum_mag
         FROM dok_Pozycja WHERE ob_DokHanId={$id}"
    );
    echo $ref . ' st=' . ($h[0]['dok_Status'] ?? '') . ' ex=' . ($h[0]['dok_StatusEx'] ?? '')
        . ' zrez=' . ($h[0]['dok_ZrealizowaneZRezerwacja'] ?? '')
        . ' | poz_z_IloscMag=' . ($mag[0]['with_mag'] ?? '?')
        . ' sumMag=' . ($mag[0]['sum_mag'] ?? '?') . "\n";
}

echo "\n=== COM Rezerwacja (Sfera) ===\n";
try {
    $subiektGt = SubiektGT::getInstance($cfg)->connect();
    foreach ($refs as $ref) {
        if (!$subiektGt->SuDokumentyManager->Istnieje($ref)) {
            echo "$ref nie istnieje w COM\n";
            continue;
        }
        $doc = $subiektGt->SuDokumentyManager->Wczytaj($ref);
        $rez = '?';
        try { $rez = $doc->Rezerwacja ? 'TAK' : 'NIE'; } catch (Exception $e) { $rez = 'err'; }
        $st = '?';
        try { $st = (string)$doc->Status; } catch (Exception $e) {}
        echo "$ref COM Rezerwacja=$rez Status=$st\n";

        // Jesli rezerwacja nadal TAK przy statusie 8 — zdejmij i zapisz Status=8
        if ($rez === 'TAK') {
            $doc->Rezerwacja = false;
            OrderComWriter::trySetProperty($doc, array('Status','StatusDokumentu','StanDokumentu'), 8);
            OrderComWriter::setOrderStatusEx($doc, 4);
            OrderComWriter::saveDocument($doc, 'clear-padlock:' . $ref);
            $doc2 = $subiektGt->SuDokumentyManager->Wczytaj($ref);
            $rez2 = $doc2->Rezerwacja ? 'TAK' : 'NIE';
            echo "  -> po Zapisz Rezerwacja=$rez2 Status=" . (string)$doc2->Status . "\n";
        }
    }
} catch (Throwable $e) {
    echo "COM error: " . $e->getMessage() . "\n";
}
