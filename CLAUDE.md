# コエミル（qa_tool）— Claude Code 向け引き継ぎ

セミナーの質問をGoogleフォーム→スプレッドシートで集め、公開CSVを読み込んで
ワードクラウドと所属別の集計を出す単一HTMLツール。GitHub Pages（静的）で公開し、
会場のプロジェクタに投影して使う（Zoom＋会場のハイブリッド開催）。

## 作業ルール
- 返答は日本語。結論から書き、前置き・お世辞は不要。説明は簡潔に。
- 利用者は卸売会社の管理部門の役員。コードの専門家ではないので、変更点は「何が変わるか」で説明する。
- 変更したら、必ず動作確認（下記のテスト方法）をしてから報告する。確認できていないものは「未確認」と書く。
- `config.json` は運用データ（本番のCSV URLなど）。**勝手に上書きしない**。項目を足すときは README の表と合わせる。

## 構成
- `index.html` … 本体（HTML・CSS・JSが1ファイル）。ビルド工程なし。
- `config.json` … 本番設定。GitHub Pages はブラウザから書き込めないため、手動コミットで更新する。
- `README.md` … 設置手順と config.json の項目表。機能を変えたら更新する。
- `form-kit.html` … Googleフォーム用のヘッダー画像（1600×400）とQRコード案内スライド（1920×1080）をcanvasで作ってPNG保存するページ。紙の質問用紙（A4に1枚／2枚分）もHTML＋印刷用CSSで作る（印刷時は `#paperSection` の用紙以外を `display:none`。visibility だと隠れた部分が場所を取り4ページになった）。config.json の eventTitle / speaker を読む。QRは qrcode-generator 1.4.4（CDN）。
- `manual.html` … 投影モードの当日運用マニュアル（静的HTML、印刷可）。index.html の上部からリンク。機能を変えたら合わせて更新する。
- 外部ライブラリ（CDN: cdn.jsdelivr.net、バージョン固定）: PapaParse 5.4.1 / wordcloud2.js 1.2.2 / Kuromoji.js 0.1.2（辞書は約18MB）/ qrcode-generator 1.4.4（index.html の0件時QRと form-kit.html）。

## config.json の項目
`csvUrl`（`/pub?...output=csv` 形式。`/pubhtml` は不可）/ `lockDataSource`（画面のURL入力を隠す）/
`questionColumn` / `categoryColumn`（現在は「所属」）/ `excludeWords` / `autoRefresh` /
`refreshIntervalMs`（5000・15000〔テスト用〕・30000・60000・300000のみ）/ `eventTitle` / `speaker` / `formUrl`（form-kit.html と投影モード0件時のQR用。回答用URL）/ `nameColumn`（質問者。省略時は自動判定）/ `highlightSeconds`（新着の光る秒数。既定60、0で次の変化まで）/ `preQuestionColumn` / `preQuestionValue` / `showPreQuestions`（事前質問の表示切替）/ `enabled` / `availableFrom` / `availableUntil`（公開期間。期間外は `body.closed` で全体を隠し、CSV・辞書を読まない）/
`morphology`（`true` のときだけ形態素解析を使う。初期は無効＝簡易抽出）

