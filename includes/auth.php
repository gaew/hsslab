<?php
// /includes/auth.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
  // เพิ่มความปลอดภัย session
  ini_set('session.use_strict_mode', '1');
  ini_set('session.cookie_httponly', '1');
  // ถ้าเป็น HTTPS ให้เปิดด้วย:
  // ini_set('session.cookie_secure', '1');
  session_start();
}

function require_login(): void {
  if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
  }
}

function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

function require_role(array $allowedRoles): void {
  $u = current_user();
  if (!$u || !in_array($u['role'], $allowedRoles, true)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }
}