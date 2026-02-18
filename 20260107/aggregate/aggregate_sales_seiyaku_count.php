<?php
// aggregate/aggregate_sales_seiyaku_count.php
declare(strict_types=1);

/**
 * 成約件数（日別）集計（from cache/raw_rows.json）
 *
 * ■ 抽出条件
 *  - 動画担当 = name（data/actors.json）
 *  - セールス日 = 日付（行の `セールス日` 優先、なければ `date`）
 *  - 入口 = type（actors.json の俳優 type と行の `入口`/`type` が一致）
 *  - 支払い何回目 = "1" または ""（空）
 *  - セールス担当 = name（data/sales.json）
 *  - 状態 = "入金済み"
 *
 * ■ 返り値
 *   [sales_id => [actor_id => ['YYYY-MM' => [day(int|str) => count(int)]]]]
 *
 * ■ 備考
 *  - 文字の揺れに強い正規化（全角/半角・空白・大小）
 *  - Google Sheets の日付シリアルも解釈
 *  - 「入口=type」は厳密一致（俳優側type・行側入口の両方必須）
 */

// -------------------------- helpers --------------------------
if (!function_exists('_sec_json')) {
  function _sec_json(string $path): array {
    try {
      if (!is_file($path)) return [];
      $txt = @file_get_contents($path);
      if ($txt === false || $txt === '') return [];
      $j = json_decode($txt, true);
      return is_array($j) ? $j : [];
    } catch (\Throwable $e) { return []; }
  }
}
if (!function_exists('_sec_rows')) {
  function _sec_rows(array $j): array {
    if (isset($j['items']) && is_array($j['items'])) return $j['items'];
    if (isset($j['rows'])  && is_array($j['rows']))  return $j['rows'];
    return [];
  }
}
if (!function_exists('_sec_norm')) {
  function _sec_norm(?string $s): string {
    $s = (string)$s;
    if ($s === '') return '';
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'asKV', 'UTF-8');
    $s = preg_replace('/\s+/u', '', $s) ?? '';
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    return trim($s);
  }
}
if (!function_exists('_sec_digits')) {
  function _sec_digits(string $s): string {
    return preg_replace('/[^\d]/u', '', $s) ?? '';
  }
}
if (!function_exists('_sec_date')) {
  function _sec_date($v): string {
    if (is_string($v)) {
      $s = strtr(trim($v), ['/' => '-', '.' => '-']);
      $ts = strtotime($s);
      if ($ts !== false) return date('Y-m-d', $ts);
      if (is_numeric($v)) $v = (float)$v;
    }
    if (is_int($v) || is_float($v)) {
      // Google Sheets serial (1899-12-30起点)
      $unix = (int)round(((float)$v - 25569) * 86400);
      if ($unix <= 0) return '';
      return gmdate('Y-m-d', $unix);
    }
    return '';
  }
}
// 俳優ごとの systems セット（配列/カンマ区切り両対応）を用意
if (!function_exists('_norm_for_sys')) {
  function _norm_for_sys(?string $s): string {
    $s = (string)$s;
    if ($s === '') return '';
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'asKV', 'UTF-8');
    $s = preg_replace('/\s+/u', '', $s) ?? '';
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    return trim($s);
  }
}
if (!function_exists('_split_norm_set')) {
  function _split_norm_set($val): array {
    $out = [];
    if (is_array($val)) {
      $arr = $val;
    } else {
      $s = str_replace(['、','，'], ',', (string)$val);
      $arr = array_map('trim', explode(',', $s));
    }
    foreach ($arr as $it) {
      $n = _norm_for_sys((string)$it);
      if ($n !== '') $out[$n] = true;
    }
    return $out;
  }
}

