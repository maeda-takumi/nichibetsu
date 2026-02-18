# PHP集計・管理ツール｜仕様書 v1.2

最終更新: 2025-09-24 06:42

---

## 変更概要（v1.2）
- **視聴KPIの取り込みと表示を追加**：スプシから *総再生時間（時）* / *総再生回数* / *インプレッション数* を取り込み、ダッシュボードの集計表（Summary）に表示。比較カードにも自動反映。  
- **vendorなし・service_account.json対応の取り込みスクリプト** `import_watch_metrics.php` を追加。  
- **ダッシュボード挙動の整理**：
  - 初期状態は **「日付＝非表示」「セールス＝非表示」**
  - 「日付を表示/非表示」トグルは **Summary と Sales の両テーブルへ連動**。
  - **項目/合計は固定（sticky）**、**日付のみ横スクロール**。
  - **当月 / 前月 / 比較（当月−前月）** を横並び表示。比較は正負で色付け。

> v1.1 までの内容（取り込み・CRUD・UI骨子）は踏襲。

---

## 全体アーキテクチャ
```
Google Sheets（原データ/視聴KPI/流入 等）
  └─ REST API（JWT / Service Account）
      └─ PHP（import / aggregate / cache / display）
           ├─ cache/*.json         … 一時キャッシュ（raw_rows など）
           ├─ data/*.json          … マスタと派生データ（actors / sales / inflows / watch_metrics）
           ├─ admin_people.php     … 演者・セールスのCRUD
           ├─ admin_dashboard.php  … 月別・日別のSummary/Sales表示 + 比較カード
           └─ public/styles.css    … 共通スタイル（ライト/ダーク対応, sticky安定）
```

---

## 設定（config.php 抜粋）
```php
return [
  // 既存
  'SPREADSHEET_ID' => '...',
  'SOURCES'        => [['sheet'=>'投資顧客管理','range'=>'A1:AG']],
  'TIMEZONE'       => 'Asia/Tokyo',
  'SERVICE_ACCOUNT_JSON' => __DIR__.'/secrets/service_account.json',
  'RAW_CACHE_FILE'   => __DIR__.'/cache/raw_rows.json',
  'TOKEN_CACHE_FILE' => __DIR__.'/cache/oauth_token.json',
  'HEADER_ROW'       => 1,
  'DATA_START_ROW'   => 2,
  'DATA_DIR'         => __DIR__.'/data',
  'ADMIN_TOKEN'      => 'change-me-VERY-LONG-random-token',

  // 流入取り込み（既存）
  'INFLOW_SPREADSHEET_ID' => '...',
  'INFLOW_SHEET_NAME'     => '流入分析',
  'INFLOW_RANGE'          => 'A41:N',
  'INFLOW_JSON'           => __DIR__.'/data/inflows.json',
  'INFLOW_YEAR_START'     => 2025,

  // NEW: 視聴KPI 取り込み元
  'WATCH_SHEET_ID'    => '...',
  'WATCH_SHEET_RANGE' => 'youtube!A1:E',   // A:日付, B:総再生時間(時), C:総再生回数, D:インプレッション数, E:動画担当
  'WATCH_METRICS_FILE'=> __DIR__.'/data/watch_metrics.json',
];
```
- **service_account.json** を使用（GCPのサービスアカウント鍵）。  
  対象スプシを **サービスアカウントのメールに共有（Viewer以上）** しておく。

---

## データモデル
### 1) actors.json（演者マスタ）
```json
{
  "kind": "actors",
  "updated_at": "ISO-8601",
  "items": [{"id":"uuid","name":"氏名","kana":"かな","email":"","tags":[],"active":true,"note":"","aliases":["別名","表記ゆれ"]}]
}
```
- `aliases[]` を **表記ゆれ吸収** に使用。取り込み時の「動画担当」や別表記をIDへ正規化。

### 2) sales.json（セールスマスタ）
- actors と同スキーマ（`kind: "sales"`）。

### 3) inflows.json（流入データ）
- 既存仕様のまま（別紙/既存スクリプトに準拠）。

### 4) watch_metrics.json（視聴KPI）
```json
{
  "source": "google_sheets",
  "sheet_id": "xxx",
  "range": "youtube!A1:E",
  "updated_at": "ISO-8601",
  "items": [
    {"actor_id":"uuid","actor_name":"米山元","date":"2025-07-01",
     "watch_hours":310.8335,"views":3738,"impressions":38341}
  ],
  "by_month": {
    "2025-07": {
      "uuid": {
        "1": {"watch_hours":310.8335,"views":3738,"impressions":38341}
      }
    }
  }
}
```
- `by_month[YYYY-MM][actor_id][day]` で日別に即参照できる形。