## 主な機能と実装の要点
- **自動更新**: `refreshIfChanged` がCSV本文を前回と比較し、変化があったときだけ再集計・再描画する。列の選択は保持する。
- **事前質問の表示切替**: `state.showPre` が false の間、`runAggregate` で `preQuestionColumn` の値が `preQuestionValue` の行を集計から外す（`seenQuestions` にも入れないので、表示に切り替えたとき新着として光る）。ボタンは `#preToggleBtn`（mode-bar 内）。状態は localStorage（キー `koemiru-showPre:<document.title>`）に保存。
- **0件時のQR案内**: `body.no-questions`（`runAggregate` で切替）かつ投影モードのとき `#emptyGuide` を表示。見出し行だけのCSV（回答0件）も読み込み成功として扱う（以前は失敗扱いで、自動更新が始まらなかった）。
- **投影モード**: `body.presentation`。JSがインラインで付ける `display` に勝つため、CSSは `!important` を使う。上部バー（セミナー名を太字・1行、質問数）あり。登壇者は出さない（form-kit.html 用に config の speaker は残す）。文字サイズは画面幅に連動（`--pb-fs`）。投影モード切替ボタンは右下。全画面API（Safari向けにwebkit接頭辞を吸収）。
- **投影モードの見た目**: 単語は太字（`fontWeight: 700`、Zen Kaku の700を読み込んでから描く）、カテゴリ色を `darkenColor` で18%濃く。更新時は前の画面を `.cloud-snap` に写して重ね、描き終わったら0.8秒でクロスフェード。更新直後は簡易抽出→形態素解析で2回描くため、フェード前の写しは使い回す（写し直すと描きかけを写す）。新着演出は入れ替え後（820ms後）に出す。
- **自動更新の状態**: `updateRefreshStatus` が `#pbStatus`（上部バー右）に表示。`state.lastCheckAt` は取得成功時刻（変化の有無を問わず）。間隔×3＋10秒を超えたら黄、失敗・自動更新オフは赤。10秒ごとに再評価。
- **開催レポート**: `#reportBtn` → `buildReport` が `state.rows` 全件（非表示中の事前質問も含む）から集計し `#reportView` に描画、`body.reporting` で他を隠す。印刷CSSあり。ワードクラウドは作成時点のキャンバス画像。
- **単語クリック**: ポップアップで該当質問を表示。一覧の質問クリックで1件表示（`showSingleQuestion`、質問者は `nameColumn` または「名前・氏名」を含む列を（）で表示。Escで一覧へ、自動更新後も `state.popupSingle` で同じ質問を維持）。ワードクラウドの大きさとポップアップの件数は同じ基準（`wordQuestions`）。
- **色**: 単語の色は、その単語に最も多く紐づく所属の色。
- **新着演出**: 自動更新で初めて出てきた質問（`seenQuestions` に無い行）に含まれる単語（最大12語）が大きくなり、`highlightSeconds`（既定60秒、0で無期限）か次のデータ変化の早いほうまで光る（`state.glowUntil` / `endGlow` でフェードアウト）。件数の増減では判定しない（Googleの公開CSVは更新直後に新旧データを交互に返すことがあり、同じ質問が何度も光ったため）。位置は wordcloud2 の `hover` を使って画面全体をなぞって取得する。
- **単語抽出**: 既定は簡易抽出（漢字・カタカナ・英数字の連続）。`morphology: true` のとき、Kuromoji を **Web Worker** で動かす。辞書の展開をメイン処理でやると画面全体が固まるため、必ず別スレッドにすること。辞書の準備前は簡易抽出で先に表示し、準備後に自動で切り替える。60秒で打ち切り。
- **人名を抽出しない**: 解析前に `normalizeVariants` で異体字（﨑→崎・髙→高など、CJK互換漢字はNFKC）を1文字ずつ置き換える（IPADICは「﨑」を知らず「山」「﨑」＋「さん」→「﨑さん」になっていた）。形態素解析では `固有名詞,人名` を捨て、敬称（`接尾,人名`：さん・様・氏など）の前の固有名詞・未知語も捨てる（「社長さん」「利用者様」は残す）。簡易抽出では敬称直前の漢字列を捨てる。ポップアップの印付けは `markWord` で置き換え後の文字位置に合わせる。

## 過去にハマった点（再発注意）
- 公開URLが `/pubhtml` だと行数が増える（79行になった）。`/pub?output=csv` を使う。
- Safari で投影モードが動かなかった。全画面APIの接頭辞と、辞書読み込みが原因候補。Chrome では動作確認済み。**本番の会場ではChrome推奨**。
- Googleの「ウェブに公開」CSVは、更新の反映に数分かかる。自動更新が動かないように見えても、まずCSVを直接開いて確認する。
- 公開ページが古い版のまま、ということが起きた。変更後は強制再読み込みと、公開側のコードを確認する。
- `Object.create(null)` を単語の入れ物に使う（"constructor" などの単語で壊れないように）。

## テスト方法（サンドボックスではCDNに届かない）
1. CDNのURLを `node_modules` のローカルに置き換えたテスト用コピーを作る（本番ファイルは変更しない）。
   `npm i papaparse wordcloud kuromoji` → sed で `https://cdn.jsdelivr.net/npm/...` を `node_modules/...` に置換。
2. `python3 -m http.server` でテスト用フォルダを配信。
3. Playwright（Chromium）で `config.json`・CSVを差し替えて確認。自動更新の確認には、間隔2秒の `<option value="2000">` をテスト用コピーにだけ足す。
4. Playwright の同期APIでは `time.sleep` の間はイベントが処理されない。待つときは `page.wait_for_timeout` を使う。
5. 確認項目: 初期表示 / 自動更新（変化時のみ）/ 投影モード / 単語クリックの件数一致 / 新着演出（出る・続く・次の更新で消える）/ 辞書の遅延・失敗時のフォールバック。

## サンプルデータ
`sample_qa.csv`（30件）、`sample_qa_hap.csv`（北海道エアーポート山崎社長講演向け50件）、`update_test_10.csv`（更新テスト用10件）。列は タイムスタンプ / 所属 / カテゴリ / 質問内容。

## 今後の候補
質問の「取り上げ済み」管理（スプレッドシートの列で判定）/ 類似質問のまとめ表示 / 講師用の質問カード表示 / 投影モードの明暗テーマ切替 / 光る時間の長さの設定。
