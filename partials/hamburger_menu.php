
<?php
// hamburger_menu.php
// ハンバーガーメニュー＋テーマ切替＋データ更新ボタン
?>
<style>
  .hamburger-button {
      position: fixed;
      top: 12px;
      right: 12px;
      z-index: 10000;
      width: 40px;
      height: 40px;
      background: var(--surface);
      backdrop-filter: blur(6px);
      border: 1px solid var(--border);
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, .1);
      display: flex  ;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      cursor: pointer;
  }

  .hamburger-button span {
    display: block;
    width: 24px;
    height: 2px;
    margin: 3px 0;
    background-color: var(--text);
  }

.menu-panel {
    position: fixed;
    top: 0;
    right: -300px;
    width: 300px;
    height: 100%;
    background: var(--bg);
    box-shadow: -4px 0 16px rgba(0, 0, 0, 0.1);
    transition: right 0.3s 
ease;
    z-index: 9999;
    padding: 20px;
    display: flex
;
    flex-direction: column;
    gap: 16px;
    padding-top: 85px;
}

.menu-panel.open {
    right: 0;
}

.menu-card {
    background: var(--surface);
    padding: 16px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
    display: flex;
    flex-direction: row;
    gap: 30px;
    width: 100%;
    border: solid 1px var(--border);
}

.menu-card p {
    margin: auto auto auto 0;
    font-size: 14px;
    color: var(--text);
    display: block;
    /* text-align: left; */
}
  .theme-toggle {
    cursor: pointer;
    display: inline-block;
  }

.update-button {
    padding: 8px 16px;
    background: var(--bg);
    color: var(--text);
    border: solid 1px var(--border);
    border-radius: 8px;
    cursor: pointer;
}
</style>

<div class="hamburger-button" onclick="toggleMenu()">
  <span></span>
  <span></span>
  <span></span>
</div>

<div class="menu-panel" id="menuPanel">

  <!-- <div class="menu-card">
    <p>データを更新</p>
    <button class="update-button" onclick="runUpdates()">更新する</button>

    <div id="loadingPopup" class="loading-popup hidden">
      <div class="loading-popup-content">
        更新中です。しばらくお待ちください…
      </div>
    </div>
  </div> -->
  <div class="menu-card">
    <p>モード切替</p>
    <?php include 'theme_toggle.php'; ?>
  </div>

  <div class="menu-card">
    <p>ログアウト</p>
    <button type="button"
            onclick="location.href='../analyze/auth/logout.php'"
            class="btn logout-btn"
            style="width:100%;padding:10px 12px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:8px;font-weight:700;">
      ログアウト
    </button>
  </div>
</div>

<script>
function toggleMenu() {
  document.getElementById('menuPanel').classList.toggle('open');
}
</script>

<!-- ハンバーガーボタン -->
<div class="hamburger-button" onclick="toggleMenuPanel()" aria-label="メニューを開く">
  <span></span>
  <span></span>
  <span></span>
</div>

<script>
  function toggleMenuPanel() {
    document.getElementById("menuPanel").classList.toggle("open");
  }
</script>
<script>
  
  function runUpdates() {
    const popup = document.getElementById("loadingPopup");
    popup.classList.remove("hidden"); // 表示

    fetch('partials/run_updates.php')
      .then(res => {
        return res.text().then(text => {
          if (!res.ok) throw new Error("更新に失敗しました: " + text);
          return text;
        });
      })
      .then(msg => {
        location.reload();
      })
      .catch(err => {
        alert(err.message);
        popup.classList.add("hidden"); // エラー時は非表示に戻す
      });
  }

</script>