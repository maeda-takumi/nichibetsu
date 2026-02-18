<?php
// auth/verify.php
declare(strict_types=1);
session_start();

/* 常に JSON を返す & 余計な出力を抑止 */
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
while (ob_get_level()) { ob_end_clean(); }

/* すべての PHP エラー/例外を JSON 化 */
set_error_handler(function($no,$str,$file,$line){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'php_error','detail'=>"$str @ $file:$line"]);
  exit;
});
set_exception_handler(function($ex){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'exception','detail'=>$ex->getMessage()]);
  exit;
});

/* 入力 */
$ROOT = dirname(__DIR__);
$CFG  = require_once $ROOT . '/config.php';  // ← ここでコケると上のハンドラが拾います
$raw  = file_get_contents('php://input');
$req  = json_decode($raw, true) ?? [];
$idToken  = $req['id_token'] ?? '';
$returnTo = $req['return_to'] ?? '/';
if (!$idToken) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing id_token']); exit; }

/* tokeninfo 取得（curl → fopen フォールバック） */
$tokeninfoUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token='.urlencode($idToken);
$http = 0; $resp = false; $err = '';

if (function_exists('curl_init')) {
  $ch = curl_init($tokeninfoUrl);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_FAILONERROR=>false]);
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
  $err  = curl_error($ch);
  curl_close($ch);
} else {
  // fopen フォールバック
  $ctx = stream_context_create(['http'=>['method'=>'GET','timeout'=>10,'ignore_errors'=>true]]);
  $resp = @file_get_contents($tokeninfoUrl, false, $ctx);
  // $http_response_header からステータス抽出
  if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
    $http = (int)$m[1];
  }
  $err = $resp === false ? 'file_get_contents failed' : '';
}

if ($resp === false) {
  http_response_code(502);
  echo json_encode(['ok'=>false,'error'=>'tokeninfo_unreachable','detail'=>$err]); exit;
}
if ($http !== 200) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'invalid_tokeninfo','http'=>$http,'detail'=>$resp]); exit;
}

$payload = json_decode($resp, true);
if (!is_array($payload)) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'tokeninfo_not_json','detail'=>substr((string)$resp,0,300)]); exit;
}

/* クレーム検証 */
$audExpected = (string)($CFG['CLIENT_ID'] ?? '');
$issAllowed  = ['accounts.google.com','https://accounts.google.com'];
if (
  empty($payload['aud']) || $payload['aud'] !== $audExpected ||
  empty($payload['iss']) || !in_array($payload['iss'], $issAllowed, true) ||
  empty($payload['exp']) || time() >= (int)$payload['exp']
) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'claims_check_failed','detail'=>$payload]); exit;
}

/* メール検証 */
$email    = strtolower(trim((string)($payload['email'] ?? '')));
$verified = (bool)($payload['email_verified'] ?? false);
if (!$email || !$verified) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'email_not_verified']); exit;
}

/* 許可リスト（新構造） */
$allowJson = __DIR__ . '/allowed_emails.json';
if (!is_file($allowJson)) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'allow_list_missing']); exit; }
$allowData = json_decode((string)file_get_contents($allowJson), true) ?? [];

$allowed = []; // email(lower) => ['name'=>..., 'active'=>bool]
foreach (($allowData['allow'] ?? []) as $row) {
  $mail   = strtolower(trim((string)($row['mail'] ?? '')));
  $name   = (string)($row['name'] ?? '');
  $active = array_key_exists('active',$row) ? (bool)$row['active'] : true;
  if ($mail !== '' && $active) $allowed[$mail] = ['name'=>$name, 'active'=>true];
}

if (!isset($allowed[$email])) {
  echo json_encode(['ok'=>false,'redirect'=>'/auth/not-authorized.php']); exit;
}

/* セッション確立 */
session_regenerate_id(true);
$_SESSION['user'] = [
  'email'   => $email,
  'name'    => ($payload['name'] ?? '') !== '' ? $payload['name'] : ($allowed[$email]['name'] ?? ''),
  'picture' => $payload['picture'] ?? '',
  'sub'     => $payload['sub'] ?? '',
  'iat'     => (int)($payload['iat'] ?? time()),
];

/* Cookie属性強化 */
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
  ini_set('session.cookie_secure','1');
}

echo json_encode(['ok'=>true,'redirect'=>$returnTo]);
