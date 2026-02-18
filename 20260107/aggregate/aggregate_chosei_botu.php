<?php
// aggregate/aggregate_chosei.php
declare(strict_types=1);

/**
 * 調整系（chosei）抽出：指定条件を満たす行を日別カウントに集計
 *
 * 抽出条件（ご指定通り）
 *  - 動画担当 = name（data/actors.json）
 *  - LINE登録日 = 日付（行の `LINE登録日` / `line_registered_date` / `登録日` を使用）
 *  - セールス担当 ≠ ""（空でなければ通す。sales.json に存在すれば sales_id にも紐付け）
 *  - 支払い何回目 = "1" または ""
 *  - 入口 = type（actors.json の俳優 type と行の `入口`/`type` が厳密一致）
 *  - システム名 = systems（actors.json）。俳優側に設定がある場合のみ OR 一致必須（カンマ区切り可）
 *
 * 返り値（用途に応じて2系統）
 *  - build_chosei_by_day_actor(): [actor_id => ['YYYY-MM' => [day => count]]]
 *  - build_chosei_by_day_sales(): [sales_id => [actor_id => ['YYYY-MM' => [day => count]]]]
 */

////////////////////
// 小物ユーティリティ
////////////////////
if (!function_exists('_ch_json')) {
  function _ch_json(string $path): array {
    try {
      if (!is_file($path)) return [];
      $txt = @file_get_contents($path);
      if ($txt === false || $txt === '') return [];
      $j = json_decode($txt, true);
      return is_array($j) ? $j : [];
    } catch (\Throwable $e) { return []; }
  }
}
if (!function_exists('_ch_rows')) {
  function _ch_rows(array $j): array {
    if (isset($j['items']) && is_array($j['items'])) return $j['items'];
    if (isset($j['rows'])  && is_array($j['rows']))  return $j['rows'];
    return [];
  }
}
if (!function_exists('_ch_norm')) {
  function _ch_norm(?string $s): string {
    $s = (string)$s;
    if ($s === '') return '';
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'asKV', 'UTF-8');
    $s = preg_replace('/\s+/u', '', $s) ?? '';
    $s = function_exists('mb_strtolower') ? mb_strtolower($s,'UTF-8') : strtolower($s);
    return trim($s);
  }
}
if (!function_exists('_ch_norm_split')) {
  function _ch_norm_split($val): array {
    $out = [];
    if (is_array($val)) $arr = $val;
    else {
      $s = str_replace(['、','，'], ',', (string)$val);
      $arr = array_map('trim', explode(',', $s));
    }
    foreach ($arr as $it) {
      $n = _ch_norm((string)$it);
      if ($n !== '') $out[$n] = true;
    }
    return $out;
  }
}
if (!function_exists('_ch_digits')) {
  function _ch_digits(string $s): string {
    return preg_replace('/[^\d]/u', '', $s) ?? '';
  }
}
if (!function_exists('_ch_date')) {
  /** 文字列 or Google Sheets serial → 'Y-m-d' */
  function _ch_date($v): string {
    if (is_string($v)) {
      $s = strtr(trim($v), ['/' => '-', '.' => '-']);
      $ts = strtotime($s);
      if ($ts !== false) return date('Y-m-d', $ts);
      if (is_numeric($v)) $v = (float)$v;
    }
    if (is_int($v) || is_float($v)) {
      $unix = (int)round(((float)$v - 25569) * 86400); // 1899-12-30 起点
      if ($unix <= 0) return '';
      return gmdate('Y-m-d', $unix);
    }
    return '';
  }
}

