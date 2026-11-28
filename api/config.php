<?php
// 画面用の設定を返す。CSV の URL などサーバだけが知っていればよい項目は返さない
require __DIR__ . '/lib.php';

$cfg = kc_config();
foreach (['csvUrl', 'serverCacheSeconds', 'eventId'] as $secret) unset($cfg[$secret]);
$cfg['server'] = true;                       // 画面側はこれを見てサーバ版として動く
$cfg['dataUrl'] = 'api/questions.php';
$cfg['lockDataSource'] = true;               // 画面から別のデータに切り替えさせない
kc_json($cfg);
