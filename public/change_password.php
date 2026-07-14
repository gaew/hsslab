<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$u = current_user();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $old = (string)($_POST['old_password'] ?? '');
  $new = (string)($_POST['new_password'] ?? '');
  $new2 = (string)($_POST['new_password2'] ?? '');

  if ($old === '' || $new === '' || $new2 === '') {
    $err = 'กรุณากรอกข้อมูลให้ครบ';
  } elseif (strlen($new) < 10) {
    $err = 'รหัสผ่านใหม่ต้องยาวอย่างน้อย 10 ตัวอักษร';
  } elseif ($new !== $new2) {
    $err = 'ยืนยันรหัสผ่านใหม่ไม่ตรงกัน';
  } else {
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE id=:id AND is_active=1 LIMIT 1");
    $stmt->execute([':id' => (int)$u['id']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($old, $user['password_hash'])) {
      $err = 'รหัสผ่านเดิมไม่ถูกต้อง';
    } else {
      $hash = password_hash($new, PASSWORD_DEFAULT);

      $stmt = $pdo->prepare("UPDATE users SET password_hash=:h WHERE id=:id");
      $stmt->execute([
        ':h' => $hash,
        ':id' => (int)$u['id']
      ]);

      $msg = 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว';
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>เปลี่ยนรหัสผ่าน</title>
<style>
body{font-family:system-ui;background:#f5f5f5;margin:0}
.wrap{max-width:560px;margin:40px auto;padding:0 16px}
.card{background:#fff;border-radius:16px;padding:22px;box-shadow:0 10px 30px rgba(0,0,0,.06)}
label{display:block;margin:.7rem 0 .25rem}
input{width:100%;padding:11px 12px;border:1px solid #ddd;border-radius:10px;font-size:16px}
button{margin-top:16px;padding:10px 14px;border:0;border-radius:10px;cursor:pointer}
.ok{background:#f0fff4;border:1px solid #b7ebc6;color:#14532d;padding:10px;border-radius:10px;margin-bottom:12px}
.err{background:#fff2f2;border:1px solid #ffd0d0;color:#9b1c1c;padding:10px;border-radius:10px;margin-bottom:12px}
a{color:#0b57d0;text-decoration:none}
  </style>
  <?php require __DIR__ . '/../includes/pwa_head.php'; ?>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h2>เปลี่ยนรหัสผ่าน</h2>
    <p>ผู้ใช้: <b><?= h($u['username']) ?></b></p>

    <?php if ($msg): ?><div class="ok"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <label>รหัสผ่านเดิม</label>
      <input type="password" name="old_password" required>

      <label>รหัสผ่านใหม่</label>
      <input type="password" name="new_password" required>

      <label>ยืนยันรหัสผ่านใหม่</label>
      <input type="password" name="new_password2" required>

      <button type="submit">บันทึก</button>
    </form>

    <p><a href="dashboard.php">← กลับ Dashboard</a></p>
  </div>
</div>
<?php require __DIR__ . '/../includes/pwa_footer.php'; ?>
</body>
</html>
