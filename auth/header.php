<?php
// ===== authチェック（header.php の先頭に追加）=====
declare(strict_types=1);

// セッション開始
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// ルート推定と設定読込（partials/header.php でもルート直下でも動く）
$ROOT = dirname(__DIR__);
if (!is_file($ROOT . '/config.php')) { $ROOT = __DIR__; }
$CFG  = is_file($ROOT . '/config.php') ? require $ROOT . '/config.php' : [];

// プロジェクトのベースURL（例: '/analyze'）
$BASE = rtrim($CFG['BASE_URL'] ?? '/analyze', '/');

// 現在のパス（クエリ含む）
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$qstr = $_SERVER['QUERY_STRING'] ?? '';
$full = $path . ($qstr !== '' ? '?' . $qstr : '');

// ログイン不要なパス（完全一致 or 前方一致）
$allowExact = [
  $BASE . '/auth/login.php',
  $BASE . '/auth/verify.php',
  $BASE . '/auth/logout.php',
  $BASE . '/not-authorized.php',
];
$allowPrefix = [
  $BASE . '/public/',   // 静的アセット
  '/public/',           // 念のため（環境により）
];

// 除外判定
$skip = in_array($path, $allowExact, true);
if (!$skip) {
  foreach ($allowPrefix as $pfx) {
    if (strncmp($path, $pfx, strlen($pfx)) === 0) { $skip = true; break; }
  }
}

// 未ログインならログイン画面へ（return_to を付与）
if (!$skip && empty($_SESSION['user'])) {
  header('Location: ' . $BASE . '/auth/login.php?return_to=' . rawurlencode($full), true, 302);
  exit;
}
// ===== ここまで追加。以下は既存のヘッダHTML/CSS読み込み等 =====
?>

<?php
// auth/header.php

// ルートと設定（未読込なら読み込む）
$ROOT = dirname(__DIR__); // /.../analyze
if (!isset($CFG)) {
  $CFG = is_file($ROOT . '/config.php') ? require $ROOT . '/config.php' : [];
}

// ベースURL（例: '/analyze'）。configにBASE_URLがあればそれを使用
$BASE = rtrim($CFG['BASE_URL'] ?? '/analyze', '/');

// 画面タイトルと追加<head>（任意）
$pageTitle = $pageTitle ?? '';
$extraHead = $extraHead ?? '';
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?: 'Sign in', ENT_QUOTES) ?></title>

  <!-- auth用はここで確実にCSSを読み込む（絶対パス） -->
  <link rel="stylesheet" href="<?= $BASE ?>/public/styles.css?v=<?= time() ?>">

  <!-- Favicon（任意。存在しなければ無視されます） -->
  <link rel="icon" href="<?= $BASE ?>/public/img/icon.ico">

  <?= $extraHead /* 必要なら個別ページから追記可 */ ?>
</head>
<body>
