<?php
// aggregate/aggregate_sales_seiyaku_count.php
declare(strict_types=1);

/**
 * 成約件数（日別）集計
 * ※ 状態 = 入金済み
 * ※ みおパパ分岐仕様対応版
 *
 * return:
 * [sid => [aid => ['YYYY-MM' => [day => count]]]]
 */

// ---------------- helpers ----------------
function _sec_json(string $p): array {
  if (!is_file($p)) return [];
  $j = json_decode((string)@file_get_contents($p), true);
  return is_array($j) ? $j : [];
}
function _sec_rows(array $j): array {
  return $j['items'] ?? $j['rows'] ?? [];
}
function _sec_norm(string $s): string {
  if ($s === '') return '';
  if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'asKV', 'UTF-8');
  $s = preg_replace('/\s+/u', '', $s) ?? '';
  return mb_strtolower($s, 'UTF-8');
}
function _sec_digits(string $s): string {
  return preg_replace('/[^\d]/u', '', $s) ?? '';
}
function _sec_date($v): string {
  if (is_string($v)) {
    $ts = strtotime(strtr(trim($v), ['/' => '-', '.' => '-']));
    if ($ts !== false) return date('Y-m-d', $ts);
    if (is_numeric($v)) $v = (float)$v;
  }
  if (is_numeric($v)) {
    $unix = (int)round(((float)$v - 25569) * 86400);
    if ($unix > 0) return gmdate('Y-m-d', $unix);
  }
  return '';
}
function _sec_norm_split($v): array {
  $out = [];
  $arr = is_array($v) ? $v : explode(',', str_replace(['、','，'], ',', (string)$v));
  foreach ($arr as $x) {
    $n = _sec_norm(trim((string)$x));
    if ($n !== '') $out[$n] = true;
  }
  return $out;
}
function _sec_contains_miopapa($v): bool {
  return mb_strpos((string)$v, 'みおパパ') !== false;
}

// ---------------- actor resolver ----------------
function _sec_find_miopapa_aid(array $actors): string {
  foreach ($actors as $a) {
    if (($a['name'] ?? '') === 'みおパパ') return (string)($a['id'] ?? '');
    if (in_array('みおパパ', (array)($a['aliases'] ?? []), true)) {
      return (string)($a['id'] ?? '');
    }
  }
  return '';
}

function _sec_resolve_actor_id(array $r, array $name2ids, array $alias2id, string $miopapaAid): string {
  if (_sec_contains_miopapa($r['流入経路'] ?? '')) {
    return $miopapaAid ?: '';
  }
  $raw = trim((string)($r['動画担当'] ?? ($r['actor'] ?? '')));
  if ($raw === '') return '';
  if (isset($name2ids[$raw])) {
    foreach ($name2ids[$raw] as $id) {
      if ($id !== $miopapaAid) return $id;
    }
    return $name2ids[$raw][0];
  }
  return $alias2id[$raw] ?? '';
}

// ---------------- builder ----------------
function build_sales_seiyaku_count_by_day(string $DATA_DIR, string $CACHE_DIR): array {
  try {
    $out = [];

    // sales
    $sales = _sec_json(rtrim($DATA_DIR,'/').'/sales.json');
    $salesName2Id = [];
    foreach (($sales['items'] ?? []) as $s) {
      if (!empty($s['id']) && !empty($s['name'])) {
        $salesName2Id[$s['name']] = $s['id'];
      }
    }

    // actors
    $actors = _sec_json(rtrim($DATA_DIR,'/').'/actors.json');
    $actorItems = $actors['items'] ?? [];
    $name2ids = $alias2id = $actorId2Type = $actorId2Systems = [];

    foreach ($actorItems as $a) {
      $id = (string)($a['id'] ?? '');
      if ($id === '') continue;
      $name2ids[$a['name']][] = $id;
      foreach (($a['aliases'] ?? []) as $al) $alias2id[$al] = $id;
      if (!empty($a['type'])) $actorId2Type[$id] = _sec_norm($a['type']);
      if (!empty($a['systems'])) $actorId2Systems[$id] = _sec_norm_split($a['systems']);
    }

    if (!$salesName2Id || !$name2ids) return [];

    $miopapaAid = _sec_find_miopapa_aid($actorItems);

    $rows = _sec_rows(_sec_json(rtrim($CACHE_DIR,'/').'/raw_rows.json'));
    foreach ($rows as $r) {
      // sales
      $sname = trim((string)($r['セールス担当'] ?? $r['sales'] ?? ''));
      if (!isset($salesName2Id[$sname])) continue;
      $sid = $salesName2Id[$sname];

      // actor
      $aid = _sec_resolve_actor_id($r, $name2ids, $alias2id, $miopapaAid);
      if ($aid === '') continue;

      // type
      if (_sec_norm((string)($r['入口'] ?? '')) !== ($actorId2Type[$aid] ?? '')) continue;

      // system
      if (!empty($actorId2Systems[$aid])) {
        $rs = _sec_norm((string)($r['システム名'] ?? ''));
        if ($rs === '' || !isset($actorId2Systems[$aid][$rs])) continue;
      }

      // status
      if (trim((string)($r['状態'] ?? '')) !== '入金済み') continue;

      // payment no
      $nd = _sec_digits((string)($r['支払い何回目'] ?? ''));
      if (!($nd === '' || $nd === '1')) continue;

      // date
      $d = _sec_date($r['セールス日'] ?? $r['date'] ?? '');
      if ($d === '') continue;
      $ts = strtotime($d);
      $ym = date('Y-m', $ts);
      $day = (int)date('j', $ts);

      $out[$sid][$aid][$ym][$day] = ($out[$sid][$aid][$ym][$day] ?? 0) + 1;
    }

    return $out;
  } catch (\Throwable $e) {
    return [];
  }
}
