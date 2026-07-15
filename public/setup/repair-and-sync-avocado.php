<?php
require_once dirname(__FILE__) . '/../init.php';
use APISubiektGT\Config;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\Order;

$c = new Config(CONFIG_INI_FILE);
$c->load();
MSSql::getInstance(['UID'=>$c->getDbUser(),'PWD'=>$c->getDbUserPass(),'Database'=>$c->getDatabase()], $c->getServer());
$apiKey = $c->getApiKey();
$base = 'http://127.0.0.1:82/api-subiekt-gt/public/api/index.php';

foreach (array('ZK 3603/07/2026','ZK 3618/07/2026','ZK 3629/07/2026') as $ref) {
    $payload = json_encode(['api_key'=>$apiKey,'data'=>['order_ref'=>$ref]], JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\n",'content'=>$payload,'timeout'=>180,'ignore_errors'=>true]]);
    echo "\n=== repair {$ref} ===\n";
    echo file_get_contents($base.'?c=order/repairStuckOrderWithoutIssue', false, $ctx);
}

foreach (array('ZK 3603/07/2026','ZK 3618/07/2026','ZK 3629/07/2026') as $ref) {
    $payload = json_encode(['api_key'=>$apiKey,'data'=>['order_ref'=>$ref,'force_resync'=>true]], JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\n",'content'=>$payload,'timeout'=>180,'ignore_errors'=>true]]);
    echo "\n=== reserve {$ref} ===\n";
    echo file_get_contents($base.'?c=order/reserve', false, $ctx);
}

MSSql::withSqlWriteFallback(function() {
    print_r(Order::syncStockReservationsFromOrdersSql(1, false, ['IW00AV1','IW00AV2']));
});

echo "\n=== statusy ===\n";
foreach (array('ZK 3603/07/2026','ZK 3618/07/2026','ZK 3629/07/2026','ZK 3643/07/2026') as $ref) {
    $z = Order::getOrderRowByRefSql($ref);
    echo "{$ref} status=".(int)($z['dok_Status']??0)."\n";
}
print_r(MSSql::getInstance()->query("SELECT tw_Symbol, st_Stan, st_StanRez FROM tw__Towar t INNER JOIN tw_Stan s ON s.st_TowId=t.tw_Id AND s.st_MagId=1 WHERE tw_Symbol IN ('IW00AV1','IW00AV2') ORDER BY tw_Symbol"));
