<?php
// /analyze/auth/allow_editor.php
declare(strict_types=1);
session_start();

/* ===== 共通ヘッダ（認証・CSS読込のみ） ===== */
$ROOT = dirname(__DIR__);               // /.../analyze
$CFG  = is_file($ROOT.'/config.php') ? require $ROOT.'/config.php' : [];
$BASE = rtrim($CFG['BASE_URL'] ?? '/analyze', '/');
$pageTitle = '許可メール編集';
require __DIR__ . '/header2.php';        // ※ auth/header.php（ハンバーガー無しの軽量版）

/* ===== 権限（管理者限定にしたい場合は config.php に ADMIN_EMAILS を用意） ===== */
$me = $_SESSION['user']['email'] ?? '';
$ADMINS = $CFG['ADMIN_EMAILS'] ?? [];   // 例: ['you@example.co.jp', 'ceo@example.com']
$isAdmin = empty($ADMINS) ? !empty($me) : in_array(strtolower($me), array_map('strtolower',$ADMINS), true);
// if (!$isAdmin) {
//   http_response_code(403);
//   echo '<main class="container" style="max-width:720px;margin:10vh auto"><div class="card p-24"><h1 class="title">権限がありません</h1><p class="muted">このページの編集権限がありません。</p></div></main>';
//   require __DIR__ . '/footer.php'; exit;
// }

/* ===== 定数など ===== */
$FILE = __DIR__ . '/allowed_emails.json';
$BACKUP_DIR = __DIR__;

/* ===== CSRF ===== */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

function load_allow(string $file): array {
  if (!is_file($file)) return ['allow'=>[]];
  $j = file_get_contents($file);
  $data = json_decode((string)$j, true);
  if (!is_array($data)) $data = ['allow'=>[]];
  // 正規化
  $out = [];
  foreach (($data['allow'] ?? []) as $row) {
    $mail = strtolower(trim((string)($row['mail'] ?? '')));
    $name = (string)($row['name'] ?? '');
    $active = array_key_exists('active',$row) ? (bool)$row['active'] : true;
    if ($mail !== '') $out[] = ['mail'=>$mail,'name'=>$name,'active'=>$active];
  }
  return ['allow'=>$out];
}
function save_allow(string $file, array $data, string $backupDir): bool {
  // バックアップ
  $backup = $backupDir . '/allowed_emails.backup.' . date('Ymd_His') . '.json';
  if (is_file($file)) @copy($file, $backup);
  // 保存（ロック）
  $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
  $tmp  = $file . '.tmp';
  if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
  return rename($tmp, $file);
}

/* ===== POST ハンドリング ===== */
$stateMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // JSONで返さず、PRGパターン（リダイレクト）でフラッシュメッセージのみ
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
    $stateMsg = 'CSRFトークンが不正です。';
  } else {
    $data = load_allow($FILE);
    $list = $data['allow'];

    $action = $_POST['action'] ?? '';
    $idx    = isset($_POST['idx']) ? (int)$_POST['idx'] : -1;

    if ($action === 'add') {
      $mail = strtolower(trim((string)($_POST['mail'] ?? '')));
      $name = (string)($_POST['name'] ?? '');
      $actv = isset($_POST['active']) ? (bool)$_POST['active'] : true;
      if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        $stateMsg = 'メール形式が不正です。';
      } else {
        // 既存同一メールがあれば上書き
        $replaced = false;
        foreach ($list as &$row) {
          if ($row['mail'] === $mail) { $row['name'] = $name; $row['active'] = $actv; $replaced = true; break; }
        }
        if (!$replaced) $list[] = ['mail'=>$mail,'name'=>$name,'active'=>$actv];
        $data['allow'] = array_values($list);
        $ok = save_allow($FILE, $data, $BACKUP_DIR);
        $stateMsg = $ok ? '追加/更新しました。' : '保存に失敗しました。権限を確認してください。';
      }

    } elseif ($action === 'update' && $idx >= 0 && isset($list[$idx])) {
      $mail = strtolower(trim((string)($_POST['mail'] ?? '')));
      $name = (string)($_POST['name'] ?? '');
      $actv = isset($_POST['active']) ? (bool)$_POST['active'] : false;
      if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        $stateMsg = 'メール形式が不正です。';
      } else {
        $list[$idx] = ['mail'=>$mail,'name'=>$name,'active'=>$actv];
        $data['allow'] = array_values($list);
        $ok = save_allow($FILE, $data, $BACKUP_DIR);
        $stateMsg = $ok ? '更新しました。' : '保存に失敗しました。';
      }

    } elseif ($action === 'toggle' && $idx >= 0 && isset($list[$idx])) {
      $list[$idx]['active'] = !$list[$idx]['active'];
      $data['allow'] = array_values($list);
      $ok = save_allow($FILE, $data, $BACKUP_DIR);
      $stateMsg = $ok ? '状態を切り替えました。' : '保存に失敗しました。';

    } elseif ($action === 'delete' && $idx >= 0 && isset($list[$idx])) {
      array_splice($list, $idx, 1);
      $data['allow'] = array_values($list);
      $ok = save_allow($FILE, $data, $BACKUP_DIR);
      $stateMsg = $ok ? '削除しました。' : '保存に失敗しました。';

    } else {
      $stateMsg = '不正な操作です。';
    }
  }

  // PRG: 画面更新で二重送信を避ける
  header('Location: '.$BASE.'/auth/allow_editor.php?msg='.rawurlencode($stateMsg));
  exit;
}

