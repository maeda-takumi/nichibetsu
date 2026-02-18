<?php
// sheets_rest.php
declare(strict_types=1);
require_once __DIR__ . '/google_jwt.php';

/**
 * A1レンジを指定して値を取得
 * @return array [['...','...'], ['...','...'], ...] の二次元配列（空なら []）
 *
 * NOTE:
 * - 日付を「表示値(1/3)」ではなく「内部値」で取るため
 *   valueRenderOption=UNFORMATTED_VALUE を付ける
 * - 日付は SERIAL_NUMBER で返す（例: 45293.0）
 */
function sheets_values_get(array $config, string $spreadsheetId, string $a1Range): array {
  $token = get_google_access_token($config);

  $baseUrl = 'https://sheets.googleapis.com/v4/spreadsheets/'
    . rawurlencode($spreadsheetId)
    . '/values/'
    . rawurlencode($a1Range);

  // ★重要：内部値で取得
  $query = http_build_query([
    'valueRenderOption'    => 'UNFORMATTED_VALUE',
    'dateTimeRenderOption' => 'SERIAL_NUMBER',
  ]);

  $url = $baseUrl . '?' . $query;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $token,
      'Accept: application/json',
    ],
    CURLOPT_TIMEOUT => 20,
  ]);

  $res = curl_exec($ch);
  if ($res === false) throw new RuntimeException('Sheets GET failed: ' . curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $data = json_decode($res, true);
  if ($code !== 200) throw new RuntimeException('Sheets error: HTTP ' . $code . ' ' . $res);

  return $data['values'] ?? [];
}
