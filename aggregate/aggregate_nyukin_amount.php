<?php
// aggregate/aggregate_nyukin_amount.php
declare(strict_types=1);

/* ===== Utils ===== */

function _ana_pick_video_actor(array $row): string {
  foreach (['動画担当','user','actor','担当','担当者','セールス担当','sales_user','sales','name'] as $k) {
    if (array_key_exists($k, $row)) {
      $v = trim((string)$row[$k]);
      if ($v !== '') return $v;
    }
  }
  return '';
}

function _ana_infer_year_month(array $row): array {
  if (!empty($row['セールス年月']) && preg_match('/(\d{4})\D+(\d{1,2})/', (string)$row['セールス年月'], $m)) {
    return [(int)$m[1], (int)$m[2]];
  }
  if (!empty($row['データ4']) && preg_match('/^(\d{4})(\d{2})$/', (string)$row['データ4'], $m)) {
    return [(int)$m[1], (int)$m[2]];
  }
  return [(int)date('Y'), (int)date('n')];
}

function _ana_normalize_mdy_with_infer(string $md, array $row): string {
  $md = trim($md);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $md)) return $md;
  if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/', $md, $m)) {
    $mm = (int)$m[1]; $dd = (int)$m[2]; $yy = null;
    if (!empty($m[3])) {
      $y = (int)$m[3];
      $yy = ($y < 100) ? (2000 + $y) : $y;
    }
    if ($yy === null) {
      [$iy] = _ana_infer_year_month($row);
      $yy = $iy;
    }
    if (checkdate($mm, $dd, $yy)) return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
  }
  return '';
}

function _ana_is_valid_day(string $ymd): bool {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
  $ts = strtotime($ymd);
  if ($ts === false) return false;
  if ($ts < strtotime('2000-01-01')) return false;
  if ($ts > time()) return false;
  return true;
}

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

function _ana_parse_money($v): float {
  if (is_numeric($v)) return (float)$v;
  $s = preg_replace('/[^\d\.\-]/', '', (string)$v);
  return (float)$s;
}

function _ana_smart_split(string $s): array {
  $s = trim($s);
  if ($s === '') return [];
  return array_values(array_filter(
    array_map('trim', preg_split('/\s*[,，、|／\/]\s*/u', $s)),
    fn($v) => $v !== ''
  ));
}

/* ===== actor profiles ===== */
function _ana_build_actor_profiles(array $actorsItems): array {
  $profiles = [];
  foreach ($actorsItems as $a) {
    $name = trim((string)($a['name'] ?? ''));
    if ($name === '') continue;

    $type = trim((string)($a['type'] ?? ''));
    $profiles[$name] = ['type' => $type];
  }
  return $profiles;
}

/* ===== 本体：入金額（日別合算・新仕様） ===== */
function build_nyukin_amount_by_day(string $DATA_DIR, string $CACHE_DIR): array {
  $res = [];

  $rawPath    = rtrim($CACHE_DIR,'/').'/raw_rows.json';
  $actorsPath = rtrim($DATA_DIR, '/').'/actors.json';
  if (!is_file($rawPath) || !is_file($actorsPath)) return $res;

  $raw    = json_decode((string)file_get_contents($rawPath), true) ?: [];
  $actors = json_decode((string)file_get_contents($actorsPath), true) ?: [];

  $rows       = $raw['rows'] ?? $raw['items'] ?? [];
  $actorsList = $actors['items'] ?? [];

  $profiles = _ana_build_actor_profiles($actorsList);

  $targetSystems = [
    'ChatGPTフロント',
    'Instagramフロント',
    '動画編集フロント',
    'TikTokフロント',
    '副業フロント',
    '副業ウェブフリフロント',
  ];

  foreach ($rows as $row) {

    $videoActor = _ana_pick_video_actor($row);
    $inflow     = (string)($row['流入経路'] ?? '');

    // システム名チェック（軽量）
    $systemName = trim((string)($row['システム名'] ?? ''));
    if ($systemName === '' || !in_array($systemName, $targetSystems, true)) continue;

    // 入金額
    $amount = _ana_parse_money($row['入金額'] ?? 0);
    if ($amount <= 0) continue;

    // 入金日
    $ymd = _ana_pick_nyukin_date($row);
    if ($ymd === '' || !_ana_is_valid_day($ymd)) continue;

    foreach ($profiles as $actorName => $profile) {

      /* ===== 新仕様分岐 ===== */

      // みおパパ
      if ($actorName === 'みおパパ') {
        if (!str_contains($inflow, 'みおパパ')) continue;
      }
      // しらほしなつみ（みおママ）
      elseif ($actorName === 'しらほしなつみ') {
        if ($videoActor !== 'しらほしなつみ') continue;
        if (str_contains($inflow, 'みおパパ')) continue;
      }
      // その他
      else {
        if ($videoActor !== $actorName) continue;
      }

      // 入口(type)一致
      if (!empty($profile['type'])) {
        if (trim((string)($row['入口'] ?? '')) !== $profile['type']) continue;
      }

      // 加算
      if (!isset($res[$ymd])) $res[$ymd] = [];
      $res[$ymd][$actorName] = ($res[$ymd][$actorName] ?? 0) + $amount;
      $res[$ymd]['_total']   = ($res[$ymd]['_total']   ?? 0) + $amount;
    }
  }

  ksort($res);
  return $res;
}

/* ===== 後方互換 ===== */
if (!defined('AGGREGATE_NYUKIN_AMOUNT_NO_AUTO')) {
  if (isset($DATA_DIR) && isset($CACHE_DIR)) {
    try {
      $nyukinAmountByDay = build_nyukin_amount_by_day((string)$DATA_DIR, (string)$CACHE_DIR);
    } catch (\Throwable $e) {
      if (!isset($nyukinAmountByDay)) $nyukinAmountByDay = [];
    }
  }
}
