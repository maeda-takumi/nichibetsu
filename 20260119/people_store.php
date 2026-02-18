<?php
// people_store.php
declare(strict_types=1);

function ds_path(array $config, string $kind): string {
  $dir = $config['DATA_DIR'] ?? (__DIR__ . '/data');
  if (!is_dir($dir)) mkdir($dir, 0775, true);
  if ($kind === 'actors') return $dir . '/actors.json';
  if ($kind === 'sales')  return $dir . '/sales.json';
  throw new RuntimeException('unknown kind');
}

function ds_load(array $config, string $kind): array {
  $path = ds_path($config, $kind);
  if (!file_exists($path)) {
    return ['kind'=>$kind, 'updated_at'=>null, 'items'=>[]];
  }
  $json = file_get_contents($path);
  $data = json_decode($json, true);
  if (!is_array($data)) $data = ['kind'=>$kind, 'updated_at'=>null, 'items'=>[]];
  if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = [];
  return $data;
}

function ds_save_atomic(array $config, string $kind, array $data): void {
  $path = ds_path($config, $kind);
  $dir  = dirname($path);
  if (!is_dir($dir)) mkdir($dir, 0775, true);

  $tmp = tempnam($dir, $kind.'_');
  $data['updated_at'] = date('c');
  $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);

  $fp = fopen($tmp, 'c+');
  if (!$fp) throw new RuntimeException('tmp open failed');
  if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException('lock failed'); }
  ftruncate($fp, 0);
  fwrite($fp, $json);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  if (!rename($tmp, $path)) {
    @unlink($tmp);
    throw new RuntimeException('rename failed');
  }
}

function uuid_v4(): string {
  $d = random_bytes(16);
  $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
  $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/** 正規化＆バリデーション（aliases 対応） */
function normalize_item(array $it): array {
  $aliases = $it['aliases'] ?? [];
  if (!is_array($aliases)) {
    // フォームでカンマ区切り文字列が来た場合の救済
    $aliases = array_filter(array_map('trim', explode(',', (string)$aliases)));
  }
  $out = [
    'id'     => (string)($it['id']    ?? ''),
    'name'   => trim((string)($it['name'] ?? '')),
    'kana'   => trim((string)($it['kana'] ?? '')),
    'email'  => trim((string)($it['email'] ?? '')),
    'tags'   => is_array($it['tags'] ?? null) ? array_values(array_map('strval', $it['tags'])) : [],
    'active' => isset($it['active']) ? (bool)$it['active'] : true,
    'note'   => (string)($it['note'] ?? ''),
    'aliases'=> array_values(array_unique(array_filter(array_map('strval', $aliases)))),
  ];
  if ($out['name'] === '') throw new RuntimeException('name is required');
  if ($out['id'] === '') $out['id'] = uuid_v4();
  return $out;
}
