<?php
// admin_dashboard.php（整理版）
declare(strict_types=1);

// ===== 設定 =====
$config   = require __DIR__ . '/config.php';
$CFG = is_file(__DIR__.'/config.php') ? (require __DIR__.'/config.php') : [];
$DATA_DIR  = $CFG['DATA_DIR']  ?? (__DIR__.'/data');
$CACHE_DIR = $CFG['CACHE_DIR'] ?? (__DIR__.'/cache');

// ★ ここで必ず helpers を読み込む（関数を使う前）
$helpersPath = __DIR__ . '/lib/helpers.php';
if (is_file($helpersPath)) {
  require_once $helpersPath;
} else {
  // デバッグしやすいように警告（本番は削除可）
  echo "<pre style='background:#300;color:#fff;padding:6px'>WARN: helpers.php not found at {$helpersPath}</pre>";
}

// ===== マスタ読み込み（id→name を先に用意）=====
$actorsData = json_decode(@file_get_contents($DATA_DIR . '/actors.json'), true) ?: ['items'=>[]];
$actorsById = [];
foreach (($actorsData['items'] ?? []) as $it) {
  $id = (string)($it['id'] ?? '');
  $nm = trim((string)($it['name'] ?? ''));
  if ($id !== '' && $nm !== '') $actorsById[$id] = $nm;
}

// ===== 集計データ生成 =====
require_once __DIR__ . '/aggregate/aggregate_inflows.php';
$inflowByDay = build_inflow_by_day($DATA_DIR, $CACHE_DIR) ?? [];

if (!empty($_GET['debug_io'])) {
  echo "<pre style='background:#024;color:#fff;padding:6px;margin:6px 0'>";
  echo "AFTER build_inflow_by_day(): days=", is_array($inflowByDay)?count($inflowByDay):-1, "\n";
  print_r(array_slice($inflowByDay, -3, 3, true));
  echo "</pre>";
}

require_once __DIR__ . '/aggregate/aggregate_chosei.php';

// $choseiByDay   = build_chosei_by_day_actor($DATA_DIR, $CACHE_DIR); // [aid][ym][d] => count
require_once __DIR__ . '/aggregate/aggregate_denwa_wait.php'; // 電話対応待ち

$denwaWaitByDay = [];
if (function_exists('build_denwa_wait_by_day')) {
  try {
    $denwaWaitByDay = build_denwa_wait_by_day($DATA_DIR, $CACHE_DIR) ?? [];
  } catch (\Throwable $e) {
    $denwaWaitByDay = [];
  }
} elseif (isset($denwaWaitByDay) && is_array($denwaWaitByDay)) {
  // 後方互換（ファイル側で自動生成済みならそのまま使う）
} else {
  $denwaWaitByDay = [];
}

require_once __DIR__ . '/aggregate/aggregate_taiou_count.php';

$taiouCountByDay = [];
if (function_exists('build_taiou_count_by_day')) {
  try {
    $taiouCountByDay = build_taiou_count_by_day($DATA_DIR, $CACHE_DIR) ?? [];
  } catch (\Throwable $e) {
    $taiouCountByDay = [];
  }
}

// 成約件数
require_once __DIR__ . '/aggregate/aggregate_seiyaku_count.php';
$seiyakuByDay = function_exists('build_seiyaku_by_day')
  ? (build_seiyaku_by_day($DATA_DIR, $CACHE_DIR) ?? [])
  : [];

// 入金額（合算）
require_once __DIR__ . '/aggregate/aggregate_nyukin_amount.php';
$nyukinAmountByDay = function_exists('build_nyukin_amount_by_day')
  ? (build_nyukin_amount_by_day($DATA_DIR, $CACHE_DIR) ?? [])
  : [];

// 任意: デバッグ (?debug_nyukin_amt=1)
if (!empty($_GET['debug_nyukin_amt'])) {
  echo '<pre style="background:#214; color:#fff; padding:6px; margin:6px 0"><b>DEBUG nyukinAmountByDay tail</b>'."\n";
  print_r(array_slice($nyukinAmountByDay, -3, 3, true));
  echo "</pre>";
}


// 任意デバッグ: ?debug_seiyaku=1
if (!empty($_GET['debug_seiyaku'])) {
  echo '<pre style="background:#141; color:#fff; padding:6px; margin:6px 0"><b>DEBUG seiyakuByDay tail</b>'."\n";
  print_r(array_slice($seiyakuByDay, -3, 3, true));
  echo "</pre>";
}

