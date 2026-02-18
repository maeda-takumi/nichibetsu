<!-- リンクトグルボタン -->
<div id="link-toggle-button">
  <img src="public/img/link.png" alt="リンク" class="link-icon">
</div>

<!-- リンクパネル -->
<aside id="link-panel">
  <h2 class="link-title">分析リンク</h2>
  <ul class="link-list">
    <li><a href="https://docs.google.com/spreadsheets/d/1yTXykjMl65LNmFaYKc0pas3aKPa7d_vio8PS5cyjDHo/edit?gid=2115523381#gid=2115523381" target="_blank">副業動画流入集計</a></li>
    <li><a href="https://docs.google.com/spreadsheets/d/1nbAzjBZfkxBuoa4U_72LBkvw44ZuqeLBEc4VMXBhxKI/edit?gid=1537891330#gid=1537891330" target="_blank">副業アンケート</a></li>
    <li><a href="https://totalappworks.com/analyze/edit_actors.php" target="_blank">演者編集</a></li>
    <li><a href="https://totalappworks.com/analyze/admin_dashboard.php" target="_blank">日別集計ダッシュボード別</a></li>
  </ul>
</aside>
<script>
document.addEventListener("DOMContentLoaded", () => {
  const toggle = document.getElementById("link-toggle-button");
  const panel = document.getElementById("link-panel");

  toggle.addEventListener("click", () => {
    panel.classList.toggle("visible");
  });
});
</script>
