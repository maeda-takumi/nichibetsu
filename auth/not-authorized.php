<?php
// /analyze/not-authorized.php
declare(strict_types=1);

// 画面タイトルだけ先に用意（auth/header.php が参照）
$pageTitle = 'アクセスが許可されていません';

// auth 用の軽量ヘッダを使用（ハンバーガー等は載りません）
require __DIR__ . '/header.php';

// auth/header.php 内で $BASE / $CFG がセットされます
$BASE = isset($BASE) ? $BASE : '/analyze';
?>
<main class="container" style="max-width:720px;margin:10vh auto">
  <div class="card p-24">
    <h1 class="title">アクセスが許可されていません</h1>
    <p class="muted" style="margin-top:8px">
      許可された Google メールアドレスでログインしてください。
    </p>

    <div class="row" style="display:flex;gap:10px;margin-top:18px">
      <a class="btn" href="<?= $BASE ?>/auth/login.php">別のアカウントでサインイン</a>
      <a class="btn ghost" href="<?= $BASE ?>/admin_dashboard.php">ダッシュボードへ戻る</a>
    </div>

    <details style="margin-top:16px">
      <summary>ヘルプ</summary>
      <ul style="margin:8px 0 0 18px;line-height:1.7">
        <li>社内の許可リストにメールが登録されているか確認してください。</li>
        <li>別の Google アカウントでサインインし直してください。</li>
      </ul>
    </details>
  </div>
</main>

<?php require __DIR__ . '/auth/footer.php'; ?>
