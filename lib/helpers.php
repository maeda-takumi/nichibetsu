<?php
// lib/helpers.php

declare(strict_types=1);

function load_json(string $path): array {
  if (!is_file($path)) return [];
  return json_decode(file_get_contents($path), true) ?? [];
}

function map_actors_by_id(array $actors): array {
  $map = [];
  foreach ($actors as $a) {
    $id = trim((string)($a['id'] ?? ''));
    $nm = trim((string)($a['name'] ?? ''));
    if ($id && $nm) $map[$id] = $nm;
  }
  return $map;
}

function y_m_d_stat(string $ym): array {
  [$y, $m] = array_map('intval', explode('-', $ym));
  return [$y, $m, cal_days_in_month(CAL_GREGORIAN, $m, $y)];
}

// lib/helpers.php のどこかに追記（他の *_get と同じ並び）

/** 総再生時間（時） */
function watch_hours_get(array $watchByDay, string $actorId, string $ym, int $d): float {
  if (!isset($watchByDay[$actorId][$ym][$d]['watch_hours'])) return 0.0;
  return (float)$watchByDay[$actorId][$ym][$d]['watch_hours'];
}

/** 総再生回数 */
function watch_views_get(array $watchByDay, string $actorId, string $ym, int $d): int {
  if (!isset($watchByDay[$actorId][$ym][$d]['views'])) return 0;
  return (int)$watchByDay[$actorId][$ym][$d]['views'];
}

/** インプレッション数 */
function watch_impressions_get(array $watchByDay, string $actorId, string $ym, int $d): int {
  if (!isset($watchByDay[$actorId][$ym][$d]['impressions'])) return 0;
  return (int)$watchByDay[$actorId][$ym][$d]['impressions'];
}

/* （任意）旧API 互換の薄いラッパ。新実装への移行期間用。 */
function watch_get(array $watchByDay, ?string $actorId, string $ym, int $d, string $key) {
  if (!$actorId) return 0;
  switch ($key) {
    case 'watch_hours':   return watch_hours_get($watchByDay, $actorId, $ym, $d);
    case 'views':         return watch_views_get($watchByDay, $actorId, $ym, $d);
    case 'impressions':   return watch_impressions_get($watchByDay, $actorId, $ym, $d);
    default:              return 0;
  }
}


if (!function_exists('inflow_get')) {
  function inflow_get(array $data, array $actorsById, string $aid, string $ym, int $d): int {
    $day = sprintf('%s-%02d', $ym, $d);

    if (!isset($data[$day])) {
      error_log("[inflow_get] {$day} → データなし");
      return 0;
    }

    // 役者名・チャンネル名の取得
    $actor = $actorsById[$aid] ?? null;
    $actorName = is_array($actor) ? ($actor['channel'] ?? $actor['name'] ?? null) : $actor;

    // ✅ 全体集計判定（IDまたは名前で判定）
    $isTotal = (
      $aid === '47dddb02-ad46-4aae-bdbd-6a37e142c3cc' ||
      $actorName === '全体集計' ||
      $actorName === '全体'
    );

    if ($isTotal) {
      if (is_array($data[$day])) {
        // _totalキー除外
        if (isset($data[$day]['_total'])) {
          error_log("[inflow_get] {$day} → _totalキー除外");
          unset($data[$day]['_total']);
        }

        $sum = array_sum(array_map('intval', $data[$day]));
        error_log("[inflow_get] 全体集計 {$day} → 合計={$sum}");
        return $sum;
      }

      $val = (int)$data[$day];
      error_log("[inflow_get] 全体集計 {$day} → 単一値={$val}");
      return $val;
    }

    // 通常チャンネル処理
    if (isset($data[$day][$aid])) {
      $val = (int)$data[$day][$aid];
      error_log("[inflow_get] 通常チャンネル={$aid}, {$day} → 値={$val}");
      return $val;
    }

    if ($actorName && isset($data[$day][$actorName])) {
      $val = (int)$data[$day][$actorName];
      error_log("[inflow_get] 通常チャンネル={$actorName}, {$day} → 値={$val}");
      return $val;
    }

    error_log("[inflow_get] {$day} → データなし (actor={$actorName})");
    return 0;
  }
}


