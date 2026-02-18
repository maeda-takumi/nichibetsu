<?php
// aggregate_denwa_wait.php
declare(strict_types=1);

/* ===== Debug (?debug_denwa=1) ===== */
function _dbg_dw($label,$val){
  if (!empty($_GET['debug_denwa'])){
    echo "<pre style='background:#013;color:#fff;padding:6px;margin:4px 0'><b>{$label}</b>\n";
    if(is_bool($val)) var_export($val); else print_r($val);
    echo "</pre>";
  }
}

/* ===== Common helpers (独立) ===== */

function _dw_pick_video_actor(array $row): string {
  // 電話系でも「動画担当」を最優先（なければ候補）
  foreach (['動画担当','actor','user','担当','担当者','sales_user','sales','name'] as $k) {
    if (array_key_exists($k, $row)) {
      $v = trim((string)$row[$k]);
      if ($v !== '') return $v;
    }
  }
  return '';
}

function _dw_infer_year_month(array $row): array {
  if (!empty($row['セールス年月']) && preg_match('/(\d{4})\D+(\d{1,2})/', (string)$row['セールス年月'], $m)) {
    return [intval($m[1]), intval($m[2])];
  }
  if (!empty($row['データ4']) && preg_match('/^(\d{4})(\d{2})$/', (string)$row['データ4'], $m)) {
    return [intval($m[1]), intval($m[2])];
  }
  return [intval(date('Y')), intval(date('n'))];
}

function _dw_normalize_mdy_with_infer(string $md, array $row): string {
  $md = trim($md);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $md)) return $md;

  if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/', $md, $m)) {
    $mm = intval($m[1]); $dd = intval($m[2]); $yy = null;

    if (!empty($m[3])) {
      $y = intval($m[3]);
      $yy = ($y < 100) ? (2000 + $y) : $y;
    }
    if ($yy === null) {
      [$iy, $_] = _dw_infer_year_month($row);
      $yy = $iy;
    }
    if (checkdate($mm, $dd, $yy)) return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
  }
  return '';
}

function _dw_is_valid_day(string $ymd): bool {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
  $ts = strtotime($ymd);
  if ($ts === false) return false;
  if ($ts < strtotime('2000-01-01')) return false;
  if ($ts > time()) return false;
  return true;
}

function _dw_smart_split(string $s): array {
  $s = trim($s);
  if ($s === '') return [];
  $parts = preg_split('/\s*[,，、|／\/]\s*/u', $s);
  $out = [];
  foreach ($parts as $p) {
    $p = trim($p);
    if ($p !== '') $out[] = $p;
  }
  return array_values(array_unique($out));
}

/* 俳優プロファイル: name => ['type'=>'副業|投資','systems'=>[]] */
function _dw_actor_profiles(array $items): array {
  $profiles = [];
  foreach ($items as $a) {
    $name = trim((string)($a['name'] ?? ''));
    if ($name === '') continue;

    // type
    $type = null;
    if (isset($a['type'])) {
      $t = trim((string)$a['type']);
      if ($t !== '') $type = ($t === '投資') ? '投資' : '副業';
    }
    if ($type === null) {
      $tags = is_array($a['tags'] ?? null) ? $a['tags'] : [];
      $joined = mb_strtolower(implode(' ', array_map(fn($t)=>trim((string)$t), $tags)));
      if ($joined !== '' && (str_contains($joined, '投資') || str_contains($joined, 'type=投資'))) {
        $type = '投資';
      }
    }
    if ($type === null) $type = '副業';

    // systems
    $systems = [];
    if (isset($a['systems'])) {
      if (is_array($a['systems'])) {
        foreach ($a['systems'] as $s) { $s = trim((string)$s); if ($s!=='') $systems[]=$s; }
      } else {
        foreach (_dw_smart_split((string)$a['systems']) as $s) $systems[] = $s;
      }
    }
    if (!$systems) {
      $tags = is_array($a['tags'] ?? null) ? $a['tags'] : [];
      foreach ($tags as $tg) {
        $tg = trim((string)$tg);
        if (preg_match('/^systems\s*=\s*(.+)$/ui', $tg, $m) || preg_match('/^system\s*:\s*(.+)$/ui', $tg, $m)) {
          foreach (_dw_smart_split($m[1]) as $s) $systems[] = $s;
        }
      }
    }
    if (!$systems) $systems = ['副業フロント','ChatGPTフロント','TikTokフロント'];

    $profiles[$name] = ['type'=>$type, 'systems'=>array_values(array_unique($systems))];
  }
  return $profiles;
}

/* ===== 抽出条件（電話対応待ち） ===== */
function _dw_row_passes_filters(array $row, array $actorProfile): bool {
  // 必須
  $salesStaff = trim((string)($row['セールス担当'] ?? ''));
  if ($salesStaff === '') return false;

  // 入口
  $entrance = trim((string)($row['入口'] ?? ''));
  if ($entrance !== ($actorProfile['type'] ?? '副業')) return false;

  // 支払い何回目 = 1 or 空
  $nth = trim((string)($row['支払い何回目'] ?? ''));
  if (!($nth === '' || $nth === '1' || $nth === 1)) return false;

  // システム名（OR）
  $rowSys = trim((string)($row['システム名'] ?? ''));
  if ($rowSys === '') return false;

  $rowSystems   = _dw_smart_split($rowSys);
  $allowSystems = (array)($actorProfile['systems'] ?? []);
  $ok = false;
  foreach ($rowSystems as $rs) {
    if (in_array($rs, $allowSystems, true)) { $ok = true; break; }
  }
  if (!$ok) return false;

  // 状態 = 電話対応待ち
  if (trim((string)($row['状態'] ?? '')) !== '電話対応待ち') return false;

  // LINE登録日 必須
  if (trim((string)($row['LINE登録日'] ?? '')) === '') return false;

  return true;
}

