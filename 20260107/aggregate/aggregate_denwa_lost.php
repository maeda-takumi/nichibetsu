<?php
// aggregate/aggregate_denwa_lost.php
declare(strict_types=1);

/* ===== 内部ユーティリティ ===== */
if (!function_exists('_adl_alias_map_from_actors_items')) {
  function _adl_alias_map_from_actors_items(array $items): array {
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

function _adl_pick_actor_name(array $row): string {
    // 俳優カードに寄せるため「動画担当」を最優先
    $candidates = ['動画担当','user','actor','担当','担当者','セールス担当','sales_user','sales','name'];
    foreach ($candidates as $k) {
        if (array_key_exists($k, $row)) {
            $v = trim((string)$row[$k]);
            if ($v !== '') return $v;
        }
    }
    return '';
}

if (!function_exists('_adl_infer_year_month')) {
  function _adl_infer_year_month(array $row): array {
      $m = [];
      if (!empty($row['セールス年月']) && preg_match('/(\d{4})\D+(\d{1,2})/', (string)$row['セールス年月'], $m)) {
          return [intval($m[1]), intval($m[2])];
      }
      if (!empty($row['データ4']) && preg_match('/^(\d{4})(\d{2})$/', (string)$row['データ4'], $m)) {
          return [intval($m[1]), intval($m[2])];
      }
      return [intval(date('Y')), intval(date('n'))];
  }
}

if (!function_exists('_adl_normalize_mdy_with_infer')) {
  function _adl_normalize_mdy_with_infer(string $md, array $row): string {
      $md = trim($md);
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $md)) return $md;
      $m = [];
      if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/', $md, $m)) {
          $mm = intval($m[1]); $dd = intval($m[2]); $yy = null;
          if (!empty($m[3])) { $y = intval($m[3]); $yy = ($y < 100) ? (2000 + $y) : $y; }
          if ($yy === null) { [$iy, $_] = _adl_infer_year_month($row); $yy = $iy; }
          if (checkdate($mm, $dd, $yy)) return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
      }
      return '';
  }
}

if (!function_exists('_adl_is_valid_day')) {
  function _adl_is_valid_day(string $ymd): bool {
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
      $ts = strtotime($ymd);
      if ($ts === false) return false;
      if ($ts < strtotime('2000-01-01')) return false; // 未来/過去の異常値避け
      if ($ts > time()) return false;
      return true;
  }
}

if (!function_exists('_adl_pick_date_for_denwa')) {
  function _adl_pick_date_for_denwa(array $row): string {
      // 電話系は“いつ発生したか”として、まず LINE登録日、なければ汎用日時を採用
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
}

if (!function_exists('_adl_build_actor_profiles')) {
  function _adl_build_actor_profiles(array $actorsItems): array {
      // actors.json の各 item から [name => ['type'=>..., 'systems'=>[...]]] を作る
      $profiles = [];
      foreach ($actorsItems as $a) {
          $name = trim((string)($a['name'] ?? ''));
          if ($name === '') continue;
          // 旧tagsから移行している場合でも、type/systems が最優先
          $type    = trim((string)($a['type'] ?? '')); // 入口: 副業/投資 など
          $systems = [];
          if (!empty($a['systems'])) {
              if (is_array($a['systems'])) {
                  $systems = array_values(array_filter(array_map('trim', $a['systems']), fn($x)=>$x!==''));
              } else {
                  // カンマ区切りで来る場合もある想定
                  $systems = array_values(array_filter(array_map('trim', explode(',', (string)$a['systems'])), fn($x)=>$x!==''));
              }
          }
          // 旧: tags フォールバックは必要ならここで
          $profiles[$name] = ['type'=>$type, 'systems'=>$systems];
      }
      return $profiles;
  }
}

/* ===== 本体：電話前失注 ===== */
/**
 * 状態="電話前失注" を、actors.json の type/systems に合わせて集計する。
 * 出力: "YYYY-MM-DD" => ["_total"=>int, "担当A"=>int, ...]
 */
function build_denwa_lost_by_day(string $DATA_DIR, string $CACHE_DIR): array {
    $res = [];

    $rawPath    = rtrim($CACHE_DIR, '/').'/raw_rows.json';   // ← 電話系は cache/raw_rows.json を見る想定
    $actorsPath = rtrim($DATA_DIR,  '/').'/actors.json';

    if (!is_file($rawPath) || !is_file($actorsPath)) return $res;

    $raw    = json_decode((string)file_get_contents($rawPath), true);
    $actors = json_decode((string)file_get_contents($actorsPath), true);

    $rows       = (isset($raw['rows'])    && is_array($raw['rows']))    ? $raw['rows']
                 :((isset($raw['items'])  && is_array($raw['items']))   ? $raw['items'] : []);
    $actorsList = (isset($actors['items'])&& is_array($actors['items']))? $actors['items'] : [];

    $aliasMap  = _adl_alias_map_from_actors_items($actorsList);
    $profiles  = _adl_build_actor_profiles($actorsList); // [name => ['type'=>..., 'systems'=>[...]]]

    foreach ($rows as $row) {
        // 状態＝電話前失注
        $state = trim((string)($row['状態'] ?? ''));
        if ($state !== '電話前失注') continue;

        // セールス担当（または候補）を抽出
        $rawName = _adl_pick_actor_name($row);
        if ($rawName === '') continue;

        $name = $aliasMap[$rawName] ?? $rawName; // alias 正規化
        // プロファイル（入口タイプ・対象システム）による絞り込み
        $prof = $profiles[$name] ?? ['type'=>'','systems'=>[]];
        $requiredType = (string)($prof['type'] ?? '');
        $requiredSys  = (array) ($prof['systems'] ?? []);
        
        // 対象のシステム名に一致するものだけ処理
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

        // 入口（type）は必須：一致しなければ除外
        $rowType = trim((string)($row['入口'] ?? ''));
        if ($requiredType !== '' && $rowType !== $requiredType) {
            continue;
        }
        // システム名（systems）：指定があれば OR 条件で少なくとも1つ一致
        if (!empty($requiredSys)) {
            $rowSys = trim((string)($row['システム名'] ?? ''));
            if ($rowSys === '') continue;
            $ok = false;
            // 入力がカンマ区切りだった場合も分割して判定
            $rowSysList = array_map('trim', explode(',', $rowSys));
            foreach ($rowSysList as $rs) {
                if (in_array($rs, $requiredSys, true)) { $ok = true; break; }
            }
            if (!$ok) continue;
        }

        // 日付
        $ymd = _adl_pick_date_for_denwa($row);
        if ($ymd === '' || !_adl_is_valid_day($ymd)) continue;

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

/* ===== 後方互換：requireしただけで $denwaLostByDay を用意（任意） =====
 * 無効化: define('AGGREGATE_DENWA_LOST_NO_AUTO', true);
 */
if (!defined('AGGREGATE_DENWA_LOST_NO_AUTO')) {
    if (isset($DATA_DIR) && isset($CACHE_DIR)) {
        try {
            /** @var array $denwaLostByDay */
            $denwaLostByDay = build_denwa_lost_by_day((string)$DATA_DIR, (string)$CACHE_DIR);
        } catch (\Throwable $e) {
            if (!isset($denwaLostByDay)) $denwaLostByDay = [];
        }
    }
}
