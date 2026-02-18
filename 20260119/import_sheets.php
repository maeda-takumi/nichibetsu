<?php
// import_sheets.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
require_once __DIR__ . '/sheets_rest.php';

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
  $body = [
    'generated_at' => date('c'),
    'headers' => $payload['headers'] ?? [],
    'rows'    => $payload['rows'] ?? [],
  ];
  file_put_contents($path, json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
}
