<?php
// ui/partials/sales_table.php  (crash-safe debug build)
declare(strict_types=1);
// --- オプション: ?trace=1 で詳細エラーを出す（本番では付けない） ---
if (!empty($_GET['trace'])) {
  ini_set('display_errors', '1');
  ini_set('html_errors', '1');
  error_reporting(E_ALL);
}

// 必要変数のデフォルト（未定義で落ちないように）
$badge_key        = isset($badge_key) ? (string)$badge_key : false;
$isDiff           = isset($isDiff) ? (bool)$isDiff : false;
$days             = isset($days) ? (int)$days : 31;
$mdef             = isset($mdef) && is_array($mdef) ? $mdef : ['ym' => date('Y-m')];
$salesPeople      = isset($salesPeople) && is_array($salesPeople) ? $salesPeople : [];
$salesMetrics     = isset($salesMetrics) && is_array($salesMetrics) ? $salesMetrics : ['セールス件数']; // 必要に応じて
$salesCountByDay  = isset($salesCountByDay) && is_array($salesCountByDay) ? $salesCountByDay : [];
$aid              = isset($aid) ? (string)$aid : ''; // 俳優フィルタ（空で全俳優合算）

// 指標名の別名対応（必要に応じて増やせる）
if (!function_exists('is_sales_count_metric')) {
  function is_sales_count_metric(string $label): bool {
    return in_array($label, ['セールス件数','対応件数'], true);
  }
}