/** 集計日：LINE登録日 */
function _dw_pick_date_ymd(array $row): string {
  return _dw_normalize_mdy_with_infer((string)($row['LINE登録日'] ?? ''), $row);
}

/**
 * 電話対応待ち（日別カウント）を raw_rows.json から集計
 * 参照優先: CACHE/raw_rows.json → DATA/raw_rows.json
 * 返却: "YYYY-MM-DD" => ["_total"=>int, "担当A"=>int, ...]
 */
function build_denwa_wait_by_day(string $DATA_DIR, string $CACHE_DIR): array {
  $res = [];

  $rawPathData  = rtrim($DATA_DIR,  '/').'/raw_rows.json';
  $rawPathCache = rtrim($CACHE_DIR, '/').'/raw_rows.json';
  $rawPath      = is_file($rawPathCache) ? $rawPathCache : $rawPathData;

  $actorsPath = rtrim($DATA_DIR, '/').'/actors.json';

  _dbg_dw('PATH raw_rows.json',  ['path'=>$rawPath,    'exists'=>is_file($rawPath),    'size'=>@filesize($rawPath)]);
  _dbg_dw('PATH actors.json',    ['path'=>$actorsPath, 'exists'=>is_file($actorsPath), 'size'=>@filesize($actorsPath)]);

  if (!is_file($rawPath) || !is_file($actorsPath)) return $res;

  $raw    = json_decode((string)file_get_contents($rawPath), true) ?: [];
  $actors = json_decode((string)file_get_contents($actorsPath), true) ?: [];

  $rows       = is_array($raw['rows'] ?? null) ? $raw['rows'] : (is_array($raw['items'] ?? null) ? $raw['items'] : []);
  $actorsList = is_array($actors['items'] ?? null) ? $actors['items'] : [];

  $profiles = _dw_actor_profiles($actorsList);

  _dbg_dw('raw rows count', count($rows));
  _dbg_dw('actors items count', count($actorsList));

  // 対象のシステム名に一致するものだけ処理（既存ロジック踏襲）
  $targetSystems = [
    'ChatGPTフロント',
    'Instagramフロント',
    '動画編集フロント',
    'TikTokフロント',
    '副業フロント',
    '副業ウェブフリフロント',
  ];

  foreach ($rows as $row) {
    // 行から取得
    $videoActor = _dw_pick_video_actor($row);
    $inflow     = (string)($row['流入経路'] ?? '');

    // 先に system を弾く（軽い）
    $systemName = trim((string)($row['システム名'] ?? ''));
    if ($systemName === '' || !in_array($systemName, $targetSystems, true)) continue;

    // actor ごとに割り当て（新仕様）
    foreach ($profiles as $actorName => $profile) {

      /* ===== 新仕様分岐 ===== */

      // みおパパ：動画担当は見ず、流入経路に「みおパパ」がある行のみ
      if ($actorName === 'みおパパ') {
        if (!str_contains($inflow, 'みおパパ')) continue;
      }
      // しらほしなつみ（みおママ）：動画担当が一致、かつ流入経路に「みおパパ」を含まない
      elseif ($actorName === 'しらほしなつみ') {
        if ($videoActor !== 'しらほしなつみ') continue;
        if (str_contains($inflow, 'みおパパ')) continue;
      }
      // その他：動画担当一致
      else {
        if ($videoActor !== $actorName) continue;
      }

      // 共通条件
      if (!_dw_row_passes_filters($row, $profile)) continue;

      $ymd = _dw_pick_date_ymd($row);
      if ($ymd === '' || !_dw_is_valid_day($ymd)) continue;

      if (!isset($res[$ymd])) $res[$ymd] = [];
      $res[$ymd][$actorName] = ($res[$ymd][$actorName] ?? 0) + 1;
      $res[$ymd]['_total']   = ($res[$ymd]['_total']   ?? 0) + 1;
    }
  }

  ksort($res);
  _dbg_dw('denwa-wait out tail 3', array_slice($res, -3, 3, true));
  return $res;
}

/* ===== 後方互換：requireしただけで $denwaWaitByDay を用意 =====
 * 無効化: define('AGGREGATE_DENWA_NO_AUTO', true);
 */
if (!defined('AGGREGATE_DENWA_NO_AUTO')) {
  if (isset($DATA_DIR) && isset($CACHE_DIR)) {
    try {
      /** @var array $denwaWaitByDay */
      $denwaWaitByDay = build_denwa_wait_by_day((string)$DATA_DIR, (string)$CACHE_DIR);
    } catch (\Throwable $e) {
      if (!isset($denwaWaitByDay)) $denwaWaitByDay = [];
      _dbg_dw('build_denwa_wait_by_day EXCEPTION', $e->getMessage());
    }
  }
}