---

## 取り込みスクリプト
### import_watch_metrics.php（NEW / vendorなし）
- **service_account.json** から JWT を生成 → `https://oauth2.googleapis.com/token` で **アクセストークン取得**。
- `spreadsheets.values.get` で `WATCH_SHEET_RANGE` を取得（A1形式）。
- ヘッダは **ゆる判定**：`日付/Date`、`総再生時間/視聴時間`、`総再生回数/Views`、`インプレッション/Impressions`、`動画担当/演者/担当` を許容。
- `actors.json` の `name/aliases` で **actor_id 正規化**（未登録はスキップ）。
- `watch_metrics.json` を出力（上記スキーマ）。

> 実行例：`php import_watch_metrics.php`  
> ※ サーバー時刻ズレはJWT失敗の主因 → **NTP同期**推奨。

---

## ダッシュボード（admin_dashboard.php）
### レイアウト
- 俳優（actors.json の `items`）ごとに **カード3枚**を横並び：**当月 / 前月 / 比較**（当月−前月）。
- 月移動：前月/今月/翌月ボタン。
- **初期状態**：
  - 「**日付＝非表示**」（テーブルに `.dates-hidden` が付与）
  - 「**セールス＝非表示**」（`.sales-panel.collapsed` で折り畳み）
- トグル：
  - **日付を表示 / 日付を非表示**（Summary と Sales の **両方**に連動）
  - **セールス表示 / セールス非表示**（カード内だけ）

### Summary（集計表）
- 行（指標）:
  - 既存：`流入数 / 調整済み / 電話対応待ち / 電話前失注 / 対応件数 / 成約件数 / セールス成約率 / 入金件数 / 入金額`
  - **NEW**：`総再生時間（時） / 総再生回数 / インプレッション数`  
    → `watch_metrics.json` を参照して **合計 + 日別** を表示。  
- 列：左から **項目（固定） / 合計（固定） / 1〜末日**（横スクロール）。
- 比較カード：当月と前月の `data-value` を用いて **差分を自動計算**（+は緑, −は赤）。

### Sales（セールス表）
- `sales.json` のユーザ別に、`セールス件数 / 成約件数 / セールス成約率 / 入金件数 / 入金額` を表示。  
- **NEW視聴KPIは追加しない**（要件どおり）。

### 実装要点（UI/CSS）
- Sticky安定のため、テーブルは `.table-surface` ラッパー内で横スクロール。  
  左2列（`.label-col` / `.total-col`）は `position: sticky`、幅は `--fixed-label` / `--fixed-total`。  
- 日付の一括表示/非表示：テーブル本体に `.dates-hidden` を付け、`.day-col` を `display:none`。
- 比較カードのハイライト：`.diff-table .diff` + `.diff-pos/.diff-neg`。

---

## 管理UI（admin_people.php）
- **演者/セールス**のCRUD（追加/編集/削除）。
- `aliases[]` 入力に対応（表記ゆれ吸収）。
- トークンヘッダ `X-Admin-Token` 必須（`config.php: ADMIN_TOKEN`）。
- ヘッダ/フッタ/スタイルは分離：`partials/header.php` / `partials/footer.php` / `public/styles.css`。

---

## セキュリティと運用
- `/data` `/cache` `/secrets` は **Web公開外** + パーミッション制御（例: 750/640）。
- 管理画面は **IP制限 or Basic認証** を併用推奨。
- 定期実行例（cron）:
  ```cron
  # 視聴KPI（5分ごと）
  */5 * * * * /usr/bin/php /var/www/app/import_watch_metrics.php >> /var/log/watch_import.log 2>&1
  # 既存取り込み/集計も同様に設定
  ```

---

## 既知の注意点
- `actors.json` にいない「動画担当」は **スキップ**（必要なら「未登録プール」実装可）。
- Stickyずれは `--fixed-label` / `--fixed-total` とセル `padding/border` の不一致で起こる → 変更時は一緒に調整。

---

## 今後の拡張
- inflows（媒体/キャンペーン）をダッシュボードへ統合。
- Summary の 0 行に実データを段階的に接続（`data-value` を順次埋める）。
- CSV/PDF エクスポートと共有リンク。

---

## 変更履歴
- **v1.2**: 視聴KPIの取り込み/表示、ダッシュボード初期非表示、比較カード連動を実装。
- **v1.1**: CRUDとダッシュボード基盤、sticky/日付トグル、比較カードの骨子。
- **v1.0**: 初版（取り込み/集計/CRUD/レイアウト案）。
