<?php
// 質問データ（CSV）を返す。サーバが Google の公開CSVを取りに行き、少しの間だけ取り置く。
// CSV の URL はサーバの設定ファイルにだけ書くので、画面を見ている人には分からない。
require __DIR__ . '/lib.php';

$cfg = kc_config();
$closed = kc_closed_message($cfg);
if ($closed !== '') kc_json(['error' => $closed], 403);   // 公開期間外はデータを返さない

$url = trim((string)($cfg['csvUrl'] ?? ''));
if ($url === '' || !preg_match('#^https://#', $url)) kc_json(['error' => 'サーバの設定に csvUrl（https で始まる公開CSVのURL）がありません'], 500);

$ttl = (int)($cfg['serverCacheSeconds'] ?? 10);
if ($ttl < 0) $ttl = 0;
$cache = kc_data_dir() . '/cache-' . md5($url) . '.csv';

function kc_send_csv(string $body, bool $stale): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if ($stale) header('X-Koemiru-Stale: 1');
    echo $body;
    exit;
}

function kc_fetch(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'koemiru',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ($body !== false && $code === 200) ? (string)$body : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'follow_location' => 1, 'user_agent' => 'koemiru', 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    // PHP 8.4 以降は http_get_last_response_headers()（8.5 で $http_response_header は非推奨）
    $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $status = implode("\n", $headers);
    // 転送のあとの最後のステータスが 200 か確かめる
    if (!preg_match_all('#^HTTP/\S+\s+(\d{3})#m', $status, $m) || end($m[1]) !== '200') return null;
    return $body;
}

// 取り置きが新しければ、そのまま返す（投影用PCが何台あっても Google への問い合わせは増えない）
if (is_file($cache) && (time() - filemtime($cache)) < $ttl) kc_send_csv((string)file_get_contents($cache), false);

// 同時に何台から来ても、取りに行くのは1回だけにする
$lock = fopen($cache . '.lock', 'c');
if ($lock) flock($lock, LOCK_EX);
clearstatcache(true, $cache);
if (is_file($cache) && (time() - filemtime($cache)) < $ttl) kc_send_csv((string)file_get_contents($cache), false);

$body = kc_fetch($url);
if ($body !== null) {
    $tmp = $cache . '.tmp';
    file_put_contents($tmp, $body);
    rename($tmp, $cache);
    kc_send_csv($body, false);
}
// Google に届かないときは、前回取れたデータを返す（画面は「変化なし」として動き続ける）
if (is_file($cache)) kc_send_csv((string)file_get_contents($cache), true);
kc_json(['error' => '公開CSVを取得できませんでした。スプレッドシートの「ウェブに公開」を確認してください'], 502);
