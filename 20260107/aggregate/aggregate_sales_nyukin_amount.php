<?php
// aggregate/aggregate_sales_nyukin_amount.php
declare(strict_types=1);

/**
 * 入金額（日別合算）集計（from cache/raw_rows.json）
 *
 * 抽出条件（ご指定通り）
 *  - 合算値カラム: 入金額（数値化して合算）
 *  - 動画担当: name（data/actors.json）
 *  - 入金日: 日付（行の `入金日` / `nyukin_date` / `入金_日` / `date`）
 *  - セールス担当: name（data/sales.json）
 *  - 状態 = "入金済み"
 *  - 入口: type（actors.json の俳優 type と行の `入口`/`type` が一致）
 *  - システム名: systems（actors.json）。俳優側に systems がある場合のみ OR一致で絞る
 *
 * 返り値（1系統のみ）
 *   [sid => [aid => ['YYYY-MM' => [day(int|str) => amount(int)]]]]
 */

// ---- helpers ----
if (!function_exists('_na_json')) {
  function _na_json(string $path): array {
    try {
      if (!is_file($path)) return [];
      $txt = @file_get_contents($path);
      if ($txt === false || $txt === '') return [];
      $j = json_decode($txt, true);
      return is_array($j) ? $j : [];
    } catch (\Throwable $e) { return []; }
  }
}
if (!function_exists('_na_rows')) {
  function _na_rows(array $j): array {
    if (isset($j['items']) && is_array($j['items'])) return $j['items'];
    if (isset($j['rows'])  && is_array($j['rows']))  return $j['rows'];
    return [];
  }
}
if (!function_exists('_na_norm')) {
  function _na_norm(?string $s): string {
    $s = (string)$s;
    if ($s === '') return '';
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'asKV', 'UTF-8');
    $s = preg_replace('/\s+/u', '', $s) ?? '';
    $s = function_exists('mb_strtolower') ? mb_strtolower($s,'UTF-8') : strtolower($s);
    return trim($s);
  }
}
if (!function_exists('_na_norm_split')) {
  function _na_norm_split($val): array {
    $out = [];
    if (is_array($val)) $arr = $val;
    else {
      $s = str_replace(['、','，'], ',', (string)$val);
      $arr = array_map('trim', explode(',', $s));
    }
    foreach ($arr as $it) {
      $n = _na_norm((string)$it);
      if ($n !== '') $out[$n] = true;
    }
    return $out;
  }
}
if (!function_exists('_na_date')) {
  /** 文字列/Sheets序数 → 'Y-m-d' */
  function _na_date($v): string {
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
if (!function_exists('_na_amount')) {
  /** 金額文字列を整数化（カンマ/円記号など除去。マイナスも許容） */
  function _na_amount($v): int {
    if (is_numeric($v)) return (int)round((float)$v);
    $s = (string)$v;
    // 全角→半角
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'asKV', 'UTF-8');
    // 通貨記号・カンマ・空白除去、数値と符号だけ残す
    $s = preg_replace('/[^\d\-\+]/u', '', $s) ?? '';
    if ($s === '' || $s === '+' || $s === '-') return 0;
    return (int)$s;
  }
}

// ---- builder ----
if (!function_exists('build_sales_nyukin_amount_by_day')) {
  /**
   * @param string $DATA_DIR
   * @param string $CACHE_DIR
   * @return array [sid => [aid => ['YYYY-MM' => [day => amount]]]]
   */
  function build_sales_nyukin_amount_by_day(string $DATA_DIR, string $CACHE_DIR): array {
    try {
      $out = [];

      // sales.json: name -> sid
      $sales = _na_json(rtrim($DATA_DIR, '/').'/sales.json');
      $salesItems = isset($sales['items']) && is_array($sales['items']) ? $sales['items'] : [];
      $salesName2Id = [];
      foreach ($salesItems as $p) {
        $id = (string)($p['id'] ?? '');
        $nm = trim((string)($p['name'] ?? ''));
        if ($id !== '' && $nm !== '') $salesName2Id[$nm] = $id;
      }

      // actors.json: actor name -> aid, id -> type(norm), id -> systems(norm set)
      $actors = _na_json(rtrim($DATA_DIR, '/').'/actors.json');
      $actorItems = isset($actors['items']) && is_array($actors['items']) ? $actors['items'] : [];
      $actorName2Id = [];
      $actorId2Type = [];
      $actorId2Systems = [];
      foreach ($actorItems as $a) {
        $id = (string)($a['id'] ?? '');
        $nm = trim((string)($a['name'] ?? ''));
        if ($id !== '' && $nm !== '') $actorName2Id[$nm] = $id;
        $tp = isset($a['type']) ? _na_norm((string)$a['type']) : '';
        if ($id !== '' && $tp!=='') $actorId2Type[$id] = $tp;
        $sysSet = _na_norm_split($a['systems'] ?? []);
        if ($id !== '' && $sysSet) $actorId2Systems[$id] = $sysSet;
      }

      if (!$salesName2Id || !$actorName2Id) return $out;

      // raw_rows.json
      $raw  = _na_json(rtrim($CACHE_DIR, '/').'/raw_rows.json');
      $rows = _na_rows($raw);
      if (!$rows) return $out;

      foreach ($rows as $r) {
        // セールス担当
        $salesName = trim((string)($r['セールス担当'] ?? ($r['sales'] ?? '')));
        if ($salesName === '' || !isset($salesName2Id[$salesName])) continue;
        $sid = $salesName2Id[$salesName];

        // 動画担当
        $actorName = trim((string)($r['動画担当'] ?? ($r['actor'] ?? '')));
        if ($actorName === '' || !isset($actorName2Id[$actorName])) continue;
        $aid = $actorName2Id[$actorName];

        // 入口 (=type) 厳密一致（俳優側type・行側入口の両方必須）
        $rowType   = _na_norm((string)($r['入口'] ?? ($r['type'] ?? '')));
        $actorType = $actorId2Type[$aid] ?? '';
        if ($rowType === '' || $actorType === '' || $rowType !== $actorType) continue;

        // システム名 (=systems) — 俳優側に設定がある場合のみ OR一致で適用
        if (isset($actorId2Systems[$aid]) && $actorId2Systems[$aid]) {
          $rowSystem = _na_norm((string)($r['システム名'] ?? ($r['system'] ?? ($r['システム'] ?? ''))));
          if ($rowSystem === '' || !isset($actorId2Systems[$aid][$rowSystem])) continue;
        }
        
        // 対象のシステム名に一致するものだけ処理
        $targetSystems = [
          'ChatGPTフロント',
          'Instagramフロント',
          '動画編集フロント',
          'TikTokフロント',
          '副業フロント',
          '副業ウェブフリフロント',
        ];
        $systemName = trim((string)($r['システム名'] ?? ''));
        if ($systemName === '' || !in_array($systemName, $targetSystems, true)) continue;

        // 入金日
        $date = _na_date($r['入金日'] ?? ($r['nyukin_date'] ?? ($r['入金_日'] ?? ($r['date'] ?? ''))));
        if ($date === '') continue;
        $ts = strtotime($date); if ($ts === false) continue;
        $ym = date('Y-m', $ts);
        $d  = (int)date('j', $ts);

        // 状態 = 入金済み
        $state = trim((string)($r['状態'] ?? ($r['status'] ?? '')));
        if ($state !== '入金済み') continue;

        // 入金額 > 0 を集計
        $amt = _na_amount($r['入金額'] ?? ($r['nyukin_amount'] ?? ($r['入金'] ?? 0)));
        if ($amt <= 0) continue;

        // 加算（俳優あり階層のみ）
        $out[$sid][$aid][$ym][$d] = (int)($out[$sid][$aid][$ym][$d] ?? 0) + (int)$amt;
      }

      return $out;
    } catch (\Throwable $e) {
      return [];
    }
  }
}
