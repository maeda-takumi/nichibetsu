<?php
// aggregate/aggregate_nyukin_amount.php
declare(strict_types=1);

/* ===== ローカルUtils（prefix: _ana_*） ===== */
if (!function_exists('_ana_alias_map_from_actors_items')) {
  function _ana_alias_map_from_actors_items(array $items): array {
    $map = [];
    foreach ($items as $a) {
      $main = trim((string)($a['name'] ?? ''));
      if ($main !== '') $map[$main] = $main;
      foreach ((array)($a['aliases'] ?? []) as $al) {
        $al = trim((string)$al);
        if ($al !== '') $map[$al] = $main ?: $al;
      }
    }
    return $map;
  }
}
if (!function_exists('_ana_pick_actor_name')) {
  function _ana_pick_actor_name(array $row): string {
    foreach (['動画担当','user','actor','担当','担当者','セールス担当','sales_user','sales','name'] as $k) {
      if (array_key_exists($k, $row)) {
        $v = trim((string)$row[$k]);
        if ($v !== '') return $v;
      }
    }
    return '';
  }
}
if (!function_exists('_ana_infer_year_month')) {
  function _ana_infer_year_month(array $row): array {
    if (!empty($row['セールス年月']) && preg_match('/(\d{4})\D+(\d{1,2})/', (string)$row['セールス年月'], $m)) {
      return [(int)$m[1], (int)$m[2]];
    }
    if (!empty($row['データ4']) && preg_match('/^(\d{4})(\d{2})$/', (string)$row['データ4'], $m)) {
      return [(int)$m[1], (int)$m[2]];
    }
    return [(int)date('Y'), (int)date('n')];
  }
}
if (!function_exists('_ana_normalize_mdy_with_infer')) {
  function _ana_normalize_mdy_with_infer(string $md, array $row): string {
    $md = trim($md);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $md)) return $md;
    if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/', $md, $m)) {
      $mm = (int)$m[1]; $dd = (int)$m[2]; $yy = null;
      if (!empty($m[3])) { $y = (int)$m[3]; $yy = ($y < 100) ? (2000 + $y) : $y; }
      if ($yy === null) { [$iy] = _ana_infer_year_month($row); $yy = $iy; }
      if (checkdate($mm, $dd, $yy)) return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
    }
    return '';
  }
}
if (!function_exists('_ana_is_valid_day')) {
  function _ana_is_valid_day(string $ymd): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
    $ts = strtotime($ymd);
    if ($ts === false) return false;
    if ($ts < strtotime('2000-01-01')) return false;
    if ($ts > time()) return false;
    return true;
  }
}
if (!function_exists('_ana_pick_nyukin_date')) {
  function _ana_pick_nyukin_date(array $row): string {
    if (!empty($row['入金日'])) {
      $ymd = _ana_normalize_mdy_with_infer((string)$row['入金日'], $row);
      if ($ymd !== '') return $ymd;
    }
    foreach (['date','dt','created_at','datetime','time'] as $k) {
      if (!empty($row[$k])) {
        $ts = strtotime((string)$row[$k]);
        if ($ts !== false) return date('Y-m-d', $ts);
      }
    }
    return '';
  }
}
if (!function_exists('_ana_build_actor_profiles')) {
  function _ana_build_actor_profiles(array $actorsItems): array {
    $profiles = [];
    foreach ($actorsItems as $a) {
      $name = trim((string)($a['name'] ?? ''));
      if ($name === '') continue;
      $type = trim((string)($a['type'] ?? '')); // 入口
      $profiles[$name] = ['type'=>$type];
    }
    return $profiles;
  }
}
if (!function_exists('_ana_parse_money')) {
  function _ana_parse_money($v): float {
    if (is_numeric($v)) return (float)$v;
    $s = preg_replace('/[^\d\.\-]/', '', (string)$v);
    return (float)$s;
  }
}

/* ===== 本体：入金額（日別合算） =====
 * 条件:
 *  - 担当 = actor（エイリアス正規化）
 *  - 日付 = 入金日
 *  - 入口(type) が actors.json の type と一致（空なら不問）
 *  - 入金額 > 0 のみ合算
 */
function build_nyukin_amount_by_day(string $DATA_DIR, string $CACHE_DIR): array {
  $res = [];

  $rawPath    = rtrim($CACHE_DIR,'/').'/raw_rows.json';
  $actorsPath = rtrim($DATA_DIR, '/').'/actors.json';
  if (!is_file($rawPath) || !is_file($actorsPath)) return $res;

  $raw    = json_decode((string)file_get_contents($rawPath), true);
  $actors = json_decode((string)file_get_contents($actorsPath), true);

  $rows       = (isset($raw['rows']) && is_array($raw['rows'])) ? $raw['rows']
              : ((isset($raw['items']) && is_array($raw['items'])) ? $raw['items'] : []);
  $actorsList = (isset($actors['items']) && is_array($actors['items'])) ? $actors['items'] : [];

  $aliasMap = _ana_alias_map_from_actors_items($actorsList);
  $profiles = _ana_build_actor_profiles($actorsList);

  foreach ($rows as $row) {
    $rawName = _ana_pick_actor_name($row);
    if ($rawName === '') continue;
    $name = $aliasMap[$rawName] ?? $rawName;

    // 入口(type)一致チェック
    $reqType = (string)($profiles[$name]['type'] ?? '');
    $rowType = trim((string)($row['入口'] ?? ''));
    if ($reqType !== '' && $rowType !== $reqType) continue;

    // 入金額
    $amount = _ana_parse_money($row['入金額'] ?? 0);
    if ($amount <= 0) continue;

    // 日付＝入金日
    $ymd = _ana_pick_nyukin_date($row);
    if ($ymd === '' || !_ana_is_valid_day($ymd)) continue;
    
    // 対象のシステム名に一致するものだけ処理
    $targetSystems = [
      'ChatGPTフロント',
      'Instagramフロント',
      '動画編集フロント',
      'TikTokフロント',
      '副業フロント',
      '副業ウェブフリフロント',
    ];
    $systemName = trim((string)($row['システム名'] ?? ''));
    if ($systemName === '' || !in_array($systemName, $targetSystems, true)) continue;

    // 加算
    if (!isset($res[$ymd])) $res[$ymd] = [];
    if (!isset($res[$ymd][$name])) $res[$ymd][$name] = 0;
    $res[$ymd][$name] += $amount;
    if (!isset($res[$ymd]['_total'])) $res[$ymd]['_total'] = 0;
    $res[$ymd]['_total'] += $amount;
  }

  ksort($res);
  return $res;
}

/* ===== 後方互換：requireだけで $nyukinAmountByDay を供給（任意） =====
   無効化: define('AGGREGATE_NYUKIN_AMOUNT_NO_AUTO', true); */
if (!defined('AGGREGATE_NYUKIN_AMOUNT_NO_AUTO')) {
  if (isset($DATA_DIR) && isset($CACHE_DIR)) {
    try {
      /** @var array $nyukinAmountByDay */
      $nyukinAmountByDay = build_nyukin_amount_by_day((string)$DATA_DIR, (string)$CACHE_DIR);
    } catch (\Throwable $e) {
      if (!isset($nyukinAmountByDay)) $nyukinAmountByDay = [];
    }
  }
}
