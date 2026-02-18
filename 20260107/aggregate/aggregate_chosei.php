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


/** 動画担当の取り出し（第一候補：動画担当） */
function _pick_actor_name(array $row): string {
  foreach (['動画担当','actor','user','担当','担当者','sales_user','sales','name'] as $k) {
    if (array_key_exists($k, $row)) {
      $v = trim((string)$row[$k]);
      if ($v !== '') return $v;
    }
  }
  return '';
}

/** 年月の推定（MM/DD 形式で年が無いとき用） */
function _infer_year_month(array $row): array {
  if (!empty($row['セールス年月']) && preg_match('/(\d{4})\D+(\d{1,2})/', (string)$row['セールス年月'], $m)) {
    return [intval($m[1]), intval($m[2])];
  }
  if (!empty($row['データ4']) && preg_match('/^(\d{4})(\d{2})$/', (string)$row['データ4'], $m)) {
    return [intval($m[1]), intval($m[2])];
  }
  return [intval(date('Y')), intval(date('n'))];
}

/** MM/DD[/YY] → YYYY-MM-DD 正規化。既にYYYY-MM-DDならそのまま */
function _normalize_mdy_with_infer(string $md, array $row): string {
  $md = trim($md);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $md)) return $md;
  if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/', $md, $m)) {
    $mm = intval($m[1]); $dd = intval($m[2]); $yy = null;
    if (!empty($m[3])) { $y = intval($m[3]); $yy = ($y < 100) ? (2000 + $y) : $y; }
    if ($yy === null) { [$iy, $im] = _infer_year_month($row); $yy = $iy; }
    if (checkdate($mm, $dd, $yy)) return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
  }
  return '';
}

function _is_valid_day(string $ymd): bool {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
  $ts = strtotime($ymd);
  if ($ts === false) return false;
  if ($ts < strtotime('2000-01-01')) return false; // 古すぎる日付は除外
  if ($ts > time()) return false;                  // 未来日を除外
  return true;
}

/* ===== 文字列分割ヘルパ（カンマ/全角カンマ/読点/縦棒/スラッシュなど） ===== */
function _smart_split(string $s): array {
  $s = trim($s);
  if ($s === '') return [];
  // , ， 、 | ／ / などを区切りとして扱う
  $parts = preg_split('/\s*[,，、|／\/]\s*/u', $s);
  $out = [];
  foreach ($parts as $p) {
    $p = trim($p);
    if ($p !== '') $out[] = $p;
  }
  return $out;
}

/* ===== 俳優プロファイル（name => ['type'=>'副業|投資','systems'=>[]]） =====
 * 優先: items[].type / items[].systems → tags から推定 → 既定値
 * systems は配列またはカンマ区切り文字列のどちらでもOK
 */
function _actor_profiles(array $items): array {
  $profiles = [];
  foreach ($items as $a) {
    $name = trim((string)($a['name'] ?? ''));
    if ($name === '') continue;

    // --- type ---
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

    // --- systems ---
    $systems = [];
    if (isset($a['systems'])) {
      if (is_array($a['systems'])) {
        foreach ($a['systems'] as $s) {
          $s = trim((string)$s);
          if ($s !== '') $systems[] = $s;
        }
      } else {
        foreach (_smart_split((string)$a['systems']) as $s) $systems[] = $s;
      }
    }
    if (!$systems) {
      // tags から systems=... の書式を拾う（後方互換）
      $tags = is_array($a['tags'] ?? null) ? $a['tags'] : [];
      foreach ($tags as $tg) {
        $tg = trim((string)$tg);
        if ($tg === '') continue;
        // 例: "systems=副業フロント,ChatGPTフロント"
        if (preg_match('/^systems\s*=\s*(.+)$/ui', $tg, $m)) {
          foreach (_smart_split($m[1]) as $s) $systems[] = $s;
        } elseif (preg_match('/^system\s*:\s*(.+)$/ui', $tg, $m)) {
          foreach (_smart_split($m[1]) as $s) $systems[] = $s;
        }
      }
    }
    // 既定の許可リスト（未指定時に以前の3種を許容）
    if (!$systems) $systems = ['副業フロント','ChatGPTフロント','TikTokフロント'];

    $profiles[$name] = ['type'=>$type, 'systems'=>array_values(array_unique($systems))];
  }
  return $profiles;
}

/* ===== ご指定の抽出条件（actorプロファイルで入口/システムを判定） =====
 * - 動画担当（actor）: 空でない
 * - セールス担当: 空でない
 * - 入口: actor_profile['type'] と一致（"副業" or "投資"）
 * - 支払い何回目: 1 または 空文字
 * - システム名: raw側の「システム名」(カンマ区切り可) のいずれかが actor_profile['systems'] に含まれる
 * - 集計日: LINE登録日（正規化＆妥当日付）
 */
