<?php
// youtube_watch_import.php
// 依存: config.php, data/actors.json, sheets_rest.php（＋その内部の google_jwt.php / SERVICE_ACCOUNT_JSON）
// - Sheets: サービスアカウントで認証
// - YouTube: 各チャンネルの oauth/{channel}/token.json（refresh_token からアクセストークン発行）

declare(strict_types=1);
mb_internal_encoding('UTF-8');

$config = require __DIR__ . '/config.php';
date_default_timezone_set(isset($config['TIMEZONE']) ? $config['TIMEZONE'] : 'Asia/Tokyo');

require_once __DIR__ . '/sheets_rest.php'; // get_google_access_token() を内部で利用

// ---- ユーティリティ ----
function http_post_form(string $url, array $fields): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($fields),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT => 30,
  ]);
  $res = curl_exec($ch);
  if ($res === false) throw new RuntimeException('HTTP POST failed: '.curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $res, json_decode($res, true)];
}

function http_get_json(string $url, array $headers): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 30,
  ]);
  $res = curl_exec($ch);
  if ($res === false) throw new RuntimeException('HTTP GET failed: '.curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $res, json_decode($res, true)];
}

// ---- Sheets 追記（サービスアカウントトークンを使用）----
function sheets_values_append(array $config, string $spreadsheetId, string $a1Range, array $rows): void {
  $token = get_google_access_token($config); // ← sheets_rest.php 内のJWT認証
  $url = 'https://sheets.googleapis.com/v4/spreadsheets/'.
         rawurlencode($spreadsheetId).'/values/'.
         rawurlencode($a1Range).':append?valueInputOption=USER_ENTERED';

  $payload = json_encode(['values' => $rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer '.$token,
      'Content-Type: application/json',
      'Accept: application/json',
    ],
    CURLOPT_TIMEOUT => 30,
  ]);
  $res = curl_exec($ch);
  if ($res === false) throw new RuntimeException('Sheets append failed: '.curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($code !== 200 && $code !== 201) {
    throw new RuntimeException('Sheets append error: HTTP '.$code.' '.$res);
  }
}

// ---- A列（yyyy/mm/dd or yyyy-mm-dd or シリアル）→ DateTimeImmutable ----
function parse_sheet_date_to_dt($v, string $tz): ?DateTimeImmutable {
  if ($v === null || $v === '') return null;

  if (is_string($v)) {
    $s = strtr(trim($v), ['／'=>'/', '/'=>'-', '.'=>'-']); // 全角→半角, 区切りを '-'
    $ts = strtotime($s);
    if ($ts !== false) return (new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone($tz));
    // 数字文字列（"45678"）ならシリアル扱い
    if (ctype_digit($v)) $v = (float)$v;
  }
  if (is_int($v) || is_float($v)) {
    $base = new DateTimeImmutable('1899-12-30', new DateTimeZone($tz)); // Google/Excel基準
    $days = (int)floor((float)$v);
    return $base->modify('+'.$days.' days');
  }
  return null;
}

// ---- actors.json 読み込み ----
function get_actors(): array {
  $path = __DIR__ . '/data/actors.json';
  if (!file_exists($path)) {
    echo "❌ actors.json が見つかりません: {$path}\n";
    return [];
  }
  $json = json_decode(file_get_contents($path), true);
  return $json['items'] ?? [];
}

// ---- YouTube アクセストークン（refresh_token から）----
function get_yt_access_token_for_channel(string $channelName): ?string {
  $path = __DIR__ . "/outh/{$channelName}/token.json";
  if (!file_exists($path)) return null;
  $t = json_decode(file_get_contents($path), true);
  if (empty($t['client_id']) || empty($t['client_secret']) || empty($t['refresh_token'])) return null;

  [$code, $raw, $json] = http_post_form('https://oauth2.googleapis.com/token', [
    'client_id' => $t['client_id'],
    'client_secret' => $t['client_secret'],
    'refresh_token' => $t['refresh_token'],
    'grant_type' => 'refresh_token',
  ]);
  if ($code !== 200 || empty($json['access_token'])) {
    echo "❌ アクセストークン取得失敗（YouTube）: {$channelName} / HTTP {$code}\n{$raw}\n";
    return null;
  }
  return $json['access_token'];
}

// ========== メイン ==========

