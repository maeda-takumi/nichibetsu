<?php
// aggregate_chosei.php
declare(strict_types=1);

/* ===== Debug (?debug_choseifs=1) ===== */
function _dbg_c($label,$val){
  if (!empty($_GET['debug_choseifs'])){
    echo "<pre style='background:#030;color:#fff;padding:6px;margin:4px 0'><b>{$label}</b>\n";
    if(is_bool($val)) var_export($val); else print_r($val);
    echo "</pre>";
  }
}

/** 動画担当の取り出し */
function _pick_actor_name(array $row): string {
  foreach (['動画担当','actor','user','担当','担当者','sales_user','sales','name'] as $k) {
    if (array_key_exists($k, $row)) {
      $v = trim((string)$row[$k]);
      if ($v !== '') return $v;
    }
  }
  return '';
}

/** 年月の推定 */
function _infer_year_month(array $row): array {
  if (!empty($row['セールス年月']) && preg_match('/(\d{4})\D+(\d{1,2})/', (string)$row['セールス年月'], $m)) {
    return [intval($m[1]), intval($m[2])];
  }
  return [intval(date('Y')), intval(date('n'))];
}

/** MM/DD → YYYY-MM-DD */
function _normalize_mdy_with_infer(string $md, array $row): string {
  $md = trim($md);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $md)) return $md;
  if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $md, $m)) {
    [$y] = _infer_year_month($row);
    return sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]);
  }
  return '';
}

function _is_valid_day(string $ymd): bool {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
  $ts = strtotime($ymd);
  return $ts !== false && $ts <= time();
}

/* ===== 分割ヘルパ ===== */
function _smart_split(string $s): array {
  if ($s === '') return [];
  return array_values(array_filter(
    array_map('trim', preg_split('/\s*[,，、|／\/]\s*/u', $s)),
    fn($v) => $v !== ''
  ));
}

/* ===== actor profiles ===== */
function _actor_profiles(array $items): array {
  $profiles = [];
  foreach ($items as $a) {
    $name = trim((string)($a['name'] ?? ''));
    if ($name === '') continue;

    $type = ($a['type'] ?? '') === '投資' ? '投資' : '副業';

    $systems = [];
    if (!empty($a['systems'])) {
      foreach (_smart_split((string)$a['systems']) as $s) $systems[] = $s;
    }
    if (!$systems) $systems = ['副業フロント','ChatGPTフロント','TikTokフロント'];

    $profiles[$name] = [
      'type'    => $type,
      'systems' => array_values(array_unique($systems)),
    ];
  }
  return $profiles;
}

/* ===== chosei filter ===== */
function _row_passes_chosei_filters(array $row, array $actorProfile): bool {
  if (trim((string)($row['セールス担当'] ?? '')) === '') return false;
  if (trim((string)($row['入口'] ?? '')) !== ($actorProfile['type'] ?? '副業')) return false;

  $nth = trim((string)($row['支払い何回目'] ?? ''));
  if (!($nth === '' || $nth === '1')) return false;

  $rowSystems = _smart_split((string)($row['システム名'] ?? ''));
  if (!$rowSystems) return false;

  foreach ($rowSystems as $rs) {
    if (in_array($rs, $actorProfile['systems'], true)) return true;
  }
  return false;
}

/** 集計日 */
function _pick_date_ymd_for_chosei(array $row): string {
  return _normalize_mdy_with_infer((string)($row['LINE登録日'] ?? ''), $row);
}

/**
 * chosei 集計（新仕様）
 */
function build_chosei_by_day(string $DATA_DIR, string $CACHE_DIR): array {
  $res = [];

  $rawPath = is_file("$CACHE_DIR/raw_rows.json")
    ? "$CACHE_DIR/raw_rows.json"
    : "$DATA_DIR/raw_rows.json";

  $actorsPath = "$DATA_DIR/actors.json";
  if (!is_file($rawPath) || !is_file($actorsPath)) return $res;

  $raw    = json_decode(file_get_contents($rawPath), true) ?: [];
  $actors = json_decode(file_get_contents($actorsPath), true) ?: [];

  $rows       = $raw['rows'] ?? $raw['items'] ?? [];
  $actorsList = $actors['items'] ?? [];

  $profiles = _actor_profiles($actorsList);

  foreach ($rows as $row) {

    $videoActor = _pick_actor_name($row);
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

      // その他 actor は従来通り（動画担当一致）
      else {
        if ($videoActor !== $actorName) continue;
      }

      /* ===== 共通条件 ===== */
      if (!_row_passes_chosei_filters($row, $profile)) continue;

      $ymd = _pick_date_ymd_for_chosei($row);
      if ($ymd === '' || !_is_valid_day($ymd)) continue;

      if (!isset($res[$ymd])) $res[$ymd] = [];
      $res[$ymd][$actorName] = ($res[$ymd][$actorName] ?? 0) + 1;
      $res[$ymd]['_total']   = ($res[$ymd]['_total'] ?? 0) + 1;
    }
  }

  ksort($res);
  return $res;
}

/* ===== auto ===== */
if (!defined('AGGREGATE_CHOSEI_NO_AUTO')) {
  if (isset($DATA_DIR, $CACHE_DIR)) {
    $choseiByDay = build_chosei_by_day($DATA_DIR, $CACHE_DIR);
  }
}
