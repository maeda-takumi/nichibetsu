<?php
/* ===== 入金件数デバッグ（ゼロ原因の切り分け）==================================
 * 使い方:
 *   ?debug_nyukin=1
 * オプション:
 *   &ym=YYYY-MM     … 対象月（省略時 $mdef['ym'] → なければ当月）
 *   &n=5            … 検証する担当者件数（先頭から）
 *   &aid=ACTOR_ID   … 俳優を絞って検証（省略時は全俳優合算）
 *   &list=1         … ヒット行サンプル（各担当 最大20件）
 *   &unmatched=1    … 未登録の担当名／動画担当名の一覧を表示
 */
if (!empty($_GET['debug_nyukin'])) {
  $ym   = isset($_GET['ym']) ? (string)$_GET['ym'] : ($mdef['ym'] ?? date('Y-m'));
  $n    = max(1, (int)($_GET['n'] ?? 5));
  $aidFilter = isset($_GET['aid']) ? (string)$_GET['aid'] : ($aid ?? null);
  $list = !empty($_GET['list']);
  $showUnmatched = !empty($_GET['unmatched']);

  // sales.json
  $salesJson = @json_decode((string)@file_get_contents(rtrim($DATA_DIR,'/').'/sales.json'), true) ?: [];
  $salesItems = (isset($salesJson['items']) && is_array($salesJson['items'])) ? $salesJson['items'] : [];
  $name2sid = $sid2name = [];
  foreach ($salesItems as $p) {
    $sid = (string)($p['id'] ?? '');
    $nm  = trim((string)($p['name'] ?? ''));
    if ($sid !== '' && $nm !== '') { $name2sid[$nm]=$sid; $sid2name[$sid]=$nm; }
  }
  // actors.json（admin 側で既に $actorsData を読んでいる前提）
  $actorItems = (isset($actorsData['items']) && is_array($actorsData['items'])) ? $actorsData['items'] : [];
  $aname2aid = $aid2name = [];
  foreach ($actorItems as $a) {
    $id=(string)($a['id']??''); $nm=trim((string)($a['name']??''));
    if ($id!=='' && $nm!=='') { $aname2aid[$nm]=$id; $aid2name[$id]=$nm; }
  }

  // raw_rows.json（CACHE）
  $rawJson = @json_decode((string)@file_get_contents(rtrim($CACHE_DIR,'/').'/raw_rows.json'), true) ?: [];
  $rows = (isset($rawJson['items']) && is_array($rawJson['items'])) ? $rawJson['items'] :
          ((isset($rawJson['rows']) && is_array($rawJson['rows'])) ? $rawJson['rows'] : []);

  // ヘルパ
  $parseDate = function($v): string {
    if (is_string($v)) { $s=strtr(trim($v),['/'=>'-','.'=>'-']); $ts=strtotime($s); if($ts!==false) return date('Y-m-d',$ts); if(is_numeric($v)) $v=(float)$v; }
    if (is_int($v)||is_float($v)) { $unix=(int)round(((float)$v-25569)*86400); if($unix<=0) return ''; return gmdate('Y-m-d',$unix); }
    return '';
  };
  $digits = fn($s)=> (preg_replace('/[^\d]/u','',(string)$s) ?? '');
  $asInt  = function($v){ if(is_numeric($v)) return (int)$v; $s=preg_replace('/[^\d\-]/u','',(string)$v); return ($s===''||$s==='-')?0:(int)$s; };

  // 検証対象の担当者
  $probe = [];
  foreach ($salesItems as $p) { if (count($probe)>=$n) break;
    $sid=(string)($p['id']??''); $nm=trim((string)($p['name']??'')); if($sid!=='' && $nm!=='') $probe[]=['id'=>$sid,'name'=>$nm];
  }

  // 入金日カラム検出（表記ゆらぎ対応）
  $nyukinDateKeys = ['入金日','入金_日','nyukin_date','入金予定日','入金日付'];
  $hasNyukinDate = false;
  foreach ($rows as $r) { foreach ($nyukinDateKeys as $k) { if (array_key_exists($k,$r) && trim((string)$r[$k])!=='') { $hasNyukinDate=true; break 2; } } }

  header('Content-Type: text/html; charset=UTF-8');
  echo '<div style="font:14px/1.6 system-ui,sans-serif;padding:12px;background:#0b1220;color:#e8f0ff">';
  echo '<h2 style="margin:0 0 12px">NYUKIN COUNT DEBUG (ym='.htmlspecialchars($ym).')</h2>';
  echo '<div style="margin:0 0 8px;opacity:.9">aid filter: <code>'.htmlspecialchars((string)$aidFilter).'</code> ['.htmlspecialchars($aid2name[$aidFilter] ?? '—').'] / 入金日カラム検出: <strong>'.($hasNyukinDate?'OK':'見つからず').'</strong></div>';

  foreach ($probe as $sp) {
    $sid=$sp['id']; $sname=$sp['name'];

    // ① 集計側（月合計）…形A/Bどちらでも合算できるように
    $sumAgg=0;
    if (isset($salesNyukinCountByDay[$sid]) && is_array($salesNyukinCountByDay[$sid])) {
      $ymKeys = [$ym, sprintf('%04d-%02d',(int)substr($ym,0,4),(int)substr($ym,5))];
      if ($aidFilter) {
        foreach ($ymKeys as $yk) foreach (($salesNyukinCountByDay[$sid][$aidFilter][$yk] ?? []) as $d=>$c) $sumAgg += (int)$c;
      } else {
        foreach ($salesNyukinCountByDay[$sid] as $maybeAid=>$byYm) {
          if (!is_array($byYm)) continue;
          foreach ($ymKeys as $yk) foreach (($byYm[$yk] ?? []) as $d=>$c) $sumAgg += (int)$c;
        }
        // 形B（actor階層なし）も試す
        foreach ($ymKeys as $yk) foreach (($salesNyukinCountByDay[$sid][$yk] ?? []) as $d=>$c) $sumAgg += (int)$c;
      }
    }

    // ② 元データから再集計（同条件）＋理由カウント
    $sumSrc=0; $samples=[];
    $reasons = [
      '担当名が空/未登録'=>0, '動画担当が空/未登録'=>0, '入金日なし/不正'=>0, '月が対象外'=>0,
      '状態が対象外'=>0, '支払い何回目≠1/空'=>0, 'aidフィルタ不一致'=>0,
    ];
    $unmatchedSales=[]; $unmatchedActors=[];
    $allowedStates=['失注','入金済み','入金待ち','一旦保留'];

    foreach ($rows as $r) {
      // セールス担当
      $salesName=trim((string)($r['セールス担当'] ?? ($r['sales'] ?? '')));
      if ($salesName==='' || !isset($name2sid[$salesName])) { if($salesName!=='') $unmatchedSales[$salesName]=true; $reasons['担当名が空/未登録']++; continue; }
      if ($name2sid[$salesName] !== $sid) continue;

      // 動画担当
      $actorName=trim((string)($r['動画担当'] ?? ($r['actor'] ?? '')));
      if ($actorName==='' || !isset($aname2aid[$actorName])) { if($actorName!=='') $unmatchedActors[$actorName]=true; $reasons['動画担当が空/未登録']++; continue; }
      $aidRow=$aname2aid[$actorName];
      if ($aidFilter && $aidRow !== $aidFilter) { $reasons['aidフィルタ不一致']++; continue; }

      // 入金日（←ここがセールス件数と違う）
      $dateRaw = $r['入金日'] ?? ($r['入金_日'] ?? ($r['nyukin_date'] ?? null));
      $date = $parseDate($dateRaw);
      if ($date==='') { $reasons['入金日なし/不正']++; continue; }
      $ts=strtotime($date); if($ts===false){ $reasons['入金日なし/不正']++; continue; }
      $ymRow = date('Y-m', $ts);
      if ($ymRow !== $ym) { $reasons['月が対象外']++; continue; }

      // 状態
      $state = trim((string)($r['状態'] ?? ($r['status'] ?? '')));
      if (!in_array($state, $allowedStates, true)) { $reasons['状態が対象外']++; continue; }

      // 支払い何回目
      $nraw = (string)($r['支払い何回目'] ?? ($r['支払何回目'] ?? ''));
      $ndig = $digits($nraw);
      if (!($ndig==='1' || $ndig==='')) { $reasons['支払い何回目≠1/空']++; continue; }

      // ヒット
      $sumSrc++;
      if ($list && count($samples)<20) $samples[]=['date'=>$date,'sales'=>$salesName,'actor'=>$actorName,'state'=>$state,'n'=>$nraw];
    }

    // 出力
    echo '<div style="margin:10px 0;padding:10px;border:1px solid #25406d;border-radius:8px;background:#0e213d">';
    echo '<div style="font-weight:600;margin-bottom:6px">SID: <code>'.htmlspecialchars($sid).'</code> / 担当: '.htmlspecialchars($sname).'</div>';
    echo '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:6px">';
    echo '<tr><th style="text-align:left;padding:4px;border-bottom:1px solid #274a7a">項目</th>'.
         '<th style="text-align:right;padding:4px;border-bottom:1px solid #274a7a">集計結果</th>'.
         '<th style="text-align:right;padding:4px;border-bottom:1px solid #274a7a">元JSON再集計</th>'.
         '<th style="text-align:right;padding:4px;border-bottom:1px solid #274a7a">差分(元-集計)</th></tr>';
    echo '<tr><td style="padding:4px 6px">入金件数</td>'.
         '<td style="padding:4px 6px;text-align:right">'.number_format($sumAgg).'</td>'.
         '<td style="padding:4px 6px;text-align:right">'.number_format($sumSrc).'</td>'.
         '<td style="padding:4px 6px;text-align:right">'.number_format($sumSrc - $sumAgg).'</td></tr>';
    echo '</table>';

    echo '<div style="font-weight:600">スキップ理由の内訳</div>';
    echo '<pre style="background:#0b1c34;padding:8px;border-radius:6px;max-height:180px;overflow:auto">';
    foreach ($reasons as $k=>$v) echo $k.': '.$v."\n";
    echo '</pre>';

    if ($list) {
      echo '<div style="font-weight:600;margin-top:6px">ヒット行サンプル（最大20件）</div>';
      echo '<pre style="background:#0b1c34;padding:8px;border-radius:6px;max-height:220px;overflow:auto">';
      if ($samples) { foreach ($samples as $s) printf("%s  sales=%s  actor=%s  状態=%s  支払い何回目=%s\n",$s['date'],$s['sales'],$s['actor'],$s['state'],$s['n']); }
      else { echo "(no rows)\n"; }
      echo '</pre>';
    }

    echo '</div>';
  }

  if ($showUnmatched) {
    $unknownSales = []; $unknownActors = [];
    foreach ($rows as $r) {
      $sn=trim((string)($r['セールス担当'] ?? ($r['sales'] ?? '')));
      if ($sn!=='' && !isset($name2sid[$sn])) $unknownSales[$sn]=true;
      $an=trim((string)($r['動画担当'] ?? ($r['actor'] ?? '')));
      if ($an!=='' && !isset($aname2aid[$an])) $unknownActors[$an]=true;
    }
    echo '<div style="margin-top:12px;padding:10px;border:1px dashed #335b97;border-radius:8px;background:#0e213d">';
    echo '<div style="font-weight:600;margin-bottom:6px">未登録の担当名 / 動画担当名</div>';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
    echo '<div><div style="opacity:.9;margin-bottom:4px">担当名（sales.json 未登録）</div><pre style="background:#0b1c34;padding:8px;border-radius:6px;max-height:200px;overflow:auto">';
    if ($unknownSales) { ksort($unknownSales); foreach(array_keys($unknownSales) as $nm) echo $nm,"\n"; } else { echo "(none)\n"; }
    echo '</pre></div>';
    echo '<div><div style="opacity:.9;margin-bottom:4px">動画担当（actors.json 未登録）</div><pre style="background:#0b1c34;padding:8px;border-radius:6px;max-height:200px;overflow:auto">';
    if ($unknownActors) { ksort($unknownActors); foreach(array_keys($unknownActors) as $nm) echo $nm,"\n"; } else { echo "(none)\n"; }
    echo '</pre></div>';
    echo '</div></div>';
  }

  echo '<div style="margin-top:10px;opacity:.8">tips: ?debug_nyukin=1&ym=YYYY-MM&n=5&aid=ACTOR_ID&list=1&unmatched=1</div>';
  echo '</div>';
  exit;
}
