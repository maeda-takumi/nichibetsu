<?php
// aggregate/aggregate_watch.php
declare(strict_types=1);

/**
 * data/watch_metrics.json を読み込み、actors.json の channel と一致する actor(id) に
 * 日別（YYYY-MM / 日）で watch_hours / views / impressions を集計する。
 *
 * 戻り値:
 *   [
 *     actor_id => [
 *       'YYYY-MM' => [
 *          1 => ['watch_hours'=>float, 'views'=>int, 'impressions'=>int],
 *          2 => [...],
 *          ...
 *       ]
 *     ],
 *   ]
 *
 * ※ 他 aggregate_* と同様に、呼び出し元で require して
 *    build_watch_by_day($DATA_DIR, $CACHE_DIR) を呼ぶ運用。
 */

function _watch_safe_json(string $path): array {
  if (!is_file($path)) return [];
  $txt = (string)@file_get_contents($path);
  if ($txt === '') return [];
  $j = json_decode($txt, true);
  return is_array($j) ? $j : [];
}

function _watch_pick_items(array $json): array {
  if (isset($json['items']) && is_array($json['items'])) return $json['items'];
  if (isset($json['rows'])  && is_array($json['rows']))  return $json['rows'];
  return [];
}

/** actors.json: id => ['name','channel'] マップを返す */
function _actors_meta_map(string $DATA_DIR): array {
  $actors = _watch_safe_json(rtrim($DATA_DIR, '/').'/actors.json');
  $items  = isset($actors['items']) && is_array($actors['items']) ? $actors['items'] : [];
  $map = []; // id => ['name'=>..., 'channel'=>...]
  foreach ($items as $a) {
    $id = trim((string)($a['id'] ?? ''));
    if ($id === '') continue;
    $name    = trim((string)($a['name'] ?? ''));
    $channel = trim((string)($a['channel'] ?? ''));
    $map[$id] = ['name'=>$name, 'channel'=>$channel];
  }
  return $map;
}

/** channel(空はname) => id の逆引き */
function _channel_to_id(array $meta): array {
  $ch2id = [];
  foreach ($meta as $id => $m) {
    $ch = trim((string)($m['channel'] ?? ''));
    $nm = trim((string)($m['name'] ?? ''));
    if ($ch === '') $ch = $nm;
    if ($ch !== '' && !isset($ch2id[$ch])) $ch2id[$ch] = $id;
    // name でもフォールバックできるよう二重化（任意）
    if ($nm !== '' && !isset($ch2id[$nm])) $ch2id[$nm] = $id;
  }
  return $ch2id;
}

/** メイン：他aggregateと同じ呼び出し仕様 */
function build_watch_by_day(string $DATA_DIR, string $CACHE_DIR): array {
  $out = [];

  $watchPath = rtrim($DATA_DIR, '/').'/watch_metrics.json';
  $rows = _watch_pick_items(_watch_safe_json($watchPath));
  if (!$rows) return $out;

  $meta  = _actors_meta_map($DATA_DIR);     // id => ['name','channel']
  if (!$meta) return $out;
  $ch2id = _channel_to_id($meta);           // channel/name => id

  foreach ($rows as $r) {
    $actorName = trim((string)($r['actor_name'] ?? ''));
    $date      = trim((string)($r['date'] ?? ''));
    if ($actorName === '' || $date === '') continue;

    $aid = $ch2id[$actorName] ?? null;
    if ($aid === null) continue;

    $ts = strtotime($date);
    if ($ts === false) continue;
    $ym = date('Y-m', $ts);
    $d  = (int)date('j', $ts);

    $wh = (float)($r['watch_hours'] ?? 0);
    $vw = (int)  ($r['views']       ?? 0);
    $im = (int)  ($r['impressions'] ?? 0);

    if (!isset($out[$aid]))            $out[$aid] = [];
    if (!isset($out[$aid][$ym]))       $out[$aid][$ym] = [];
    if (!isset($out[$aid][$ym][$d]))   $out[$aid][$ym][$d] = ['watch_hours'=>0.0, 'views'=>0, 'impressions'=>0];

    $out[$aid][$ym][$d]['watch_hours'] += $wh;
    $out[$aid][$ym][$d]['views']       += $vw;
    $out[$aid][$ym][$d]['impressions'] += $im;
  }

  return $out;
}
