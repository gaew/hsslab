<?php
// /public/login.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = (string)($_POST['password'] ?? '');

  if ($username === '' || $password === '') {
    $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
  } else {
    $stmt = $pdo->prepare("SELECT id, lab_id, username, password_hash, full_name, role, is_active
                           FROM users
                           WHERE username = :u
                           LIMIT 1");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    $success = 0;
    if ($user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
      // login success
      session_regenerate_id(true);

      $_SESSION['user'] = [
        'id'        => (int)$user['id'],
        'lab_id'    => $user['lab_id'] !== null ? (int)$user['lab_id'] : null,
        'username'  => $user['username'],
        'full_name' => $user['full_name'],
        'role'      => $user['role'],
      ];

      $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id")
          ->execute([':id' => $user['id']]);

      $success = 1;
    } else {
      $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง (หรือบัญชีถูกปิดใช้งาน)';
    }

    // auth log
    $pdo->prepare("INSERT INTO auth_logs (user_id, username, success, ip_addr, user_agent)
                   VALUES (:uid, :un, :s, :ip, :ua)")
        ->execute([
          ':uid' => $user['id'] ?? null,
          ':un'  => $username,
          ':s'   => $success,
          ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
          ':ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);

    if ($success === 1) {
      header('Location: dashboard.php');
      exit;
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login | QDocs</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f5f5;margin:0}
    .card{max-width:420px;margin:8vh auto;background:#fff;border-radius:14px;padding:22px;box-shadow:0 10px 30px rgba(0,0,0,.08)}
    label{display:block;margin:.6rem 0 .2rem}
    input{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:10px;font-size:16px}
    button{width:100%;padding:10px 12px;border:0;border-radius:10px;font-size:16px;cursor:pointer;margin-top:14px}
    .err{background:#fff2f2;border:1px solid #ffd0d0;color:#9b1c1c;padding:10px;border-radius:10px;margin-bottom:12px}
    .muted{color:#666;font-size:13px;margin-top:10px}
    h1{font-size:18px;margin:0 0 10px}
  </style>
  <?php require __DIR__ . '/../includes/pwa_head.php'; ?>
</head>
<body>
  <div class="card">
    <h1>ระบบเอกสารคุณภาพห้องปฏิบัติการ (QDocs)</h1>

    <?php if ($error !== ''): ?>
      <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <label>ชื่อผู้ใช้</label>
      <input name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

      <label>รหัสผ่าน</label>
      <input name="password" type="password" required>

      <button type="submit">เข้าสู่ระบบ</button>
    </form>

    <div class="muted">
      * ผู้ใช้แต่ละ LAB จะเห็นเฉพาะข้อมูลของ LAB ตัวเอง
    </div>
  </div>
<?php require __DIR__ . '/../includes/pwa_footer.php'; ?>
</body>
</html>
