<?php
// config.php
return [
  // スプレッドシート識別子
  'SPREADSHEET_ID' => '1HGz1Jq-S3UWKqtbDtPnRmaDiK5LRUWYifBtBquyVFhU',
  
  // 取り込み対象（複数シートOK）
  //   - 'sheet' : シート名
  //   - 'range' : 取得レンジ（省略可。未指定なら A1:ZZ で全体）
  'SOURCES' => [
    ['sheet' => '投資顧客管理',       'range' => 'A1:AG'],
    // ['sheet' => 'raw_2024', 'range' => 'A1:ZZ'],
  ],

  'TIMEZONE' => 'Asia/Tokyo',
  'SERVICE_ACCOUNT_JSON' => __DIR__ . '/secrets/service_account.json',
  'RAW_CACHE_FILE'   => __DIR__ . '/cache/raw_rows.json',
  'TOKEN_CACHE_FILE' => __DIR__ . '/cache/oauth_token.json',

  // ★ 追加：1始まり
  'HEADER_ROW'      => 1, // 1行目がヘッダ
  'DATA_START_ROW'  => 2, // 2行目からデータ

    // ...（既存設定そのまま）...

  // JSON保存先（書き込み権限 0775 以上を付与）
  'DATA_DIR' => __DIR__ . '/data',

  // 管理用APIトークン（十分長い乱数に変更）
  'ADMIN_TOKEN' => 'change-me-VERY-LONG-random-token',

  // ==============================
  // 流入マスタ用（年別スプシ）
  // ==============================
  'INFLOW_SHEET_NAME'     => '流入分析', // シート名
  'INFLOW_RANGE'          => 'A41:N',     // 必要なら列範囲指定
  'INFLOW_JSON'           => __DIR__ . '/data/inflows.json',
  'INFLOW_SPREADSHEET_ID_2025' => '1diUtnA9lPJB5Txit3erbf5DWwclVLnJsAAWRfZe3C2g',
  'INFLOW_YEAR_START_2025'     => 2025,

  'INFLOW_SPREADSHEET_ID_2026' => '1H1sxn-TexW-FcVIyLPdSvFkL3x4C8llUwZLasDdtJ1g',
  'INFLOW_YEAR_START_2026'     => 2026,

  // 今後
  // 'INFLOW_SPREADSHEET_ID_2027' => '',
  // 'INFLOW_YEAR_START_2027'     => 2027,
  
  // ▼新: 視聴系KPIの取り込み元スプシ
  'WATCH_SPREADSHEET_ID' => '1VLzWhMZPPZFT_ZQ0iqr5iJewZlg88zfv1y5avR87Rw0',
  'WATCH_SHEET_NAME'     => 'youtube',  // 例: タブ名
  'WATCH_RANGE'          => 'A1:E',     // 例: 列レンジのみ（ヘッダ1行＋データ）,

  // タブ構成の想定（縦持ち/ロング形式）:
  // A:日付(YYYY-MM-DD), B:ユーザ名, C:総再生時間（時）, D:総再生回数, E:インプレッション数

  // ▼新: 保存先JSON
  'WATCH_METRICS_FILE' => __DIR__ . '/data/watch_metrics.json',

  //クライアント ID
  'CLIENT_ID' => '1017539828423-u0abun6ov9ma8321fnc1387emltlobnn.apps.googleusercontent.com',
  //Google Cloud Console　APIKEY
  'API_KEY' => 'AIzaSyAnKWcpv3a92BcVo-00-eYC7SuY0b1jY6E',

];
