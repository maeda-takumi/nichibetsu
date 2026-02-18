<?php
session_start();
if (!empty($_SESSION['user'])) {
  header('Location: /analyze/admin_dashboard.php', true, 302);
  exit;
}
// 未ログイン時の案内（任意）
header('Location: /analyze/auth/login.php?return_to=%2Fanalyze%2Fadmin_dashboard.php', true, 302);
exit;
