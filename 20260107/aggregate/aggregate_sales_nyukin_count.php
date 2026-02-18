<?php
// aggregate/aggregate_sales_nyukin_count.php
declare(strict_types=1);

/**
 * 入金件数（日別）集計（from cache/raw_rows.json）
 *
 * 抽出条件
 *  - 動画担当 = name（data/actors.json）
 *  - 入金日 = 日付（行の `入金日` / `nyukin_date` / `入金_日` / `date`）
 *  - 入口 = type（actors.json の俳優 type と行の `入口`/`type` が一致）
 *  - 支払い何回目 = "1" または ""（空）
 *  - セールス担当 = name（data/sales.json）
 *  - 状態 = "入金済み"
 *  - 入金額 > 0（`入金額` / `nyukin_amount` / `入金`）
 *  - システム名 = systems（actors.json）。俳優側 systems がある場合のみ OR一致で絞る
 *
 * 返り値（1系統のみ）
 *   [sales_id => [actor_id => ['YYYY-MM' => [day(int|str) => count(int)]]]]
 */

// ---- helpers ----
if (!function_exists('_ny_json')) {
  function _ny_json(string $path): array {
    try {
      if (!is_file($path)) return [];
      $txt = @file_get_contents($path);
      if ($txt === false || $txt === '') return [];
      $j = json_decode($txt, true);
      return is_array($j) ? $j : [];
    } catch (\Throwable $e) { return []; }
  }
}
if (!function_exists('_ny_rows')) {
  function _ny_rows(array $j): array {
    if (isset($j['items']) && is_array($j['items'])) return $j['items'];
    if (isset($j['rows'])  && is_array($j['rows']))  return $j['rows'];
    return [];
  }
}
if (!function_exists('_ny_norm')) {
  function _ny_norm(?string $s): string {
    $s = (string)$s;
    if ($s === '') return '';
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'asKV', 'UTF-8');
    $s = preg_replace('/\s+/u', '', $s) ?? '';
    $s = function_exists('mb_strtolower') ? mb_strtolower($s,'UTF-8') : strtolower($s);
    return trim($s);
  }
}
if (!function_exists('_ny_norm_split')) {
  function _ny_norm_split($val): array {
    $out = [];
    if (is_array($val)) $arr = $val;
    else {
      $s = str_replace(['、','，'], ',', (string)$val);
      $arr = array_map('trim', explode(',', $s));
    }
    foreach ($arr as $it) {
      $n = _ny_norm((string)$it);
      if ($n !== '') $out[$n] = true;
    }
    return $out;
  }
}
if (!function_exists('_ny_digits')) {
  function _ny_digits(string $s): string {
    return preg_replace('/[^\d]/u', '', $s) ?? '';
  }
}
if (!function_exists('_ny_int')) {
  function _ny_int($v): int {
    if (is_numeric($v)) return (int)$v;
    $s = preg_replace('/[^\d\-]/u', '', (string)$v);
    return ($s==='' || $s==='-') ? 0 : (int)$s;
  }
}
if (!function_exists('_ny_date')) {
  /** 文字列 or Google Sheets serial を 'Y-m-d' に */
  function _ny_date($v): string {
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

// ---- builder ----
if (!function_exists('build_sales_nyukin_count_by_day')) {
  /**
   * @param string $DATA_DIR
   * @param string $CACHE_DIR
   * @return array [sid => [aid => ['YYYY-MM' => [day => count]]]]
   */
  function build_sales_nyukin_count_by_day(string $DATA_DIR, string $CACHE_DIR): array {
    try {
      $out = [];

      // sales.json: name -> sid
      $sales = _ny_json(rtrim($DATA_DIR, '/').'/sales.json');
      $salesItems = isset($sales['items']) && is_array($sales['items']) ? $sales['items'] : [];
      $salesName2Id = [];
      foreach ($salesItems as $p) {
        $id = (string)($p['id'] ?? '');
        $nm = trim((string)($p['name'] ?? ''));
        if ($id !== '' && $nm !== '') $salesName2Id[$nm] = $id;
      }

      // actors.json: actor name -> aid, id -> type(norm), id -> systems(norm set)
      $actors = _ny_json(rtrim($DATA_DIR, '/').'/actors.json');
      $actorItems = isset($actors['items']) && is_array($actors['items']) ? $actors['items'] : [];
      $actorName2Id = [];
      $actorId2Type = [];
      $actorId2Systems = [];
      foreach ($actorItems as $a) {
        $id = (string)($a['id'] ?? '');
        $nm = trim((string)($a['name'] ?? ''));
        if ($id !== '' && $nm !== '') $actorName2Id[$nm] = $id;
        $tp = isset($a['type']) ? _ny_norm((string)$a['type']) : '';
        if ($id !== '' && $tp!=='') $actorId2Type[$id] = $tp;
        $sysSet = _ny_norm_split($a['systems'] ?? []);
        if ($id !== '' && $sysSet) $actorId2Systems[$id] = $sysSet;
      }

      if (!$salesName2Id || !$actorName2Id) return $out;

      // raw_rows.json
      $raw  = _ny_json(rtrim($CACHE_DIR, '/').'/raw_rows.json');
      $rows = _ny_rows($raw);
      if (!$rows) return $out;

      foreach ($rows as $r) {
        // セールス担当
        $salesName = trim((string)($r['セールス担当'] ?? ($r['sales'] ?? '')));
        if ($salesName === '' || !isset($salesName2Id[$salesName])) continue;
        $sid = $salesName2Id[$salesName];

        // 動画担当（name）
        $actorName = trim((string)($r['動画担当'] ?? ($r['actor'] ?? '')));
        if ($actorName === '' || !isset($actorName2Id[$actorName])) continue;
        $aid = $actorName2Id[$actorName];

        // 入口 (=type) 厳密一致（俳優側type・行側入口の両方必須）
        $rowType   = _ny_norm((string)($r['入口'] ?? ($r['type'] ?? '')));
        $actorType = $actorId2Type[$aid] ?? '';
        if ($rowType === '' || $actorType === '' || $rowType !== $actorType) continue;

        // システム名 (=systems) — 俳優側に設定がある場合のみ OR一致で適用
        if (isset($actorId2Systems[$aid]) && $actorId2Systems[$aid]) {
          $rowSystem = _ny_norm((string)($r['システム名'] ?? ($r['system'] ?? ($r['システム'] ?? ''))));
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
        $date = _ny_date($r['入金日'] ?? ($r['nyukin_date'] ?? ($r['入金_日'] ?? ($r['date'] ?? ''))));
        if ($date === '') continue;
        $ts = strtotime($date); if ($ts === false) continue;
        $ym = date('Y-m', $ts);
        $d  = (int)date('j', $ts);

        // 状態 = 入金済み
        $state = trim((string)($r['状態'] ?? ($r['status'] ?? '')));
        if ($state !== '入金済み') continue;

        // 支払い何回目（"1" or ""）
        $nraw = (string)($r['支払い何回目'] ?? ($r['支払何回目'] ?? ''));
        $ndig = _ny_digits($nraw);
        if (!($ndig === '1' || $ndig === '')) continue;

        // 入金額 > 0
        $amt = _ny_int($r['入金額'] ?? ($r['nyukin_amount'] ?? ($r['入金'] ?? 0)));
        if ($amt <= 0) continue;

        // カウント（俳優あり階層のみ）
        $out[$sid][$aid][$ym][$d] = (int)($out[$sid][$aid][$ym][$d] ?? 0) + 1;
      }

      return $out;
    } catch (\Throwable $e) {
      return [];
    }
  }
}
