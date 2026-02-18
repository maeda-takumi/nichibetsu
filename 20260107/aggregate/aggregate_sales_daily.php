<?php
// aggregate/aggregate_sales_daily.php
declare(strict_types=1);

/**
 * 目的：
 *   sales（担当者）ごとの日別集計を作る。
 *   4指標：対応件数(taiou) / 成約件数(seiyaku) / 入金件数(nyukin_count) / 入金額(nyukin_amount)
 *
 * 入力想定：
 *   - DATA/raw_rows.json … 行データ。少なくとも以下の列のいずれかがある想定
 *       'date' | '登録日'         : 日付
 *       'sales' | '担当' | '担当者' : 担当者名（= sales.json の name と一致 もしくは alias で解決）
 *       'status' | '状態'         : ステータス（文字列）
 *       '入金額' | 'nyukin_amount' : 金額（数値/文字列）
 *   - DATA/sales.json … sales担当のマスタ
 *       { "items": [ { "id": "s001", "name": "山田", ... }, ... ] }
 *   - （任意）DATA/aliases.json … 担当名の別名解決（あれば）
 *
 * 出力：
 *   $salesDailyByDay = [
 *     sales_id => [
 *       'YYYY-MM' => [
 *          1 => ['taiou'=>int, 'seiyaku'=>int, 'nyukin_count'=>int, 'nyukin_amount'=>int],
 *          2 => [...],
 *       ]
 *     ]
 *   ]
 */

function _sd_safe_json(string $path): array {
  if (!is_file($path)) return [];
  $txt = (string)@file_get_contents($path);
  if ($txt === '') return [];
  $j = json_decode($txt, true);
  return is_array($j) ? $j : [];
}

function _sd_pick_rows(array $j): array {
  if (isset($j['items']) && is_array($j['items'])) return $j['items'];
  if (isset($j['rows'])  && is_array($j['rows']))  return $j['rows'];
  return [];
}

// sales.json: name => id / kana などの逆引きを作る
function _sd_sales_maps(string $DATA_DIR): array {
  $sales = _sd_safe_json(rtrim($DATA_DIR,'/').'/sales.json');
  $items = isset($sales['items']) && is_array($sales['items']) ? $sales['items'] : [];
  $name2id = [];  // "山田" => "s001"
  $id2name = [];  // "s001" => "山田"
  foreach ($items as $p) {
    $id = (string)($p['id'] ?? '');
    $nm = trim((string)($p['name'] ?? ''));
    if ($id !== '' && $nm !== '') {
      $name2id[$nm] = $id;
      $id2name[$id] = $nm;
    }
  }
  return [$name2id, $id2name];
}

// 文字列ステータスをカテゴリに落とす（要件に合わせて調整可能）
function _sd_status_bucket(string $status): string {
  $s = mb_strtolower(trim($status), 'UTF-8');
  // 成約系
  if ($s === '成約' || str_contains($s, '契約') || str_contains($s, '受注')) return 'seiyaku';
  // 入金系
  if ($s === '入金' || str_contains($s, '入金済')) return 'nyukin';
  // 対応系（商談/対応/架電など広めに）
  if (str_contains($s, '対応') || str_contains($s, '商談') || str_contains($s, '架電') || str_contains($s, '折衝')) return 'taiou';
  return 'other';
}

// 金額パース
function _sd_parse_int($v): int {
  if (is_numeric($v)) return (int)$v;
  $s = preg_replace('/[^\d\-]/u', '', (string)$v);
  return ($s === '' || $s === '-') ? 0 : (int)$s;
}

// シート日付 or 文字列日付を YYYY-MM-DD 化
function _sd_parse_date($v): string {
  if (is_string($v)) {
    $s = strtr(trim($v), ['/' => '-', '.' => '-']);
    $ts = strtotime($s);
    if ($ts !== false) return date('Y-m-d', $ts);
    if (is_numeric($v)) $v = (float)$v;
  }
  if (is_int($v) || is_float($v)) {
    // Google Sheets 序数日 1899-12-30 基準
    $days = (float)$v;
    $unix = (int)round(($days - 25569) * 86400); // 1970-01-01
    if ($unix <= 0) return '';
    return gmdate('Y-m-d', $unix);
  }
  return '';
}

function build_sales_daily_by_day(string $DATA_DIR, string $CACHE_DIR): array {
  $out = [];

  [$name2id, $id2name] = _sd_sales_maps($DATA_DIR);
  if (!$name2id) return $out;

  $raw   = _sd_safe_json(rtrim($DATA_DIR,'/').'/raw_rows.json');
  $rows  = _sd_pick_rows($raw);
  if (!$rows) return $out;

  // 列名のゆらぎに対応（担当/日付/状態/入金額）
  $pick = function(array $r, array $cands, $def='') {
    foreach ($cands as $k) {
      if (array_key_exists($k, $r)) {
        $v = $r[$k];
        return is_string($v) ? trim($v) : $v;
      }
    }
    return $def;
  };

  foreach ($rows as $r) {
    $salesName = (string)$pick($r, ['sales','担当','担当者'], '');
    if ($salesName === '') continue;
    $sid = $name2id[$salesName] ?? null;
    if ($sid === null) continue; // マスタ未登録は集計外

    $date = _sd_parse_date($pick($r, ['date','登録日','計上日'], ''));
    if ($date === '') continue;
    $ts = strtotime($date);
    if ($ts === false) continue;

    $ym = date('Y-m', $ts);
    $d  = (int)date('j', $ts);

    $status = (string)$pick($r, ['status','状態'], '');
    $bucket = _sd_status_bucket($status);

    if (!isset($out[$sid])) $out[$sid] = [];
    if (!isset($out[$sid][$ym])) $out[$sid][$ym] = [];
    if (!isset($out[$sid][$ym][$d])) {
      $out[$sid][$ym][$d] = ['taiou'=>0, 'seiyaku'=>0, 'nyukin_count'=>0, 'nyukin_amount'=>0];
    }
    // 対象のシステム名に一致するものだけ処理
    $targetSystems = [
      'ChatGPTフロント',
      'Instagramフロント',
      '動画編集フロント',
      'TikTokフロント',
      '副業フロント',
      '副業ウェブフリフロント',
    ];
    $systemName = trim((string)($r['システム名'] ?? ''));
    if ($systemName === '' || !in_array($systemName, $targetSystems, true)) continue;

    if ($bucket === 'seiyaku') {
      $out[$sid][$ym][$d]['seiyaku'] += 1;
    } elseif ($bucket === 'nyukin') {
      $out[$sid][$ym][$d]['nyukin_count'] += 1;
      $amt = _sd_parse_int($pick($r, ['入金額','nyukin_amount','入金'], '0'));
      $out[$sid][$ym][$d]['nyukin_amount'] += $amt;
    } elseif ($bucket === 'taiou') {
      $out[$sid][$ym][$d]['taiou'] += 1;
    }
  }

  return $out;
}
