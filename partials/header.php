<?php
// ===== 認証ガードを header.php の最上部に追記 =====
declare(strict_types=1);

// セッション開始（重複開始もOK）
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// プロジェクトルートの推定と config 読み込み
$ROOT_CANDIDATES = [__DIR__, dirname(__DIR__), dirname(__FILE__, 2)];
$CFG = [];
foreach ($ROOT_CANDIDATES as $R) {
  if (is_file($R . '/config.php')) { $CFG = require $R . '/config.php'; $ROOT = $R; break; }
}
if (empty($CFG) && is_file(__DIR__ . '/config.php')) { $CFG = require __DIR__ . '/config.php'; $ROOT = __DIR__; }

// BASE_URL（未定義なら /analyze を既定）
$BASE = rtrim($CFG['BASE_URL'] ?? '/analyze', '/');

// 現在のパス（クエリも含む）
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$qstr = $_SERVER['QUERY_STRING'] ?? '';
$full = $path . ($qstr !== '' ? '?' . $qstr : '');

// ログイン不要のパス（login/verify/logout と公開アセットは除外）
$allowExact = [
  $BASE . '/auth/login.php',
  $BASE . '/auth/verify.php',
  $BASE . '/auth/logout.php',
  $BASE . '/not-authorized.php',
];
$allowPrefix = [
  $BASE . '/public/',   // プロジェクトの静的ファイル
];

// 未ログインなら login へ（return_to を付与）
$skip = in_array($path, $allowExact, true);
if (!$skip) {
  foreach ($allowPrefix as $pfx) {
    if (strncmp($path, $pfx, strlen($pfx)) === 0) { $skip = true; break; }
  }
}
if (!$skip && empty($_SESSION['user'])) {
  header('Location: ' . $BASE . '/auth/login.php?return_to=' . rawurlencode($full), true, 302);
  exit;
}

// （キャッシュで戻れてしまうのを防ぐなら、管理画面系で no-store を付与）
if (!$skip) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}
// ===== 追記ここまで =====
?>

<?php
// partials/header.php
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/x-icon" href="public/img/icon.ico">
<link rel="apple-touch-icon" href="public/img/icon.ico">

<title><?= htmlspecialchars($pageTitle ?? '管理', ENT_QUOTES) ?></title>
<?= $extraHead ?? '' ?>
</head>
<body>

<?php require __DIR__ . '/money.php'; // ← これを追加 ?>
<?php require __DIR__ . '/date_reload.php'; // ← これを追加 ?>
<?php require __DIR__ . '/links_menu.php'; // ← これを追加 ?>
<?php require __DIR__ . '/hamburger_menu.php'; // ← これを追加 ?>