// 任意デバッグ：?debug_taiou=1
if (!empty($_GET['debug_taiou'])) {
  echo '<pre style="background:#113;color:#fff;padding:6px;margin:6px 0"><b>DEBUG taiouCountByDay tail</b>'."\n";
  print_r(array_slice($taiouCountByDay, -3, 3, true));
  echo "</pre>";
}


// 任意のデバッグ
if (!empty($_GET['debug_denwa'])) {
  echo '<pre style="background:#013;color:#fff;padding:6px;margin:6px 0"><b>DEBUG denwaWaitByDay tail</b>'."\n";
  print_r(array_slice($denwaWaitByDay, -3, 3, true));
  echo "</pre>";
}

// すでにある呼び出し群の直後あたり
require_once __DIR__ . '/aggregate/aggregate_denwa_lost.php';

$denwaLostByDay = [];
if (function_exists('build_denwa_lost_by_day')) {
  try {
    $denwaLostByDay = build_denwa_lost_by_day($DATA_DIR, $CACHE_DIR) ?? [];
  } catch (\Throwable $e) {
    $denwaLostByDay = [];
  }
} elseif (isset($denwaLostByDay) && is_array($denwaLostByDay)) {
  // 後方互換（ファイル側自動生成）
} else {
  $denwaLostByDay = [];
}

// 任意デバッグ ?debug_denwalost=1
if (!empty($_GET['debug_denwalost'])) {
  echo '<pre style="background:#301;color:#fff;padding:6px;margin:6px 0"><b>DEBUG denwaLostByDay tail</b>'."\n";
  print_r(array_slice($denwaLostByDay, -3, 3, true));
  echo "</pre>";
}
// 入金件数
require_once __DIR__ . '/aggregate/aggregate_nyukin_count.php';
$nyukinCountByDay = function_exists('build_nyukin_count_by_day')
  ? (build_nyukin_count_by_day($DATA_DIR, $CACHE_DIR) ?? [])
  : [];

// 任意デバッグ (?debug_nyukin=1)
if (!empty($_GET['debug_nyukin'])) {
  echo '<pre style="background:#142; color:#fff; padding:6px; margin:6px 0"><b>DEBUG nyukinCountByDay tail</b>'."\n";
  print_r(array_slice($nyukinCountByDay, -3, 3, true));
  echo "</pre>";
}



// （任意）関数が読めているかワンポイント確認
if (!empty($_GET['debug_io'])) {
  echo "<pre style='background:#013;color:#fff;padding:6px;margin:6px 0'>";
  echo 'helpers loaded: ', (function_exists('inflow_get') && function_exists('chosei_get')) ? 'YES' : 'NO', "\n";
  echo "</pre>";
}
if (!empty($_GET['debug_io'])) {
  ini_set('display_errors','1'); error_reporting(E_ALL);
  echo "<pre style='background:#111;color:#0f0;padding:8px;margin:6px 0'>";
  echo "helpers loaded: ", (function_exists('inflow_get') && function_exists('chosei_get') ? 'YES' : 'NO'), "\n";

  if (function_exists('build_inflow_by_day')) {
    $rf = new ReflectionFunction('build_inflow_by_day');
    echo "build_inflow_by_day defined at: ", $rf->getFileName(), ":", $rf->getStartLine(), "\n";
  } else {
    echo "build_inflow_by_day: NOT FOUND\n";
  }

  echo "AFTER build_inflow_by_day(): days=", is_array($inflowByDay)?count($inflowByDay):-1, "\n";
  print_r(array_slice($inflowByDay, -3, 3, true));
  echo "</pre>";
}

// ★ 新規追加
require_once __DIR__ . '/aggregate/aggregate_watch_metrics.php';

$watchByDay = build_watch_by_day($DATA_DIR, $CACHE_DIR);
/* ===== WATCH デバッグ（aidパラメータ不要） =====
 * 依存: $actorsData, $actorsById, $watchByDay, $DATA_DIR, $mdef
 */
