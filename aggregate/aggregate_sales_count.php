<?php
// aggregate/aggregate_sales_count.php
declare(strict_types=1);

/**
 * セールス件数（日別）集計（from cache/raw_rows.json）
 *
 * ■ 抽出条件（ご指定どおり）
 *  - （新仕様）actor割当：
 *      - actor=みおパパ：流入経路に「みおパパ」を含む行のみ（動画担当は見ない）
 *      - actor=しらほしなつみ：動画担当=しらほしなつみ かつ 流入経路に「みおパパ」を含まない
 *      - その他：動画担当=actor
 *  - セールス日 = 日付（行の `セールス日` 優先、なければ `date`）
 *  - 入口 = type（data/actors.json と行の `入口`/`type` が一致）
 *  - 支払い何回目 = "1" または ""（空）
 *  - セールス担当 = name（data/sales.json）
 *  - 状態 ∈ {"失注","入金済み","入金待ち","一旦保留"}
 *  - （既存）systems 指定がある actor のみ system 一致必須
 *
 * ■ 返り値の形
 *   [sales_id => [actor_id => ['YYYY-MM' => [day(int|str) => count(int)]]]]
 */

// -------------------------- small helpers --------------------------
if (!function_exists('_asc_json')) {
  function _asc_json(string $path): array {
    try {
      if (!is_file($path)) return [];
      $txt = @file_get_contents($path);
      if ($txt === false || $txt === '') return [];
      $j = json_decode($txt, true);
      return is_array($j) ? $j : [];
    } catch (\Throwable $e) { return []; }
  }
}

if (!function_exists('_asc_rows')) {
  function _asc_rows(array $j): array {
    if (isset($j['items']) && is_array($j['items'])) return $j['items'];
    if (isset($j['rows'])  && is_array($j['rows']))  return $j['rows'];
    return [];
  }
}

if (!function_exists('_asc_norm')) {
  /** 全角→半角、空白除去、lower 化 */
  function _asc_norm(?string $s): string {
    $s = (string)$s;
    if ($s === '') return '';
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'asKV', 'UTF-8');
    $s = preg_replace('/\s+/u', '', $s) ?? '';
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    return trim($s);
  }
}

if (!function_exists('_asc_digits')) {
  /** 数字だけ抽出（全角含む） */
  function _asc_digits(string $s): string {
    return preg_replace('/[^\d]/u', '', $s) ?? '';
  }
}

if (!function_exists('_asc_date')) {
  /** 文字列日付 or Google Sheets 序数日 → 'Y-m-d' */
  function _asc_date($v): string {
    if (is_string($v)) {
      $s = strtr(trim($v), ['/' => '-', '.' => '-']);
      $ts = strtotime($s);
      if ($ts !== false) return date('Y-m-d', $ts);
      if (is_numeric($v)) $v = (float)$v;
    }
    if (is_int($v) || is_float($v)) {
      // Google Sheets 日付シリアル（1899-12-30 起点）
      $unix = (int)round(((float)$v - 25569) * 86400);
      if ($unix <= 0) return '';
      return gmdate('Y-m-d', $unix);
    }
    return '';
  }
}

// systems 正規化
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

