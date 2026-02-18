<?php
// aggregate/aggregate_sales_nyukin_amount.php
declare(strict_types=1);

/**
 * 入金額（日別合算）集計（from cache/raw_rows.json）
 *
 * 抽出条件（ご指定通り）
 *  - 合算値カラム: 入金額（数値化して合算）
 *  - 入金日: 日付（行の `入金日` / `nyukin_date` / `入金_日` / `date`）
 *  - セールス担当: name（data/sales.json）
 *  - 状態 = "入金済み"
 *  - 入口: type（actors.json の俳優 type と行の `入口`/`type` が一致）
 *  - システム名: systems（actors.json）。俳優側に systems がある場合のみ OR一致で絞る
 *  - ★みおパパ仕様：
 *      ・流入経路に「みおパパ」を含む行は「みおパパ actor（aliases/tags/name で判定）」へ
 *      ・それ以外は通常どおり（動画担当名ベース）。しらほしなつみの場合は「みおパパ actor」以外へ
 *
 * 返り値
 *   [sid => [aid => ['YYYY-MM' => [day(int) => amount(int)]]]]
 */

/* ---------- helpers ---------- */
if (!function_exists('_na_json')) {
  function _na_json(string $path): array {
    try {
      if (!is_file($path)) return [];
      $txt = @file_get_contents($path);
      if ($txt === false || $txt === '') return [];
      $j = json_decode($txt, true);
      return is_array($j) ? $j : [];
    } catch (\Throwable $e) { return []; }
  }
}
if (!function_exists('_na_rows')) {
  function _na_rows(array $j): array {
    if (isset($j['items']) && is_array($j['items'])) return $j['items'];
    if (isset($j['rows'])  && is_array($j['rows']))  return $j['rows'];
    return [];
  }
}
if (!function_exists('_na_norm')) {
  function _na_norm(?string $s): string {
    $s = (string)$s;
    if ($s === '') return '';
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'asKV', 'UTF-8');
    $s = preg_replace('/\s+/u', '', $s) ?? '';
    $s = function_exists('mb_strtolower') ? mb_strtolower($s,'UTF-8') : strtolower($s);
    return trim($s);
  }
}
if (!function_exists('_na_norm_split')) {
  function _na_norm_split($val): array {
    $out = [];
    if (is_array($val)) $arr = $val;
    else {
      $s = str_replace(['、','，'], ',', (string)$val);
      $arr = array_map('trim', explode(',', $s));
    }
    foreach ($arr as $it) {
      $n = _na_norm((string)$it);
      if ($n !== '') $out[$n] = true;
    }
    return $out;
  }
}
if (!function_exists('_na_date')) {
  /** 文字列/Sheets序数 → 'Y-m-d' */
  function _na_date($v): string {
    if (is_string($v)) {
      $s = strtr(trim($v), ['/' => '-', '.' => '-']);
      $ts = strtotime($s);
      if ($ts !== false) return date('Y-m-d', $ts);
      if (is_numeric($v)) $v = (float)$v;
    }
    if (is_int($v) || is_float($v)) {
      $unix = (int)round(((float)$v - 25569) * 86400); // 1899-12-30 起点
      if ($unix <= 0) return '';
      return gmdate('Y-m-d', $unix);
    }
    return '';
  }
}
if (!function_exists('_na_amount')) {
  /** 金額文字列を整数化（カンマ/円記号など除去。マイナスも許容） */
  function _na_amount($v): int {
    if (is_numeric($v)) return (int)round((float)$v);
    $s = (string)$v;
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'asKV', 'UTF-8');
    $s = preg_replace('/[^\d\-\+]/u', '', $s) ?? '';
    if ($s === '' || $s === '+' || $s === '-') return 0;
    return (int)$s;
  }
}

if (!function_exists('_na_contains_miopapa')) {
  function _na_contains_miopapa($v): bool {
    $s = (string)$v;
    return ($s !== '' && mb_strpos($s, 'みおパパ') !== false);
  }
}

/**
 * actors.json から「みおパパ actor」を探して aid を返す
 * 優先: aliases に "みおパパ" → name が "みおパパ" → tags に "みおパパ"
 */