if (!empty($_GET['debug_watch'])) {
  // 基本パラメータ
  $ym   = isset($_GET['ym']) ? (string)$_GET['ym'] : ($mdef['ym'] ?? date('Y-m'));
  $n    = max(1, (int)($_GET['n'] ?? 5));
  $list = !empty($_GET['list']);
  $showUnmatched = !empty($_GET['unmatched']);

  // actors.json の補助マップ
  $items = isset($actorsData['items']) && is_array($actorsData['items']) ? $actorsData['items'] : [];
  $id2meta = []; // id => ['name','channel']
  $key2id  = []; // channel(空ならname) および name => id
  foreach ($items as $a) {
    $id = (string)($a['id'] ?? '');
    if ($id === '') continue;
    $name = trim((string)($a['name'] ?? ''));
    $ch   = trim((string)($a['channel'] ?? ''));
    $id2meta[$id] = ['name'=>$name, 'channel'=>$ch];
    $k1 = $ch !== '' ? $ch : $name;
    if ($k1 !== '') $key2id[$k1] = $id;
    if ($name !== '') $key2id[$name] = $id; // name でも引けるように
  }

  // watch_metrics.json を読み込み
  $watchPath = rtrim($DATA_DIR,'/').'/watch_metrics.json';
  $watchRaw  = is_file($watchPath) ? json_decode((string)file_get_contents($watchPath), true) : [];
  $rows      = [];
  if (isset($watchRaw['items']) && is_array($watchRaw['items'])) $rows = $watchRaw['items'];
  elseif (isset($watchRaw['rows']) && is_array($watchRaw['rows'])) $rows = $watchRaw['rows'];

  // 検証対象の aid を先頭から n 件ピック
  $probeAids = array_slice(array_keys($actorsById), 0, $n);

  header('Content-Type: text/html; charset=UTF-8');
  echo '<div style="font:14px/1.6 system-ui, sans-serif;padding:12px;background:#0b1220;color:#e8f0ff">';
  echo '<h2 style="margin:0 0 12px">WATCH DEBUG (ym='.htmlspecialchars($ym).')</h2>';

  // 俳優ごとに: 集計 vs 元データを突き合わせ
  foreach ($probeAids as $aid) {
    $meta = $id2meta[$aid] ?? ['name'=>$actorsById[$aid] ?? '(unknown)','channel'=>''];
    $byDay = $watchByDay[$aid][$ym] ?? [];

    // ① 集計側（watchByDay）の月合計
    $sumWd = ['watch_hours'=>0.0,'views'=>0,'impressions'=>0];
    foreach ($byDay as $d => $vals) {
      $sumWd['watch_hours'] += (float)($vals['watch_hours'] ?? 0);
      $sumWd['views']       += (int)  ($vals['views'] ?? 0);
      $sumWd['impressions'] += (int)  ($vals['impressions'] ?? 0);
    }

    // ② 元JSONからの再集計（actor_name→aid を解決して該当分だけ）
    $sumSrc = ['watch_hours'=>0.0,'views'=>0,'impressions'=>0];
    $samples = [];
    if ($rows) {
      foreach ($rows as $r) {
        $an = trim((string)($r['actor_name'] ?? ''));
        $dt = trim((string)($r['date'] ?? ''));
        if ($an === '' || $dt === '') continue;
        $ts = strtotime($dt);
        if ($ts === false) continue;
        $ymRow = date('Y-m', $ts);
        if ($ymRow !== $ym) continue;
        $aidRow = $key2id[$an] ?? null;
        if ($aidRow !== $aid) continue;

        $wh = (float)($r['watch_hours'] ?? 0);
        $vw = (int)  ($r['views'] ?? 0);
        $im = (int)  ($r['impressions'] ?? 0);
        $sumSrc['watch_hours'] += $wh;
        $sumSrc['views']       += $vw;
        $sumSrc['impressions'] += $im;

        if ($list && count($samples) < 20) {
          $samples[] = [
            'date'=>$dt, 'actor_name'=>$an,
            'watch_hours'=>$wh, 'views'=>$vw, 'impressions'=>$im
          ];
        }
      }
    }

    echo '<div style="margin:10px 0;padding:10px;border:1px solid #25406d;border-radius:8px;background:#0e213d">';
    echo '<div style="font-weight:600;margin-bottom:6px">';
    echo 'AID: <code>'.htmlspecialchars($aid).'</code> / NAME: '.htmlspecialchars($meta['name']).' / CHANNEL: '.htmlspecialchars($meta['channel']).'</div>';

    echo '<table style="width:100%;border-collapse:collapse;font-size:13px">';
    echo '<tr><th style="text-align:left;padding:4px;border-bottom:1px solid #274a7a">項目</th><th style="text-align:right;padding:4px;border-bottom:1px solid #274a7a">集計結果</th><th style="text-align:right;padding:4px;border-bottom:1px solid #274a7a">元JSON再集計</th><th style="text-align:right;padding:4px;border-bottom:1px solid #274a7a">差分(元-集計)</th></tr>';

    $rowsShow = [
      ['総再生時間（時）', sprintf('%.3f',$sumWd['watch_hours']), sprintf('%.3f',$sumSrc['watch_hours']), sprintf('%.3f',$sumSrc['watch_hours'] - $sumWd['watch_hours'])],
      ['総再生回数', number_format($sumWd['views']), number_format($sumSrc['views']), number_format($sumSrc['views'] - $sumWd['views'])],
      ['インプレッション数', number_format($sumWd['impressions']), number_format($sumSrc['impressions']), number_format($sumSrc['impressions'] - $sumWd['impressions'])],
    ];
    foreach ($rowsShow as $rr) {
      echo '<tr><td style="padding:4px 6px">'.$rr[0].'</td>'.
           '<td style="padding:4px 6px;text-align:right">'.$rr[1].'</td>'.
           '<td style="padding:4px 6px;text-align:right">'.$rr[2].'</td>'.
           '<td style="padding:4px 6px;text-align:right">'.$rr[3].'</td></tr>';
    }
    echo '</table>';

    // 非0日の一覧（watchByDay）
    echo '<div style="margin-top:6px;font-weight:600">非0日（watchByDay）</div>';
    echo '<pre style="background:#0b1c34;padding:8px;border-radius:6px;max-height:160px;overflow:auto">';
    $printed = 0;
    if ($byDay) {
      ksort($byDay);
      foreach ($byDay as $d=>$vals) {
        $wh = (float)($vals['watch_hours'] ?? 0);
        $vw = (int)  ($vals['views'] ?? 0);
        $im = (int)  ($vals['impressions'] ?? 0);
        if ($wh != 0 || $vw != 0 || $im != 0) {
          printf("%s-%02d  hours=%.3f  views=%d  impressions=%d\n", $ym, (int)$d, $wh, $vw, $im);
          if (++$printed >= 40) { echo "(…省略…)\n"; break; }
        }
      }
      if ($printed === 0) echo "(none)\n";
    } else {
      echo "(none)\n";
    }
    echo '</pre>';

    // サンプル行（元JSON）
    if ($list) {
      echo '<div style="margin-top:6px;font-weight:600">元JSONサンプル（最大20件）</div>';
      echo '<pre style="background:#0b1c34;padding:8px;border-radius:6px;max-height:160px;overflow:auto">';
      if ($samples) {
        foreach ($samples as $s) {
          printf("%s  %s  hours=%.3f  views=%d  impressions=%d\n",
            $s['date'], $s['actor_name'], $s['watch_hours'], $s['views'], $s['impressions']);
        }
      } else {
        echo "(no rows)\n";
      }
      echo '</pre>';
    }

    // 補足ヒント
    if (array_sum($sumWd) == 0 && array_sum($sumSrc) > 0) {
      echo '<div style="margin-top:6px;color:#ffcd7a">⚠ watchByDay が 0 だが元JSONは > 0。→ 紐付けや日付キーの投入処理を確認。</div>';
    } elseif (array_sum($sumWd) > 0 && array_sum($sumSrc) == 0) {
      echo '<div style="margin-top:6px;color:#ffcd7a">⚠ 元JSON再集計が 0。→ ym='.htmlspecialchars($ym).' が一致しているか、actor_name が期待通りか確認。</div>';
    }

    echo '</div>';
  }

  // actors に紐付かない actor_name の一覧
  if ($showUnmatched) {
    $unmatched = [];
    foreach ($rows as $r) {
      $an = trim((string)($r['actor_name'] ?? ''));
      if ($an !== '' && !isset($key2id[$an])) $unmatched[$an] = true;
    }
    echo '<div style="margin-top:12px;padding:10px;border:1px dashed #335b97;border-radius:8px;background:#0e213d">';
    echo '<div style="font-weight:600;margin-bottom:6px">actors.json に紐付かない actor_name</div>';
    echo '<pre style="background:#0b1c34;padding:8px;border-radius:6px;max-height:220px;overflow:auto">';
    if ($unmatched) {
      ksort($unmatched);
      foreach (array_keys($unmatched) as $nm) echo $nm, "\n";
    } else {
      echo "(none)\n";
    }
    echo '</pre></div>';
  }

  echo '<div style="margin-top:10px;opacity:.8">tips: ?debug_watch=1&ym=YYYY-MM&n=5&list=1&unmatched=1</div>';
  echo '</div>';
  exit;
}


