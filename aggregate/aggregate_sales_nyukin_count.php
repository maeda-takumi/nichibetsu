<?php
// aggregate/aggregate_sales_nyukin_count.php
declare(strict_types=1);

/**
 * 入金件数（日別）集計（from cache/raw_rows.json）
 *
 * ★ みおパパ分岐対応版
 *
 * 返り値:
 *   [sid => [aid => ['YYYY-MM' => [day(int) => count(int)]]]]
 */

// ---------- helpers ----------
if (!function_exists('_ny_json')) {
  function _ny_json(string $path): array {
    try {
      if (!is_file($path)) return [];
      $txt = @file_get_contents($path);
      if ($txt === false || $txt === '') return [];
      $j = json_decode($txt, true);
      return is_array($j) ? $j : [];
    } catch (\Throwable $e) {
      return [];
    }
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
  function _ny_norm(string $s): string {
    $s = (string)$s;
    if ($s === '') return '';
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'asKV', 'UTF-8');
    $s = preg_replace('/\s+/u', '', $s) ?? '';
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
  }
}

if (!function_exists('_ny_norm_split')) {
  function _ny_norm_split($v): array {
    $out = []; // ★ 修正：{} → []
    $arr = is_array($v) ? $v : explode(',', str_replace(['、','，'], ',', (string)$v));
    foreach ($arr as $x) {
      $n = _ny_norm(trim((string)$x));
      if ($n !== '') $out[$n] = true;
    }
    return $out;
  }
}

if (!function_exists('_ny_digits')) {
  function _ny_digits(string $s): string {
    return preg_replace('/[^\d]/u', '', (string)$s) ?? '';
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
    if (is_int($v) || is_float($v) || is_numeric($v)) {
      $unix = (int)round(((float)$v - 25569) * 86400); // 1899-12-30 起点
      if ($unix <= 0) return '';
      return gmdate('Y-m-d', $unix);
    }
    return '';
  }
}

if (!function_exists('_ny_contains_miopapa')) {
  function _ny_contains_miopapa($v): bool {
    return mb_strpos((string)$v, 'みおパパ') !== false;
  }
}

// ---- miopapa actor 解決 ----
if (!function_exists('_ny_find_miopapa_actor_id')) {
  function _ny_find_miopapa_actor_id(array $actors): string {
    foreach ($actors as $a) {
      if (in_array('みおパパ', (array)($a['aliases'] ?? []), true)) {
        return (string)($a['id'] ?? '');
      }
    }
    foreach ($actors as $a) {
      if (($a['name'] ?? '') === 'みおパパ') return (string)($a['id'] ?? '');
    }
    return '';
  }
}

if (!function_exists('_ny_resolve_actor_id')) {
  function _ny_resolve_actor_id(
    array $row,
    array $name2ids,
    array $alias2id,
    string $miopapaAid
  ): string {

    // ① 流入経路に「みおパパ」が入っているなら、必ず みおパパ actor_id
    if (_ny_contains_miopapa($row['流入経路'] ?? '')) {
      return $miopapaAid ?: '';
    }

    // ② それ以外は動画担当から解決（同名複数の場合は「みおパパ以外」を優先）
    $rawName = trim((string)($row['動画担当'] ?? ($row['actor'] ?? '')));
    if ($rawName === '') return '';

    if (isset($name2ids[$rawName]) && is_array($name2ids[$rawName]) && $name2ids[$rawName]) {
      foreach ($name2ids[$rawName] as $id) {
        if ($miopapaAid !== '' && $id === $miopapaAid) continue;
        return $id;
      }
      return (string)$name2ids[$rawName][0];
    }

    return $alias2id[$rawName] ?? '';
  }
}

