<?php
// ui/dashboard_view.php
?>
<div class="container">
  <h1>日別集計ダッシュボード
    <small style="color:var(--muted)">（基準: <?= htmlspecialchars($base) ?> / 前月: <?= htmlspecialchars($prev) ?>）</small>
  </h1>

  <div class="card compact dash-toolbar">
    <?php $bprev = date('Y-m', strtotime("$base-01 -1 month")); $bnext = date('Y-m', strtotime("$base-01 +1 month")); ?>
    <div class="btn-group">
      <a class="btn ghost" href="?month=<?= $bprev ?>">&laquo; <?= $bprev ?></a>
      <a class="btn" href="?month=<?= date('Y-m') ?>">今月（<?= date('Y-m') ?>）</a>
      <a class="btn ghost" href="?month=<?= $bnext ?>"><?= $bnext ?> &raquo;</a>
    </div>
  </div>

  <?php foreach ($actors as $a): $aid = (string)($a['id'] ?? ''); if ($aid==='') continue; ?>
    <h2><?= htmlspecialchars($a['name'] ?? '') ?><?php if (!empty($a['kana'])): ?><span class="meta"> / <?= htmlspecialchars($a['kana']) ?></span><?php endif; ?></h2>

    <div class="months-grid months-grid-3">
    <?php
      [$curY,$curM,$curDays] = y_m_d_stat($base);
      foreach ($months as $mdef):
        $isDiff = ($mdef['key']==='diff');
        [$y,$m,$days] = y_m_d_stat($mdef['ym']);
        if ($isDiff) { $y=$curY; $m=$curM; $days=$curDays; }
        $badge = $mdef['label'].' / '.$mdef['ym'];
    ?>
      <section class="card month-card" data-actor-id="<?= htmlspecialchars($aid) ?>" data-month="<?= htmlspecialchars($mdef['ym']) ?>" data-kind="<?= $mdef['key'] ?>">
        <?php include __DIR__ . '/partials/month_card.php'; ?>
      </section>
    <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