// 表示用データ
$actors        = load_json($DATA_DIR.'/actors.json')['items'] ?? [];
$salesPeople   = load_json($DATA_DIR.'/sales.json')['items'] ?? [];
$watchByDay = build_watch_by_day($DATA_DIR, $CACHE_DIR);

$actorsById    = map_actors_by_id($actors);
$choseiByDay   = build_chosei_by_day($DATA_DIR, $CACHE_DIR);
// $choseiByDay   = build_chosei_by_day_actor($DATA_DIR, $CACHE_DIR); // [aid][ym][d] => count
$inflowByDay   = build_inflow_by_day($DATA_DIR, $CACHE_DIR);
$denwaWaitByDay   = build_denwa_wait_by_day($DATA_DIR, $CACHE_DIR);
$denwaLostByDay   = build_denwa_lost_by_day($DATA_DIR, $CACHE_DIR);
$taiouCountByDay   = build_taiou_count_by_day($DATA_DIR, $CACHE_DIR);
$seiyakuByDay   = build_seiyaku_by_day($DATA_DIR, $CACHE_DIR);
$nyukinAmountByDay   = build_nyukin_amount_by_day($DATA_DIR, $CACHE_DIR);
// $watchByDay    = build_watch_by_day($DATA_DIR, $CACHE_DIR); // ★追加（他の build_* と同じ形）

