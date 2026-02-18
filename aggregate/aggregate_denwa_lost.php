<?php
// aggregate/aggregate_denwa_lost.php
declare(strict_types=1);

/* ===== 内部ユーティリティ ===== */

function _adl_pick_actor_name(array $row): string {
    // 電話系でも「動画担当」を最優先
    foreach (['動画担当','user','actor','担当','担当者','セールス担当','sales_user','sales','name'] as $k) {
        if (array_key_exists($k, $row)) {
            $v = trim((string)$row[$k]);
            if ($v !== '') return $v;
        }
    }
    return '';
}

function _adl_infer_year_month(array $row): array {
    if (!empty($row['セールス年月']) && preg_match('/(\d{4})\D+(\d{1,2})/', (string)$row['セールス年月'], $m)) {
        return [intval($m[1]), intval($m[2])];
    }
    return [intval(date('Y')), intval(date('n'))];
}

function _adl_normalize_mdy_with_infer(string $md, array $row): string {
    $md = trim($md);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $md)) return $md;
    if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $md, $m)) {
        [$y] = _adl_infer_year_month($row);
        return sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]);
    }
    return '';
}

function _adl_is_valid_day(string $ymd): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
    $ts = strtotime($ymd);
    return $ts !== false && $ts <= time();
}

function _adl_pick_date_for_denwa(array $row): string {
    if (!empty($row['LINE登録日'])) {
        $ymd = _adl_normalize_mdy_with_infer((string)$row['LINE登録日'], $row);
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

/* ===== actor profiles ===== */
function _adl_build_actor_profiles(array $actorsItems): array {
    $profiles = [];
    foreach ($actorsItems as $a) {
        $name = trim((string)($a['name'] ?? ''));
        if ($name === '') continue;

        $type = trim((string)($a['type'] ?? ''));
        $systems = [];

        if (!empty($a['systems'])) {
            foreach (preg_split('/\s*[,，、|／\/]\s*/u', (string)$a['systems']) as $s) {
                $s = trim($s);
                if ($s !== '') $systems[] = $s;
            }
        }

        $profiles[$name] = [
            'type'    => $type,
            'systems' => $systems,
        ];
    }
    return $profiles;
}

/* ===== 本体：電話前失注（新仕様） ===== */
/**
 * 状態="電話前失注" を新仕様で日別集計
 * 出力: "YYYY-MM-DD" => ["_total"=>int, "担当A"=>int, ...]
 */
function build_denwa_lost_by_day(string $DATA_DIR, string $CACHE_DIR): array {
    $res = [];

    $rawPath    = rtrim($CACHE_DIR, '/').'/raw_rows.json';
    $actorsPath = rtrim($DATA_DIR,  '/').'/actors.json';
    if (!is_file($rawPath) || !is_file($actorsPath)) return $res;

    $raw    = json_decode((string)file_get_contents($rawPath), true) ?: [];
    $actors = json_decode((string)file_get_contents($actorsPath), true) ?: [];

    $rows       = $raw['rows']   ?? $raw['items']   ?? [];
    $actorsList = $actors['items'] ?? [];

    $profiles = _adl_build_actor_profiles($actorsList);

    foreach ($rows as $row) {

        // 状態：電話前失注のみ
        if (trim((string)($row['状態'] ?? '')) !== '電話前失注') continue;

        $videoActor = _adl_pick_actor_name($row);
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
            // その他 actor
            else {
                if ($videoActor !== $actorName) continue;
            }

            /* ===== 共通条件 ===== */

            // 入口（type）
            if (!empty($profile['type'])) {
                if (trim((string)($row['入口'] ?? '')) !== $profile['type']) continue;
            }

            // システム名
            $systemName = trim((string)($row['システム名'] ?? ''));
            if ($systemName === '') continue;

            if (!empty($profile['systems'])) {
                if (!in_array($systemName, $profile['systems'], true)) continue;
            }

            // 日付
            $ymd = _adl_pick_date_for_denwa($row);
            if ($ymd === '' || !_adl_is_valid_day($ymd)) continue;

            // 加算
            if (!isset($res[$ymd])) $res[$ymd] = [];
            $res[$ymd][$actorName] = ($res[$ymd][$actorName] ?? 0) + 1;
            $res[$ymd]['_total']   = ($res[$ymd]['_total']   ?? 0) + 1;
        }
    }

    ksort($res);
    return $res;
}

/* ===== 自動配線（後方互換） ===== */
if (!defined('AGGREGATE_DENWA_LOST_NO_AUTO')) {
    if (isset($DATA_DIR, $CACHE_DIR)) {
        try {
            $denwaLostByDay = build_denwa_lost_by_day((string)$DATA_DIR, (string)$CACHE_DIR);
        } catch (\Throwable $e) {
            if (!isset($denwaLostByDay)) $denwaLostByDay = [];
        }
    }
}
