<?php
/* ===== SALES COUNT DEBUG (セールス件数突き合わせ) =============================
 * 使い方:
 *   ?debug_sales=1
 * オプション:
 *   &ym=YYYY-MM   … 対象月（省略時 $mdef['ym'] か当月）
 *   &n=5          … 検証する担当者の件数（先頭から）
 *   &aid=ACTOR_ID … 俳優で絞る（省略時は全俳優合算）
 *   &list=1       … ヒット行サンプル表示（最大20）
 */
if (!empty($_GET['debug_sales'])) {
  $ym   = isset($_GET['ym']) ? (string)$_GET['ym'] : ($mdef['ym'] ?? date('Y-m'));
  $n    = max(1, (int)($_GET['n'] ?? 5));
  $aidFilter = isset($_GET['aid']) ? (string)$_GET['aid'] : ($aid ?? null);
  $list = !empty($_GET['list']);

  // sales.json
  $salesJson = @json_decode((string)@file_get_contents(rtrim($DATA_DIR,'/').'/sales.json'), true) ?: [];
  $salesItems = (isset($salesJson['items']) && is_array($salesJson['items'])) ? $salesJson['items'] : [];
  $name2sid = $sid2name = [];
  foreach ($salesItems as $p) {
    $sid=(string)($p['id']??''); $nm=trim((string)($p['name']??''));
    if ($sid!=='' && $nm!=='') { $name2sid[$nm]=$sid; $sid2name[$sid]=$nm; }
  }

  // actors.json（name→id, id→type, id→systems）
  $actorsData = @json_decode((string)@file_get_contents(rtrim($DATA_DIR,'/').'/actors.json'), true) ?: [];
  $actorItems = (isset($actorsData['items']) && is_array($actorsData['items'])) ? $actorsData['items'] : [];
  $aname2aid=[]; $aid2name=[]; $aid2type=[]; $aid2systems=[];
  $norm = function(?string $s): string {
    $s=(string)$s;
    if (function_exists('mb_convert_kana')) $s=mb_convert_kana($s,'asKV','UTF-8');
    $s=preg_replace('/\s+/u','',$s)??''; $s=trim($s);
    if (function_exists('mb_strtolower')) $s=mb_strtolower($s,'UTF-8'); else $s=strtolower($s);
    return $s;
  };
  $splitSet = function($v) use ($norm): array {
    $out=[]; if (is_array($v)) $arr=$v; else { $s=str_replace(['、','，'], ',', (string)$v); $arr=array_map('trim', explode(',', $s)); }
    foreach ($arr as $it) { $n=$norm((string)$it); if ($n!=='') $out[$n]=true; }
    return $out;
  };
  foreach ($actorItems as $a) {
    $id=(string)($a['id']??''); $nm=trim((string)($a['name']??''));
    if ($id!=='' && $nm!=='') { $aname2aid[$nm]=$id; $aid2name[$id]=$nm; }
    $t = isset($a['type']) ? $norm((string)$a['type']) : '';
    if ($id!=='' && $t!=='') $aid2type[$id]=$t;
    $sys=$splitSet($a['systems'] ?? []);
    if ($id!=='' && $sys) $aid2systems[$id]=$sys;
  }

  // raw_rows.json
  $rawJson = @json_decode((string)@file_get_contents(rtrim($CACHE_DIR,'/').'/raw_rows.json'), true) ?: [];
  $rows = (isset($rawJson['items']) && is_array($rawJson['items'])) ? $rawJson['items'] :
          ((isset($rawJson['rows']) && is_array($rawJson['rows'])) ? $rawJson['rows'] : []);

  // 対象担当者
  $probe=[]; foreach ($salesItems as $p){ if(count($probe)>=$n) break;
    $sid=(string)($p['id']??''); $nm=trim((string)($p['name']??'')); if($sid!=='' && $nm!=='') $probe[]=['id'=>$sid,'name'=>$nm];
  }

  // allowed
  $allowedStates = ['失注','入金済み','入金待ち','一旦保留'];

  // HTML
  header('Content-Type: text/html; charset=UTF-8');
  echo '<div style="font:14px/1.6 system-ui,sans-serif;padding:12px;background:#0b1220;color:#e8f0ff">';
  echo '<h2 style="margin:0 0 12px">SALES COUNT DEBUG (ym='.htmlspecialchars($ym).')</h2>';

  foreach ($probe as $sp) {
    $sid=$sp['id']; $sname=$sp['name'];

    // ① 表示で使われる合計（getterベース）
    $sumDisplay=0;
    for ($d=1; $d<=31; $d++) {
      if (!isset($days) || $d > (int)$days) break;
      if (function_exists('sales_count_get')) {
        $sumDisplay += (int)sales_count_get($salesCountByDay ?? [], $sid, $aidFilter ?? null, $ym, $d);
      }
    }

    // ② キューブ直接合算（俳優階層のみ・フラット無視）
    $cube = $salesCountByDay[$sid] ?? [];
    $sumCube=0;
    if (is_array($cube)) {
      if ($aidFilter) {
        foreach (($cube[$aidFilter][$ym] ?? []) as $d=>$v) $sumCube += (int)$v;
      } else {
        foreach ($cube as $aidKey=>$byYm) {
          if (!is_array($byYm)) continue;
          foreach (($byYm[$ym] ?? []) as $d=>$v) $sumCube += (int)$v;
        }
      }
    }

    // ③ 元データ再集計（同条件：type/systemsフィルタ込み）
    $sumSrc=0; $samples=[];
    foreach ($rows as $r) {
      $salesName=trim((string)($r['セールス担当'] ?? ($r['sales'] ?? '')));
      if ($salesName==='' || !isset($name2sid[$salesName]) || $name2sid[$salesName] !== $sid) continue;

      $actorName=trim((string)($r['動画担当'] ?? ($r['actor'] ?? '')));
      if ($actorName==='' || !isset($aname2aid[$actorName])) continue;
      $aidRow=$aname2aid[$actorName];
      if ($aidFilter && $aidRow !== $aidFilter) continue;

      // type フィルタ（俳優側 type が設定されていれば一致必須）
      if (isset($aid2type[$aidRow])) {
        $rowType=$norm((string)($r['入口'] ?? ($r['type'] ?? '')));
        if ($rowType==='' || $rowType !== $aid2type[$aidRow]) continue;
      }
      // systems フィルタ（俳優側 systems が設定されていれば OR一致必須）
      if (isset($aid2systems[$aidRow]) && $aid2systems[$aidRow]) {
        $rowSys=$norm((string)($r['システム名'] ?? ($r['system'] ?? ($r['システム'] ?? ''))));
        if ($rowSys==='' || !isset($aid2systems[$aidRow][$rowSys])) continue;
      }

      // セールス日
      $dateRaw = $r['セールス日'] ?? ($r['date'] ?? '');
      $dateStr = (function($v){
        if (is_string($v)) { $s=strtr(trim($v),['/'=>'-','.'=>'-']); $ts=strtotime($s); if($ts!==false) return date('Y-m-d',$ts); if(is_numeric($v)) $v=(float)$v; }
        if (is_int($v)||is_float($v)) { $unix=(int)round(((float)$v-25569)*86400); if($unix<=0) return ''; return gmdate('Y-m-d',$unix); }
        return '';
      })($dateRaw);
      if ($dateStr==='') continue;
      $ts=strtotime($dateStr); if($ts===false) continue;
      if (date('Y-m',$ts)!==$ym) continue;

      // 状態
      $state=trim((string)($r['状態'] ?? ($r['status'] ?? '')));
      if (!in_array($state, $allowedStates, true)) continue;

      // 支払い何回目
      $nraw=(string)($r['支払い何回目'] ?? ($r['支払何回目'] ?? ''));
      $ndig=preg_replace('/[^\d]/u','',$nraw) ?? '';
      if (!($ndig==='1' || $ndig==='')) continue;

      $sumSrc++;
      if ($list && count($samples)<20) $samples[]=['date'=>$dateStr,'sales'=>$salesName,'actor'=>$actorName,'state'=>$state,'n'=>$nraw];
    }

    // 出力
    echo '<div style="margin:10px 0;padding:10px;border:1px solid #25406d;border-radius:8px;background:#0e213d">';
    echo '<div style="font-weight:600;margin-bottom:6px">SID: <code>'.htmlspecialchars($sid).'</code> / 担当: '.htmlspecialchars($sname).'</div>';
    echo '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:6px">';
    echo '<tr><th style="text-align:left;padding:4px;border-bottom:1px solid #274a7a">項目</th>'.
         '<th style="text-align:right;padding:4px;border-bottom:1px solid #274a7a">表示( getter )</th>'.
         '<th style="text-align:right;padding:4px;border-bottom:1px solid #274a7a">キューブ直合算</th>'.
         '<th style="text-align:right;padding:4px;border-bottom:1px solid #274a7a">元JSON再集計</th></tr>';
    echo '<tr><td style="padding:4px 6px">セールス件数</td>'.
         '<td style="padding:4px 6px;text-align:right">'.number_format($sumDisplay).'</td>'.
         '<td style="padding:4px 6px;text-align:right">'.number_format($sumCube).'</td>'.
         '<td style="padding:4px 6px;text-align:right">'.number_format($sumSrc).'</td></tr>';
    echo '</table>';

    if ($list) {
      echo '<div style="font-weight:600;margin-top:6px">ヒット行サンプル（最大20件）</div>';
      echo '<pre style="background:#0b1c34;padding:8px;border-radius:6px;max-height:220px;overflow:auto">';
      if ($samples) { foreach ($samples as $s) printf("%s  sales=%s  actor=%s  状態=%s  支払い何回目=%s\n",$s['date'],$s['sales'],$s['actor'],$s['state'],$s['n']); }
      else { echo "(no rows)\n"; }
      echo '</pre>';
    }
    echo '</div>';
  }
  echo '</div>';
  exit;
}