require_once __DIR__ . '/aggregate/aggregate_sales_count.php';
$salesCountByDay = build_sales_count_by_day($DATA_DIR, $CACHE_DIR);
require_once __DIR__ ."/debug/debug_sales_count.php";
require_once __DIR__ . '/aggregate/aggregate_sales_seiyaku_count.php';
$salesSeiyakuCountByDay = build_sales_seiyaku_count_by_day($DATA_DIR, $CACHE_DIR);
require_once __DIR__ . '/aggregate/aggregate_sales_nyukin_count.php';
$salesNyukinCountByDay = build_sales_nyukin_count_by_day($DATA_DIR, $CACHE_DIR);
require_once __DIR__ ."/debug/debug_sales_nyukin.php";
require_once __DIR__ . '/aggregate/aggregate_sales_nyukin_amount.php';
$salesNyukinAmountByDay = build_sales_nyukin_amount_by_day($DATA_DIR, $CACHE_DIR);


require_once __DIR__ . '/debug/debug_chosei.php';
// 月指定
$base = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $base)) $base = date('Y-m');
$prev = date('Y-m', strtotime("$base-01 -1 month"));

$months = [
  ['key'=>'current', 'label'=>'当月', 'ym'=>$base],
  ['key'=>'prev',    'label'=>'前月', 'ym'=>$prev],
  ['key'=>'diff',    'label'=>'比較', 'ym'=>$base],
];

$metrics = [
  '総再生時間（時）','総再生回数','インプレッション数',
  '流入数','流入比率','1流入再生','調整済み','電話対応待ち','電話前失注',
  '対応件数','成約件数','セールス成約率','入金件数','入金額',
];

$salesMetrics = ['セールス件数','成約件数','セールス成約率','入金件数','入金額'];

// echo '<pre>';
// echo "DEBUG inflow sample:\n";
// print_r(array_slice($inflowByDay, 0, 5, true));
// echo "\nDEBUG chosei sample:\n";
// print_r(array_slice($choseiByDay, 0, 5, true));
// echo '</pre>';

$pageTitle = "日別集計ダッシュボード ($base / $prev)";
$extraHead = '<link rel="stylesheet" href="public/styles.css?v=' . time() . '">';
require __DIR__ . '/partials/header.php';
?>
<?php
// 先頭付近 or この見出しの少し前で実行（1回だけでOK）
date_default_timezone_set('Asia/Tokyo'); // 必要ならJSTに固定