if (!function_exists('denwa_get')) {
  function denwa_get(array $data, array $actorsById, string $aid, string $ym, int $d): int {
    $actor = $actorsById[$aid] ?? null;
    $actorName = is_array($actor) ? ($actor['channel'] ?? $actor['name'] ?? null) : $actor;
    $day = sprintf('%s-%02d', $ym, $d);

    // ✅ 全体集計判定
    $isTotal = (
      $aid === '47dddb02-ad46-4aae-bdbd-6a37e142c3cc' ||
      $actorName === '全体集計' ||
      $actorName === '全体'
    );

    if ($isTotal) {
      if (!isset($data[$day])) {
        error_log("[denwa_get] {$day} → データなし");
        return 0;
      }

      if (is_array($data[$day])) {
        // 🟡 _total除外処理
        if (isset($data[$day]['_total'])) {
          error_log("[denwa_get] {$day} → _totalキー除外");
          unset($data[$day]['_total']);
        }

        $sum = array_sum(array_map('intval', $data[$day]));
        error_log("[denwa_get] 全体集計 {$day} → 合計={$sum}");
        return $sum;
      }

      $val = (int)$data[$day];
      error_log("[denwa_get] 全体集計 {$day} → 単一値={$val}");
      return $val;
    }

    // 通常チャンネル処理
    if (!$actorName) return 0;
    $val = (int)($data[$day][$actorName] ?? 0);
    error_log("[denwa_get] 通常チャンネル={$actorName}, {$day} → 値={$val}");
    return $val;
  }
}


if (!function_exists('chosei_get')) {
  function chosei_get(array $data, array $actorsById, string $aid, string $ym, int $d): int {
    $actor = $actorsById[$aid] ?? null;
    $actorName = is_array($actor) ? ($actor['channel'] ?? $actor['name'] ?? null) : $actor;
    $day = sprintf('%s-%02d', $ym, $d);


    // ✅ 全体集計判定
    $isTotal = (
      $aid === '47dddb02-ad46-4aae-bdbd-6a37e142c3cc' ||
      $actorName === '全体集計' ||
      $actorName === '全体'
    );
    if ($isTotal) {
      if (!isset($data[$day])) return 0;

      // _total キーを除外
      if (is_array($data[$day])) {
        unset($data[$day]['_total']);

        $result = array_sum(array_map('intval', $data[$day]));
      } else {
        $result = (int)$data[$day];
      }

      return $result;
    }




    if (!$actorName) return 0;
    return (int)($data[$day][$actorName] ?? 0);
  }
}

