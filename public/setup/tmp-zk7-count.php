<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config; use APISubiektGT\MSSql;
$c = new Config(CONFIG_INI_FILE); $c->load();
MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());

$r = MSSql::getInstance()->query(
    "SELECT COUNT(*) AS cnt FROM dok__Dokument zk
     WHERE zk.dok_Typ=16 AND zk.dok_Status=7 AND ISNULL(zk.dok_DoDokId,0)=0
       AND NOT EXISTS (
           SELECT 1 FROM dok_Pozycja wz_p
           INNER JOIN dok_Pozycja zk_p ON zk_p.ob_Id = wz_p.ob_DoId
           INNER JOIN dok__Dokument wz ON wz.dok_Id = wz_p.ob_DokMagId AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
           WHERE zk_p.ob_DokHanId = zk.dok_Id
       )"
);
echo "ZK 7 bez WZ (lipiec+): ";
$july = MSSql::getInstance()->query(
    "SELECT COUNT(*) AS cnt FROM dok__Dokument zk
     WHERE zk.dok_Typ=16 AND zk.dok_Status=7 AND zk.dok_DataWyst >= '2026-07-01'
       AND ISNULL(zk.dok_DoDokId,0)=0
       AND NOT EXISTS (
           SELECT 1 FROM dok_Pozycja wz_p
           INNER JOIN dok_Pozycja zk_p ON zk_p.ob_Id = wz_p.ob_DoId
           INNER JOIN dok__Dokument wz ON wz.dok_Id = wz_p.ob_DokMagId AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
           WHERE zk_p.ob_DokHanId = zk.dok_Id
       )"
);
print_r(array('all' => $r[0]['cnt'] ?? 0, 'july' => $july[0]['cnt'] ?? 0));

echo "\n3603/3618/3629 status:\n";
print_r(MSSql::getInstance()->query(
    "SELECT dok_NrPelny, dok_Status FROM dok__Dokument
     WHERE dok_NrPelny IN ('ZK 3603/07/2026','ZK 3618/07/2026','ZK 3629/07/2026')"
));
