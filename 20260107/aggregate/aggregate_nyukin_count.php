<?php
// aggregate/aggregate_nyukin_count.php
declare(strict_types=1);

/* ===== ローカルUtils（prefix: _anc_*） ===== */
if (!function_exists('_anc_alias_map_from_actors_items')) {
  function _anc_alias_map_from_actors_items(array $items): array {
    $map = [];
    foreach ($items as $a) {
      $main = isset($a['name']) ? trim((string)$a['name']) : '';
      if ($main !== '') $map[$main] = $main;
      if (!empty($a['aliases']) && is_array($a['aliases'])) {
        foreach ($a['aliases'] as $al) {
          $al = trim((string)$al);
          if ($al !== '') $map[$al] = $main ?: $al;
        }
      }
    }
    return $map;
  }
}
if (!function_exists('_anc_pick_actor_name')) {
  function _anc_pick_actor_name(array $row): string {
    $candidates = ['動画担当','user','actor','担当','担当者','セールス担当','sales_user','sales','name'];
    foreach ($candidates as $k) {
      if (array_key_exists($k, $row)) {
        $v = trim((string)$row[$k]);
        if ($v !== '') return $v;
      }
    }
    return '';
  }
}
if (!function_exists('_anc_infer_year_month')) {
  function _anc_infer_year_month(array $row): array {
    if (!empty($row['セールス年月']) && preg_match('/(\d{4})\D+(\d{1,2})/', (string)$row['セールス年月'], $m)) {
      return [(int)$m[1], (int)$m[2]];
    }
    if (!empty($row['データ4']) && preg_match('/^(\d{4})(\d{2})$/', (string)$row['データ4'], $m)) {
      return [(int)$m[1], (int)$m[2]];
    }
    return [(int)date('Y'), (int)date('n')];
  }
}
if (!function_exists('_anc_normalize_mdy_with_infer')) {
  function _anc_normalize_mdy_with_infer(string $md, array $row): string {
    $md = trim($md);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $md)) return $md;
    if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/', $md, $m)) {
      $mm = (int)$m[1]; $dd = (int)$m[2]; $yy = null;
      if (!empty($m[3])) { $y = (int)$m[3]; $yy = ($y < 100) ? (2000 + $y) : $y; }
      if ($yy === null) { [$iy] = _anc_infer_year_month($row); $yy = $iy; }
      if (checkdate($mm, $dd, $yy)) return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
    }
    return '';
  }
}
if (!function_exists('_anc_is_valid_day')) {
  function _anc_is_valid_day(string $ymd): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
    $ts = strtotime($ymd);
    if ($ts === false) return false;
    if ($ts < strtotime('2000-01-01')) return false;
    if ($ts > time()) return false;
    return true;
  }
}
function _anc_pick_nyukin_date(array $row): string {
if (!empty($row['入金日'])) {
    $ymd = _anc_normalize_mdy_with_infer((string)$row['入金日'], $row);
    if ($ymd !== '') return $ymd;
}
// フォールバック（万一）
foreach (['date','dt','created_at','datetime','time'] as $k) {
    if (!empty($row[$k])) {
    $ts = strtotime((string)$row[$k]);
    if ($ts !== false) return date('Y-m-d', $ts);
    }
}
return '';
}
if (!function_exists('_anc_build_actor_profiles')) {
  function _anc_build_actor_profiles(array $actorsItems): array {
    $profiles = [];
    foreach ($actorsItems as $a) {
      $name = trim((string)($a['name'] ?? ''));
      if ($name === '') continue;
      $type = trim((string)($a['type'] ?? ''));     // 入口
      // 今回は systems は条件に含めない（仕様）
      $profiles[$name] = ['type'=>$type];
    }
    return $profiles;
  }
}
if (!function_exists('_anc_parse_money')) {
  function _anc_parse_money($v): float {
    // 例: "¥12,345" "12,345" "12345" などに対応
    if (is_numeric($v)) return (float)$v;
    $s = preg_replace('/[^\d\.\-]/', '', (string)$v);
    return (float)$s;
  }
}
if (!function_exists('_anc_pick_payment_no')) {
  function _anc_pick_payment_no(array $row): string {
    // 「支払い何回目」「支払何回目」どちらにも対応
    $keys = ['支払い何回目','支払何回目'];
    foreach ($keys as $k) {
      if (array_key_exists($k, $row)) return trim((string)$row[$k]);
    }
    return '';
  }
}

