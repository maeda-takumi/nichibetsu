<?php

// import_inflows_matrix.php 新仕様対応版
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

mb_internal_encoding('UTF-8');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/sheets_rest.php';

/**
 * 日付判定：2行目に混ざるゴミを除去する
 * 使える形式：YYYY/MM/DD, YYYY-MM-DD, M/D, M/D(曜)
 */
function parse_date_cell($s): ?string {
    if ($s === null) return null;

    // 数値（日付シリアル）対応
    // Googleスプレッドシート / Excel の日付が 45809 みたいに来る
    if (is_int($s) || is_float($s) || (is_string($s) && is_numeric($s))) {
        $num = (float)$s;

        // 0や変な値は除外
        if ($num <= 0) return null;

        // シリアル値 → 日付 (Y-m-d)
        // 基準日: 1899-12-30 (Excel互換でよく使う)
        $base = new DateTime('1899-12-30');
        $base->modify('+' . (int)$num . ' days');

        return $base->format('Y-m-d');
    }

    // 文字列として処理
    $s = trim((string)$s);
    if ($s === '') return null;

    // 全角→半角
    $s = strtr($s, ['／'=>'/', '－'=>'-', '　'=>' ', '（'=>'(', '）'=>')']);

    // YYYY/MM/DD or YYYY-MM-DD
    if (preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }

    // M/D or M/D(曜)
    if (preg_match('/^(\d{1,2})\/(\d{1,2})/', $s, $m)) {
        $nowYear  = (int)date('Y');

        $month = (int)$m[1];
        $day   = (int)$m[2];

        // 仮で今年として作る
        $date = sprintf('%04d-%02d-%02d', $nowYear, $month, $day);

        // 未来日になる場合は前年扱い
        if ($date > date('Y-m-d')) {
            $date = sprintf('%04d-%02d-%02d', $nowYear - 1, $month, $day);
        }

        return $date;
    }

    return null;
}



/**
 * 数値化
 */
function to_number($v): float {
    $s = str_replace([',',' ','¥','￥'], '', (string)$v);
    return is_numeric($s) ? (float)$s : 0.0;
}

/**
 * inflows.json を読み込み（既存があれば上書き用に利用）
 */
function load_existing_inflows(string $path): array {
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return $data['items'] ?? [];
}

/**
 * actor.json から tag と name を取得
 */
function load_actor_tags(array $config): array {
    $dir = $config['DATA_DIR'] ?? (__DIR__ . '/data');
    $path = $dir . '/actors.json';
    if (!file_exists($path)) return [];

    $json = json_decode(file_get_contents($path), true);
    $items = $json['items'] ?? [];

    $result = [];
    foreach ($items as $a) {
        $name = $a['name'] ?? '';
        $tags = $a['tags'] ?? [];
        if (!$name || !is_array($tags) || count($tags) === 0) continue;

        // tags[0] をシート名として採用
        $sheetName = $tags[0];
        $result[] = ['user' => $name, 'sheet' => $sheetName];
    }
    return $result;
}

// ========================= main =============================
// 対象年：2025 ～ 現在年
$startYear   = 2025;
$currentYear = (int)date('Y');

// 年 => スプレッドシートID の対応表を作る
$spreadsheetMap = [];
for ($y = $startYear; $y <= $currentYear; $y++) {
    $key = 'INFLOW_SPREADSHEET_ID_' . $y;
    if (!empty($config[$key])) {
        $spreadsheetMap[$y] = $config[$key];
    }
}

if (!$spreadsheetMap) {
    die("INFLOW_SPREADSHEET_ID_YYYY が config.php にありません\n");
}


$actorSheets = load_actor_tags($config);

// $existing = load_existing_inflows($config['INFLOW_JSON']);
$existing = load_existing_inflows($config['INFLOW_JSON']);
$newItems = [];

// 対象年のデータだけ削除
foreach ($existing as $i => $item) {
    $year = (int)substr($item['date'], 0, 4);
    if (isset($spreadsheetMap[$year])) {
        unset($existing[$i]);
    }
}

// 既存データをキー化（上書き判定用）
// key = user|date
foreach ($existing as $item) {
    $key = $item['user'] . '|' . $item['date'];
    $combined[$key] = $item;
}


// 各 actor のシートを処理
foreach ($spreadsheetMap as $year => $spreadsheetId) {

    echo "=== Processing {$year} ===\n";

    foreach ($actorSheets as $info) {
        $user  = $info['user'];
        $sheet = $info['sheet'];

        echo "  Sheet: {$sheet}\n";

        $values = sheets_values_get(
            $config,
            $spreadsheetId,
            $sheet . '!A1:ZZ'
        );


        if (!$values) continue;

        $rowHeader = $values[0] ?? [];
        $rowDate   = $values[1] ?? [];
        $rowValue1 = $values[2] ?? [];
        $rowValue2 = $values[3] ?? [];

        $isMioMama = trim((string)$sheet) === 'みおママ' || trim((string)$user) === 'みおママ';

        $ver2StartCol = null;
        $headerCount  = count($rowHeader);
        for ($c = 0; $c < $headerCount; $c++) {
            if (trim((string)($rowHeader[$c] ?? '')) === 'ver2') {
                $ver2StartCol = $c;
                break;
            }
        }

        $colCount = max(count($rowDate), count($rowValue1), count($rowValue2));

        for ($c = 0; $c < $colCount; $c++) {
            $date = parse_date_cell($rowDate[$c] ?? '');
            if ($date === null) continue;

            // 年をスプシ年に合わせて上書き
            $date = $year . substr($date, 4);

            $num = to_number($rowValue1[$c] ?? '');

            if ($isMioMama && $ver2StartCol !== null && $c >= $ver2StartCol) {
                $num += to_number($rowValue2[$c] ?? '');
            }

            if ($num <= 0) continue;

            $newItems[] = [
                'user'  => $user,
                'date'  => $date,
                'value' => $num,
            ];
        }
    }
}


// inflows.json 保存
$dst = $config['INFLOW_JSON'];
if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0775, true);

$out = array_merge($existing, $newItems);

file_put_contents(
    $dst,
    json_encode(
        ['kind' => 'inflows', 'updated_at' => date('c'), 'items' => $out],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    )
);

echo "inflows.json written: " . count($out) . " records\n";