// ---------- builder ----------
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

      // actors.json: name -> [ids], alias -> id, id -> type(norm), id -> systems(norm set)
      $actors = _ny_json(rtrim($DATA_DIR, '/').'/actors.json');
      $actorItems = isset($actors['items']) && is_array($actors['items']) ? $actors['items'] : [];
      $name2ids = [];
      $alias2id = [];
      $actorId2Type = [];
      $actorId2Systems = [];

      foreach ($actorItems as $a) {
        $id = (string)($a['id'] ?? '');
        $nm = trim((string)($a['name'] ?? ''));
        if ($id !== '' && $nm !== '') $name2ids[$nm][] = $id;

        foreach ((array)($a['aliases'] ?? []) as $al) {
          $al = trim((string)$al);
          if ($al !== '' && $id !== '') $alias2id[$al] = $id;
        }

        $tp = isset($a['type']) ? _ny_norm((string)$a['type']) : '';
        if ($id !== '' && $tp !== '') $actorId2Type[$id] = $tp;

        $sysSet = _ny_norm_split($a['systems'] ?? []);
        if ($id !== '' && $sysSet) $actorId2Systems[$id] = $sysSet;
      }

      if (!$salesName2Id || !$name2ids) return $out;

      $miopapaAid = _ny_find_miopapa_actor_id($actorItems);

      // raw_rows.json
      $raw  = _ny_json(rtrim($CACHE_DIR, '/').'/raw_rows.json');
      $rows = _ny_rows($raw);
      if (!$rows) return $out;

      $targetSystems = [
        'ChatGPTフロント',
        'Instagramフロント',
        '動画編集フロント',
        'TikTokフロント',
        '副業フロント',
        '副業ウェブフリフロント',
      ];

      foreach ($rows as $r) {
        // セールス担当
        $salesName = trim((string)($r['セールス担当'] ?? ($r['sales'] ?? '')));
        if ($salesName === '' || !isset($salesName2Id[$salesName])) continue;
        $sid = $salesName2Id[$salesName];

        // actor_id 解決（みおパパ分岐込み）
        $aid = _ny_resolve_actor_id($r, $name2ids, $alias2id, $miopapaAid);
        if ($aid === '') continue;

        // 入口 (=type) 厳密一致
        $rowType   = _ny_norm((string)($r['入口'] ?? ($r['type'] ?? '')));
        $actorType = $actorId2Type[$aid] ?? '';
        if ($rowType === '' || $actorType === '' || $rowType !== $actorType) continue;

        // 対象のシステム名に一致するものだけ処理
        $systemName = trim((string)($r['システム名'] ?? ''));
        if ($systemName === '' || !in_array($systemName, $targetSystems, true)) continue;

        // 状態 = 入金済み
        $state = trim((string)($r['状態'] ?? ($r['status'] ?? '')));
        if ($state !== '入金済み') continue;

        // 支払い何回目（"1" or ""）
        $nraw = (string)($r['支払い何回目'] ?? ($r['支払何回目'] ?? ''));
        $ndig = _ny_digits($nraw);
        if (!($ndig === '1' || $ndig === '')) continue;

        // 入金額 > 0（数値化の簡易）
        $amtRaw = $r['入金額'] ?? ($r['nyukin_amount'] ?? ($r['入金'] ?? 0));
        $amt = is_numeric($amtRaw) ? (int)$amtRaw : (int)preg_replace('/[^\d\-]/u', '', (string)$amtRaw);
        if ($amt <= 0) continue;

        // 入金日
        $date = _ny_date($r['入金日'] ?? ($r['nyukin_date'] ?? ($r['入金_日'] ?? ($r['date'] ?? ''))));
        if ($date === '') continue;
        $ts = strtotime($date);
        if ($ts === false) continue;

        $ym = date('Y-m', $ts);
        $d  = (int)date('j', $ts);

        // カウント
        $out[$sid][$aid][$ym][$d] = (int)($out[$sid][$aid][$ym][$d] ?? 0) + 1;
      }

      return $out;
    } catch (\Throwable $e) {
      return [];
    }
  }
}
