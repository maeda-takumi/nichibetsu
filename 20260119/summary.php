<?php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

// ================================
// 設定
// ================================
$BASE_DIR = __DIR__;
$month = $_GET['month'] ?? date('Y-m'); // 指定月（例：2025-10）

// ================================
// 集計スクリプトの読込
// ================================
require "config.php";
require_once "{$BASE_DIR}/aggregate/aggregate_inflows.php";
require_once "{$BASE_DIR}/aggregate/aggregate_taiou_count.php";
require_once "{$BASE_DIR}/aggregate/aggregate_seiyaku_count.php";
require_once "{$BASE_DIR}/aggregate/aggregate_sales_nyukin_count.php";
require_once "{$BASE_DIR}/aggregate/aggregate_sales_nyukin_amount.php";

// ================================
// 集計データ（_total）抽出関数
// ================================
function sum_month_total(array $byDay, string $ym): int|float {
  $sum = 0;
  foreach ($byDay as $d => $row) {
    if (is_string($d) && str_starts_with($d, $ym.'-')) {
      $sum += (int)($row['_total'] ?? 0);
    }
  }
  return $sum;
}

// ================================
// 1️⃣ 全チャンネル合計
// ================================
$inflow_total       = sum_month_total($inflowByDay ?? [],       $month);
$taiou_total        = sum_month_total($taiouByDay ?? [],        $month);
$seiyaku_total      = sum_month_total($seiyakuByDay ?? [],      $month);
$nyukin_count_total = sum_month_total($salesNyukinCountByDay ?? [], $month);
$nyukin_amount_total= sum_month_total($salesNyukinAmountByDay ?? [],$month);

// 成約率・入金率
$sales_rate  = $taiou_total ? round($seiyaku_total / $taiou_total * 100, 1) : 0;
$nyukin_rate = $seiyaku_total ? round($nyukin_count_total / $seiyaku_total * 100, 1) : 0;

// ================================
// 2️⃣ セールス別サマリー
// ================================

$sales = json_decode(file_get_contents("{$BASE_DIR}/data/sales.json"), true);
$salesList = $sales['items'] ?? [];

$sales_summary = [];
foreach ($salesList as $s) {
  $name = $s['name'];
  $id   = $s['id'] ?? $name;

  $taiou        = sum_month_total($taiouByDay[$id]        ?? [], $month);
  $seiyaku      = sum_month_total($seiyakuByDay[$id]      ?? [], $month);
  $nyukin_count = sum_month_total($salesNyukinCountByDay[$id] ?? [], $month);
  $nyukin_amount= sum_month_total($salesNyukinAmountByDay[$id]?? [], $month);

  $rate_sales   = $taiou ? round($seiyaku / $taiou * 100, 1) : 0;
  $rate_nyukin  = $seiyaku ? round($nyukin_count / $seiyaku * 100, 1) : 0;

  $sales_summary[] = [
    'name' => $name,
    'taiou' => $taiou,
    'seiyaku' => $seiyaku,
    'rate_sales' => $rate_sales,
    'nyukin_count' => $nyukin_count,
    'nyukin_amount' => $nyukin_amount,
    'rate_nyukin' => $rate_nyukin,
  ];
}

$pageTitle = "全体集計";
$extraHead = '<link rel="stylesheet" href="public/styles.css?v=' . time() . '">';
require __DIR__ . '/partials/header.php';
?>


<h1>📊 月次サマリー（全チャンネル＋セールス別）</h1>

<form method="get" action="">
  <label>対象月：
    <input type="month" name="month" value="<?= htmlspecialchars($month) ?>">
  </label>
  <button type="submit">集計実行</button>
</form>

<h2>【全チャンネル合計】 <?= htmlspecialchars($month) ?></h2>
<table>
  <tr>
    <th>流入数</th>
    <th>対応数</th>
    <th>成約件数</th>
    <th>セールス成約率</th>
    <th>入金件数</th>
    <th>入金率</th>
    <th>入金額</th>
  </tr>
  <tr class="summary-total">
    <td><?= number_format($inflow_total) ?></td>
    <td><?= number_format($taiou_total) ?></td>
    <td><?= number_format($seiyaku_total) ?></td>
    <td><?= $sales_rate ?>%</td>
    <td><?= number_format($nyukin_count_total) ?></td>
    <td><?= $nyukin_rate ?>%</td>
    <td>¥<?= number_format($nyukin_amount_total) ?></td>
  </tr>
</table>

<h2>【セールス別サマリー】</h2>
<table>
  <tr>
    <th>セールス名</th>
    <th>対応件数</th>
    <th>成約件数</th>
    <th>成約率</th>
    <th>入金件数</th>
    <th>入金率</th>
    <th>入金額</th>
  </tr>
  <?php foreach ($sales_summary as $row): ?>
  <tr>
    <td><?= htmlspecialchars($row['name']) ?></td>
    <td><?= number_format($row['taiou']) ?></td>
    <td><?= number_format($row['seiyaku']) ?></td>
    <td><?= $row['rate_sales'] ?>%</td>
    <td><?= number_format($row['nyukin_count']) ?></td>
    <td><?= $row['rate_nyukin'] ?>%</td>
    <td>¥<?= number_format($row['nyukin_amount']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php 

require __DIR__ . '/partials/footer.php'; 
?>