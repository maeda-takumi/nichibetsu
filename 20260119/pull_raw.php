<?php
// pull_raw.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['TIMEZONE']);
require_once __DIR__ . '/import_sheets.php';

try {
  $payload = fetchAllSourcesAssocNoSDK($config);
  saveRawCache($config, $payload);

  $n = count($payload['rows']);
  echo "Imported rows: {$n}\n";
  if ($n) {
    echo "Headers: ".implode(' | ', $payload['headers'])."\n";
    foreach (array_slice($payload['rows'], 0, 3) as $i => $r) {
      echo '#'.($i+1).': '.json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
    }
  }
  if (!empty($config['RAW_CACHE_FILE'])) {
    echo "\nCache: {$config['RAW_CACHE_FILE']}\n";
  }
} catch (Throwable $e) {
  fwrite(STDERR, "[ERROR] ".$e->getMessage()."\n");
  exit(1);
}