/* ===== 本体：入金件数（日別） =====
 * 条件:
 *  - 状態 = '入金済' または '入金済み'
 *  - 入口(type) が actors.json の type と一致（空なら不問）
 *  - 支払い何回目 = '1' または 空
 *  - 入金額 > 0
 *  - 日付 = 入金日
 */
function build_nyukin_count_by_day(string $DATA_DIR, string $CACHE_DIR): array {
  $res = [];

  $rawPath    = rtrim($CACHE_DIR,'/').'/raw_rows.json';
  $actorsPath = rtrim($DATA_DIR, '/').'/actors.json';
  if (!is_file($rawPath) || !is_file($actorsPath)) return $res;

  $raw    = json_decode((string)file_get_contents($rawPath), true);
  $actors = json_decode((string)file_get_contents($actorsPath), true);

  $rows       = (isset($raw['rows']) && is_array($raw['rows'])) ? $raw['rows']
              : ((isset($raw['items']) && is_array($raw['items'])) ? $raw['items'] : []);
  $actorsList = (isset($actors['items']) && is_array($actors['items'])) ? $actors['items'] : [];

  $aliasMap = _anc_alias_map_from_actors_items($actorsList);
  $profiles = _anc_build_actor_profiles($actorsList);

  foreach ($rows as $row) {
    // 状態
    $state = trim((string)($row['状態'] ?? ''));
    if ($state !== '入金済' && $state !== '入金済み') continue;

    // 支払い何回目が "1" または "" のみ対象
    $shiharai = (string)($row['支払い何回目'] ?? '');
    if (!($shiharai === '1' || $shiharai === '')) continue;

    // // 支払い何回目
    // $payNo = _anc_pick_payment_no($row); // '' or '1' を許可
    // if ($payNo !== '' && $payNo !== '1') continue;

    // 入金額
    $amount = _anc_parse_money($row['入金額'] ?? 0);
    if ($amount <= 0) continue;
    
    // 対象システム名リストでフィルタ
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

    // 担当名
    $rawName = _anc_pick_actor_name($row);
    if ($rawName === '') continue;
    $name = $aliasMap[$rawName] ?? $rawName;

    // 入口（type）
    $reqType = (string)($profiles[$name]['type'] ?? '');
    $rowType = trim((string)($row['入口'] ?? ''));
    if ($reqType !== '' && $rowType !== $reqType) continue;

    // 日付＝入金日
    $ymd = _anc_pick_nyukin_date($row);
    if ($ymd === '' || !_anc_is_valid_day($ymd)) continue;

    // 加算
    if (!isset($res[$ymd])) $res[$ymd] = [];
    if (!isset($res[$ymd][$name])) $res[$ymd][$name] = 0;
    $res[$ymd][$name]++;
    if (!isset($res[$ymd]['_total'])) $res[$ymd]['_total'] = 0;
    $res[$ymd]['_total']++;
  }

  ksort($res);
  return $res;
}

/* ===== 後方互換：requireだけで $nyukinCountByDay を供給（任意） =====
   無効化: define('AGGREGATE_NYUKIN_COUNT_NO_AUTO', true); */
if (!defined('AGGREGATE_NYUKIN_COUNT_NO_AUTO')) {
  if (isset($DATA_DIR) && isset($CACHE_DIR)) {
    try {
      /** @var array $nyukinCountByDay */
      $nyukinCountByDay = build_nyukin_count_by_day((string)$DATA_DIR, (string)$CACHE_DIR);
    } catch (\Throwable $e) {
      if (!isset($nyukinCountByDay)) $nyukinCountByDay = [];
    }
  }
}
