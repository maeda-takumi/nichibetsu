<?php
// import_watch_metrics.php — 既存IMPORTと同じ関数だけを使う極小実装
// 依存: sheets_rest.php の sheets_values_get($config, $SPREADSHEET_ID, $A1_RANGE)

mb_internal_encoding('UTF-8');

$config = require __DIR__ . '/config.php';
date_default_timezone_set(isset($config['TIMEZONE']) ? $config['TIMEZONE'] : 'Asia/Tokyo');

// 既存の取り込みと同じ依存だけ読み込む
require_once __DIR__ . '/sheets_rest.php';

// ---- 設定（他IMPORTと同じ粒度に統一）----
// * 旧キー（WATCH_SHEET_ID / WATCH_SHEET_RANGE）もフォールバック対応
$spreadsheetId = (string)(
  isset($config['WATCH_SPREADSHEET_ID']) ? $config['WATCH_SPREADSHEET_ID'] :
  (isset($config['WATCH_SHEET_ID'])      ? $config['WATCH_SHEET_ID']      : '')
);
$sheetName = (string)(
  isset($config['WATCH_SHEET_NAME']) ? $config['WATCH_SHEET_NAME'] :
  (preg_match('/^([^!]+)!/', isset($config['WATCH_SHEET_RANGE']) ? $config['WATCH_SHEET_RANGE'] : '', $m) ? $m[1] : '')
);
$rangeCols = (string)(
  isset($config['WATCH_RANGE']) ? $config['WATCH_RANGE'] :
  (preg_match('/![A-Z0-9:]+$/i', isset($config['WATCH_SHEET_RANGE']) ? $config['WATCH_SHEET_RANGE'] : '', $m) ? ltrim($m[0], '!') : 'A1:E')
);
$outPath = (string)(
  isset($config['WATCH_METRICS_FILE']) ? $config['WATCH_METRICS_FILE'] : (__DIR__ . '/data/watch_metrics.json')
);

function abortx($msg, $http=500){
  if (PHP_SAPI !== 'cli' && !headers_sent()) http_response_code($http);
  error_log('[ERROR] '.$msg);
  echo "ERROR: {$msg}\n";
  exit(1);
}
if ($spreadsheetId==='') abortx('WATCH_SPREADSHEET_ID (or WATCH_SHEET_ID) is empty');
if ($sheetName==='')     abortx('WATCH_SHEET_NAME が未設定です');
if (!is_dir(dirname($outPath)) && !@mkdir(dirname($outPath), 0775, true)) {
  abortx('Failed to create data dir: '.dirname($outPath));
}

// ---- 日付（数値シリアル/文字列どちらも）→ YYYY-MM-DD ----
function parse_sheet_date($v, $tz){
  if (is_string($v)) {
    $s = strtr(trim($v), array('/'=>'-', '.'=>'-'));
    $ts = strtotime($s);
    if ($ts !== false) return date('Y-m-d', $ts);
    if (is_numeric($v)) $v = (float)$v; // "45678" のような文字列数値も対応
  }
  if (is_int($v) || is_float($v)) {
    $base = new DateTimeImmutable('1899-12-30', new DateTimeZone($tz)); // Google/Excel基準
    $days = (int)floor((float)$v);
    $dt   = $base->modify('+'.$days.' days');
    if ($dt) return $dt->format('Y-m-d');
  }
  return null;
}

// ---- ヘッダゆる判定（既存IMPORTと同等の“名称ゆる判定”だけ）----
function header_map($headers){
  $m = array('date'=>-1,'watch_hours'=>-1,'views'=>-1,'impressions'=>-1,'actor_name'=>-1);
  foreach ($headers as $i=>$raw) {
    $h = preg_replace('/\s+/', '', mb_strtolower(trim((string)$raw)));
    if ($h==='') continue;
    if     (preg_match('/^(日付|date)$/u',$h))                    $m['date']=$i;
    elseif (preg_match('/(総?再生(時間|時)|視聴時間)/u',$h))       $m['watch_hours']=$i;
    elseif (preg_match('/(総?再生数|views?)/u',$h))             $m['views']=$i;
    elseif (preg_match('/(インプレッション|impressions?)/u',$h))  $m['impressions']=$i;
    elseif (preg_match('/(動画担当|演者|担当|actor|talent)/u',$h)) $m['actor_name']=$i;
  }
  return $m;
}

