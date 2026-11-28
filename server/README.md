# コエミル サーバ版の設置手順（FreeBSD + Apache 2.4 + mod_php）

GitHub Pages 版と同じ画面（`index.html` など）を自分のサーバに置き、`api/` の PHP で次のことを行います。

- **質問データを外から見えなくする**: Google の公開CSVはサーバが取りに行き、画面には `api/questions.php` から渡す。CSV の URL はサーバの設定ファイルにだけ書く。
- **パスワード**: 画面全体に Apache のパスワード（Basic認証）を掛ける。
- **取り上げ済み・事前質問の表示を共有**: 投影用PCと司会者の端末などで同じ状態になる（3秒ごとに同期。SQLite に保存）。
- **公開期間をサーバで判定**: 期間外は `api/` がデータを返さない（GitHub Pages 版は画面を隠すだけ）。

画面は `api/config.php` が使えればサーバ版として、使えなければ（GitHub Pages など）従来どおり `config.json` で動きます。同じファイルを両方に置けます。

## 必要なもの

| もの | 確認コマンド | 無いとき |
|---|---|---|
| Apache 2.4 + mod_php | `apachectl -M \| grep php` | — |
| PHP の SQLite（PDO） | `php -m \| grep -i pdo_sqlite` | `pkg install php85-pdo_sqlite` |
| PHP で https を取得する機能（どちらか） | `php -m \| grep -Ei 'curl\|openssl'` | `pkg install php85-curl`（または `php85-openssl`） |
| git | `git --version` | `pkg install git` |

拡張を入れたら `service apache24 restart`。

## 1. ファイルを置く

```sh
git clone https://github.com/SAITOKazuyoshi/qa_tool.git /usr/local/www/koemiru
```

更新するときは `cd /usr/local/www/koemiru && git pull` だけです（再起動は不要）。

## 2. 設定ファイルとデータ置き場を作る

```sh
mkdir -p /usr/local/etc/koemiru
cp /usr/local/www/koemiru/server/config.sample.json /usr/local/etc/koemiru/config.json
chown root:www /usr/local/etc/koemiru/config.json
chmod 640 /usr/local/etc/koemiru/config.json

mkdir -p /var/db/koemiru
chown www:www /var/db/koemiru
chmod 750 /var/db/koemiru
```

`/usr/local/etc/koemiru/config.json` を編集します。項目は GitHub Pages 版の `config.json` と同じで、次が増えています。

| 項目 | 説明 |
|---|---|
| `csvUrl` | 公開CSVのURL。**画面には渡りません** |
| `serverCacheSeconds` | 公開CSVを取り置く秒数（既定 10）。何台で見ても Google への問い合わせはこの間隔に1回 |
| `eventId` | 取り上げ済みなどの記録を分ける名前（省略時は `eventTitle`）。タイトルを直しても記録を引き継ぎたいときに付ける |

`refreshIntervalMs` は 5000（5秒）がおすすめです（取り置きがあるので Google の負担は増えません）。ただし Google の「ウェブに公開」自体の反映待ち（数分）は変わりません。

## 3. パスワードを作る

```sh
htpasswd -c /usr/local/etc/apache24/koemiru.htpasswd koemiru
```

2人目以降は `-c` を付けずに実行します。

## 4. Apache の設定

`server/apache-koemiru.conf` の「ここから下」を `/usr/local/etc/apache24/koemiru-common.conf` として保存し、

- **お試し**（今のサイトの下 `https://skr.0228.me/koemiru/` で動かす）: 既存の skr.0228.me の `<VirtualHost *:443>` の中に
  ```apache
  Alias /koemiru /usr/local/www/koemiru
  Include etc/apache24/koemiru-common.conf
  ```
- **本番**（`koemiru.228.jp`）: 設定例 (B) の VirtualHost を作る

```sh
apachectl configtest && service apache24 graceful
```

## 5. 動作確認

```sh
# パスワードなし → 401 になること
curl -s -o /dev/null -w '%{http_code}\n' https://skr.0228.me/koemiru/
# 設定（csvUrl が含まれていないこと）
curl -s -u koemiru https://skr.0228.me/koemiru/api/config.php
# 質問データ（CSV が返ること）
curl -s -u koemiru https://skr.0228.me/koemiru/api/questions.php | head -3
# 共有状態（{"done":[],"showPre":null} のように返ること）
curl -s -u koemiru https://skr.0228.me/koemiru/api/state.php
# config.json・.git は見えないこと → 403
curl -s -o /dev/null -w '%{http_code}\n' -u koemiru https://skr.0228.me/koemiru/config.json
curl -s -o /dev/null -w '%{http_code}\n' -u koemiru https://skr.0228.me/koemiru/.git/config
```

ブラウザで開き、投影用PCとスマホなど2台で、片方で質問を「取り上げ済み」にするともう片方に数秒で反映されることを確かめます。

## 6. 本番に切り替える（koemiru.228.jp）

1. GitHub の Settings → Pages でカスタムドメインを外す（または Pages を止める）。
2. DNS の `koemiru.228.jp` を、GitHub Pages からこのサーバの IP アドレスに向け直す。
3. 設定例 (B) の VirtualHost を作り、`certbot --apache -d koemiru.228.jp` で証明書を取る。
4. 5 の動作確認を `https://koemiru.228.jp/` で行う。
5. リポジトリの `config.json` から `csvUrl` を消す（公開リポジトリに残さないため）。過去の CSV は「ウェブに公開」を止めておく。

## 毎日のバックアップ（任意）

取り上げ済みなどの記録は `/var/db/koemiru/koemiru.sqlite` だけです。`/etc/crontab` などに次を追加します。

```sh
0 4 * * * www sqlite3 /var/db/koemiru/koemiru.sqlite ".backup /var/db/koemiru/backup-$(date +\%a).sqlite"
```

## うまく動かないとき

| 症状 | 確認すること |
|---|---|
| 画面が GitHub Pages 版の動き（データソース欄が出る・config.json を読みに行く） | `api/config.php` が PHP として動いているか（中身がそのまま見えていないか）。mod_php の `SetHandler` 設定 |
| 「サーバの設定ファイルが読めません」 | `/usr/local/etc/koemiru/config.json` の場所と、www ユーザーが読めるか |
| 「データ用フォルダに書き込めません」 | `/var/db/koemiru` の所有者が www か |
| 「公開CSVを取得できませんでした」 | `csvUrl`、スプレッドシートの「ウェブに公開」、PHP の curl / openssl 拡張 |
| 取り上げ済みが他の端末に反映されない | `api/state.php` が 200 を返すか。データ用フォルダの権限 |