function _row_passes_chosei_filters(array $row, array $actorProfile): bool {
  $salesStaff = trim((string)($row['セールス担当'] ?? ''));
  if ($salesStaff === '') return false;

  $entrance = trim((string)($row['入口'] ?? ''));
  if ($entrance !== ($actorProfile['type'] ?? '副業')) return false;

  $nthRaw = (string)($row['支払い何回目'] ?? '');
  $nth    = trim($nthRaw);
  if (!($nth === '' || $nth === '1' || $nth === 1)) return false;

  $rowSys = trim((string)($row['システム名'] ?? ''));
  if ($rowSys === '') return false;

  $rowSystems  = _smart_split($rowSys);
  $allowSystems = (array)($actorProfile['systems'] ?? []);

  // OR 条件：どれか一つでも一致すればOK
  $ok = false;
  foreach ($rowSystems as $rs) {
    // 完全一致基準（必要なら大小/全角半角正規化を追加）
    if (in_array($rs, $allowSystems, true)) { $ok = true; break; }
  }
  if (!$ok) return false;

  $lineDate = trim((string)($row['LINE登録日'] ?? ''));
  if ($lineDate === '') return false;

  return true;
}

/** 集計日：LINE登録日を正規化 */
function _pick_date_ymd_for_chosei(array $row): string {
  return _normalize_mdy_with_infer((string)($row['LINE登録日'] ?? ''), $row);
}

/**
 * chosei（日別調整済み）を raw_rows.json から集計
 * 出力: "YYYY-MM-DD" => ["_total"=>int, "担当A"=>int, ...]
 * 参照ファイル優先度: CACHE/raw_rows.json → DATA/raw_rows.json
 */
function build_chosei_by_day(string $DATA_DIR, string $CACHE_DIR): array {
  $res = [];

  $rawPathData  = rtrim($DATA_DIR,  '/').'/raw_rows.json';
  $rawPathCache = rtrim($CACHE_DIR, '/').'/raw_rows.json';
  $rawPath      = is_file($rawPathCache) ? $rawPathCache : $rawPathData;

  $actorsPath = rtrim($DATA_DIR, '/').'/actors.json';

  _dbg_c('PATH raw_rows.json',  ['path'=>$rawPath,    'exists'=>is_file($rawPath),    'size'=>@filesize($rawPath)]);
  _dbg_c('PATH actors.json',    ['path'=>$actorsPath, 'exists'=>is_file($actorsPath), 'size'=>@filesize($actorsPath)]);

  if (!is_file($rawPath) || !is_file($actorsPath)) return $res;

  $raw    = json_decode((string)file_get_contents($rawPath), true) ?: [];
  $actors = json_decode((string)file_get_contents($actorsPath), true) ?: [];

  $rows       = is_array($raw['rows'] ?? null) ? $raw['rows'] : (is_array($raw['items'] ?? null) ? $raw['items'] : []);
  $actorsList = is_array($actors['items'] ?? null) ? $actors['items'] : [];

  $aliasMap  = _alias_map_from_actors_items($actorsList);
  $profiles  = _actor_profiles($actorsList); // name => ['type','systems']

  _dbg_c('raw rows count', count($rows));
  _dbg_c('actors items count', count($actorsList));
  _dbg_c('actor profiles (sample)', array_slice($profiles, 0, 10, true));

  foreach ($rows as $row) {
    // 担当名（別名→正規名）
    $name = _pick_actor_name($row);
    if ($name === '') continue;
    $fixed = $aliasMap[$name] ?? $name;

    $profile = $profiles[$fixed] ?? ['type'=>'副業','systems'=>['副業フロント','ChatGPTフロント','TikTokフロント']];

    // ご指定条件に合致?
    if (!_row_passes_chosei_filters($row, $profile)) continue;
    
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

    // 集計日＝LINE登録日
    $ymd = _pick_date_ymd_for_chosei($row);
    if ($ymd === '' || !_is_valid_day($ymd)) continue;

    // 加算
    if (!isset($res[$ymd])) $res[$ymd] = [];
    $res[$ymd][$fixed]   = ($res[$ymd][$fixed]   ?? 0) + 1;
    $res[$ymd]['_total'] = ($res[$ymd]['_total'] ?? 0) + 1;
  }

  ksort($res);
  _dbg_c('chosei out tail 3', array_slice($res, -3, 3, true));
  return $res;
}

/* ===== 自動配線（後方互換） =====
 * 無効化: define('AGGREGATE_CHOSEI_NO_AUTO', true);
 */
if (!defined('AGGREGATE_CHOSEI_NO_AUTO')) {
  if (isset($DATA_DIR) && isset($CACHE_DIR)) {
    try {
      /** @var array $choseiByDay */
      $choseiByDay = build_chosei_by_day((string)$DATA_DIR, (string)$CACHE_DIR);
    } catch (\Throwable $e) {
      if (!isset($choseiByDay)) $choseiByDay = [];
      _dbg_c('build_chosei_by_day EXCEPTION', $e->getMessage());
    }
  }
}
