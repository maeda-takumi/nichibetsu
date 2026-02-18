<?php


$pageTitle = "演者編集";
$extraHead = '<link rel="stylesheet" href="public/styles.css?v=' . time() . '">';
require 'partials/header.php';

$jsonPath = 'data/actors.json';

// JSON読み込み
if (!file_exists($jsonPath)) {
    die('<p style="color:red;">actors.json が見つかりません。</p>');
}

/** JSON読み込み（確実に配列へ） */
$raw = @file_get_contents($jsonPath);
$data = is_string($raw) ? json_decode($raw, true) : [];
if (!is_array($data)) {
    $data = [];
}
$items = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : [];

/** 保存処理：id で該当要素のみ更新（未存在なら追加） */
// ✅ 削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = $_POST['delete_id'];
    $items = array_filter($items, fn($item) => $item['id'] !== $deleteId);
    $data['items'] = array_values($items);
    file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "<script>alert('チャンネルを削除しました。');</script>";
}elseif($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (string)$_POST['id'];
    $found = false;

    // 既存を id で更新
    foreach ($items as &$item) {
        if (isset($item['id']) && $item['id'] === $id) {
            $item['name']      = (string)($_POST['name'] ?? '');
            $item['channel']   = (string)($_POST['channel'] ?? '');
            $item['channel_id']= (string)($_POST['channel_id'] ?? '');
            $item['active']    = isset($_POST['active']);
            $item['type']      = (string)($_POST['type'] ?? '');
            $item['systems']   = (string)($_POST['systems'] ?? '');
            $item['note']      = (string)($_POST['note'] ?? '');
            $item['kana']      = (string)($_POST['kana'] ?? '');
            $item['email']     = (string)($_POST['email'] ?? '');
            $item['tags']      = ($t = trim((string)($_POST['tags'] ?? ''))) === '' ? [] : array_map('trim', explode(',', $t));
            $item['aliases']   = ($a = trim((string)($_POST['aliases'] ?? ''))) === '' ? [] : array_map('trim', explode(',', $a));
            $item['img']       = (string)($_POST['img'] ?? '');
            $found = true;
            break;
        }
    }
    unset($item);

    // 見つからなければ追加（新規）
    if (!$found) {
        $items[] = [
            'id'         => $id,
            'name'       => (string)($_POST['name'] ?? ''),
            'channel'    => (string)($_POST['channel'] ?? ''),
            'channel_id' => (string)($_POST['channel_id'] ?? ''),
            'active'     => isset($_POST['active']),
            'type'       => (string)($_POST['type'] ?? ''),
            'systems'    => (string)($_POST['systems'] ?? ''),
            'note'       => (string)($_POST['note'] ?? ''),
            'kana'       => (string)($_POST['kana'] ?? ''),
            'email'      => (string)($_POST['email'] ?? ''),
            'tags'       => ($t = trim((string)($_POST['tags'] ?? ''))) === '' ? [] : array_map('trim', explode(',', $t)),
            'aliases'    => ($a = trim((string)($_POST['aliases'] ?? ''))) === '' ? [] : array_map('trim', explode(',', $a)),
            'img'        => (string)($_POST['img'] ?? ''),
        ];
    }

    // 保存
    $data['items'] = $items;
    file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

?>
    <script>
        alert('保存しました。');
    </script>
    
<?php
    }
    // トークン有効チェック関数（重複定義防止付き）
    if (!function_exists('isTokenValid')) {
        function isTokenValid($path, $channel = '') {
            if (!file_exists($path)) {
                // echo "<p style='color:red;'>❌ token.json が見つかりません: {$path}</p>";
                return false;
            }

            $json = json_decode(file_get_contents($path), true);
            if (!$json) {
                // echo "<p style='color:red;'>❌ token.json の内容が不正です: {$path}</p>";
                return false;
            }

            $token = $json['access_token'] ?? $json['token'] ?? null;
            $refreshToken = $json['refresh_token'] ?? null;
            $tokenUri = $json['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $clientId = $json['client_id'] ?? '';
            $clientSecret = $json['client_secret'] ?? '';

            // 有効期限取得
            $expireTime = null;
            if (isset($json['expiry'])) {
                $expireTime = strtotime($json['expiry']);
            } elseif (isset($json['expires_in'], $json['created'])) {
                $expireTime = $json['created'] + $json['expires_in'];
            }

            // トークン期限切れチェック
            if ($expireTime !== null && $expireTime < time()) {
                // echo "<p style='color:orange;'>⚠️ {$channel} のトークン期限切れ（期限: " . date('Y-m-d H:i:s', $expireTime) . "）→ リフレッシュを試行中...</p>";

                if ($refreshToken && $clientId && $clientSecret) {
                    // リフレッシュリクエスト
                    $postData = http_build_query([
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'refresh_token' => $refreshToken,
                        'grant_type' => 'refresh_token',
                    ]);

                    $opts = [
                        'http' => [
                            'method'  => 'POST',
                            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                            'content' => $postData
                        ]
                    ];

                    $context = stream_context_create($opts);
                    $response = @file_get_contents($tokenUri, false, $context);
                    $result = json_decode($response, true);

                    if (!empty($result['access_token'])) {
                        // 新しいトークンを保存
                        $json['token'] = $result['access_token'];
                        $json['expiry'] = date('c', time() + $result['expires_in']);
                        file_put_contents($path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                        // echo "<p style='color:green;'>✅ {$channel} のトークンをリフレッシュしました（新しい期限: " . date('Y-m-d H:i:s', time() + $result['expires_in']) . "）</p>";
                        return true;
                    } else {
                        // echo "<p style='color:red;'>❌ {$channel} のトークンリフレッシュに失敗しました。レスポンス: " . htmlspecialchars($response) . "</p>";
                        return false;
                    }
                } else {
                    // echo "<p style='color:red;'>❌ {$channel} の refresh_token または client 情報が不足しています。</p>";
                    return false;
                }
            }

            // echo "<p style='color:green;'>✅ {$channel} のトークンは有効です。</p>";
            return true;
        }
    }



?>

<div class="channel-frame">
<h2>YouTubeチャンネル管理</h2>

<!-- 新規追加ボタン -->
<button type="button" class="add-btn" onclick="openModal('new')">＋ 新しいチャンネルを追加</button>

<ul class="channel-list">
<?php foreach ($items as $ch): ?>
    <?php
        $imgPath = trim($ch['img'] ?? '');
        $channelName = $ch['channel'] ?? '';
        $initial = mb_substr($channelName, 0, 1); // 頭1文字を取得（日本語対応）
    ?>
    <li class="channel-item card">
        <!-- チャンネルアイコン -->
        <div class="channel-avatar">
            <?php if ($imgPath && file_exists($imgPath)): ?>
                <img src="<?= htmlspecialchars($imgPath) ?>" alt="<?= htmlspecialchars($channelName) ?>">
            <?php else: ?>
                <div class="channel-initial"><?= htmlspecialchars($initial) ?></div>
            <?php endif; ?>
        </div>

        <!-- チャンネル情報 -->
        <div class="channel-info">
            <strong><?= htmlspecialchars($channelName) ?></strong><br>
            <small>ID: <?= htmlspecialchars($ch['channel_id']) ?></small>
        </div>

        <?php
        // 各チャンネルの token.json のパスを定義
        $tokenPath = "outh/" . $ch['channel'] . '/token.json';
        // 判定実行
        $isAuthenticated = isTokenValid($tokenPath);
        ?>

        <?php if ($isAuthenticated): ?>
            <span class="auth-status success">認証済み</span>
        <?php else: ?>
            <!-- <div><?=$tokenPath?></div> -->            <button type="button"
                    class="outh-btn"
                    onclick="startOAuth('<?= htmlspecialchars($ch['channel_id']) ?>')">
                認証
            </button>
        <?php endif; ?>

        <button type="button" class="edit-btn" onclick="openModal('<?= $ch['id'] ?>')">編集</button>
    </li>
<?php endforeach; ?>
</ul>


<!-- 各チャンネルの編集モーダル（初期は非表示） -->

<?php foreach ($items as $ch): ?>
<div id="modal-<?= $ch['id'] ?>" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="close" onclick="closeModal('<?= $ch['id'] ?>')">&times;</span>
        <h3>編集：<?= htmlspecialchars($ch['channel']) ?></h3>

        <form method="post">
            <input type="hidden" name="id" value="<?= htmlspecialchars($ch['id']) ?>">

            <label>名前</label>
            <input type="text" name="name" value="<?= htmlspecialchars($ch['name']) ?>">

            <label>チャンネル名</label>
            <input type="text" name="channel" value="<?= htmlspecialchars($ch['channel']) ?>">

            <label>Channel ID</label>
            <input type="text" name="channel_id" value="<?= htmlspecialchars($ch['channel_id']) ?>">

            <label>Active</label>
            <input type="checkbox" name="active" <?= $ch['active'] ? 'checked' : '' ?>>

            <label>タイプ</label>
            <input type="text" name="type" value="<?= htmlspecialchars($ch['type']) ?>">

            <label>Systems</label>
            <input type="text" name="systems" value="<?= htmlspecialchars($ch['systems']) ?>">

            <label>メモ</label>
            <textarea name="note" rows="3"><?= htmlspecialchars($ch['note']) ?></textarea>

            <label>タグ (カンマ区切り)</label>
            <input type="text" name="tags" value="<?= htmlspecialchars(implode(',', $ch['tags'])) ?>">

            <label>別名 (カンマ区切り)</label>
            <input type="text" name="aliases" value="<?= htmlspecialchars(implode(',', $ch['aliases'])) ?>">

            <label>画像パス</label>
            <input type="text" name="img" value="<?= htmlspecialchars($ch['img']) ?>">

            <input type="hidden" name="kana" value="<?= htmlspecialchars($ch['kana']) ?>">
            <input type="hidden" name="email" value="<?= htmlspecialchars($ch['email']) ?>">

            <div class="modal-footer">

                <button type="submit" class="save-btn">保存</button>
                <button type="button" class="cancel-btn" onclick="closeModal('<?= $ch['id'] ?>')">キャンセル</button>
            </div>
        </form>
        <form method="post" onsubmit="return confirm('⚠️ 本当に削除しますか？');">
            <input type="hidden" name="delete_id" value="<?= htmlspecialchars($ch['id']) ?>">
            <button type="submit" class="delete-btn">削除</button>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- 新規追加モーダル -->
<?php
function generate_uuid() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
?>

<div id="modal-new" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="close" onclick="closeModal('new')">&times;</span>
        <h3>新しいチャンネルを追加</h3>

        <form method="post">
            <input type="hidden" name="id" value="<?= generate_uuid(); ?>">

            <label>名前</label>
            <input type="text" name="name" required>

            <label>チャンネル名</label>
            <input type="text" name="channel" required>

            <label>Channel ID</label>
            <input type="text" name="channel_id">

            <label>Active</label>
            <input type="checkbox" name="active" checked>

            <label>タイプ</label>
            <input type="text" name="type" value="副業">

            <label>Systems</label>
            <input type="text" name="systems" value="副業フロント,TikTokフロント,ChatGPTフロント">

            <label>メモ</label>
            <textarea name="note" rows="3"></textarea>

            <label>タグ (カンマ区切り)</label>
            <input type="text" name="tags">

            <label>別名 (カンマ区切り)</label>
            <input type="text" name="aliases">

            <label>画像パス</label>
            <input type="text" name="img" value="public/img/">

            <input type="hidden" name="kana" value="">
            <input type="hidden" name="email" value="">

            <div class="modal-footer">
                <button type="submit" class="save-btn">追加</button>
                <button type="button" class="cancel-btn" onclick="closeModal('new')">キャンセル</button>
            </div>
        </form>
    </div>
</div>

</div>
<script>
// ✅ 初期はすべて非表示
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
});

// ✅ 編集ボタン押下で対象モーダル表示
function openModal(id) {
    const modal = document.getElementById('modal-' + id);
    if (modal) modal.style.display = 'block';
}

// ✅ 閉じるボタンまたは外枠クリックで閉じる
function closeModal(id) {
    const modal = document.getElementById('modal-' + id);
    if (modal) modal.style.display = 'none';
}

window.onclick = function(event) {
    const modals = document.getElementsByClassName('modal');
    for (let m of modals) {
        if (event.target === m) m.style.display = 'none';
    }
};

</script>
<script>
function startOAuth(channelId) {
    if (!channelId) {
        alert("チャンネルIDが見つかりません。");
        return;
    }
    // YouTubeAuthManager の処理を呼び出す
    const url = 'outh/youtube_auth_manager.php?channel_id=' + encodeURIComponent(channelId);
    window.open(url, '_blank', 'width=600,height=800'); // 新しいウィンドウで認証
}
</script>


<?php require 'partials/footer.php'; ?>
