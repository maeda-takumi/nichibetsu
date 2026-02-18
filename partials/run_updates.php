
<?php
// run_updates.php
// ../配下のスクリプトを順に実行

$commands = [
    '../import_inflows_matrix.php',
    '../import_watch_metrics.php',
    '../pull_raw.php'
];

foreach ($commands as $script) {
    if (file_exists($script)) {
        include $script;
    } else {
        http_response_code(500);
        echo "ファイルが見つかりません: " . $script;
        exit;
    }
}

http_response_code(200);
echo "更新完了";
?>
