<?php
// aggregate_inflows.php
declare(strict_types=1);

function _dbg_i($label, $val): void {
  if (!empty($_GET['debug_inflowfs'])) {
    echo "<pre style='background:#023;color:#fff;padding:6px;margin:4px 0'><b>{$label}</b>\n";
    if (is_bool($val)) var_export($val); else print_r($val);
    echo "</pre>";
  }
}

function _valid_ymd(string $ymd): bool {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
  $ts = strtotime($ymd);
  if ($ts === false) return false;
  if ($ts > time()) return false;                 // 未来日除外
  if ($ts < strtotime('2000-01-01')) return false; // 過去すぎ除外（必要なら調整）
  return true;
}


function build_inflow_by_day(string $DATA_DIR, string $CACHE_DIR): array {
  $out = [];

  $inflowsPath = rtrim($DATA_DIR, '/').'/inflows.json';
  $actorsPath  = rtrim($DATA_DIR, '/').'/actors.json';

  _dbg_i('PATH inflows.json', ['path'=>$inflowsPath, 'exists'=>is_file($inflowsPath), 'size'=>@filesize($inflowsPath)]);
  _dbg_i('PATH actors.json',  ['path'=>$actorsPath,  'exists'=>is_file($actorsPath),  'size'=>@filesize($actorsPath)]);

  if (!is_file($inflowsPath)) return $out;

  $infl = json_decode((string)file_get_contents($inflowsPath), true) ?: [];
  $items = [];
  if (isset($infl['items']) && is_array($infl['items'])) $items = $infl['items'];
  elseif (isset($infl['rows']) && is_array($infl['rows'])) $items = $infl['rows'];

  _dbg_i('inflows items count', count($items));
  if (!$items) return $out;

  $alias = [];
  if (is_file($actorsPath)) {
    $actors = json_decode((string)file_get_contents($actorsPath), true) ?: [];
    $alias  = _alias_map_from_actors_items((array)($actors['items'] ?? []));
    _dbg_i('actors items count', count((array)($actors['items'] ?? [])));
  }

  foreach ($items as $it) {
    $name = trim((string)($it['user'] ?? ''));
    $date = trim((string)($it['date'] ?? ''));
    $val  = (int)($it['value'] ?? 0);

    // 運用上、value が 0/未定義は 1件として扱う（必要なら厳格化してOK）
    if ($val <= 0) $val = 1;

    if ($name === '' || $date === '' || !_valid_ymd($date)) continue;

    $fixed = $alias[$name] ?? $name;

    if (!isset($out[$date])) $out[$date] = [];
    $out[$date][$fixed]   = ($out[$date][$fixed]   ?? 0) + $val;
    $out[$date]['_total'] = ($out[$date]['_total'] ?? 0) + $val;
  }

  if ($out) ksort($out);
  _dbg_i('inflow out tail 3', array_slice($out, -3, 3, true));
  return $out;
}

if (!defined('AGGREGATE_INFLOWS_NO_AUTO')) {
  if (isset($DATA_DIR) && isset($CACHE_DIR)) {
    try {
      /** @var array $inflowByDay */
      $inflowByDay = build_inflow_by_day((string)$DATA_DIR, (string)$CACHE_DIR);
    } catch (\Throwable $e) {
      if (!isset($inflowByDay)) $inflowByDay = [];
      _dbg_i('build_inflow_by_day EXCEPTION', $e->getMessage());
    }
  }
}
