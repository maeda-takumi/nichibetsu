<?php
// api_people.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/people_store.php';

header('Content-Type: application/json; charset=utf-8');

// --- 簡易トークン認証（同一オリジン前提） ---
$token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_GET['token'] ?? '');
if (($config['ADMIN_TOKEN'] ?? '') !== $token) {
  http_response_code(401);
  echo json_encode(['error'=>'unauthorized']); exit;
}

// kind: actors | sales
$kind = $_GET['kind'] ?? ($_POST['kind'] ?? '');
if (!in_array($kind, ['actors','sales'], true)) {
  http_response_code(400);
  echo json_encode(['error'=>'invalid kind']); exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
  if ($method === 'GET') {
    // 一覧
    $data = ds_load($config, $kind);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }

  // POSTのみ書き込み
  if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error'=>'method not allowed']); exit;
  }

  // JSONボディ受信
  $raw  = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) $body = $_POST;

  $action = $body['action'] ?? '';
  $data = ds_load($config, $kind);

  if ($action === 'upsert') {
    $item = normalize_item($body['item'] ?? []);
    // 既存検索
    $idx = -1;
    foreach ($data['items'] as $i => $r) {
      if (($r['id'] ?? '') === $item['id']) { $idx = $i; break; }
    }
    if ($idx >= 0) {
      $data['items'][$idx] = $item;
    } else {
      array_unshift($data['items'], $item); // 先頭に挿入
    }
    ds_save_atomic($config, $kind, $data);
    echo json_encode(['ok'=>true, 'item'=>$item], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'delete') {
    $id = (string)($body['id'] ?? '');
    if ($id === '') throw new RuntimeException('id required');
    $data['items'] = array_values(array_filter($data['items'], fn($r)=> ($r['id'] ?? '') !== $id));
    ds_save_atomic($config, $kind, $data);
    echo json_encode(['ok'=>true]); exit;
  }

  http_response_code(400);
  echo json_encode(['error'=>'invalid action']);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
