<?php
// auth/login.php
declare(strict_types=1);
session_start();

if (!empty($_SESSION['user'])) { header('Location: /'); exit; }

// ルート・設定
$ROOT = dirname(__DIR__);                      // /.../analyze
$CFG  = require_once $ROOT . '/config.php';    // CLIENT_ID 等はここから
$BASE = rtrim($CFG['BASE_URL'] ?? '/analyze', '/');

$returnTo = isset($_GET['return_to']) ? (string)$_GET['return_to'] : '/';

// 画面タイトルのみ渡して auth/header.php を使用（ハンバーガー等なし）
$pageTitle = '社内専用ログイン';
require __DIR__ . '/header.php';
?>
<script src="https://accounts.google.com/gsi/client" async defer></script>

<main class="container" style="max-width:480px;margin:10vh auto">
  <div class="card p-24">
    <h1 class="title">Googleアカウントでログイン</h1>
    <p class="muted">許可された社内メールのみアクセスできます。</p>

    <div id="g_id_onload"
         data-client_id="<?= htmlspecialchars($CFG['CLIENT_ID'] ?? '', ENT_QUOTES) ?>"
         data-context="signin"
         data-ux_mode="popup"
         data-callback="onGoogleCredential"
         data-auto_select="false"></div>

    <div class="g_id_signin"
         data-type="standard"
         data-size="large"
         data-shape="rectangular"
         data-theme="outline"></div>

    <noscript><p style="color:#b00">このページを利用するにはJavaScriptを有効にしてください。</p></noscript>
  </div>
</main>

<script>
  const BASE = "<?= $BASE ?>";

  async function onGoogleCredential(resp){
    try{
      const r = await fetch(BASE + '/auth/verify.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        credentials: 'include',
        body: JSON.stringify({ id_token: resp.credential, return_to: "<?= htmlspecialchars($returnTo, ENT_QUOTES) ?>" })
      });
      const data = await r.json();
      if(!r.ok || !data.ok){
        location.href = BASE + (data.redirect || '/admin_dashboard.php');
        return;
      }
      location.href = data.redirect || "<?= htmlspecialchars($returnTo, ENT_QUOTES) ?>";
    }catch(e){
      alert('ログインに失敗しました: ' + e.message);
    }
  }
</script>

<?php require __DIR__ . '/footer.php'; ?>
