<?php
// 取り上げ済み・事前質問の表示切替を、投影用PCと司会者の端末などで共有する
//   GET  … {done: [質問文…], showPre: true|false|null}
//   POST … {"action":"done","key":"質問文","on":true} / {"action":"resetDone"} / {"action":"showPre","value":true}
require __DIR__ . '/lib.php';

$cfg = kc_config();
$closed = kc_closed_message($cfg);
if ($closed !== '') kc_json(['error' => $closed], 403);
$event = kc_event($cfg);
$db = kc_db();

function kc_state(PDO $db, string $event): array {
    $st = $db->prepare('SELECT qkey FROM done WHERE event = ? ORDER BY qkey');
    $st->execute([$event]);
    $done = $st->fetchAll(PDO::FETCH_COLUMN);
    $st = $db->prepare("SELECT value FROM setting WHERE event = ? AND name = 'showPre'");
    $st->execute([$event]);
    $v = $st->fetchColumn();
    return ['done' => $done, 'showPre' => $v === false ? null : $v === '1'];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') kc_json(kc_state($db, $event));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') kc_json(['error' => 'method'], 405);

// 他のサイトから勝手に書き換えられないよう、画面が付ける目印のヘッダーを必須にする
if (($_SERVER['HTTP_X_KOEMIRU'] ?? '') !== '1') kc_json(['error' => 'forbidden'], 403);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) kc_json(['error' => 'bad request'], 400);
$action = (string)($in['action'] ?? '');

if ($action === 'done') {
    $key = (string)($in['key'] ?? '');
    if ($key === '' || strlen($key) > 12000) kc_json(['error' => 'bad key'], 400);
    if (!empty($in['on'])) {
        $db->prepare('INSERT OR REPLACE INTO done (event, qkey, at) VALUES (?, ?, ?)')->execute([$event, $key, time()]);
    } else {
        $db->prepare('DELETE FROM done WHERE event = ? AND qkey = ?')->execute([$event, $key]);
    }
} elseif ($action === 'resetDone') {
    $db->prepare('DELETE FROM done WHERE event = ?')->execute([$event]);
} elseif ($action === 'showPre') {
    $db->prepare("INSERT OR REPLACE INTO setting (event, name, value) VALUES (?, 'showPre', ?)")
       ->execute([$event, !empty($in['value']) ? '1' : '0']);
} else {
    kc_json(['error' => 'unknown action'], 400);
}
kc_json(kc_state($db, $event));
