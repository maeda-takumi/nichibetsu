<?php
// admin_people.php
declare(strict_types=1);
$config = require __DIR__ . '/config.php';

$adminToken = $config['ADMIN_TOKEN'] ?? '';
$apiEndpoint = 'api_people.php';

$pageTitle = '演者 / セールス 管理';
$extraHead  = '<link rel="stylesheet" href="public/styles.css">';
$extraFoot  = '<script src="public/admin_people.js" defer></script>';

require __DIR__ . '/partials/header.php';
?>

<div class="container" data-admin-token="<?= htmlspecialchars($adminToken, ENT_QUOTES) ?>"
     data-api="<?= htmlspecialchars($apiEndpoint, ENT_QUOTES) ?>">
  <h1>演者 / セールス 管理</h1>

  <div class="tabs">
    <button data-kind="actors" class="active">演者（動画担当）</button>
    <button data-kind="sales">セールス担当</button>
  </div>

  <!-- Actors panel -->
  <section id="panel-actors" class="panel active">
    <div class="card">
      <h3>新規追加 / 編集</h3>
      <div class="row"><label>名前 *</label><input id="a-name"></div>
      <div class="row"><label>かな</label><input id="a-kana"></div>
      <div class="row"><label>メール</label><input id="a-email"></div>
      <div class="row"><label>タグ（カンマ区切り）</label><input id="a-tags"></div>
      <div class="row"><label>別名（カンマ区切り）</label><input id="a-aliases"></div>
      <div class="row"><label>有効</label><input id="a-active" type="checkbox" checked></div>
      <div class="row"><label>メモ</label><textarea id="a-note" rows="3"></textarea></div>
      <div class="row"><span></span>
        <button class="primary" onclick="submitUpsert('actors')">保存</button>
        <button onclick="clearForm('actors')">クリア</button>
        <span class="hint" id="a-id-hint"></span>
      </div>
    </div>
    <div class="card list">
      <h3>一覧</h3>
      <table id="a-table"><thead><tr>
        <th>名前</th><th>かな</th><th>メール</th><th>タグ</th><th>別名</th><th>有効</th><th>メモ</th><th>操作</th>
      </tr></thead><tbody></tbody></table>
    </div>
  </section>

  <!-- Sales panel -->
  <section id="panel-sales" class="panel">
    <div class="card">
      <h3>新規追加 / 編集</h3>
      <div class="row"><label>名前 *</label><input id="s-name"></div>
      <div class="row"><label>かな</label><input id="s-kana"></div>
      <div class="row"><label>メール</label><input id="s-email"></div>
      <div class="row"><label>タグ（カンマ区切り）</label><input id="s-tags"></div>
      <div class="row"><label>有効</label><input id="s-active" type="checkbox" checked></div>
      <div class="row"><label>メモ</label><textarea id="s-note" rows="3"></textarea></div>
      <div class="row"><span></span>
        <button class="primary" onclick="submitUpsert('sales')">保存</button>
        <button onclick="clearForm('sales')">クリア</button>
        <span class="hint" id="s-id-hint"></span>
      </div>
    </div>
    <div class="card list">
      <h3>一覧</h3>
      <table id="s-table"><thead><tr>
        <th>名前</th><th>かな</th><th>メール</th><th>タグ</th><th>有効</th><th>メモ</th><th>操作</th>
      </tr></thead><tbody></tbody></table>
    </div>
  </section>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