/* ===== 表示 ===== */
$data = load_allow($FILE);
$list = $data['allow'];
$msg  = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
?>
<main class="container" style="max-width:880px;margin:6vh auto">
  <div class="card p-24">
    <h1 class="title">許可メール編集</h1>
    <p class="muted">ファイル: <code><?= htmlspecialchars($FILE) ?></code></p>

    <?php if ($msg): ?>
      <div class="alert" style="margin:12px 0;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;">
        <?= htmlspecialchars($msg) ?>
      </div>
    <?php endif; ?>

    <!-- 追加フォーム -->
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="action" value="add">
      <input name="mail" type="email" required placeholder="mail@example.com" style="flex:1;min-width:220px;padding:8px 10px">
      <input name="name" type="text"  placeholder="氏名" style="flex:1;min-width:180px;padding:8px 10px">
      <label style="display:flex;align-items:center;gap:6px">
        <input name="active" type="checkbox" value="1" checked> active
      </label>
      <button class="btn" type="submit">追加/更新</button>
    </form>

    <!-- 一覧 -->
    <div class="table-wrap" style="overflow:auto;">
      <table class="table" style="width:100%;border-collapse:collapse;">
        <thead>
          <tr>
            <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px">メール</th>
            <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px">氏名</th>
            <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px">状態</th>
            <th style="border-bottom:1px solid #e5e7eb;padding:8px;width:240px">操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($list as $i => $row): ?>
          <tr>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
              <input type="hidden" name="idx"  value="<?= $i ?>">
              <td style="border-bottom:1px solid #f1f5f9;padding:6px">
                <input name="mail" type="email" value="<?= htmlspecialchars($row['mail']) ?>" required style="width:100%;padding:6px">
              </td>
              <td style="border-bottom:1px solid #f1f5f9;padding:6px">
                <input name="name" type="text"  value="<?= htmlspecialchars($row['name']) ?>" style="width:100%;padding:6px">
              </td>
              <td style="border-bottom:1px solid #f1f5f9;padding:6px">
                <label style="display:flex;align-items:center;gap:6px">
                  <input type="checkbox" name="active" value="1" <?= $row['active'] ? 'checked':''; ?>> active
                </label>
              </td>
              <td style="border-bottom:1px solid #f1f5f9;padding:6px">
                <button class="btn" name="action" value="update" type="submit">保存</button>
                <button class="btn ghost" name="action" value="toggle" type="submit">有効/無効</button>
                <button class="btn danger" name="action" value="delete" type="submit"
                  onclick="return confirm('削除してよろしいですか？')">削除</button>
              </td>
            </form>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="margin-top:12px">
      <a class="btn ghost" href="<?= $BASE ?>/admin_dashboard.php">ダッシュボードへ戻る</a>
    </div>
  </div>
</main>

<?php require __DIR__ . '/footer.php'; ?>