if (!function_exists('_na_find_miopapa_actor_id')) {
  function _na_find_miopapa_actor_id(array $actorItems): string {
    // 1) aliases
    foreach ($actorItems as $a) {
      $id = (string)($a['id'] ?? '');
      if ($id === '') continue;
      $aliases = $a['aliases'] ?? [];
      if (is_array($aliases) && in_array('みおパパ', $aliases, true)) return $id;
    }
    // 2) name
    foreach ($actorItems as $a) {
      $id = (string)($a['id'] ?? '');
      $nm = trim((string)($a['name'] ?? ''));
      if ($id !== '' && $nm === 'みおパパ') return $id;
    }
    // 3) tags
    foreach ($actorItems as $a) {
      $id = (string)($a['id'] ?? '');
      if ($id === '') continue;
      $tags = $a['tags'] ?? [];
      if (is_array($tags) && in_array('みおパパ', $tags, true)) return $id;
    }
    return '';
  }
}

/**
 * raw 行から actor_id(aid) を解決する
 *  - 流入経路に「みおパパ」→ みおパパ actor_id
 *  - それ以外：
 *      - 動画担当名に一致する actor を返す
 *      - 動画担当が「しらほしなつみ」の場合は「みおパパ actor」以外を優先
 *  - それでも無理なら aliasMap で拾えるもの（ただし name重複は避けるため最後の手段）
 */
if (!function_exists('_na_resolve_actor_id')) {
  function _na_resolve_actor_id(array $r, array $actorItems, array $actorName2Ids, array $aliasName2Id, string $miopapaAid): string {
    $inflow = (string)($r['流入経路'] ?? '');
    $rawActorName = trim((string)($r['動画担当'] ?? ($r['actor'] ?? '')));
    if ($rawActorName === '') return '';

    // (A) みおパパ判定が先
    if (_na_contains_miopapa($inflow)) {
      if ($miopapaAid !== '') return $miopapaAid;
      // フォールバック：name/alias で "みおパパ" が引けるなら
      if (isset($aliasName2Id['みおパパ'])) return (string)$aliasName2Id['みおパパ'];
      // それでもダメなら空
      return '';
    }

    // (B) 通常：動画担当名で候補を得る（name重複に対応するため name=>ids）
    $ids = $actorName2Ids[$rawActorName] ?? [];
    if ($ids) {
      // しらほしなつみ は「みおパパ側以外」を優先
      if ($rawActorName === 'しらほしなつみ' && $miopapaAid !== '') {
        foreach ($ids as $id) {
          if ($id !== $miopapaAid) return (string)$id;
        }
      }
      // 通常は先頭
      return (string)$ids[0];
    }

    // (C) aliases で解決（ただし name重複時は aliases の方が一意になりやすい）
    if (isset($aliasName2Id[$rawActorName])) {
      $aid = (string)$aliasName2Id[$rawActorName];
      // しらほしなつみ の場合は miopapa を避ける
      if ($rawActorName === 'しらほしなつみ' && $miopapaAid !== '' && $aid === $miopapaAid) {
        // 代替を探す
        $ids2 = $actorName2Ids['しらほしなつみ'] ?? [];
        foreach ($ids2 as $id) {
          if ($id !== $miopapaAid) return (string)$id;
        }
      }
      return $aid;
    }

    return '';
  }
}