// ================= 前処理：YouTube情報を自動取得 =================
$ytScript = __DIR__ . '/youtube_get_info.php';
if (file_exists($ytScript)) {
  echo "▶️ YouTubeデータ取得を開始します...\n";
  $cmd = 'php ' . escapeshellarg($ytScript);
  $descriptor = [
    1 => ['pipe', 'w'], // STDOUT
    2 => ['pipe', 'w'], // STDERR
  ];
  $process = proc_open($cmd, $descriptor, $pipes);
  if (is_resource($process)) {
    // 出力をリアルタイム表示
    while (($line = fgets($pipes[1])) !== false) echo $line;
    while (($line = fgets($pipes[2])) !== false) echo $line;
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
      abortx("YouTube情報取得スクリプトがエラー終了しました (exit={$exitCode})");
    }
  } else {
    abortx("YouTube情報取得スクリプトを実行できませんでした");
  }
} else {
  echo "⚠️ youtube_get_info.php が見つかりません。スキップします。\n";
}

// ================= 実行 =================
try {
  // 既存ヘルパをそのまま使用（他IMPORTと同一レイヤ）
  $a1 = $sheetName . '!' . $rangeCols;
  $values = sheets_values_get($config, $spreadsheetId, $a1); // ← 既存関数のみ

  if (!is_array($values) || empty($values)) abortx('Sheet has no rows');

  $headers = $values[0];
  $map = header_map($headers);

  $items   = array();
  $byMonth = array();
  $toF = function($s){ return (float)str_replace(',', '', (string)$s); };
  $toI = function($s){ return (int)  str_replace(',', '', (string)$s); };

  for ($r=1, $n=count($values); $r<$n; $r++) {
    $row = $values[$r];
    $get = function($i) use ($row) { return ($i>=0 && $i<count($row)) ? $row[$i] : ''; };

    $dateRaw  = $get(isset($map['date']) ? $map['date'] : -1);
    $actor    = (string)$get(isset($map['actor_name']) ? $map['actor_name'] : -1);

    $dateStr = parse_sheet_date($dateRaw, isset($config['TIMEZONE']) ? $config['TIMEZONE'] : 'Asia/Tokyo');
    if ($dateStr === null) continue; // 日付が取れない行だけ除外

    $ts = strtotime($dateStr);
    if ($ts === false) continue;

    $wh = (isset($map['watch_hours']) && $map['watch_hours'] >= 0) ? $toF($get($map['watch_hours'])) : 0.0;
    $vw = (isset($map['views'])       && $map['views']       >= 0) ? $toI($get($map['views']))       : 0;
    $im = (isset($map['impressions']) && $map['impressions'] >= 0) ? $toI($get($map['impressions'])) : 0;

    $ym  = date('Y-m', $ts);
    $day = (string)date('j', $ts);

    // 取り込みは“そのまま”——加工・スキップなし
    $items[] = array(
      'actor_name'  => $actor,
      'date'        => date('Y-m-d', $ts),
      'watch_hours' => $wh,
      'views'       => $vw,
      'impressions' => $im,
    );
    if (!isset($byMonth[$ym])) $byMonth[$ym] = array();
    if (!isset($byMonth[$ym][$actor])) $byMonth[$ym][$actor] = array();
    $byMonth[$ym][$actor][$day] = array(
      'watch_hours'=>$wh, 'views'=>$vw, 'impressions'=>$im,
    );
  }

  $out = array(
    'source'     => 'google_sheets',
    'sheet_id'   => $spreadsheetId,
    'range'      => $a1,
    'updated_at' => date('c'),
    'items'      => $items,
    'by_month'   => $byMonth,
  );

  $json = json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if ($json === false) abortx('json_encode failed');
  if (file_put_contents($outPath, $json) === false) abortx('Failed to write '.$outPath);

  echo "OK: saved to {$outPath}\n";
} catch (Throwable $e) {
  abortx($e->getMessage());
}
