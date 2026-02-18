<?php
// import_sheets.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
require_once __DIR__ . '/sheets_rest.php';
function sheets_serial_to_ymd($v, string $tz = 'Asia/Tokyo'): string {
  // 文字列でも数値でもOKにする
  if ($v === null || $v === '') return '';

  if (!is_numeric($v)) {
    // すでに文字列日付（2026-01-03 等）ならそのまま返す
    $s = trim((string)$v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    return $s; // それ以外は変換せず返す
  }

  $serial = (float)$v;

  // 0以下は日付として扱わない
  if ($serial <= 0) return '';

  // 小数点以下は時刻なので切り捨て（必要なら保持も可能）
  $days = (int)floor($serial);

  // Google Sheets の serial は 1899-12-30 起点
  $base = new DateTime('1899-12-30', new DateTimeZone($tz));
  $base->modify("+{$days} days");

  return $base->format('Y-m-d');
}

function fetchSheetAsAssocNoSDK(array $config, string $sheetName, ?string $range = 'A1:ZZ'): array {
  $spreadsheetId = $config['SPREADSHEET_ID'];
  $a1 = $sheetName.'!'.($range ?? 'A1:ZZ');

  $values = sheets_values_get($config, $spreadsheetId, $a1);
  if (empty($values)) return [];

  // 1始まりの設定値（未設定ならデフォルト：1/2）
  $headerRow     = max(1, (int)($config['HEADER_ROW']     ?? 1));
  $dataStartRow  = max(1, (int)($config['DATA_START_ROW'] ?? 2));
  if ($dataStartRow <= $headerRow) $dataStartRow = $headerRow + 1;

  // 配列は0始まりなので補正
  $headerIdx = $headerRow - 1;
  $dataIdx   = $dataStartRow - 1;

  if (!isset($values[$headerIdx])) return [];

  $header = array_map('trim', $values[$headerIdx]);
  $rows   = array_slice($values, $dataIdx); // データ開始行から取得

  // ヘッダ全空対策：空ヘッダは COL_番号 にする
  $header = array_map(function($h, $i){
    $h = (string)$h;
    return $h !== '' ? $h : ('COL_'.($i+1));
  }, $header, array_keys($header));

  $out = [];
  foreach ($rows as $r) {
    // ヘッダ数にパディング
    if (count($r) < count($header)) $r = array_pad($r, count($header), '');
    $assoc = [];
    foreach ($header as $i => $col) {
      $assoc[$col] = (string)($r[$i] ?? '');
    }
    // 全空行スキップ
    $allEmpty = true;
    foreach ($assoc as $v) { if ($v !== '') { $allEmpty = false; break; } }
    if (!$allEmpty) $out[] = $assoc;
  }
  return $out;
}

function fetchAllSourcesAssocNoSDK(array $config): array {
  $union = [];
  $blocks = [];
  foreach ($config['SOURCES'] as $src) {
    $rows = fetchSheetAsAssocNoSDK($config, $src['sheet'], $src['range'] ?? 'A1:ZZ');
    $blocks[] = $rows;
    foreach ($rows as $r) foreach ($r as $k => $_) $union[$k] = true;
  }
  $cols = array_keys($union);

  $merged = [];
  foreach ($blocks as $rows) {
    foreach ($rows as $r) {
      $line = [];
      foreach ($cols as $k) $line[$k] = $r[$k] ?? '';
      $merged[] = $line;
    }
  }
  return ['headers' => $cols, 'rows' => $merged];
}

function saveRawCache(array $config, array $payload): void {
  $path = $config['RAW_CACHE_FILE'] ?? null;
  if (!$path) return;
  if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);

  $rows = $payload['rows'] ?? [];

  // ★変換したい日付列名（必要なら増やす）
  $dateCols = [
    'LINE登録日',
    'セールス日',
    '入金日',
  ];

  foreach ($rows as $idx => $row) {
    if (!is_array($row)) continue;

    foreach ($dateCols as $col) {
      if (!array_key_exists($col, $row)) continue;

      $rows[$idx][$col] = sheets_serial_to_ymd($row[$col]);
    }
  }

  $body = [
    'generated_at' => date('c'),
    'headers' => $payload['headers'] ?? [],
    'rows'    => $rows,
  ];

  file_put_contents(
    $path,
    json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
  );
}

// ========================= main =============================

// 直呼びで実行されたときだけ動かす（requireされた場合は動かない）
$calledDirectly = false;
if (PHP_SAPI === 'cli') {
  global $argv;
  $calledDirectly = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
} else {
  $calledDirectly = isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);
}

if ($calledDirectly) {
  error_reporting(E_ALL);
  ini_set('display_errors', '1');
  ini_set('log_errors', '1');

  $config = require __DIR__ . '/config.php';

  // 取得→結合
  $payload = fetchAllSourcesAssocNoSDK($config);

  // キャッシュ保存（RAW_CACHE_FILE が設定されていれば）
  saveRawCache($config, $payload);

  $rowsCount = isset($payload['rows']) ? count($payload['rows']) : 0;
  $headersCount = isset($payload['headers']) ? count($payload['headers']) : 0;

  // 出力（import_inflows_matrix.php と同じく、テキストログでOKならこれ）
  if (PHP_SAPI === 'cli') {
    echo "Sheets fetch OK\n";
    echo "headers: {$headersCount}\n";
    echo "rows: {$rowsCount}\n";
    echo "RAW_CACHE_FILE: " . ($config['RAW_CACHE_FILE'] ?? '(not set)') . "\n";
  } else {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Sheets fetch OK\n";
    echo "headers: {$headersCount}\n";
    echo "rows: {$rowsCount}\n";
    echo "RAW_CACHE_FILE: " . ($config['RAW_CACHE_FILE'] ?? '(not set)') . "\n";
  }
}
