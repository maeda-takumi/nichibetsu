<?php
// aggregate/aggregate_taiou_count.php
declare(strict_types=1);

/* ---- 共通ユーティリティ ---- */
if (!function_exists('_atc_alias_map_from_actors_items')) {
  function _atc_alias_map_from_actors_items(array $items): array {
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

function _atc_pick_actor_name(array $row): string {
  foreach (['動画担当','user','actor','担当','担当者','セールス担当','sales_user','sales','name'] as $k) {
    if (array_key_exists($k, $row)) {
      $v = trim((string)$row[$k]);
      if ($v !== '') return $v;
    }
  }
  return '';
}

function _atc_infer_year_month(array $row): array {
  if (!empty($row['セールス年月']) && preg_match('/(\d{4})\D+(\d{1,2})/', (string)$row['セールス年月'], $m)) {
    return [(int)$m[1], (int)$m[2]];
  }
  return [(int)date('Y'), (int)date('n')];
}

function _atc_normalize_mdy_with_infer(string $md, array $row): string {
  $md = trim($md);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $md)) return $md;
  if (preg_match('/^(\d{1,2})\/(\d{1,2})/', $md, $m)) {
    [$y] = _atc_infer_year_month($row);
    if (checkdate((int)$m[1], (int)$m[2], $y)) {
      return sprintf('%04d-%02d-%02d', $y, (int)$m[1], (int)$m[2]);
    }
  }
  return '';
}

function _atc_is_valid_day(string $ymd): bool {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
  $ts = strtotime($ymd);
  return $ts !== false && $ts <= time();
}

function _atc_pick_date_for_row(array $row): string {
  if (!empty($row['LINE登録日'])) {
    return _atc_normalize_mdy_with_infer((string)$row['LINE登録日'], $row);
  }
  return '';
}

function _atc_build_actor_profiles(array $actorsItems): array {
  $profiles = [];
  foreach ($actorsItems as $a) {
    $name = trim((string)($a['name'] ?? ''));
    if ($name === '') continue;
    $profiles[$name] = [
      'type'    => trim((string)($a['type'] ?? '')),
      'systems' => is_array($a['systems'] ?? null)
        ? array_values(array_filter(array_map('trim', $a['systems'])))
        : array_values(array_filter(array_map('trim', explode(',', (string)($a['systems'] ?? ''))))),
    ];
  }
  return $profiles;
}

/* ---- 本体 ---- */
function build_taiou_count_by_day(string $DATA_DIR, string $CACHE_DIR): array {
  $res = [];

  $rawPath    = rtrim($CACHE_DIR,'/').'/raw_rows.json';
  $actorsPath = rtrim($DATA_DIR,'/').'/actors.json';
  if (!is_file($rawPath) || !is_file($actorsPath)) return $res;

  $raw    = json_decode(file_get_contents($rawPath), true) ?: [];
  $actors = json_decode(file_get_contents($actorsPath), true) ?: [];

  $rows       = $raw['rows'] ?? $raw['items'] ?? [];
  $actorsList = $actors['items'] ?? [];

  $aliasMap = _atc_alias_map_from_actors_items($actorsList);
  $profiles = _atc_build_actor_profiles($actorsList);

  $okStates = ['失注','入金済み','入金待ち','一旦保留','再調整'];

  foreach ($rows as $row) {
    if (!in_array(trim((string)($row['状態'] ?? '')), $okStates, true)) continue;

    $shiharai = trim((string)($row['支払い何回目'] ?? ''));
    if ($shiharai !== '' && $shiharai !== '1') continue;

    $inflow = (string)($row['流入経路'] ?? '');

    /* ===== 新仕様：みお分岐 ===== */
    $name = '';

    // みおパパ
    if (str_contains($inflow, 'みおパパ')) {
      $name = 'みおパパ';
    } else {
      // 通常（動画担当ベース）
      $rawName = _atc_pick_actor_name($row);
      if ($rawName === '') continue;
      $name = $aliasMap[$rawName] ?? $rawName;

      // しらほしなつみ だが流入経路がみおパパ → 除外
      if ($name === 'しらほしなつみ' && str_contains($inflow, 'みおパパ')) {
        continue;
      }
    }

    $prof = $profiles[$name] ?? ['type'=>'','systems'=>[]];

    // システム名制限
    $targetSystems = [
      'ChatGPTフロント','Instagramフロント','動画編集フロント',
      'TikTokフロント','副業フロント','副業ウェブフリフロント',
    ];
    $sys = trim((string)($row['システム名'] ?? ''));
    if ($sys === '' || !in_array($sys, $targetSystems, true)) continue;

    if ($prof['type'] !== '' && trim((string)($row['入口'] ?? '')) !== $prof['type']) continue;
    if ($prof['systems'] && !in_array($sys, $prof['systems'], true)) continue;

    $ymd = _atc_pick_date_for_row($row);
    if ($ymd === '' || !_atc_is_valid_day($ymd)) continue;

    if (!isset($res[$ymd])) $res[$ymd] = [];
    $res[$ymd][$name] = ($res[$ymd][$name] ?? 0) + 1;
    $res[$ymd]['_total'] = ($res[$ymd]['_total'] ?? 0) + 1;
  }

  ksort($res);
  return $res;
}

/* ---- auto ---- */
if (!defined('AGGREGATE_TAIOU_NO_AUTO')) {
  if (isset($DATA_DIR, $CACHE_DIR)) {
    $taiouCountByDay = build_taiou_count_by_day($DATA_DIR, $CACHE_DIR);
  }
}