////////////////////
// 共通ロード
////////////////////
if (!function_exists('_ch_load_masters')) {
  /**
   * @return array [$salesName2Id, $actorName2Id, $actorId2Type, $actorId2Systems]
   */
  function _ch_load_masters(string $DATA_DIR): array {
    $sales = _ch_json(rtrim($DATA_DIR,'/').'/sales.json');
    $actors= _ch_json(rtrim($DATA_DIR,'/').'/actors.json');

    $salesName2Id = [];
    foreach ((isset($sales['items']) && is_array($sales['items'])) ? $sales['items'] : [] as $p) {
      $id = (string)($p['id'] ?? ''); $nm = trim((string)($p['name'] ?? ''));
      if ($id !== '' && $nm !== '') $salesName2Id[$nm] = $id;
    }

    $actorName2Id = []; $actorId2Type = []; $actorId2Systems = [];
    foreach ((isset($actors['items']) && is_array($actors['items'])) ? $actors['items'] : [] as $a) {
      $id = (string)($a['id'] ?? ''); $nm = trim((string)($a['name'] ?? ''));
      if ($id !== '' && $nm !== '') $actorName2Id[$nm] = $id;
      $tp = isset($a['type']) ? _ch_norm((string)$a['type']) : '';
      if ($id !== '' && $tp!=='') $actorId2Type[$id] = $tp;
      $sys = _ch_norm_split($a['systems'] ?? []);
      if ($id !== '' && $sys) $actorId2Systems[$id] = $sys;
    }
    return [$salesName2Id, $actorName2Id, $actorId2Type, $actorId2Systems];
  }
}

////////////////////
// 中核ロジック（1件判定）
////////////////////
if (!function_exists('_ch_match_row')) {
  /**
   * 条件を満たすか判定し、該当すれば (aid, ym, d, sid|null) を返す
   * - sid は sales.json に名前があれば ID、無ければ null
   */
  function _ch_match_row(array $r, array $masters): array {
    [$salesName2Id, $actorName2Id, $actorId2Type, $actorId2Systems] = $masters;

    // セールス担当（空は不可）
    $salesName = trim((string)($r['セールス担当'] ?? ($r['sales'] ?? '')));
    if ($salesName === '') return [false, null, null, null, null];
    $sid = $salesName2Id[$salesName] ?? null; // なければ null のまま（actor集計は通す）

    // 動画担当（name → aid）
    $actorName = trim((string)($r['動画担当'] ?? ($r['actor'] ?? '')));
    if ($actorName === '' || !isset($actorName2Id[$actorName])) return [false, null, null, null, null];
    $aid = $actorName2Id[$actorName];

    // 入口（俳優type と 行の入口が一致）
    $rowType   = _ch_norm((string)($r['入口'] ?? ($r['type'] ?? '')));
    $actorType = $actorId2Type[$aid] ?? '';
    if ($rowType === '' || $actorType === '' || $rowType !== $actorType) return [false, null, null, null, null];

    // システム名（俳優側に設定がある場合のみ OR一致で適用）
    if (!empty($actorId2Systems[$aid])) {
      $rowSystem = _ch_norm((string)($r['システム名'] ?? ($r['system'] ?? ($r['システム'] ?? ''))));
      if ($rowSystem === '' || !isset($actorId2Systems[$aid][$rowSystem])) return [false, null, null, null, null];
    }

    // 支払い何回目 = 1 or 空
    $nth_raw = (string)($r['支払い何回目'] ?? ($r['支払何回目'] ?? ''));
    $nth = _ch_digits($nth_raw);
    if (!($nth === '1' || $nth === '')) return [false, null, null, null, null];

    // LINE登録日
    $date = _ch_date($r['LINE登録日'] ?? ($r['line_registered_date'] ?? ($r['登録日'] ?? '')));
    if ($date === '') return [false, null, null, null, null];
    $ts = strtotime($date);
    if ($ts === false) return [false, null, null, null, null];
    $ym = date('Y-m', $ts);
    $d  = (int)date('j',  $ts);

    return [true, $aid, $ym, $d, $sid];
  }
}

////////////////////
// ビルダー：俳優別
////////////////////
if (!function_exists('build_chosei_by_day_actor')) {
  /**
   * 俳優別カウント
   * @return array [aid => ['YYYY-MM' => [day => count]]]
   */
  function build_chosei_by_day_actor(string $DATA_DIR, string $CACHE_DIR): array {
    try {
      $masters = _ch_load_masters($DATA_DIR);
      $rows = _ch_rows(_ch_json(rtrim($CACHE_DIR,'/').'/raw_rows.json'));
      if (!$rows) return [];
      $out = [];
      foreach ($rows as $r) {
        [$ok, $aid, $ym, $d] = _ch_match_row($r, $masters);
        if (!$ok) continue;
        $out[$aid][$ym][$d] = (int)($out[$aid][$ym][$d] ?? 0) + 1;
      }
      return $out;
    } catch (\Throwable $e) {
      return [];
    }
  }
}

