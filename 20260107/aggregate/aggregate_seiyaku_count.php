<?php
// aggregate/aggregate_seiyaku_count.php
declare(strict_types=1);

/* ===== 共通ユーティリティ（prefix: _asc_*） ===== */
if (!function_exists('_asc_alias_map_from_actors_items')) {
  function _asc_alias_map_from_actors_items(array $items): array {
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
if (!function_exists('_asc_pick_actor_name')) {
  // 俳優カードに揃えるため「動画担当」を最優先。無ければセールス担当など。
  function _asc_pick_actor_name(array $row): string {
    $candidates = ['動画担当','user','actor','担当','担当者','セールス担当','sales_user','sales','name'];
    foreach ($candidates as $k) {
      if (array_key_exists($k, $row)) {
        $v = trim((string)$row[$k]);
        if ($v !== '') return $v;
      }
    }
    return '';
  }
}
if (!function_exists('_asc_infer_year_month')) {
  function _asc_infer_year_month(array $row): array {
    if (!empty($row['セールス年月']) && preg_match('/(\d{4})\D+(\d{1,2})/', (string)$row['セールス年月'], $m)) {
      return [intval($m[1]), intval($m[2])];
    }
    if (!empty($row['データ4']) && preg_match('/^(\d{4})(\d{2})$/', (string)$row['データ4'], $m)) {
      return [intval($m[1]), intval($m[2])];
    }
    return [intval(date('Y')), intval(date('n'))];
  }
}
if (!function_exists('_asc_normalize_mdy_with_infer')) {
  function _asc_normalize_mdy_with_infer(string $md, array $row): string {
    $md = trim($md);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $md)) return $md;
    if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/', $md, $m)) {
      $mm = intval($m[1]); $dd = intval($m[2]); $yy = null;
      if (!empty($m[3])) { $y = intval($m[3]); $yy = ($y < 100) ? (2000 + $y) : $y; }
      if ($yy === null) { [$iy, ] = _asc_infer_year_month($row); $yy = $iy; }
      if (checkdate($mm, $dd, $yy)) return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
    }
    return '';
  }
}
if (!function_exists('_asc_is_valid_day')) {
  function _asc_is_valid_day(string $ymd): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
    $ts = strtotime($ymd);
    if ($ts === false) return false;
    if ($ts < strtotime('2000-01-01')) return false;
    if ($ts > time()) return false;
    return true;
  }
}
if (!function_exists('_asc_pick_date_for_row')) {
  function _asc_pick_date_for_row(array $row): string {
    if (!empty($row['LINE登録日'])) {
      $ymd = _asc_normalize_mdy_with_infer((string)$row['LINE登録日'], $row);
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
if (!function_exists('_asc_build_actor_profiles')) {
  function _asc_build_actor_profiles(array $actorsItems): array {
    $profiles = [];
    foreach ($actorsItems as $a) {
      $name = trim((string)($a['name'] ?? ''));
      if ($name === '') continue;
      $type    = trim((string)($a['type'] ?? '')); // 入口
      $systems = [];
      if (!empty($a['systems'])) {
        if (is_array($a['systems'])) {
          $systems = array_values(array_filter(array_map('trim', $a['systems']), fn($x)=>$x!==''));
        } else {
          $systems = array_values(array_filter(array_map('trim', explode(',', (string)$a['systems'])), fn($x)=>$x!==''));
        }
      }
      $profiles[$name] = ['type'=>$type, 'systems'=>$systems];
    }
    return $profiles;
  }
}

/* ===== 本体：成約件数（日別） =====
 * 条件: 状態 = '入金済み'
 * 入口(type)/システム名(systems) は actors.json に従って適合した行のみ集計
 * 出力: "YYYY-MM-DD" => ["_total"=>int, "担当A"=>int, ...]
 */
function build_seiyaku_by_day(string $DATA_DIR, string $CACHE_DIR): array {
  $res = [];

  $rawPath    = rtrim($CACHE_DIR,'/').'/raw_rows.json';
  $actorsPath = rtrim($DATA_DIR, '/').'/actors.json';
  if (!is_file($rawPath) || !is_file($actorsPath)) return $res;

  $raw    = json_decode((string)file_get_contents($rawPath), true);
  $actors = json_decode((string)file_get_contents($actorsPath), true);

  $rows       = (isset($raw['rows']) && is_array($raw['rows'])) ? $raw['rows']
              : ((isset($raw['items']) && is_array($raw['items'])) ? $raw['items'] : []);
  $actorsList = (isset($actors['items']) && is_array($actors['items'])) ? $actors['items'] : [];

  $aliasMap = _asc_alias_map_from_actors_items($actorsList);
  $profiles = _asc_build_actor_profiles($actorsList);

  foreach ($rows as $row) {
    $state = trim((string)($row['状態'] ?? ''));
    if ($state !== '入金済み') continue;

    // 支払い何回目が "1" または "" のみ対象
    $shiharai = (string)($row['支払い何回目'] ?? '');
    if (!($shiharai === '1' || $shiharai === '')) continue;

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

    
    // 担当名（動画担当優先、無ければセールス担当など）
    $rawName = _asc_pick_actor_name($row);
    if ($rawName === '' && !empty($row['セールス担当'])) {
      $rawName = trim((string)$row['セールス担当']);
    }
    if ($rawName === '') continue;

    $name = $aliasMap[$rawName] ?? $rawName;

    // プロファイル（入口 / システム名）の適合
    $prof = $profiles[$name] ?? ['type'=>'','systems'=>[]];
    $requiredType = (string)($prof['type'] ?? '');
    $requiredSys  = (array) ($prof['systems'] ?? []);

    $rowType = trim((string)($row['入口'] ?? ''));
    if ($requiredType !== '' && $rowType !== $requiredType) continue;

    if (!empty($requiredSys)) {
      $rowSys = trim((string)($row['システム名'] ?? ''));
      if ($rowSys === '') continue;
      $rowSysList = array_map('trim', explode(',', $rowSys));
      $ok = false;
      foreach ($rowSysList as $rs) {
        if (in_array($rs, $requiredSys, true)) { $ok = true; break; }
      }
      if (!$ok) continue;
    }

    // 日付キー
    $ymd = _asc_pick_date_for_row($row);
    if ($ymd === '' || !_asc_is_valid_day($ymd)) continue;

    // 加算
    if (!isset($res[$ymd])) $res[$ymd] = [];
    if (!isset($res[$ymd][$name])) $res[$ymd][$name] = 0;
    $res[$ymd][$name]++;
    if (!isset($res[$ymd]['_total'])) $res[$ymd]['_total'] = 0;
    $res[$ymd]['_total']++;
  }

  // ここから追記：月内の欠損日を自動補完（当月は「今日」まで、過去月は月末まで）
  ksort($res);

  // その月に1日でも成約がある月を列挙
  $months = [];
  foreach (array_keys($res) as $ymd) {
    $months[substr($ymd, 0, 7)] = true; // 'YYYY-MM'
  }

  $todayYmd = date('Y-m-d');
  $todayYm  = substr($todayYmd, 0, 7);
  $todayD   = (int)substr($todayYmd, 8, 2);

  foreach (array_keys($months) as $ym) {
    $y  = (int)substr($ym, 0, 4);
    $m  = (int)substr($ym, 5, 2);
    $md = cal_days_in_month(CAL_GREGORIAN, $m, $y);
    $last = ($ym === $todayYm) ? $todayD : $md; // 未来日は作らない

    for ($d = 1; $d <= $last; $d++) {
      $day = sprintf('%s-%02d', $ym, $d);
      if (!isset($res[$day])) $res[$day] = []; // 成約0件でもキーを確保
    }
  }

  ksort($res);
  return $res;
}


/* ===== 後方互換：requireだけで $seiyakuByDay を供給（任意） =====
   無効化: define('AGGREGATE_SEIYAKU_NO_AUTO', true); */
if (!defined('AGGREGATE_SEIYAKU_NO_AUTO')) {
  if (isset($DATA_DIR) && isset($CACHE_DIR)) {
    try {
      /** @var array $seiyakuByDay */
      $seiyakuByDay = build_seiyaku_by_day((string)$DATA_DIR, (string)$CACHE_DIR);
    } catch (\Throwable $e) {
      if (!isset($seiyakuByDay)) $seiyakuByDay = [];
    }
  }
}
