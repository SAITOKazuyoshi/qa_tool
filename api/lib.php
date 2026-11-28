<?php
// コエミル サーバ版の共通処理（api/*.php から読み込む）
// 設定ファイルは公開フォルダの外に置く（既定: /usr/local/etc/koemiru/config.json）。
// Apache の SetEnv KOEMIRU_CONFIG / KOEMIRU_DATA で場所を変えられる。
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

function kc_env(string $name, string $default): string {
    $v = getenv($name);
    if ($v === false || $v === '') { $v = $_SERVER[$name] ?? ($_SERVER['REDIRECT_' . $name] ?? ''); }
    return ($v === '' || $v === false) ? $default : (string)$v;
}

function kc_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function kc_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $path = kc_env('KOEMIRU_CONFIG', '/usr/local/etc/koemiru/config.json');
    if (!is_readable($path)) kc_json(['error' => 'サーバの設定ファイルが読めません（' . $path . '）'], 500);
    $cfg = json_decode((string)file_get_contents($path), true);
    if (!is_array($cfg)) kc_json(['error' => 'サーバの設定ファイル（config.json）の書き方が正しくありません'], 500);
    return $cfg;
}

// データ（キャッシュ・SQLite）を置くフォルダ。Apache（www ユーザー）が書き込める必要がある
function kc_data_dir(): string {
    $dir = rtrim(kc_env('KOEMIRU_DATA', '/var/db/koemiru'), '/');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) kc_json(['error' => 'データ用フォルダを作れません（' . $dir . '）'], 500);
    if (!is_writable($dir)) kc_json(['error' => 'データ用フォルダに書き込めません（' . $dir . '）'], 500);
    return $dir;
}

// イベントの区別（取り上げ済みなどの記録をイベントごとに分ける）。eventId が無ければ講演タイトル
function kc_event(array $cfg): string {
    $id = trim((string)($cfg['eventId'] ?? ''));
    if ($id === '') $id = trim((string)($cfg['eventTitle'] ?? ''));
    return $id === '' ? 'default' : $id;
}

// 公開期間（画面側の availabilityMessage と同じ決まり）。期間外なら理由の文、期間内なら ''
function kc_parse_date($v, bool $endOfDay): ?int {
    if (!is_string($v) || trim($v) === '') return null;
    $t = str_replace('/', '-', trim($v));
    if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $t)) $t .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
    $ts = strtotime($t);
    return $ts === false ? null : $ts;
}
function kc_closed_message(array $cfg): string {
    $en = $cfg['enabled'] ?? true;
    if ($en === false || $en === 'false') return '現在、このページは公開していません。';
    $now = time();
    $from = kc_parse_date($cfg['availableFrom'] ?? null, false);
    $until = kc_parse_date($cfg['availableUntil'] ?? null, true);
    if ($from !== null && $now < $from) return '公開前です。';
    if ($until !== null && $now > $until) return '公開期間は終了しました。';
    return '';
}

function kc_db(): PDO {
    static $db = null;
    if ($db !== null) return $db;
    $db = new PDO('sqlite:' . kc_data_dir() . '/koemiru.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('CREATE TABLE IF NOT EXISTS done (event TEXT NOT NULL, qkey TEXT NOT NULL, at INTEGER NOT NULL, PRIMARY KEY (event, qkey))');
    $db->exec('CREATE TABLE IF NOT EXISTS setting (event TEXT NOT NULL, name TEXT NOT NULL, value TEXT NOT NULL, PRIMARY KEY (event, name))');
    return $db;
}