$updatedAt = '—';
$tryPaths = 'cache/raw_rows.json';


  if (file_exists($tryPaths)) {
    $mtime = filemtime($tryPaths);
    $updatedAt = date('Y-m-d H:i', $mtime);  // 例）2025-10-02 00:00
  }
?>

<div class="container">
<h1>日別集計ダッシュボード
  <br>
  <small style="color:var(--muted)">
    データ更新日時 <?= htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8') ?>
  </small>
</h1>

<?php require_once 'ui/partials/button_generator.php';?>
<?php echo getInformationTooltip();?> 

  <div class="card compact dash-toolbar">
    <?php $bprev = date('Y-m', strtotime("$base-01 -1 month")); $bnext = date('Y-m', strtotime("$base-01 +1 month")); ?>
    <div class="btn-group">
      <a class="btn ghost" href="?month=<?= $bprev ?>">&laquo; <?= $bprev ?></a>
      <a class="btn" href="?month=<?= date('Y-m') ?>">今月（<?= date('Y-m') ?>）</a>
      <a class="btn ghost" href="?month=<?= $bnext ?>"><?= $bnext ?> &raquo;</a>
    </div>
  </div>
    
  <div class="dash-toolbar card compact">
    <form method="get" class="date-select-form">
      <label for="max_days" class="label-inline">表示日数：</label>
      <select name="max_days" id="max_days" class="date-select">
        <option value="">全期間</option>
        <?php for ($d = 1; $d <= 31; $d++): ?>
          <option value="<?= $d ?>" <?= ($_GET['max_days'] ?? '') == $d ? 'selected' : '' ?>>
            <?= $d ?>日まで
          </option>
        <?php endfor; ?>
      </select>
      <button type="submit" class="btn primary">再計算</button>
    </form>
  </div>




  <?php foreach ($actors as $a): $aid = (string)($a['id'] ?? ''); if ($aid==='') continue; ?>
    <?php
      $name    = trim((string)($a['name'] ?? ''));
      $kana    = trim((string)($a['kana'] ?? ''));
      $channel = trim((string)($a['channel'] ?? ($a['channel;'] ?? '')));
      $img     = trim((string)($a['img'] ?? ''));
    ?>
    <h2 class="actor-header">
      <?php if ($img !== ''): ?>
        <img
          class="avatar"
          src="<?= htmlspecialchars($img) ?>"
          alt="<?= htmlspecialchars($name ?: 'actor') ?>"
          loading="lazy" decoding="async" referrerpolicy="no-referrer"
        >
      <?php else: ?>
        <!-- 画像が無い場合のプレースホルダ（先頭1文字） -->
        <span class="avatar placeholder" aria-hidden="true">
          <?= $name !== '' ? htmlspecialchars(mb_substr($name, 0, 1)) : '?' ?>
        </span>
      <?php endif; ?>

      <span class="actor-name">
        <?= htmlspecialchars($name) ?>
        <?php if ($kana !== ''): ?>
          <span class="meta"><?= htmlspecialchars($kana) ?></span>
        <?php endif; ?>
        <?php if ($channel !== ''): ?>
          <span class="channel"><?= htmlspecialchars($channel) ?></span>
        <?php endif; ?>
      </span>
    </h2>

    <div class="months-grid months-grid-3">
    <?php
    [$curY,$curM,$curDays] = y_m_d_stat($base);

    foreach ($months as $mdef):
      $isDiff = ($mdef['key'] === 'diff');
      [$y,$m,$days] = y_m_d_stat($mdef['ym']);
      if ($isDiff) { $y=$curY; $m=$curM; $days=$curDays; }

      // ▼ max_days指定があれば制限（安全補正あり）
      if (isset($_GET['max_days']) && is_numeric($_GET['max_days'])) {
          $maxDays = (int)$_GET['max_days'];
          // 月末より大きい値を自動で切り詰め
          $days = min($days, $maxDays);
      }

      $badge = $mdef['label'].' / '.$mdef['ym'];
      $badge_key=$mdef['key'];
    ?>
      <section class="card month-card"
              data-month="<?= htmlspecialchars($mdef['ym']) ?>"
              data-kind="<?= $mdef['key'] ?>">
        <?php include __DIR__ . '/ui/partials/month_card.php'; ?>
      </section>
    <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
<script src="public/dashboard.js?v=<?php echo time(); ?>"></script>
