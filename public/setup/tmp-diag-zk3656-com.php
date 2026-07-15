<?php
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\MSSql;

$c = new Config(CONFIG_INI_FILE);
$c->load();
MSSql::getInstance([
    'UID' => $c->getDbUser(),
    'PWD' => $c->getDbUserPass(),
    'Database' => $c->getDatabase(),
], $c->getServer());

$ref = 'ZK 3656/07/2026';

echo "=== WZ z NaPodstawie = {$ref} ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT dok_NrPelny, dok_Status, dok_DataWyst, dok_NrPelnyOryg
     FROM dok__Dokument WHERE dok_Typ=11 AND dok_NrPelnyOryg='{$ref}'"
));

echo "\n=== Powiązania ob_DoId / ob_Powiazane ===\n";
print_r(MSSql::getInstance()->query(
    "SELECT wz.dok_NrPelny, wp.ob_DoId, zp.ob_Id AS zk_pos_id
     FROM dok_Pozycja zp
     INNER JOIN dok__Dokument zk ON zk.dok_Id=zp.ob_DokHanId AND zk.dok_NrPelny='{$ref}'
     LEFT JOIN dok_Pozycja wp ON wp.ob_DoId=zp.ob_Id
     LEFT JOIN dok__Dokument wz ON wz.dok_Id=wp.ob_DokMagId AND wz.dok_Typ=11
     WHERE wp.ob_Id IS NOT NULL"
));

echo "\n=== Logi: SYS-000408 / 3656 / DY62C (15.07) ===\n";
$logDir = 'c:/xampp/htdocs/api-subiekt-gt/log/';
foreach (array('api_26_07_15.log', 'api_26_07_14.log') as $file) {
    $path = $logDir . $file;
    if (!is_file($path)) {
        continue;
    }
    $fh = fopen($path, 'r');
    $n = 0;
    while ($fh && ($line = fgets($fh)) !== false) {
        if (stripos($line, '3656') !== false || stripos($line, '000408') !== false
            || (stripos($line, 'DY62C') !== false && stripos($line, 'add') !== false)) {
            echo $line;
            $n++;
            if ($n >= 25) {
                break;
            }
        }
    }
    if ($fh) {
        fclose($fh);
    }
}

echo "\n=== API get order (COM Rezerwacja) ===\n";
$cfg = $c;
$apiKey = $cfg->getApiKey();
$payload = json_encode([
    'api_key' => $apiKey,
    'data' => ['order_ref' => $ref],
], JSON_UNESCAPED_UNICODE);
$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 120,
        'ignore_errors' => true,
    ],
]);
$raw = @file_get_contents('http://127.0.0.1:82/api-subiekt-gt/public/api/index.php?c=order/get', false, $ctx);
echo $raw !== false ? $raw : "API failed\n";