// 1) A列の最新日付を取得
$spreadsheetId = (string)$config['WATCH_SPREADSHEET_ID'];
$sheetName     = (string)$config['WATCH_SHEET_NAME'];
$a1_read       = $sheetName.'!A:A'; // 全A列（ヘッダ込み推奨）
$tz            = isset($config['TIMEZONE']) ? $config['TIMEZONE'] : 'Asia/Tokyo';

try {
  // sheets_rest.php のGET（サービスアカウントで認証）
  $values = sheets_values_get($config, $spreadsheetId, $a1_read);

  // デバッグ：先頭10件を表示
  echo "📋 取得したA列データ（先頭10件）:\n";
  $c = 0;
  foreach ($values as $r) {
    if (isset($r[0])) {
      echo "  → " . var_export($r[0], true) . "\n";
      if (++$c >= 10) break;
    }
  }
  echo "-------------------------\n";

  // 最新日付（最大）を求める
  $latest = null;
  foreach ($values as $idx => $row) {
    if (!isset($row[0])) continue;
    $dt = parse_sheet_date_to_dt($row[0], $tz);
    if (!$dt) continue;
    if ($latest === null || $dt > $latest) $latest = $dt;
  }

  if ($latest === null) {
    echo "⚠️ スプレッドシートに有効な日付が見つかりませんでした。\n";
    exit;
  }

  $startDate = $latest->modify('+1 day');
  $endDate   = (new DateTimeImmutable('today', new DateTimeZone($tz)));

  if ($startDate > $endDate) {
    echo "📅 取得対象なし（".$startDate->format('Y-m-d')." > ".$endDate->format('Y-m-d')."）\n";
    exit;
  }

  echo "📅 取得範囲：".$startDate->format('Y-m-d')." 〜 ".$endDate->format('Y-m-d')."\n\n";

} catch (Throwable $e) {
  echo "❌ Sheets読み取りでエラー: ".$e->getMessage()."\n";
  exit;
}

// 2) 各チャンネルのAnalyticsを取得 → Sheetsへappend
$actors = get_actors();
if (!$actors) {
  echo "⚠️ actors.json の items が空です。\n";
  exit;
}

foreach ($actors as $actor) {
  $channelName = $actor['channel']    ?? '';
  $channelId   = $actor['channel_id'] ?? '';

  if (!$channelName) continue;
  if (!$channelId) {
    echo "❌ スキップ（channel_idなし）: {$channelName}\n";
    continue;
  }

  $ytToken = get_yt_access_token_for_channel($channelName);
  if (!$ytToken) {
    echo "❌ スキップ（tokenなし）: {$channelName}\n";
    continue;
  }

  $apiUrl = "https://youtubeanalytics.googleapis.com/v2/reports?" . http_build_query([
    'ids'        => "channel=={$channelId}",
    'startDate'  => $startDate->format('Y-m-d'),
    'endDate'    => $endDate->format('Y-m-d'),
    'metrics'    => 'views,estimatedMinutesWatched',
    'dimensions' => 'day',
  ]);

  [$code, $raw, $json] = http_get_json($apiUrl, ["Authorization: Bearer {$ytToken}"]);
  if ($code !== 200) {
    echo "❌ 取得失敗: {$channelName} / HTTP {$code}\n{$raw}\n\n";
    continue; // API制限/一時エラーは仕様通りスルー
  }

  $rows = $json['rows'] ?? [];
  if (!$rows) {
    echo "⚠️ データなし: {$channelName}\n\n";
    continue;
  }

  echo "✅ {$channelName} ({$channelId})\n";
  $append = [];
  foreach ($rows as $r) {
    // [day, views, minutes]
    $day     = (string)$r[0];       // "YYYY-MM-DD"
    $views   = (int)$r[1];
    $minutes = (int)$r[2];
    $hours   = round($minutes / 60, 2); // 仕様：時（小数2桁）

    echo "  📅 {$day} / 🕒 {$hours} 時 / ▶️ {$views}\n";
    $append[] = [
      str_replace('-', '/', $day), // A: yyyy/mm/dd
      $hours,                      // B: 総再生時間（時）
      $views,                      // C: 再生回数
      "",                          // D: インプレッション（無視）
      $channelName,                // E: 動画担当（actors.jsonのchannel）
    ];
  }

  // Append to Sheet
  try {
    sheets_values_append($config, $spreadsheetId, $sheetName.'!A:E', $append);
    echo "   ↳ ✅ 追記: ".count($append)." 行\n\n";
  } catch (Throwable $e) {
    echo "   ↳ ❌ 追記エラー: ".$e->getMessage()."\n\n";
  }
}
