<?php
// partials/summary_global.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=utf-8');

// ---- パスの基準（必要に応じて調整） ----
$BASE_DIR = dirname(__DIR__); // /analyze
$AGG_DIR  = $BASE_DIR;        // aggregate_*.php が /analyze 直下なら BASE_DIR のまま

// ---- 集計モジュール読込（auto生成を活用） ----
require_once "{$AGG_DIR}/aggregate/aggregate_inflows.php";
require_once "{$AGG_DIR}/aggregate/aggregate_denwa_wait.php";
require_once "{$AGG_DIR}/aggregate/aggregate_denwa_lost.php";
require_once "{$AGG_DIR}/aggregate/aggregate_nyukin_count.php";
require_once "{$AGG_DIR}/aggregate/aggregate_nyukin_amount.php";
// 必要に応じて追加（例：調整）
// require_once "{$AGG_DIR}/aggregate_chosei.php";

// ---- ユーティリティ ----
function sum_month_total(array $byDay, string $ym): int|float {
  $sum = 0;
  foreach ($byDay as $d => $row) {
    if (is_string($d) && str_starts_with($d, $ym.'-')) {
      $sum += (int)($row['_total'] ?? 0);
    }
  }
  return $sum;
}

$today = (new DateTime('today'))->format('Y-m-d');
$ym    = (new DateTime('today'))->format('Y-m');

// ---- セーフティ（未定義でも落とさない）----
$inflowByDay      = $inflowByDay      ?? [];
$denwaWaitByDay   = $denwaWaitByDay   ?? [];
$denwaLostByDay   = $denwaLostByDay   ?? [];
$nyukinCountByDay = $nyukinCountByDay ?? [];
$nyukinAmountByDay= $nyukinAmountByDay?? [];

// ---- 今日の値 ----
$today_inflows       = (int)($inflowByDay[$today]['_total']      ?? 0);
$today_denwa_wait    = (int)($denwaWaitByDay[$today]['_total']   ?? 0);
$today_denwa_lost    = (int)($denwaLostByDay[$today]['_total']   ?? 0);
$today_nyukin_count  = (int)($nyukinCountByDay[$today]['_total'] ?? 0);
$today_nyukin_amount = (int)($nyukinAmountByDay[$today]['_total']?? 0);

// ---- 今月(MTD)の累計 ----
$mtd_inflows       = sum_month_total($inflowByDay,       $ym);
$mtd_denwa_wait    = sum_month_total($denwaWaitByDay,    $ym);
$mtd_denwa_lost    = sum_month_total($denwaLostByDay,    $ym);
$mtd_nyukin_count  = sum_month_total($nyukinCountByDay,  $ym);
$mtd_nyukin_amount = sum_month_total($nyukinAmountByDay, $ym);

// ---- レスポンス ----
echo json_encode([
  'date' => $today,
  'ym'   => $ym,
  'today' => [
    'inflows'       => $today_inflows,
    'denwa_wait'    => $today_denwa_wait,
    'denwa_lost'    => $today_denwa_lost,
    'nyukin_count'  => $today_nyukin_count,
    'nyukin_amount' => $today_nyukin_amount,
  ],
  'mtd' => [
    'inflows'       => $mtd_inflows,
    'denwa_wait'    => $mtd_denwa_wait,
    'denwa_lost'    => $mtd_denwa_lost,
    'nyukin_count'  => $mtd_nyukin_count,
    'nyukin_amount' => $mtd_nyukin_amount,
  ],
  'generated_at' => date('c')
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
