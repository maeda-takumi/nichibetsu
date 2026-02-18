<?php
// update_button.php
// 常時表示されるデータ更新ボタン
?>

<!-- 更新ボタン -->
<div id="update-toggle-button" onclick="runUpdates()" aria-label="データ更新">
  <img src="public/img/reload.png" alt="更新" class="link-icon">
</div>

<!-- ローディングポップアップ -->
<div id="loadingPopup" class="loading-popup hidden">
  <div class="loading-popup-content">
    更新中です。しばらくお待ちください…
  </div>
</div>

<script>
  function runUpdates() {
    const popup = document.getElementById("loadingPopup");
    popup.classList.remove("hidden");

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
        popup.classList.add("hidden");
      });
  }
</script>