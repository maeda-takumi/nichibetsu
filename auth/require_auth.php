<?php
// auth/require_auth.php
declare(strict_types=1);
session_start();

if (empty($_SESSION['user'])) {
  $returnTo = urlencode($_SERVER['REQUEST_URI'] ?? '/');
  header('Location: /auth/login.php?return_to=' . $returnTo);
  exit;
}
