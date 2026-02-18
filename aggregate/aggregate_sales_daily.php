<?php
// aggregate/aggregate_sales_daily.php
declare(strict_types=1);

/**
 * sales（担当者）ごとの日別集計
 * 指標：対応件数 / 成約件数 / 入金件数 / 入金額
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

// sales.json: name => id
function _sd_sales_maps(string $DATA_DIR): array {
  $sales = _sd_safe_json(rtrim($DATA_DIR,'/').'/sales.json');
  $items = isset($sales['items']) && is_array($sales['items']) ? $sales['items'] : [];
  $name2id = [];
  foreach ($items as $p) {
    $id = (string)($p['id'] ?? '');
    $nm = trim((string)($p['name'] ?? ''));
    if ($id !== '' && $nm !== '') $name2id[$nm] = $id;
  }
  return $name2id;
}

function _sd_status_bucket(string $status): string {
  $s = mb_strtolower(trim($status), 'UTF-8');
  if ($s === '成約' || str_contains($s, '契約') || str_contains($s, '受注')) return 'seiyaku';
  if ($s === '入金' || str_contains($s, '入金済')) return 'nyukin';
  if (str_contains($s, '対応') || str_contains($s, '商談') || str_contains($s, '架電') || str_contains($s, '折衝')) return 'taiou';
  return 'other';
}

function _sd_parse_int($v): int {
  if (is_numeric($v)) return (int)$v;
  $s = preg_replace('/[^\d\-]/u', '', (string)$v);
  return ($s === '' || $s === '-') ? 0 : (int)$s;
}

function _sd_parse_date($v): string {
  if (is_string($v)) {
    $s = strtr(trim($v), ['/' => '-', '.' => '-']);
    $ts = strtotime($s);
    if ($ts !== false) return date('Y-m-d', $ts);
    if (is_numeric($v)) $v = (float)$v;
  }
  if (is_int($v) || is_float($v)) {
    $unix = (int)round(((float)$v - 25569) * 86400);
    if ($unix <= 0) return '';
    return gmdate('Y-m-d', $unix);
  }
  return '';
}

function build_sales_daily_by_day(string $DATA_DIR, string $CACHE_DIR): array {
  $out = [];

  $name2id = _sd_sales_maps($DATA_DIR);
  if (!$name2id) return $out;

  $raw  = _sd_safe_json(rtrim($DATA_DIR,'/').'/raw_rows.json');
  $rows = _sd_pick_rows($raw);
  if (!$rows) return $out;

  $pick = function(array $r, array $cands, $def='') {
    foreach ($cands as $k) {
      if (array_key_exists($k, $r)) {
        $v = $r[$k];
        return is_string($v) ? trim($v) : $v;
      }
    }
    return $def;
  };

  $targetSystems = [
    'ChatGPTフロント',
    'Instagramフロント',
    '動画編集フロント',
    'TikTokフロント',
    '副業フロント',
    '副業ウェブフリフロント',
  ];

  foreach ($rows as $r) {

    // --- system フィルタ（最優先） ---
    $systemName = trim((string)($r['システム名'] ?? ''));
    if ($systemName === '' || !in_array($systemName, $targetSystems, true)) continue;

    // --- sales ---
    $salesName = (string)$pick($r, ['sales','担当','担当者'], '');
    if ($salesName === '') continue;
    $sid = $name2id[$salesName] ?? null;
    if ($sid === null) continue;

    // --- date ---
    $date = _sd_parse_date($pick($r, ['date','登録日','計上日'], ''));
    if ($date === '') continue;
    $ts = strtotime($date);
    if ($ts === false) continue;

    $ym = date('Y-m', $ts);
    $d  = (int)date('j', $ts);

    // --- status ---
    $status = (string)$pick($r, ['status','状態'], '');
    $bucket = _sd_status_bucket($status);

    if (!isset($out[$sid][$ym][$d])) {
      $out[$sid][$ym][$d] = [
        'taiou'         => 0,
        'seiyaku'       => 0,
        'nyukin_count'  => 0,
        'nyukin_amount' => 0,
      ];
    }

    if ($bucket === 'seiyaku') {
      $out[$sid][$ym][$d]['seiyaku']++;
    } elseif ($bucket === 'nyukin') {
      $out[$sid][$ym][$d]['nyukin_count']++;
      $amt = _sd_parse_int($pick($r, ['入金額','nyukin_amount','入金'], '0'));
      $out[$sid][$ym][$d]['nyukin_amount'] += $amt;
    } elseif ($bucket === 'taiou') {
      $out[$sid][$ym][$d]['taiou']++;
    }
  }

  return $out;
}