/* ---------- builder ---------- */
if (!function_exists('build_sales_nyukin_amount_by_day')) {
  /**
   * @param string $DATA_DIR
   * @param string $CACHE_DIR
   * @return array [sid => [aid => ['YYYY-MM' => [day => amount]]]]
   */
  function build_sales_nyukin_amount_by_day(string $DATA_DIR, string $CACHE_DIR): array {
    try {
      $out = [];

      // sales.json: name -> sid
      $sales = _na_json(rtrim($DATA_DIR, '/').'/sales.json');
      $salesItems = isset($sales['items']) && is_array($sales['items']) ? $sales['items'] : [];
      $salesName2Id = [];
      foreach ($salesItems as $p) {
        $id = (string)($p['id'] ?? '');
        $nm = trim((string)($p['name'] ?? ''));
        if ($id !== '' && $nm !== '') $salesName2Id[$nm] = $id;
      }

      // actors.json: name -> [ids...], alias -> id, id -> type(norm), id -> systems(norm set)
      $actors = _na_json(rtrim($DATA_DIR, '/').'/actors.json');
      $actorItems = isset($actors['items']) && is_array($actors['items']) ? $actors['items'] : [];
      $actorName2Ids = [];
      $aliasName2Id  = [];
      $actorId2Type = [];
      $actorId2Systems = [];

      foreach ($actorItems as $a) {
        $id = (string)($a['id'] ?? '');
        $nm = trim((string)($a['name'] ?? ''));
        if ($id === '' || $nm === '') continue;

        // name => ids（重複名対応）
        if (!isset($actorName2Ids[$nm])) $actorName2Ids[$nm] = [];
        $actorName2Ids[$nm][] = $id;

        // aliases => id（基本一意想定）
        $aliases = $a['aliases'] ?? [];
        if (is_array($aliases)) {
          foreach ($aliases as $al) {
            $al = trim((string)$al);
            if ($al !== '') $aliasName2Id[$al] = $id;
          }
        }

        $tp = isset($a['type']) ? _na_norm((string)$a['type']) : '';
        if ($tp !== '') $actorId2Type[$id] = $tp;

        $sysSet = _na_norm_split($a['systems'] ?? []);
        if ($sysSet) $actorId2Systems[$id] = $sysSet;
      }

      if (!$salesName2Id || !$actorItems) return $out;

      // みおパパ actor_id（見つからない場合は ''）
      $miopapaAid = _na_find_miopapa_actor_id($actorItems);

      // raw_rows.json
      $raw  = _na_json(rtrim($CACHE_DIR, '/').'/raw_rows.json');
      $rows = _na_rows($raw);
      if (!$rows) return $out;

      // 対象のシステム名に一致するものだけ処理
      $targetSystems = [
        'ChatGPTフロント',
        'Instagramフロント',
        '動画編集フロント',
        'TikTokフロント',
        '副業フロント',
        '副業ウェブフリフロント',
      ];

      foreach ($rows as $r) {
        // 状態 = 入金済み
        $state = trim((string)($r['状態'] ?? ($r['status'] ?? '')));
        if ($state !== '入金済み') continue;

        // セールス担当
        $salesName = trim((string)($r['セールス担当'] ?? ($r['sales'] ?? '')));
        if ($salesName === '' || !isset($salesName2Id[$salesName])) continue;
        $sid = $salesName2Id[$salesName];

        // 対象システム（先に弾く）
        $systemName = trim((string)($r['システム名'] ?? ''));
        if ($systemName === '' || !in_array($systemName, $targetSystems, true)) continue;

        // actor_id 解決（★みおパパ分岐込み）
        $aid = _na_resolve_actor_id($r, $actorItems, $actorName2Ids, $aliasName2Id, $miopapaAid);
        if ($aid === '') continue;

        // 入口 (=type) 厳密一致（俳優側type・行側入口の両方必須）
        $rowType   = _na_norm((string)($r['入口'] ?? ($r['type'] ?? '')));
        $actorType = $actorId2Type[$aid] ?? '';
        if ($rowType === '' || $actorType === '' || $rowType !== $actorType) continue;

        // システム名 (=systems) — 俳優側に設定がある場合のみ OR一致で適用
        if (isset($actorId2Systems[$aid]) && $actorId2Systems[$aid]) {
          $rowSystem = _na_norm((string)($r['システム名'] ?? ($r['system'] ?? ($r['システム'] ?? ''))));
          if ($rowSystem === '' || !isset($actorId2Systems[$aid][$rowSystem])) continue;
        }

        // 入金日
        $date = _na_date($r['入金日'] ?? ($r['nyukin_date'] ?? ($r['入金_日'] ?? ($r['date'] ?? ''))));
        if ($date === '') continue;
        $ts = strtotime($date); if ($ts === false) continue;
        $ym = date('Y-m', $ts);
        $d  = (int)date('j', $ts);

        // 入金額 > 0
        $amt = _na_amount($r['入金額'] ?? ($r['nyukin_amount'] ?? ($r['入金'] ?? 0)));
        if ($amt <= 0) continue;

        // 加算
        $out[$sid][$aid][$ym][$d] = (int)($out[$sid][$aid][$ym][$d] ?? 0) + (int)$amt;
      }

      return $out;
    } catch (\Throwable $e) {
      return [];
    }
  }
}
