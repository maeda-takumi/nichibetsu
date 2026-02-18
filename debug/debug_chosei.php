<?php
/* ================= CHOSEI DEBUG =================
 * 使い方:
 *   ?debug_chosei=1&ym=YYYY-MM&sid=SALES_ID&aid=ACTOR_ID&list=1&n=30&halt=1
 * 目的:
 *   1) getter 合計   : 画面が使っているキューブ変数からの合計
 *   2) キューブ素合計: 検知できた $chosei の配列を直接合算
 *   3) 再抽出件数    : aggregate_chosei.php と同条件で raw_rows.json を再抽出
 *   4) スキップ内訳  : どこで弾かれているか可視化（type / systems / LINE登録日 / 回数 / セールス空 etc.）
 * ================================================= */
if (!empty($_GET['debug_chosei'])) {
  // 必要なら読み込み（既に読み込んでいれば重複してもOK）
//   @require_once __DIR__ . '/aggregate/aggregate_chosei.php';

  // ---- パラメータ ----
  $ymDbg  = (string)($_GET['ym']  ?? ($mdef['ym'] ?? date('Y-m')));
  $sidDbg = (string)($_GET['sid'] ?? ($salesPeople[0]['id'] ?? ''));
  $aidDbg = (string)($_GET['aid'] ?? ($aid ?? '')); // この画面が「俳優1人モード」の場合は $aid が渡っている想定
  $doList = !empty($_GET['list']);
  $nList  = max(1, (int)($_GET['n'] ?? 30));
  $halt   = !empty($_GET['halt']);

  // ---- 画面が保持している（はずの）キューブ変数を自動検出 ----
  // 候補を順に探して最初に見つかったものを採用
  $candidates = [
    'choseiByDay'       => isset($choseiByDay)       ? $choseiByDay       : null, // 既存名の可能性
    'choseiBySales'     => isset($choseiBySales)     ? $choseiBySales     : null, // [sid][aid][ym][d]
    'choseiByActor'     => isset($choseiByActor)     ? $choseiByActor     : null, // [aid][ym][d]
    'choseiByDaySales'  => isset($choseiByDaySales)  ? $choseiByDaySales  : null, // [sid][aid][ym][d]
    'choseiByDayActor'  => isset($choseiByDayActor)  ? $choseiByDayActor  : null, // [aid][ym][d]
  ];
  $cubeName = null; $cubeRef = null;
  foreach ($candidates as $k=>$ref) { if (is_array($ref)) { $cubeName=$k; $cubeRef=$ref; break; } }

  // ---- 1) getter 合計（画面の getter があればそれを使用。無ければ配列直積算）----
  $getter = 0;
  if ($cubeName !== null) {
    // 画面 getter があるならここで置き換え可能（例：chosei_count_get）。今は素直に配列から日別合算。
    if (isset($cubeRef[$sidDbg][$aidDbg][$ymDbg]) && is_array($cubeRef[$sidDbg][$aidDbg][$ymDbg])) {
      foreach ($cubeRef[$sidDbg][$aidDbg][$ymDbg] as $v) $getter += (int)$v;
    } elseif (isset($cubeRef[$aidDbg][$ymDbg]) && is_array($cubeRef[$aidDbg][$ymDbg])) {
      foreach ($cubeRef[$aidDbg][$ymDbg] as $v) $getter += (int)$v;
    }
  }

  // ---- 2) キューブ素合計（直接）----
  $cube = 0;
  if ($cubeName !== null) {
    if (isset($cubeRef[$sidDbg][$aidDbg][$ymDbg]) && is_array($cubeRef[$sidDbg][$aidDbg][$ymDbg])) {
      foreach ($cubeRef[$sidDbg][$aidDbg][$ymDbg] as $v) $cube += (int)$v;
    } elseif (isset($cubeRef[$aidDbg][$ymDbg]) && is_array($cubeRef[$aidDbg][$ymDbg])) {
      foreach ($cubeRef[$aidDbg][$ymDbg] as $v) $cube += (int)$v;
    }
  }

  // ---- 3) 再抽出（aggregate_chosei.php の条件で raw_rows を再評価）----
  // chosei_debug_list() は元行をそのまま返す → 件数やサンプル表示に使う
  $reRows = function_exists('chosei_debug_list')
    ? chosei_debug_list($DATA_DIR, $CACHE_DIR, ['ym'=>$ymDbg, 'sid'=>$sidDbg, 'aid'=>$aidDbg /*, 'limit'=>任意*/])
    : [];

  // スキップ内訳も取りたいので、_ch_match_row と同等のロジックで raw_rows を走査
  $skip = [
    'sales空'          => 0,
    'actor不明/未登録'  => 0,
    'type不一致/欠落'  => 0,
    'systems不一致'    => 0,
    '支払い回数≠1/空' => 0,
    'LINE登録日不正'    => 0,
    '月が対象外'       => 0,
    'aid/sidフィルタ不一致'=> 0,
  ];
  $sumRe = count($reRows); // debug_list は条件を満たす行のみ返す：=再抽出件数
  $listRows = $doList ? array_slice($reRows, 0, $nList) : [];

  // さらに詳しいスキップ理由が必要なら、ここで raw_rows をフルスキャンして内訳を埋める
  // （負荷が気になる場合は省略可）
  try {
    $raw  = @json_decode((string)@file_get_contents(rtrim($CACHE_DIR,'/').'/raw_rows.json'), true) ?: [];
    $rows = (isset($raw['items']) && is_array($raw['items'])) ? $raw['items']
          : ((isset($raw['rows'])  && is_array($raw['rows']))  ? $raw['rows'] : []);
    if ($rows && function_exists('_ch_load_masters') && function_exists('_ch_match_row')) {
      [$salesName2Id, $actorName2Id, $actorId2Type, $actorId2Systems] = _ch_load_masters($DATA_DIR);
      $sid2name = array_flip($salesName2Id);
      $aid2name = array_flip($actorName2Id);
      foreach ($rows as $r) {
        // まず _ch_match_row の共通条件で判定
        [$ok, $aidX, $ymX, $dX, $sidX] = _ch_match_row($r, [$salesName2Id,$actorName2Id,$actorId2Type,$actorId2Systems]);
        // 失敗したら「どこで失敗したか」をざっくりカウント（簡易）
        if (!$ok) {
          // 簡易内訳（正確な枝分かれは _ch_match_row に依存するため概算）
          $salesName = trim((string)($r['セールス担当'] ?? ($r['sales'] ?? '')));
          $actorName = trim((string)($r['動画担当'] ?? ($r['actor'] ?? '')));
          $rowType   = (string)($r['入口'] ?? ($r['type'] ?? ''));
          $lineDate  = (string)($r['LINE登録日'] ?? ($r['line_registered_date'] ?? ($r['登録日'] ?? '')));
          if ($salesName==='') { $skip['sales空']++; continue; }
          if ($actorName==='' || !isset($actorName2Id[$actorName])) { $skip['actor不明/未登録']++; continue; }
          $aidTmp = $actorName2Id[$actorName] ?? '';
          $actorType = $actorId2Type[$aidTmp] ?? '';
          if ($rowType==='' || $actorType==='' || strcasecmp($rowType, $actorType)!==0) { $skip['type不一致/欠落']++; continue; }
          if (!empty($actorId2Systems[$aidTmp])) {
            $rowSys = (string)($r['システム名'] ?? ($r['system'] ?? ($r['システム'] ?? '')));
            if ($rowSys==='' || !isset($actorId2Systems[$aidTmp][mb_strtolower(preg_replace('/\s+/u','',mb_convert_kana($rowSys,'asKV','UTF-8')), 'UTF-8')])) { $skip['systems不一致']++; continue; }
          }
          $nth_raw = (string)($r['支払い何回目'] ?? ($r['支払何回目'] ?? ''));
          $nth = preg_replace('/[^\d]/u','', $nth_raw) ?? '';
          if (!($nth === '1' || $nth === '')) { $skip['支払い回数≠1/空']++; continue; }
          $date = (string)($r['LINE登録日'] ?? ($r['line_registered_date'] ?? ($r['登録日'] ?? '')));
          $ts = strtotime(strtr($date, ['/' => '-', '.' => '-']));
          if (!$date || $ts===false) { $skip['LINE登録日不正']++; continue; }
          if (date('Y-m',$ts) !== $ymDbg) { $skip['月が対象外']++; continue; }
          // ここまで来て ok でないのは、aid/sid フィルタで外れている可能性
          if ($aidDbg!=='' && $actorName !== ($aid2name[$aidDbg] ?? '')) { $skip['aid/sidフィルタ不一致']++; continue; }
          if ($sidDbg!=='' && $salesName !== ($sid2name[$sidDbg] ?? '')) { $skip['aid/sidフィルタ不一致']++; continue; }
        }
      }
    }
  } catch (\Throwable $e) { /* no-op */ }

  // ---- 出力 ----
  echo '<div style="font:14px/1.6 system-ui,sans-serif;padding:12px;background:#0b1220;color:#e8f0ff;border-radius:8px;margin:8px 0">';
  echo '<div style="font-weight:700;margin-bottom:6px">CHOSEI DEBUG</div>';
  echo '<div>ym=<b>'.htmlspecialchars($ymDbg,ENT_QUOTES).'</b>';
  echo ' / sid=<b>'.htmlspecialchars($sidDbg,ENT_QUOTES).'</b>';
  echo ' / aid=<b>'.htmlspecialchars($aidDbg,ENT_QUOTES).'</b>';
  echo ' / cube=<b>'.htmlspecialchars($cubeName ?? 'N/A',ENT_QUOTES).'</b></div>';

  echo '<table style="margin-top:8px;border-collapse:collapse">';
  echo '<tr><th style="text-align:left;padding:4px 8px;border-bottom:1px solid #294a7a">項目</th><th style="text-align:right;padding:4px 8px;border-bottom:1px solid #294a7a">件数</th></tr>';
  echo '<tr><td style="padding:4px 8px">getter 合計</td><td style="padding:4px 8px;text-align:right">'.number_format((int)$getter).'</td></tr>';
  echo '<tr><td style="padding:4px 8px">キューブ素合計</td><td style="padding:4px 8px;text-align:right">'.number_format((int)$cube).'</td></tr>';
  echo '<tr><td style="padding:4px 8px">再抽出（raw_rows.json）</td><td style="padding:4px 8px;text-align:right">'.number_format((int)$sumRe).'</td></tr>';
  echo '</table>';

  echo '<div style="margin-top:10px;font-weight:700">スキップ理由の内訳（概算）</div>';
  echo '<pre style="background:#0b1c34;padding:8px;border-radius:6px;max-height:260px;overflow:auto;margin:6px 0">';
  foreach ($skip as $k=>$v) echo htmlspecialchars($k,ENT_QUOTES).': '.(int)$v."\n";
  echo "</pre>";

  // サンプル一覧
  if ($doList && $listRows) {
    echo '<div style="margin-top:6px;font-weight:700">ヒット行サンプル (最大 '.(int)$nList.' 件)</div>';
    echo '<table style="border-collapse:collapse;width:100%">';
    echo '<tr>';
    foreach (['date'=>'LINE登録日','line_name'=>'LINE名','actor'=>'動画担当','state'=>'状態','type'=>'入口','system'=>'システム名','nth_pay'=>'支払い何回目'] as $k=>$lbl) {
      echo '<th style="text-align:left;padding:6px 8px;border-bottom:1px solid #294a7a;white-space:nowrap">'.htmlspecialchars($lbl,ENT_QUOTES).'</th>';
    }
    echo '</tr>';
    foreach ($listRows as $r) {
      echo '<tr>';
      echo '<td style="padding:4px 8px">'.htmlspecialchars((string)$r['date'],ENT_QUOTES).'</td>';
      echo '<td style="padding:4px 8px">'.htmlspecialchars((string)$r['line_name'],ENT_QUOTES).'</td>';
      echo '<td style="padding:4px 8px">'.htmlspecialchars((string)$r['actor'],ENT_QUOTES).'</td>';
      echo '<td style="padding:4px 8px">'.htmlspecialchars((string)$r['state'],ENT_QUOTES).'</td>';
      echo '<td style="padding:4px 8px">'.htmlspecialchars((string)$r['type'],ENT_QUOTES).'</td>';
      echo '<td style="padding:4px 8px">'.htmlspecialchars((string)$r['system'],ENT_QUOTES).'</td>';
      echo '<td style="padding:4px 8px">'.htmlspecialchars((string)$r['nth_pay'],ENT_QUOTES).'</td>';
      echo '</tr>';
    }
    echo '</table>';
  }

  echo '<div style="opacity:.8;margin-top:6px">tips: ?debug_chosei=1&ym=YYYY-MM&sid=SALES_ID&aid=ACTOR_ID&list=1&n=30 — getter≠cube は表示側/キーの食い違い、cube≠再抽出 は集計側（aggregate_chosei.php）のフィルタ/パースの問題が濃厚です。</div>';
  echo '</div>';

  if ($halt) { exit; }
}
?>