/* ===== ヘルパ ===== */
function _alias_map_from_actors_items(array $items): array {
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

if (!function_exists('denwa_lost_get')) {
  function denwa_lost_get(array $data, array $actorsById, string $aid, string $ym, int $d): int {
    $actor = $actorsById[$aid] ?? null;
    $actorName = is_array($actor) ? ($actor['channel'] ?? $actor['name'] ?? null) : $actor;
    $day = sprintf('%s-%02d', $ym, $d);

    // 全体集計判定
    $isTotal = (
      $aid === '47dddb02-ad46-4aae-bdbd-6a37e142c3cc' ||
      $actorName === '全体集計' ||
      $actorName === '全体'
    );

    if ($isTotal) {
      if (!isset($data[$day])) return 0;
      if (is_array($data[$day])) {
        // 🔸 _total除外処理
        if (isset($data[$day]['_total'])) unset($data[$day]['_total']);
        $sum = array_sum(array_map('intval', $data[$day]));
        error_log("[denwa_lost_get] 全体集計 {$day} => {$sum}");
        return $sum;
      }
      return (int)$data[$day];
    }

    $val = (int)($data[$day][$actorName] ?? 0);
    return $val;
  }
}

if (!function_exists('taiou_get')) {
  function taiou_get(array $data, array $actorsById, string $aid, string $ym, int $d): int {
    $actor = $actorsById[$aid] ?? null;
    $actorName = is_array($actor) ? ($actor['channel'] ?? $actor['name'] ?? null) : $actor;
    $day = sprintf('%s-%02d', $ym, $d);

    $isTotal = (
      $aid === '47dddb02-ad46-4aae-bdbd-6a37e142c3cc' ||
      $actorName === '全体集計' ||
      $actorName === '全体'
    );

    if ($isTotal) {
      if (!isset($data[$day])) return 0;
      if (is_array($data[$day])) {
        if (isset($data[$day]['_total'])) unset($data[$day]['_total']);
        $sum = array_sum(array_map('intval', $data[$day]));
        error_log("[taiou_get] 全体集計 {$day} => {$sum}");
        return $sum;
      }
      return (int)$data[$day];
    }

    return (int)($data[$day][$actorName] ?? 0);
  }
}

if (!function_exists('seiyaku_get')) {
  function seiyaku_get(array $data, array $actorsById, string $aid, string $ym, int $d): int {
    $actor = $actorsById[$aid] ?? null;
    $actorName = is_array($actor) ? ($actor['channel'] ?? $actor['name'] ?? null) : $actor;
    $day = sprintf('%s-%02d', $ym, $d);

    $isTotal = (
      $aid === '47dddb02-ad46-4aae-bdbd-6a37e142c3cc' ||
      $actorName === '全体集計' ||
      $actorName === '全体'
    );

    if ($isTotal) {
      if (!isset($data[$day])) return 0;
      if (is_array($data[$day])) {
        if (isset($data[$day]['_total'])) unset($data[$day]['_total']);
        $sum = array_sum(array_map('intval', $data[$day]));
        error_log("[seiyaku_get] 全体集計 {$day} => {$sum}");
        return $sum;
      }
      return (int)$data[$day];
    }

    return (int)($data[$day][$actorName] ?? 0);
  }
}

if (!function_exists('nyukin_count_get')) {
  function nyukin_count_get(array $data, array $actorsById, string $aid, string $ym, int $d): int {
    $actor = $actorsById[$aid] ?? null;
    $actorName = is_array($actor) ? ($actor['channel'] ?? $actor['name'] ?? null) : $actor;
    $day = sprintf('%s-%02d', $ym, $d);

    $isTotal = (
      $aid === '47dddb02-ad46-4aae-bdbd-6a37e142c3cc' ||
      $actorName === '全体集計' ||
      $actorName === '全体'
    );

    if ($isTotal) {
      if (!isset($data[$day])) return 0;
      if (is_array($data[$day])) {
        if (isset($data[$day]['_total'])) unset($data[$day]['_total']);
        $sum = array_sum(array_map('intval', $data[$day]));
        error_log("[nyukin_count_get] 全体集計 {$day} => {$sum}");
        return $sum;
      }
      return (int)$data[$day];
    }

    return (int)($data[$day][$actorName] ?? 0);
  }
}

if (!function_exists('nyukin_amount_get')) {
  function nyukin_amount_get(array $data, array $actorsById, string $aid, string $ym, int $d): float {
    $actor = $actorsById[$aid] ?? null;
    $actorName = is_array($actor) ? ($actor['channel'] ?? $actor['name'] ?? null) : $actor;
    $day = sprintf('%s-%02d', $ym, $d);

    $isTotal = (
      $aid === '47dddb02-ad46-4aae-bdbd-6a37e142c3cc' ||
      $actorName === '全体集計' ||
      $actorName === '全体'
    );

    if ($isTotal) {
      if (!isset($data[$day])) return 0.0;
      if (is_array($data[$day])) {
        if (isset($data[$day]['_total'])) unset($data[$day]['_total']);
        $sum = array_sum(array_map('floatval', $data[$day]));
        error_log("[nyukin_amount_get] 全体集計 {$day} => {$sum}");
        return $sum;
      }
      return (float)$data[$day];
    }

    return (float)($data[$day][$actorName] ?? 0);
  }
}


// helpers.php に追加（未定義なら）
if (!function_exists('sales_rate_get')) {
  /**
   * セールス成約率 = 成約件数 / 対応件数 * 100
   * @return float パーセント値（0〜100）。分母0なら0。
   */
  function sales_rate_get(
    array $seiyakuByDay,
    array $taiouByDay,
    array $actorsById,
    string $aid,
    string $ym,
    int $d
  ): float {
    $actor = $actorsById[$aid] ?? null;
    $actorName = is_array($actor) ? ($actor['channel'] ?? $actor['name'] ?? null) : $actor;
    $day = sprintf('%s-%02d', $ym, $d);

    // ✅ 全体集計判定
    $isTotal = (
      $aid === '47dddb02-ad46-4aae-bdbd-6a37e142c3cc' ||
      $actorName === '全体集計' ||
      $actorName === '全体'
    );

    // ✅ 全体集計モード
    if ($isTotal) {
      if (!isset($seiyakuByDay[$day]) || !isset($taiouByDay[$day])) {
        error_log("[sales_rate_get] {$day} → データなし（全体集計）");
        return 0.0;
      }

      // _total除外処理
      if (isset($seiyakuByDay[$day]['_total'])) unset($seiyakuByDay[$day]['_total']);
      if (isset($taiouByDay[$day]['_total'])) unset($taiouByDay[$day]['_total']);

      // 日別全体の合計を計算
      $seiyaku = array_sum(array_map('intval', $seiyakuByDay[$day]));
      $taiou   = array_sum(array_map('intval', $taiouByDay[$day]));

      if ($taiou <= 0) return 0.0;
      $rate = ($seiyaku / $taiou) * 100.0;

      error_log("[sales_rate_get] 全体集計 {$day} → 成約={$seiyaku}, 対応={$taiou}, 成約率=" . number_format($rate, 2) . "%");
      return $rate;
    }

    // ✅ 通常チャンネル処理
    $seiyaku = (int)($seiyakuByDay[$day][$actorName] ?? 0);
    $taiou   = (int)($taiouByDay[$day][$actorName] ?? 0);

    if ($taiou <= 0) return 0.0;
    $rate = ($seiyaku / $taiou) * 100.0;

    error_log("[sales_rate_get] {$actorName} {$day} → 成約={$seiyaku}, 対応={$taiou}, 成約率=" . number_format($rate, 2) . "%");
    return $rate;
  }
}


/**
 * 期間合計の成約率（合計成約 / 合計対応 * 100）
 */
if (!function_exists('sales_rate_total')) {

  function sales_rate_total(
    array $seiyakuByDay,
    array $taiouByDay,
    array $actorsById,
    string $aid,
    string $ym,
    int $days
  ): float {

    // ✅ ログファイル出力先
    $logFile = __DIR__ . '/sales_rate_log.txt';

    // ✅ ログ出力用の小関数
    $write_log = function ($data) use ($logFile) {
      $timestamp = date('Y-m-d H:i:s');
      $text = "[$timestamp] " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
      file_put_contents($logFile, $text, FILE_APPEND);
    };

    // ==========================
    // デバッグ：配列構造を丸ごと確認
    // ==========================
    $write_log([
      'type' => 'debug_structure',
      'ym' => $ym,
      'aid' => $aid,
      'seiyakuByDay_keys' => array_keys($seiyakuByDay),
      'taiouByDay_keys' => array_keys($taiouByDay),
      // 👇 以下は1件目の中身サンプル（巨大すぎる配列を全部吐くのを防止）
      'sample_seiyaku' => reset($seiyakuByDay),
      'sample_taiou' => reset($taiouByDay)
    ]);

    // ==========================
    // 通常処理開始
    // ==========================
    $actor = $actorsById[$aid] ?? null;
    $actorName = is_array($actor) ? ($actor['channel'] ?? $actor['name'] ?? null) : $actor;

    $isTotal = (
      $aid === '47dddb02-ad46-4aae-bdbd-6a37e142c3cc' ||
      $actorName === '全体集計' ||
      $actorName === '全体'
    );

    $sumSei = 0;
    $sumTai = 0;

    for ($d = 1; $d <= $days; $d++) {
      $day = sprintf('%s-%02d', $ym, $d);

      // 🔍 日付存在チェックログ
      if (!isset($seiyakuByDay[$day]) || !isset($taiouByDay[$day])) {
        $write_log([
          'type' => 'missing_day',
          'day' => $day,
          'actorName' => $actorName,
          'exists_seiyaku' => isset($seiyakuByDay[$day]),
          'exists_taiou' => isset($taiouByDay[$day])
        ]);
        continue;
      }

      if ($isTotal) {
        if (isset($seiyakuByDay[$day]['_total'])) unset($seiyakuByDay[$day]['_total']);
        if (isset($taiouByDay[$day]['_total'])) unset($taiouByDay[$day]['_total']);

        $sumSei += array_sum(array_map('intval', $seiyakuByDay[$day]));
        $sumTai += array_sum(array_map('intval', $taiouByDay[$day]));
      } else {
        $sumSei += (int)($seiyakuByDay[$day][$actorName] ?? 0);
        $sumTai += (int)($taiouByDay[$day][$actorName] ?? 0);
      }

      // 🔹 日ごとの進行ログ
      $write_log([
        'type' => 'daily',
        'day' => $day,
        'actorName' => $actorName,
        'sumSei' => $sumSei,
        'sumTai' => $sumTai,
        'isTotal' => $isTotal
      ]);
    }

    if ($sumTai <= 0) {
      $write_log([
        'type' => 'warning',
        'actorName' => $actorName,
        'ym' => $ym,
        'message' => '分母ゼロ'
      ]);
      return 0.0;
    }

    $rate = ($sumSei / $sumTai) * 100.0;

    $write_log([
      'type' => 'result',
      'actorName' => $actorName,
      'ym' => $ym,
      'sumSei' => $sumSei,
      'sumTai' => $sumTai,
      'rate' => round($rate, 2)
    ]);

    return $rate;
  }
}



// /** 対応件数（sales別・日別） */
// function sales_taiou_get(array $salesDailyByDay, string $salesId, string $ym, int $d): int {
//   return (int)($salesDailyByDay[$salesId][$ym][$d]['taiou'] ?? 0);
// }
// /** 成約件数 */
// function sales_seiyaku_get(array $salesDailyByDay, string $salesId, string $ym, int $d): int {
//   return (int)($salesDailyByDay[$salesId][$ym][$d]['seiyaku'] ?? 0);
// }
// /** 入金件数 */
// function sales_nyukin_count_get(array $salesDailyByDay, string $salesId, string $ym, int $d): int {
//   return (int)($salesDailyByDay[$salesId][$ym][$d]['nyukin_count'] ?? 0);
// }
// /** 入金額 */
// function sales_nyukin_amount_get(array $salesDailyByDay, string $salesId, string $ym, int $d): int {
//   return (int)($salesDailyByDay[$salesId][$ym][$d]['nyukin_amount'] ?? 0);
// }

// 既存の sales_count_get をこの頑健版に置換
// helpers.php 内の sales_count_get を置換
if (!function_exists('sales_count_get')) {
  /**
   * セールス件数取得
   * 全体集計（俳優未指定）の場合は全俳優合計。
   * _totalキー除外・安全チェック付き。
   */
  function sales_count_get(array $cube, string $salesId, ?string $actorId, string $ym, int $d): int {
    if (!isset($cube[$salesId]) || !is_array($cube[$salesId])) return 0;
    $S = $cube[$salesId];

    // YM候補: 入力そのまま / 0詰め / 非0詰め
    $ymKeys = [$ym];
    $parts = explode('-', $ym);
    if (count($parts) === 2) {
      $y = (int)$parts[0];
      $m = (int)$parts[1];
      foreach ([sprintf('%04d-%02d', $y, $m), sprintf('%04d-%d', $y, $m)] as $cand) {
        if (!in_array($cand, $ymKeys, true)) $ymKeys[] = $cand;
      }
    }

    // day候補（int / "int"）
    $dKeys = [$d, (string)$d];

    // YM/day の最初の一致だけ返す
    $pickOne = function(array $byYm) use ($ymKeys, $dKeys): int {
      foreach ($ymKeys as $yk) {
        if (!isset($byYm[$yk]) || !is_array($byYm[$yk])) continue;
        foreach ($dKeys as $dk) {
          if (array_key_exists($dk, $byYm[$yk])) return (int)$byYm[$yk][$dk];
        }
      }
      return 0;
    };

    // ✅ 全体集計判定（actorIdが空 or 全体集計）
    $isTotal = (
      $actorId === null ||
      $actorId === '' ||
      $actorId === '47dddb02-ad46-4aae-bdbd-6a37e142c3cc'
    );

    // ✅ 俳優未指定 → 全俳優合算（_totalキー除外）
    if ($isTotal) {
      $sum = 0;
      foreach ($S as $actorKey => $byYm) {
        if ($actorKey === '_total' || !is_array($byYm)) continue; // 🔸 _total除外
        $sum += $pickOne($byYm);
      }
      return $sum;
    }

    // ✅ 個別俳優の場合
    return (isset($S[$actorId]) && is_array($S[$actorId])) ? $pickOne($S[$actorId]) : 0;
  }
}




// helpers.php（未定義なら追加・既存が古ければ置換）
if (!function_exists('sales_seiyaku_count_get')) {
  function sales_seiyaku_count_get(array $cube, string $salesId, ?string $actorId, string $ym, int $d): int {
    if (!isset($cube[$salesId]) || !is_array($cube[$salesId])) return 0;
    $S = $cube[$salesId];

    // YM候補（最初の一致のみ採用）
    $ymKeys = [$ym];
    $parts = explode('-', $ym);
    if (count($parts) === 2) {
      $y = (int)$parts[0];
      $m = (int)$parts[1];
      foreach ([sprintf('%04d-%02d', $y, $m), sprintf('%04d-%d', $y, $m)] as $cand) {
        if (!in_array($cand, $ymKeys, true)) $ymKeys[] = $cand;
      }
    }

    $dKeys = [$d, (string)$d];

    // ▼ 日付ごとの値を1件だけ取得
    $pickOne = function(array $byYm) use ($ymKeys, $dKeys): int {
      foreach ($ymKeys as $yk) {
        if (!isset($byYm[$yk]) || !is_array($byYm[$yk])) continue;
        foreach ($dKeys as $dk) {
          if (array_key_exists($dk, $byYm[$yk])) {
            return (int)$byYm[$yk][$dk];
          }
        }
      }
      return 0;
    };

    // ✅ 全体集計判定
    $isTotal = (
      $actorId === null ||
      $actorId === '' ||
      $actorId === '47dddb02-ad46-4aae-bdbd-6a37e142c3cc'
    );

    // ✅ 全体集計モード：全俳優合算（_total除外）
    if ($isTotal) {
      $sum = 0;
      foreach ($S as $actorKey => $byYm) {
        if ($actorKey === '_total' || !is_array($byYm)) continue; // _total除外
        $sum += $pickOne($byYm);
      }
      return $sum;
    }

    // ✅ 通常俳優モード
    return (isset($S[$actorId]) && is_array($S[$actorId])) ? $pickOne($S[$actorId]) : 0;
  }
}


// === helpers.php ===
// ※ 既存の sales_nyukin_count_get を完全に置き換える（function_exists ガードは付けない）



// 入金件数 getter（sales_count_get と同構造）
if (!function_exists('sales_nyukin_count_get')) {
  function sales_nyukin_count_get(array $cube, string $salesId, ?string $actorId, string $ym, int $d): int {
    if (!isset($cube[$salesId]) || !is_array($cube[$salesId])) return 0;
    $S = $cube[$salesId];

    // YM候補
    $ymKeys = [$ym];
    $parts = explode('-', $ym);
    if (count($parts) === 2) {
      $y = (int)$parts[0];
      $m = (int)$parts[1];
      foreach ([sprintf('%04d-%02d', $y, $m), sprintf('%04d-%d', $y, $m)] as $cand) {
        if (!in_array($cand, $ymKeys, true)) $ymKeys[] = $cand;
      }
    }

    // 日付候補
    $dKeys = [$d, (string)$d];

    // 日ごとの値を1件だけ取得
    $pickOne = function(array $byYm) use ($ymKeys, $dKeys): int {
      foreach ($ymKeys as $yk) {
        if (!isset($byYm[$yk]) || !is_array($byYm[$yk])) continue;
        foreach ($dKeys as $dk) {
          if (array_key_exists($dk, $byYm[$yk])) {
            return (int)$byYm[$yk][$dk];
          }
        }
      }
      return 0;
    };

    // ✅ 全体集計判定
    $isTotal = (
      $actorId === null ||
      $actorId === '' ||
      $actorId === '47dddb02-ad46-4aae-bdbd-6a37e142c3cc'
    );

    // ✅ 全体集計モード（_total除外）
    if ($isTotal) {
      $sum = 0;
      foreach ($S as $actorKey => $byYm) {
        if ($actorKey === '_total' || !is_array($byYm)) continue; // 🔸 _total除外
        $sum += $pickOne($byYm);
      }
      return $sum;
    }

    // ✅ 通常チャンネル処理
    return (isset($S[$actorId]) && is_array($S[$actorId])) ? $pickOne($S[$actorId]) : 0;
  }
}

?>
<?php
if (!function_exists('nyukin_count_pick_all')) {
  /**
   * 入金件数取得（全体集計対応版）
   * データ構造：
   *  - 形A: [sid][actor_id][ym][d]
   *  - 形B: [sid][ym][d]
   */
  function nyukin_count_pick_all(array $cube, string $sid, string $ym, int $d, ?string $actorId=null): int {
    if (!isset($cube[$sid]) || !is_array($cube[$sid])) return 0;
    $S = $cube[$sid];

    // YM 正規化候補（全角/スラッシュ/ドット → 半角ハイフン, 2025-9/2025-09 両対応）
    $norm = function(string $ym): array {
      $x = str_replace(['ー','−','–','—','／','/','.'], ['-','-','-','-','-','-','-'], trim($ym));
      $x = preg_replace('/\s+/u', '', $x);
      if (preg_match('/^(\d{4})-(\d{1,2})$/u', $x, $m)) {
        $Y = (int)$m[1]; $M = (int)$m[2];
        return [sprintf('%04d-%02d', $Y, $M), sprintf('%04d-%d', $Y, $M)];
      }
      return [$x];
    };
    $ymCands = $norm($ym);
    $dCands  = [$d, (string)$d];

    // ✅ 形B: [sid][ym][d]（俳優階層なし）
    foreach ($ymCands as $yk) {
      if (isset($S[$yk]) && is_array($S[$yk])) {
        foreach ($dCands as $dk) {
          if (isset($S[$yk][$dk])) return (int)$S[$yk][$dk];
        }
      }
    }

    // ✅ 形A: [sid][actor_id][ym][d]（俳優階層あり）

    // ▶ 俳優が指定されている場合（個別集計）
    if ($actorId !== null && $actorId !== '') {
      $A = $S[$actorId] ?? null;
      if (is_array($A)) {
        foreach ($ymCands as $yk) {
          if (isset($A[$yk]) && is_array($A[$yk])) {
            foreach ($dCands as $dk) {
              if (isset($A[$yk][$dk])) return (int)$A[$yk][$dk];
            }
          }
        }
      }
      return 0;
    }

    // ▶ 俳優未指定（全体集計モード）
    $sum = 0;
    foreach ($S as $actorKey => $byYm) {
      if ($actorKey === '_total' || !is_array($byYm)) continue; // 🔸 _total除外
      foreach ($ymCands as $yk) {
        if (!isset($byYm[$yk]) || !is_array($byYm[$yk])) continue;
        foreach ($dCands as $dk) {
          if (isset($byYm[$yk][$dk])) $sum += (int)$byYm[$yk][$dk];
        }
      }
    }

    return $sum;
  }
}

// helpers.php に追記（または既存の同名をこの内容に置換）

if (!function_exists('sales_nyukin_amount_get')) {
  function sales_nyukin_amount_get(array $cube, string $salesId, ?string $actorId, string $ym, int $d): float {
    if (!isset($cube[$salesId]) || !is_array($cube[$salesId])) return 0.0;
    $S = $cube[$salesId];

    // YM候補（0詰め・非0詰め両対応）
    $ymKeys = [$ym];
    $parts = explode('-', $ym);
    if (count($parts) === 2) {
      $y = (int)$parts[0];
      $m = (int)$parts[1];
      foreach ([sprintf('%04d-%02d', $y, $m), sprintf('%04d-%d', $y, $m)] as $cand) {
        if (!in_array($cand, $ymKeys, true)) $ymKeys[] = $cand;
      }
    }

    // day候補（int / "int"）
    $dKeys = [$d, (string)$d];

    // ✅ 日ごとの値を1件だけ取得
    $pickOne = function(array $byYm) use ($ymKeys, $dKeys): float {
      foreach ($ymKeys as $yk) {
        if (!isset($byYm[$yk]) || !is_array($byYm[$yk])) continue;
        foreach ($dKeys as $dk) {
          if (array_key_exists($dk, $byYm[$yk])) {
            return (float)$byYm[$yk][$dk];
          }
        }
      }
      return 0.0;
    };

    // ✅ 全体集計判定
    $isTotal = (
      $actorId === null ||
      $actorId === '' ||
      $actorId === '47dddb02-ad46-4aae-bdbd-6a37e142c3cc'
    );

    // ✅ 全体集計モード（_total除外）
    if ($isTotal) {
      $sum = 0.0;
      foreach ($S as $actorKey => $byYm) {
        if ($actorKey === '_total' || !is_array($byYm)) continue; // 🔸 _total除外
        $sum += $pickOne($byYm);
      }
      return $sum;
    }

    // ✅ 通常俳優モード
    return (isset($S[$actorId]) && is_array($S[$actorId])) ? $pickOne($S[$actorId]) : 0.0;
  }
}
