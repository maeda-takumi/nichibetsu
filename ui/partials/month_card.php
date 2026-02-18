<?php
// ui/partials/month_card.php
?>
<div class="month-head">
  <span class="month-badge"><?= htmlspecialchars($badge) ?></span>
  <div class="head-actions">
    <button class="btn small ghost js-toggle-dates">日付を表示</button>
    <button class="btn small ghost js-toggle-sales">セールス表示</button>
  </div>
</div>

<div class="month-body">
  <div class="scroll-x table-surface">
    <?php include __DIR__ . '/summary_table.php'; ?>
  </div>

  <div class="sales-panel collapsed">
    <div class="sales-head">
      <h3>セールス成績（ユーザ別）</h3>
    </div>
    <div class="scroll-x table-surface">
      <?php include __DIR__ . '/sales_table.php'; ?>
    </div>
  </div>
</div>