// -------------------------- builder --------------------------
if (!function_exists('build_sales_seiyaku_count_by_day')) {
  /**
   * @param string $DATA_DIR  e.g. __DIR__.'/../data'
   * @param string $CACHE_DIR e.g. __DIR__.'/../cache'
   * @return array [sid => [aid => ['YYYY-MM' => [day => count]]]]
   */
  function build_sales_seiyaku_count_by_day(string $DATA_DIR, string $CACHE_DIR): array {
    try {
      $out = [];

      // sales.json: name -> sid
      $sales = _sec_json(rtrim($DATA_DIR, '/').'/sales.json');
      $salesItems = isset($sales['items']) && is_array($sales['items']) ? $sales['items'] : [];
      $salesName2Id = [];
      foreach ($salesItems as $p) {
        $id = (string)($p['id'] ?? '');
        $nm = trim((string)($p['name'] ?? ''));
        if ($id !== '' && $nm !== '') $salesName2Id[$nm] = $id;
      }

      // actors.json: actor name -> aid, and aid -> type(norm)
      $actors = _sec_json(rtrim($DATA_DIR, '/').'/actors.json');
      $actorItems  = isset($actors['items']) && is_array($actors['items']) ? $actors['items'] : [];
      $actorName2Id = [];
      $actorId2Type = [];
      $actorId2Systems = []; // システム名セット（正規化済み）

      foreach ($actorItems as $a) {
        $id = (string)($a['id'] ?? '');
        $nm = trim((string)($a['name'] ?? ''));
        if ($id !== '' && $nm !== '') $actorName2Id[$nm] = $id;

        $tpNorm = isset($a['type']) ? _asc_norm((string)$a['type']) : '';
        if ($id !== '' && $tpNorm !== '') $actorId2Type[$id] = $tpNorm;

        if (isset($a['systems'])) {
          $actorId2Systems[$id] = _split_norm_set($a['systems']);
        }
      }
      foreach ($actorItems as $a) {
        $id = (string)($a['id'] ?? '');
        $nm = trim((string)($a['name'] ?? ''));
        if ($id !== '' && $nm !== '') $actorName2Id[$nm] = $id;
        $tp = isset($a['type']) ? _sec_norm((string)$a['type']) : '';
        if ($id !== '' && $tp !== '') $actorId2Type[$id] = $tp;
      }

      if (!$salesName2Id || !$actorName2Id) return $out;

      // raw_rows.json
      $raw  = _sec_json(rtrim($CACHE_DIR, '/').'/raw_rows.json');
      $rows = _sec_rows($raw);
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

        // 入口 (=type) 厳密一致（俳優側type・行側入口どちらも必須）
        $rowType   = _sec_norm((string)($r['入口'] ?? ($r['type'] ?? '')));
        $actorType = $actorId2Type[$aid] ?? '';

        // セールス日
        $date = _sec_date($r['セールス日'] ?? ($r['date'] ?? ''));
        if ($date === '') continue;
        $ts = strtotime($date); if ($ts === false) continue;
        $ym = date('Y-m', $ts);
        $d  = (int)date('j', $ts);
        // ★ システム名フィルタ（俳優側に systems が設定されている場合のみ適用）
        if (isset($actorId2Systems[$aid]) && $actorId2Systems[$aid]) {
        $rowSystem = _norm_for_sys((string)($r['システム名'] ?? ($r['system'] ?? ($r['システム'] ?? ''))));
        if ($rowSystem === '' || !isset($actorId2Systems[$aid][$rowSystem])) continue;
        }
        // 状態 = 入金済み（厳密）
        $state = trim((string)($r['状態'] ?? ($r['status'] ?? '')));
        if ($state !== '入金済み') continue;

        // 支払い何回目（"1" or ""）
        $nraw = (string)($r['支払い何回目'] ?? ($r['支払何回目'] ?? ''));
        $ndig = _sec_digits($nraw);
        if (!($ndig === '1' || $ndig === '')) continue;

        // カウント（俳優あり階層のみ）
        $out[$sid][$aid][$ym][$d] = (int)($out[$sid][$aid][$ym][$d] ?? 0) + 1;
      }

      return $out;
    } catch (\Throwable $e) {
      return [];
    }
  }
}
