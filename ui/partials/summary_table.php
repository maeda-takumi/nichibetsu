<?php
// 必要変数（admin_dashboard → month_card → このファイル の順に include される前提）
// $metrics, $isDiff, $days, $mdef, $aid, $watchByDay, $inflowByDay, $choseiByDay, $denwaWaitByDay, $actorsById

?>
<?php
// 比率の分母となる行名の対応表
$__ratio_denom = [
  '調整済み'   => '流入数',
  '電話対応待ち' => '調整済み',
  '電話前失注' => '調整済み',
];

// 後段で使う「行名 => 合計」のキャッシュ
$__totals_by_label = [];

?>
<div class="scroll-x table-surface">
  <table class="summary-table modern <?= $isDiff?'diff-table':'' ?> dates-hidden">
    <thead>
      <tr class="day-row">
        <th class="main-col">項目</th>
        <th class="total-col">合計</th>
        <?php for($d=1;$d<=$days;$d++):
          $dt = sprintf('%s-%02d', $mdef['ym'], $d);
          $week = (int)date('w', strtotime($dt)); // 0:日 6:土
        ?>
          <th class="day-col <?= ($week===0||$week===6)?'weekend':'' ?>" data-day="<?= $d ?>"><?= $d ?></th>
        <?php endfor; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($metrics as $row): ?>
        <?php
          $isWatchHours = ($row === '総再生時間（時）');
          $isViews      = ($row === '総再生回数');
          $isImpr       = ($row === 'インプレッション数');
          $isInflows    = ($row === '流入数');
          $isChosei     = ($row === '調整済み');
          $isDenwaWait  = ($row === '電話対応待ち');
          $isDenwaLost  = ($row === '電話前失注');
          $isTaiou      = ($row === '対応件数');
          $isSeiyaku    = ($row === '成約件数');
          $isNyukinCnt  = ($row === '入金件数');
          $isNyukinAmt  = ($row === '入金額');
          $isSeiRate    = ($row === 'セールス成約率');
        ?>
        <tr data-type="<?= $row==='セールス成約率' ? 'pct' : ($row==='入金額' ? 'yen' : 'num') ?>">
          <th class="label-col" data-first="<?= mb_substr($row, 0, 1) ?>">
            <span class="label-span"><?= htmlspecialchars($row) ?></span>
            <?php
                $tooltipText = '準備中'; // デフォルト
                $class = '';
                if ($isWatchHours) $tooltipText = 'YoutubeAnalyticsから取得';
                elseif ($isViews) $tooltipText = 'YoutubeAnalyticsから取得';
                elseif ($isImpr) $tooltipText = 'YoutubeAnalyticsから取得';
                elseif ($isInflows) $tooltipText = 'Youtube分析シートから抽出';
                elseif ($isChosei) { $tooltipText = 'LINE登録日で抽出'; $class = 'cell-orange'; }
                elseif ($isDenwaWait) {$tooltipText = 'LINE登録日で抽出';$class = 'cell-red';}
                elseif ($isDenwaLost) {$tooltipText = 'LINE登録日で抽出';$class = 'cell-purple';}
                elseif ($isTaiou) $tooltipText = 'LINE登録日で抽出';
                elseif ($isSeiyaku) {$tooltipText = 'LINE登録日で抽出';$class = 'cell-green';}
                elseif ($isNyukinCnt) {$tooltipText = '入金日で抽出 ※先月流入分も含まれます';$class = 'cell-blue';}
                elseif ($isNyukinAmt) $tooltipText = '入金日で抽出 ※先月流入分も含まれます';
                elseif ($isSeiRate) $tooltipText = '成約件数÷対応件数';
                echo getInformationButtonHtml(htmlspecialchars($row),$tooltipText);
                echo getTooltipScript();
            ?>
          </th>

          <?php
            // 合計
            $sum = 0;
            $rowTotal = 0;
            if ($isSeiRate) {
              // 期間合計でレート算出（%）
              $sum = sales_rate_total($seiyakuByDay, $taiouCountByDay, $actorsById, $aid, $mdef['ym'], $days);
            } elseif (!$isDiff && ($isWatchHours || $isViews || $isImpr || $isInflows || $isChosei || $isDenwaWait || $isDenwaLost || $isTaiou || $isSeiyaku || $isNyukinCnt || $isNyukinAmt)) {
              for ($d=1; $d<=$days; $d++) {
                if      ($isWatchHours)   $sum += watch_hours_get($watchByDay, $aid, $mdef['ym'], $d);
                elseif  ($isViews)        $sum += watch_views_get($watchByDay, $aid, $mdef['ym'], $d);
                elseif  ($isImpr)         $sum += watch_impressions_get($watchByDay, $aid, $mdef['ym'], $d);
                elseif  ($isInflows){
                  if($isDiff){
                    $sum += inflow_get($inflowByDay, $actorsById, $aid, $mdef['ym'], $d);
                  }else{
                    $getvalue = inflow_get($inflowByDay, $actorsById, $aid, $mdef['ym'], $d);
                    $sum += $getvalue;
                    $rowTotal += (int)$getvalue;
                    $__totals_by_label["流入数"] = $rowTotal;
                  }
                }
                elseif  ($isChosei){
                  if($isDiff){
                    $sum += chosei_get($choseiByDay,  $actorsById, $aid, $mdef['ym'], $d);
                  }else{
                    $getvalue = chosei_get($choseiByDay,  $actorsById, $aid, $mdef['ym'], $d);
                    $sum += $getvalue;
                    $rowTotal += (int)$getvalue;
                    $__totals_by_label["調整済み"] = $rowTotal;
                  }
                }
                elseif  ($isDenwaWait){
                  if($isDiff){
                    $sum += denwa_get($denwaWaitByDay, $actorsById, $aid, $mdef['ym'], $d);
                  }else{
                    $getvalue = denwa_get($denwaWaitByDay, $actorsById, $aid, $mdef['ym'], $d);
                    $sum += $getvalue;
                    $rowTotal += (int)$getvalue;
                    $__totals_by_label["電話対応待ち"] = $rowTotal;
                  }
                }
                elseif  ($isDenwaLost){
                  if($isDiff){
                    $sum += denwa_lost_get($denwaLostByDay, $actorsById, $aid, $mdef['ym'], $d);
                  }else{
                    $getvalue = denwa_lost_get($denwaLostByDay, $actorsById, $aid, $mdef['ym'], $d);
                    $sum += $getvalue;
                    $rowTotal += (int)$getvalue;
                    $__totals_by_label["電話前失注"] = $rowTotal;
                  }
                }
                elseif  ($isTaiou)        $sum += chosei_get($taiouCountByDay , $actorsById, $aid, $mdef['ym'], $d);
                elseif  ($isSeiyaku)      $sum += chosei_get($seiyakuByDay,    $actorsById, $aid, $mdef['ym'], $d);
                elseif  ($isNyukinCnt)    $sum += chosei_get($nyukinCountByDay,$actorsById, $aid, $mdef['ym'], $d);
                elseif  ($isNyukinAmt)    $sum += nyukin_amount_get($nyukinAmountByDay, $actorsById, $aid, $mdef['ym'], $d);
              }
            }

            // 表示テキスト（合計）
            if ($isSeiRate) {
              $sumText = number_format($sum, 2) . '%';
            } elseif ($isNyukinAmt) {
              $sumText = '¥' . number_format((int)$sum);
            } elseif ($isWatchHours) {
              $sumText = number_format((float)$sum, 1);

            } elseif ($isChosei) {
              if($isDiff){
                $sumText = number_format((int)$sum);
              } else {
                $ratio = 0.0;
                $den = $__totals_by_label["流入数"] ?? 0;
                $num = $__totals_by_label["調整済み"] ?? 0;
                if ($den > 0) {
                  $ratio = ($num / $den) * 100;
                }
                $sumText = number_format((int)$sum) . "（" . number_format($ratio, 2, '.', '') . "％）";
              }

            } elseif ($isDenwaWait) {
              if($isDiff){
                $sumText = number_format((int)$sum);
              } else {
                // ★ 分母は「調整済み」なので、必ず調整済み > 0 を見る
                $ratio = 0.0;
                $den = $__totals_by_label["調整済み"] ?? 0;
                $num = $__totals_by_label["電話対応待ち"] ?? 0;
                if ($den > 0) {
                  $ratio = ($num / $den) * 100;
                }
                $sumText = number_format((int)$sum) . "（" . number_format($ratio, 2, '.', '') . "％）";
              }

            } elseif ($isDenwaLost) {
              if($isDiff){
                $sumText = number_format((int)$sum);
              } else {
                $ratio = 0.0;
                $den = $__totals_by_label["調整済み"] ?? 0;
                $num = $__totals_by_label["電話前失注"] ?? 0;
                if ($den > 0) {
                  $ratio = ($num / $den) * 100;
                }
                $sumText = number_format((int)$sum) . "（" . number_format($ratio, 2, '.', '') . "％）";
              }

            } else {
              $sumText = number_format((int)$sum);
            }
          ?>
          <td class="total-col" data-value="<?= $sum ?>"><?= $sumText ?></td>

          <?php for($d=1;$d<=$days;$d++): ?>
            <?php
              $v=0;
              $text="";
              if ($isDiff) {
                $v = 0; $text = '0';
              } elseif ($isSeiRate) {
                $v = sales_rate_get($seiyakuByDay, $taiouCountByDay, $actorsById, $aid, $mdef['ym'], $d);
                $text = number_format($v, 2) . '%';
              } elseif ($isNyukinAmt) {
                $v = nyukin_amount_get($nyukinAmountByDay, $actorsById, $aid, $mdef['ym'], $d);
                $text = '¥'.number_format((int)$v);
              } elseif ($isNyukinCnt) {
                $v = nyukin_count_get($nyukinCountByDay, $actorsById, $aid, $mdef['ym'], $d);
                $text = number_format((int)$v);
              } elseif ($isSeiyaku) {
                $v = seiyaku_get($seiyakuByDay, $actorsById, $aid, $mdef['ym'], $d);
                $text = number_format((int)$v);
              } elseif ($isTaiou) {
                $v = taiou_get($taiouCountByDay, $actorsById, $aid, $mdef['ym'], $d);
                $text = number_format((int)$v);
              } elseif ($isDenwaLost) {
                $v = denwa_lost_get($denwaLostByDay, $actorsById, $aid, $mdef['ym'], $d);
                $text = number_format((int)$v);
              } elseif ($isDenwaWait) {
                $v = denwa_get($denwaWaitByDay, $actorsById, $aid, $mdef['ym'], $d);
                $text = number_format((int)$v);
              } elseif ($isChosei) {
                $v = chosei_get($choseiByDay,  $actorsById, $aid, $mdef['ym'], $d);
                $text = number_format((int)$v);
              } elseif ($isInflows) {
                $v = inflow_get($inflowByDay, $actorsById, $aid, $mdef['ym'], $d);
                $text = number_format((int)$v);
              } elseif ($isWatchHours) {
                $v = watch_hours_get($watchByDay, $aid, $mdef['ym'], $d);
                $text = number_format($v, 1);
              } elseif ($isViews) {
                $v = watch_views_get($watchByDay, $aid, $mdef['ym'], $d);
                $text = number_format((int)$v);
              } elseif ($isImpr) {
                $v = watch_impressions_get($watchByDay, $aid, $mdef['ym'], $d);
                $text = number_format((int)$v);
              } else {
                $v = 0;
                $text = ($row==='セールス成約率') ? '0%' : '0';
              }
            ?>
            <td class="day-col <?= $isDiff ? 'diff ' : '' ?><?= ($v > 0 ? $class : '') ?>" data-day="<?= $d ?>" data-value="<?= $v ?>"><?= $text ?></td>
          <?php endfor; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php
// 任意のスポットデバッグ（?debug_denwa=1 & month=YYYY-MM）
if (!empty($_GET['debug_denwa'])) {
  $ym = $_GET['month'] ?? date('Y-m');
  $probeDay = 25;
  $nm = $actorsById[$aid] ?? '(unknown)';
  $probe = denwa_get($denwaWaitByDay, $actorsById, $aid, $ym, $probeDay);
  echo "<pre style='background:#024;color:#fff;padding:6px;margin:6px 0'>PROBE {$ym}-".sprintf('%02d',$probeDay)." {$nm} = {$probe}</pre>";
}
?>