////////////////////
// ビルダー：セールス×俳優
////////////////////
if (!function_exists('build_chosei_by_day_sales')) {
  /**
   * セールスIDにも紐づけ（sales.json に存在する名前だけ sid 付与）
   * @return array [sid => [aid => ['YYYY-MM' => [day => count]]]]
   */
  function build_chosei_by_day_sales(string $DATA_DIR, string $CACHE_DIR): array {
    try {
      $masters = _ch_load_masters($DATA_DIR);
      $rows = _ch_rows(_ch_json(rtrim($CACHE_DIR,'/').'/raw_rows.json'));
      if (!$rows) return [];
      $out = [];
      foreach ($rows as $r) {
        [$ok, $aid, $ym, $d, $sid] = _ch_match_row($r, $masters);
        if (!$ok) continue;
        if ($sid === null || $sid === '') continue; // sales.json に名前が無い行はこの出力には載せない
        $out[$sid][$aid][$ym][$d] = (int)($out[$sid][$aid][$ym][$d] ?? 0) + 1;
      }
      return $out;
    } catch (\Throwable $e) {
      return [];
    }
  }
}

////////////////////
// デバッグ：該当行をそのまま返す
////////////////////
if (!function_exists('chosei_debug_list')) {
  /**
   * 条件に合致した元行を、表示向けに整形して返す
   * 取得項目：LINE登録日 / LINE名 / 動画担当 / 状態 / 入口 / システム名 / 支払い何回目
   * @param array $opt ['sid'=>string|null, 'aid'=>string|null, 'ym'=>string|null, 'limit'=>int|null]
   */
  function chosei_debug_list(string $DATA_DIR, string $CACHE_DIR, array $opt=[]): array {
    $YM   = isset($opt['ym']) ? (string)$opt['ym'] : '';
    $SID  = isset($opt['sid']) ? (string)$opt['sid'] : '';
    $AID  = isset($opt['aid']) ? (string)$opt['aid'] : '';
    $LIMIT= array_key_exists('limit',$opt) ? max(1,(int)$opt['limit']) : null;

    [$salesName2Id, $actorName2Id, $actorId2Type, $actorId2Systems] = _ch_load_masters($DATA_DIR);
    $rows = _ch_rows(_ch_json(rtrim($CACHE_DIR,'/').'/raw_rows.json'));

    $name2sid = array_flip($salesName2Id);
    $name2aid = array_flip($actorName2Id);

    $out = [];
    foreach ($rows as $r) {
      [$ok, $aid, $ym, $d, $sid] = _ch_match_row($r, [$salesName2Id, $actorName2Id, $actorId2Type, $actorId2Systems]);
      if (!$ok) continue;

      // 追加の上位フィルタ（任意）
      if ($YM !== '' && $YM !== $ym) continue;
      if ($SID !== '' && $SID !== (string)($sid ?? '')) continue;
      if ($AID !== '' && $AID !== (string)$aid) continue;

      $line_date = _ch_date($r['LINE登録日'] ?? ($r['line_registered_date'] ?? ($r['登録日'] ?? '')));
      $line_name = trim((string)($r['LINE名'] ?? ($r['line_name'] ?? ($r['名前'] ?? ''))));
      $actorName = $name2aid[$aid] ?? ''; // 俳優名を逆引き（無ければ空）
      $state     = trim((string)($r['状態'] ?? ($r['status'] ?? '')));
      $type      = _ch_norm((string)($r['入口'] ?? ($r['type'] ?? '')));
      $system    = (string)($r['システム名'] ?? ($r['system'] ?? ($r['システム'] ?? '')));
      $nth       = (string)($r['支払い何回目'] ?? ($r['支払何回目'] ?? ''));

      $out[] = [
        'date'      => $line_date,
        'line_name' => $line_name,
        'actor'     => $actorName,
        'state'     => $state,
        'type'      => $type,
        'system'    => $system,
        'nth_pay'   => $nth,
        'row'       => $r,
      ];

      if ($LIMIT !== null && count($out) >= $LIMIT) break;
    }
    return $out;
  }
}
