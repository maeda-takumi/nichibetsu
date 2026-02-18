<?php
// aggregate/aggregate_nyukin_count.php
declare(strict_types=1);

/* ===== Utils ===== */

function _anc_pick_video_actor(array $row): string {
  foreach (['動画担当','user','actor','担当','担当者','セールス担当','sales_user','sales','name'] as $k) {
    if (array_key_exists($k, $row)) {
      $v = trim((string)$row[$k]);
      if ($v !== '') return $v;
    }
  }
  return '';
}

function _anc_infer_year_month(array $row): array {
  if (!empty($row['セールス年月']) && preg_match('/(\d{4})\D+(\d{1,2})/', (string)$row['セールス年月'], $m)) {
    return [(int)$m[1], (int)$m[2]];
  }
  if (!empty($row['データ4']) && preg_match('/^(\d{4})(\d{2})$/', (string)$row['データ4'], $m)) {
    return [(int)$m[1], (int)$m[2]];
  }
  return [(int)date('Y'), (int)date('n')];
}

function _anc_normalize_mdy_with_infer(string $md, array $row): string {
  $md = trim($md);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $md)) return $md;
  if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/', $md, $m)) {
    $mm = (int)$m[1]; $dd = (int)$m[2]; $yy = null;
    if (!empty($m[3])) {
      $y = (int)$m[3];
      $yy = ($y < 100) ? (2000 + $y) : $y;
    }
    if ($yy === null) {
      [$iy] = _anc_infer_year_month($row);
      $yy = $iy;
    }
    if (checkdate($mm, $dd, $yy)) return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
  }
  return '';
}

function _anc_is_valid_day(string $ymd): bool {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
  $ts = strtotime($ymd);
  if ($ts === false) return false;
  if ($ts < strtotime('2000-01-01')) return false;
  if ($ts > time()) return false;
  return true;
}

function _anc_pick_nyukin_date(array $row): string {
  if (!empty($row['入金日'])) {
    $ymd = _anc_normalize_mdy_with_infer((string)$row['入金日'], $row);
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

function _anc_parse_money($v): float {
  if (is_numeric($v)) return (float)$v;
  $s = preg_replace('/[^\d\.\-]/', '', (string)$v);
  return (float)$s;
}

/* ===== actor profiles ===== */
function _anc_build_actor_profiles(array $actorsItems): array {
  $profiles = [];
  foreach ($actorsItems as $a) {
    $name = trim((string)($a['name'] ?? ''));
    if ($name === '') continue;
    $type = trim((string)($a['type'] ?? '')); // 入口
    $profiles[$name] = ['type' => $type];
  }
  return $profiles;
}

/* ===== 本体：入金件数（日別・新仕様） ===== */
function build_nyukin_count_by_day(string $DATA_DIR, string $CACHE_DIR): array {
  $res = [];

  $rawPath    = rtrim($CACHE_DIR,'/').'/raw_rows.json';
  $actorsPath = rtrim($DATA_DIR, '/').'/actors.json';
  if (!is_file($rawPath) || !is_file($actorsPath)) return $res;

  $raw    = json_decode((string)file_get_contents($rawPath), true) ?: [];
  $actors = json_decode((string)file_get_contents($actorsPath), true) ?: [];

  $rows       = $raw['rows'] ?? $raw['items'] ?? [];
  $actorsList = $actors['items'] ?? [];

  $profiles = _anc_build_actor_profiles($actorsList);

  $targetSystems = [
    'ChatGPTフロント',
    'Instagramフロント',
    '動画編集フロント',
    'TikTokフロント',
    '副業フロント',
    '副業ウェブフリフロント',
  ];

  foreach ($rows as $row) {

    // 状態
    $state = trim((string)($row['状態'] ?? ''));
    if ($state !== '入金済' && $state !== '入金済み') continue;

    // 支払い何回目
    $shiharai = trim((string)($row['支払い何回目'] ?? ''));
    if (!($shiharai === '' || $shiharai === '1')) continue;

    // 入金額
    $amount = _anc_parse_money($row['入金額'] ?? 0);
    if ($amount <= 0) continue;

    // システム名
    $systemName = trim((string)($row['システム名'] ?? ''));
    if ($systemName === '' || !in_array($systemName, $targetSystems, true)) continue;

    // 日付
    $ymd = _anc_pick_nyukin_date($row);
    if ($ymd === '' || !_anc_is_valid_day($ymd)) continue;

    // 判定用
    $videoActor = _anc_pick_video_actor($row);
    $inflow     = (string)($row['流入経路'] ?? '');

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

      // 入口(type)
      if (!empty($profile['type'])) {
        if (trim((string)($row['入口'] ?? '')) !== $profile['type']) continue;
      }

      // 加算
      if (!isset($res[$ymd])) $res[$ymd] = [];
      $res[$ymd][$actorName] = ($res[$ymd][$actorName] ?? 0) + 1;
      $res[$ymd]['_total']   = ($res[$ymd]['_total']   ?? 0) + 1;
    }
  }

  ksort($res);
  return $res;
}

/* ===== 後方互換 ===== */
if (!defined('AGGREGATE_NYUKIN_COUNT_NO_AUTO')) {
  if (isset($DATA_DIR) && isset($CACHE_DIR)) {
    try {
      $nyukinCountByDay = build_nyukin_count_by_day((string)$DATA_DIR, (string)$CACHE_DIR);
    } catch (\Throwable $e) {
      if (!isset($nyukinCountByDay)) $nyukinCountByDay = [];
    }
  }
}
