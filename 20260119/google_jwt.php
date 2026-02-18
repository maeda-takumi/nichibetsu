<?php
// google_jwt.php (no vendor)
declare(strict_types=1);

/**
 * サービスアカウントJSONからアクセストークンを取得する（簡易ファイルキャッシュ付き）
 * 必要スコープ：Sheets読み取りだけなら https://www.googleapis.com/auth/spreadsheets.readonly
 */
function get_google_access_token(array $config): string {
  $tokenCache = $config['TOKEN_CACHE_FILE'] ?? null;
  if ($tokenCache && file_exists($tokenCache)) {
    $cached = json_decode(file_get_contents($tokenCache), true);
    if (!empty($cached['access_token']) && !empty($cached['expires_at']) && $cached['expires_at'] > time()+60) {
      return $cached['access_token'];
    }
  }

  $sa = json_decode(file_get_contents($config['SERVICE_ACCOUNT_JSON']), true);
  if (!$sa || empty($sa['client_email']) || empty($sa['private_key'])) {
    throw new RuntimeException('service_account.json の内容が不正です。');
  }

  $now = time();
  $header = ['alg' => 'RS256', 'typ' => 'JWT'];
  $claims = [
    'iss'   => $sa['client_email'],
    'scope' => 'https://www.googleapis.com/auth/spreadsheets',
    'aud'   => 'https://oauth2.googleapis.com/token',
    'iat'   => $now,
    'exp'   => $now + 3600,
  ];

  // キャッシュファイルが存在すれば削除
  if (!empty($config['TOKEN_CACHE_FILE']) && file_exists($config['TOKEN_CACHE_FILE'])) {
    unlink($config['TOKEN_CACHE_FILE']);
  }
  $jwt = jwt_encode_rs256($header, $claims, $sa['private_key']);

  // トークンエンドポイントへ
  $post = http_build_query([
    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
    'assertion'  => $jwt,
  ], '', '&');

  $ch = curl_init('https://oauth2.googleapis.com/token');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT => 20,
  ]);
  $res = curl_exec($ch);
  if ($res === false) throw new RuntimeException('Token request failed: '.curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $data = json_decode($res, true);
  if ($code !== 200 || empty($data['access_token'])) {
    throw new RuntimeException('Token error: HTTP '.$code.' '.$res);
  }

  $accessToken = $data['access_token'];
  $expiresIn   = (int)($data['expires_in'] ?? 3600);
  if ($tokenCache) {
    if (!is_dir(dirname($tokenCache))) mkdir(dirname($tokenCache), 0775, true);
    file_put_contents($tokenCache, json_encode([
      'access_token' => $accessToken,
      'expires_at'   => time() + $expiresIn - 30,
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  }
  return $accessToken;
}

/** JWT作成（RS256） */
function jwt_encode_rs256(array $header, array $claims, string $privateKeyPem): string {
  $enc = fn($o) => rtrim(strtr(base64_encode(json_encode($o, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
  $header64 = $enc($header);
  $claims64 = $enc($claims);
  $signingInput = $header64.'.'.$claims64;

  $pkey = openssl_pkey_get_private($privateKeyPem);
  if (!$pkey) throw new RuntimeException('Invalid private key.');
  $sig = '';
  $ok = openssl_sign($signingInput, $sig, $pkey, OPENSSL_ALGO_SHA256);
  openssl_pkey_free($pkey);
  if (!$ok) throw new RuntimeException('openssl_sign failed.');

  $sig64 = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
  return $signingInput.'.'.$sig64;
}