// -------------------------- main builder --------------------------
if (!function_exists('build_sales_count_by_day')) {
  /**
   * @param string $DATA_DIR  パス例: __DIR__.'/../data'
   * @param string $CACHE_DIR パス例: __DIR__.'/../cache'
   * @return array [sid => [aid => ['YYYY-MM' => [day => count]]]]
   */
  function build_sales_count_by_day(string $DATA_DIR, string $CACHE_DIR): array {

    try {
      $out = [];

      // --- sales.json: name -> id ---
      $sales = _asc_json(rtrim($DATA_DIR, '/').'/sales.json');
      $salesItems = isset($sales['items']) && is_array($sales['items']) ? $sales['items'] : [];
      $salesName2Id = [];
      foreach ($salesItems as $p) {
        $id = (string)($p['id'] ?? '');
        $nm = trim((string)($p['name'] ?? ''));
        if ($id !== '' && $nm !== '') $salesName2Id[$nm] = $id;
      }

      // --- actors.json: actor name -> id, and id -> type/systems ---
      $actors = _asc_json(rtrim($DATA_DIR, '/').'/actors.json');
      $actorItems = isset($actors['items']) && is_array($actors['items']) ? $actors['items'] : [];

      $actorName2Id = [];
      $actorId2Type = [];      // 正規化済み type
      $actorId2Systems = [];   // システム名セット（正規化済み）

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

      if (!$salesName2Id || !$actorName2Id) return $out;

      // 新仕様で必要な actor id
      $mioPapaId = $actorName2Id['みおパパ'] ?? '';
      $shirahoshiId = $actorName2Id['しらほしなつみ'] ?? '';

      // --- raw_rows.json ---
      $raw  = _asc_json(rtrim($CACHE_DIR, '/').'/raw_rows.json');
      $rows = _asc_rows($raw);
      if (!$rows) return $out;

      $allowedStates = ['失注','入金済み','入金待ち','一旦保留'];
      // $allowedStates = ['失注','入金済み','入金待ち','一旦保留','再調整'];

      foreach ($rows as $r) {

        // セールス担当（name → sid）
        $salesName = trim((string)($r['セールス担当'] ?? ($r['sales'] ?? '')));
        if ($salesName === '' || !isset($salesName2Id[$salesName])) continue;
        $sid = $salesName2Id[$salesName];

        // 状態
        $state = trim((string)($r['状態'] ?? ($r['status'] ?? '')));
        if (!in_array($state, $allowedStates, true)) continue;

        // 支払い何回目（"1" or "" のみ）
        $nraw = (string)($r['支払い何回目'] ?? ($r['支払何回目'] ?? ''));
        $ndig = _asc_digits($nraw);
        if (!($ndig === '1' || $ndig === '')) continue;

        // セールス日
        $date = _asc_date($r['セールス日'] ?? ($r['date'] ?? ''));
        if ($date === '') continue;
        $ts = strtotime($date);
        if ($ts === false) continue;
        $ym = date('Y-m', $ts);
        $d  = (int)date('j', $ts);

        // 判定用
        $videoActor = trim((string)($r['動画担当'] ?? ($r['actor'] ?? '')));
        $inflow     = (string)($r['流入経路'] ?? '');

        // 行の system（actors側 systems 指定がある場合に使う）
        $rowSystemNorm = _norm_for_sys((string)($r['システム名'] ?? ($r['system'] ?? ($r['システム'] ?? ''))));

        // 行の type
        $rowType = _asc_norm((string)($r['入口'] ?? ($r['type'] ?? '')));

        // ---- (A) みおパパ割当（流入経路で判定） ----
        if ($mioPapaId !== '' && str_contains($inflow, 'みおパパ')) {

          $aid = $mioPapaId;

          // 入口 (= type) 厳密一致（両方必須）
          $actorType = $actorId2Type[$aid] ?? '';
          if ($rowType === '' || $actorType === '' || $rowType !== $actorType) {
            // みおパパ扱いでも type が合わないならカウントしない
            // ただし次の分岐（他actor）にも回さない（=同一行の二重計上防止）
            continue;
          }

          // systems フィルタ（actorに systems が設定されている場合のみ）
          if (isset($actorId2Systems[$aid]) && $actorId2Systems[$aid]) {
            if ($rowSystemNorm === '' || !isset($actorId2Systems[$aid][$rowSystemNorm])) continue;
          }

          $out[$sid][$aid][$ym][$d] = (int)($out[$sid][$aid][$ym][$d] ?? 0) + 1;
          continue; // みおパパに割り当てた行は他 actor に回さない
        }

        // ---- (B) しらほしなつみ割当（動画担当一致 + 流入経路にみおパパ無し） ----
        if ($shirahoshiId !== '' && $videoActor === 'しらほしなつみ' && !str_contains($inflow, 'みおパパ')) {

          $aid = $shirahoshiId;

          $actorType = $actorId2Type[$aid] ?? '';
          if ($rowType === '' || $actorType === '' || $rowType !== $actorType) continue;

          if (isset($actorId2Systems[$aid]) && $actorId2Systems[$aid]) {
            if ($rowSystemNorm === '' || !isset($actorId2Systems[$aid][$rowSystemNorm])) continue;
          }

          $out[$sid][$aid][$ym][$d] = (int)($out[$sid][$aid][$ym][$d] ?? 0) + 1;
          continue;
        }

        // ---- (C) その他 actor（動画担当で通常割当） ----
        if ($videoActor === '' || !isset($actorName2Id[$videoActor])) continue;
        $aid = $actorName2Id[$videoActor];

        $actorType = $actorId2Type[$aid] ?? '';
        if ($rowType === '' || $actorType === '' || $rowType !== $actorType) continue;

        if (isset($actorId2Systems[$aid]) && $actorId2Systems[$aid]) {
          if ($rowSystemNorm === '' || !isset($actorId2Systems[$aid][$rowSystemNorm])) continue;
        }

        $out[$sid][$aid][$ym][$d] = (int)($out[$sid][$aid][$ym][$d] ?? 0) + 1;
      }

      return $out;
    } catch (\Throwable $e) {
      return [];
    }
  }
}