// 画面落下を防ぐため、一時的に Warning/Notice を例外化（このファイルの描画中のみ）
$__prev_handler = set_error_handler(function($severity, $message, $file, $line) {
  // @で抑制されたものなどは無視したい場合はここで制御
  throw new ErrorException($message, 0, $severity, $file, $line);
});
?>

    <?php
    try {
      foreach ($salesPeople as $sp):
        $sid   = (string)($sp['id']   ?? '');
        $sname = trim((string)($sp['name'] ?? ''));
        if ($sname === '' || $sid === '') continue;
    ?>
    <table
      class="sales-table modern <?= $isDiff ? 'diff-table' : '' ?> dates-hidden"
      data-sid="<?= htmlspecialchars((string)$sid, ENT_QUOTES, 'UTF-8') ?>"
    >

    <tbody>
      <thead>
        <tr class="day-row">
          <th class="sticky-col label-col main-col">担当/指標</th>
          <th class="sticky-col total-col">合計</th>
          <?php for($d=1; $d<=$days; $d++): ?>
            <th class="day-col" data-day="<?= (int)$d ?>"><?= (int)$d ?></th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tr class="section-sep">
        <th class="sticky-col label-col">— <?= htmlspecialchars($sname, ENT_QUOTES) ?> —</th>
      </tr>
      <?php foreach ($salesMetrics as $sm): ?>
        <?php
            if($sm=="セールス件数"){
              $tooltipText="セールス日で抽出";
            }elseif ($sm=="成約件数") {
              $tooltipText="セールス日で抽出";
            }elseif($sm=="セールス成約率"){
              $tooltipText="成約件数÷セールス件数";
            }elseif($sm=="入金件数"){
              $tooltipText="入金日で抽出";
            }elseif($sm=="入金額"){
              $tooltipText="入金日で抽出";
            }

        ?>
        <?php
          // 指標1行分を安全に描画（行ごとに try-catch して落ちないように）
          try {
        ?>
        <tr data-type="<?= $sm==='セールス成約率' ? 'pct' : ($sm==='入金額' ? 'yen' : 'num') ?>">

          <th class="sticky-col label-col">
            <span class="label-span"><?= htmlspecialchars((string)$sm, ENT_QUOTES) ?></span>
            <?php echo getInformationButtonHtml(htmlspecialchars($sm),$tooltipText);?>
          </th>
          <?php if (is_sales_count_metric((string)$sm)): // —— セールス件数（または別名） —— ?>
            <?php
              $ym = (string)($mdef['ym'] ?? date('Y-m'));
              $sum = 0;
              for ($dd=1; $dd <= $days; $dd++) {
                $v = 0;
                if (function_exists('sales_count_get')) {
                  $v = (int)sales_count_get($salesCountByDay, $sid, $aid, $ym, $dd);
                }
                $sum += $v;
              }
            ?>
            <td class="sticky-col total-col <?= $isDiff ? 'diff' : '' ?>"
                <?= $isDiff ? 'data-value=""' : 'data-value="'.(int)$sum.'"' ?>>
              <?= $isDiff ? '' : '<strong>'.number_format((int)$sum).'</strong>' ?>
            </td>
            <?php for($d=1; $d <= $days; $d++): ?>
              <?php
                $v = 0;
                if (function_exists('sales_count_get')) {
                  $v = (int)sales_count_get($salesCountByDay, $sid, $aid, $ym, $d);
                }
                $text = number_format($v);
              ?>
              <td class="day-col <?= $isDiff ? 'diff' : '' ?>"
                  data-day="<?= (int)$d ?>"
                  <?= $isDiff ? 'data-value=""' : 'data-value="'.(int)$v.'"' ?>>
                <?= $isDiff ? '' : (isset($text) ? $text : number_format((int)$v)) ?>
              </td>
            <?php endfor; ?>

          <?php elseif ($sm === '成約件数'): ?>
            <?php
              $ym  = (string)($mdef['ym'] ?? date('Y-m'));
              $sum = 0;
              for ($dd=1; $dd <= $days; $dd++) {
                $sum += (int)sales_seiyaku_count_get($salesSeiyakuCountByDay ?? [], $sid, $aid, $ym, $dd);
              }
            ?>
            <td class="sticky-col total-col <?= $isDiff ? 'diff' : '' ?>"
                <?= $isDiff ? 'data-value=""' : 'data-value="'.(int)$sum.'"' ?>>
              <?= $isDiff ? '' : '<strong>'.number_format((int)$sum).'</strong>' ?>
            </td>
            <?php for ($d=1; $d <= $days; $d++): ?>
              <?php $v = (int)sales_seiyaku_count_get($salesSeiyakuCountByDay ?? [], $sid, $aid, $ym, $d); ?>
              <td class="day-col <?= $isDiff ? 'diff' : '' ?>"
                  data-day="<?= (int)$d ?>"
                  <?= $isDiff ? 'data-value=""' : 'data-value="'.(int)$v.'"' ?>>
                <?= $isDiff ? '' : number_format((int)$v) ?>
              </td>
            <?php endfor; ?>

          <?php elseif ($sm === '入金件数'): ?>
            <?php
              $ym  = (string)($mdef['ym'] ?? date('Y-m'));
              $sum = 0;
              for ($dd=1; $dd <= $days; $dd++) {
                $sum += (int) sales_nyukin_count_get($salesNyukinCountByDay ?? [], $sid, $aid, $ym, $dd);
              }
            ?>
            <td class="sticky-col total-col <?= $isDiff ? 'diff' : '' ?>"
                <?= $isDiff ? 'data-value=""' : 'data-value="'.(int)$sum.'"' ?>>
              <?= $isDiff ? '' : '<strong>'.number_format((int)$sum).'</strong>' ?>
            </td>
            <?php for ($d=1; $d <= $days; $d++): ?>
              <?php $v = (int) sales_nyukin_count_get($salesNyukinCountByDay ?? [], $sid, $aid, $ym, $d); ?>
              <td class="day-col <?= $isDiff ? 'diff' : '' ?>"
                  data-day="<?= (int)$d ?>"
                  <?= $isDiff ? 'data-value=""' : 'data-value="'.(int)$v.'"' ?>>
                <?= $isDiff ? '' : number_format((int)$v) ?>
              </td>
            <?php endfor; ?>

          <?php elseif ($sm === '入金額'): ?>
            <?php
              $ym  = (string)($mdef['ym'] ?? date('Y-m'));
              $sum = 0;
              for ($dd=1; $dd <= $days; $dd++) {
                $sum += (int) sales_nyukin_amount_get($salesNyukinAmountByDay ?? [], $sid, $aid, $ym, (int)$dd);
              }
            ?>
            <td class="sticky-col total-col <?= $isDiff ? 'diff' : '' ?>"
                <?= $isDiff ? 'data-value=""' : 'data-value="'.(int)$sum.'"' ?>>
              <?= $isDiff ? '' : '<strong>'.number_format((int)$sum).'</strong>' ?>
            </td>
            <?php for ($d=1; $d <= $days; $d++): ?>
              <?php $v = (int) sales_nyukin_amount_get($salesNyukinAmountByDay ?? [], $sid, $aid, $ym, (int)$d); ?>
              <td class="day-col <?= $isDiff ? 'diff' : '' ?>"
                  data-day="<?= (int)$d ?>"
                  <?= $isDiff ? 'data-value=""' : 'data-value="'.(int)$v.'"' ?>>
                <?= $isDiff ? '' : number_format((int)$v) ?>
              </td>
            <?php endfor; ?>

          <?php elseif ($sm === 'セールス成約率'): ?>
            <?php
              $ym  = (string)($mdef['ym'] ?? date('Y-m'));
              // 合計: (合計成約数 / 合計セールス件数) * 100
              $sumSei = 0; $sumSales = 0;
              for ($dd = 1; $dd <= $days; $dd++) {
                $sumSei   += (int) sales_seiyaku_count_get($salesSeiyakuCountByDay ?? [], $sid, $aid, $ym, (int)$dd);
                $sumSales += (int) sales_count_get       ($salesCountByDay        ?? [], $sid, $aid, $ym, (int)$dd);
              }
              $sumRate = ($sumSales > 0) ? ($sumSei / $sumSales * 100) : 0.0;
            ?>
            <?php if ($isDiff): ?>
              <td class="sticky-col total-col diff" data-value=""></td>
            <?php else: ?>
              <td class="sticky-col total-col"
                  data-value="<?= htmlspecialchars((string)$sumRate, ENT_QUOTES) ?>">
                <strong><?= htmlspecialchars(number_format($sumRate, 1), ENT_QUOTES) ?>%</strong>
              </td>
            <?php endif; ?>

            <?php for ($d = 1; $d <= $days; $d++): ?>
              <?php
                // 日別: (当日成約数 / 当日セールス件数) * 100
                $daySei   = (int) sales_seiyaku_count_get($salesSeiyakuCountByDay ?? [], $sid, $aid, $ym, (int)$d);
                $daySales = (int) sales_count_get       ($salesCountByDay        ?? [], $sid, $aid, $ym, (int)$d);
                $dayRate  = ($daySales > 0) ? ($daySei / $daySales * 100) : 0.0;
                $txt      = number_format($dayRate, 1) . '%';
              ?>
              <?php if ($isDiff): ?>
                <td class="day-col diff"
                    data-day="<?= (int)$d ?>"
                    data-value=""></td>
              <?php else: ?>
                <td class="day-col"
                    data-day="<?= (int)$d ?>"
                    data-value="<?= htmlspecialchars(number_format($dayRate, 1), ENT_QUOTES) ?>">
                  <?= htmlspecialchars($txt, ENT_QUOTES) ?>
                </td>
              <?php endif; ?>
            <?php endfor; ?>

          <?php else: // —— まだ未実装の指標は 0 表示のまま —— ?>
            <td class="sticky-col total-col <?= $isDiff ? 'diff' : '' ?>"
                <?= $isDiff ? 'data-value=""' : 'data-value="0"' ?>>
              <?= $isDiff ? '' : '<strong>0</strong>' ?>
            </td>
            <?php for($d=1; $d<=$days; $d++): ?>
              <td class="day-col <?= $isDiff ? 'diff' : '' ?>"
                  data-day="<?= (int)$d ?>"
                  <?= $isDiff ? 'data-value=""' : 'data-value="0"' ?>>
                <?= $isDiff ? '' : '0' ?>
              </td>
            <?php endfor; ?>
          <?php endif; ?>

        </tr>
        <?php
          } catch (Throwable $rowErr) {
            // 該当行だけ赤帯で理由を出して続行
            $msg = htmlspecialchars($rowErr->getMessage(), ENT_QUOTES);
            echo '<tr><td class="sticky-col label-col" colspan="'.(2+$days).'" style="color:#fff;background:#7a1f2c">';
            echo '行描画エラー: '.$msg.'</td></tr>';
          }
        ?>
        
      <?php endforeach; ?>
      
        </tbody>
      </table>
    <?php
      endforeach;
    } catch (Throwable $outer) {
      // ループの外枠での致命的エラー
      $msg = htmlspecialchars($outer->getMessage(), ENT_QUOTES);
      echo '<tr><td class="sticky-col label-col" colspan="'.(2+$days).'" style="color:#fff;background:#7a1f2c">';
      echo 'テーブル描画エラー: '.$msg.'</td></tr>';
    }
    ?>
