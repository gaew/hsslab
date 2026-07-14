<?php
// /public/lab_users_manage.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_role(['LAB_ADMIN']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$u = current_user();
$lab_id = (int)($u['lab_id'] ?? 0);
if ($lab_id <= 0) {
  http_response_code(400);
  echo "LAB not assigned.";
  exit;
}

$msg = '';
$err = '';

// ดึงข้อมูล LAB (เอาไปโชว์หัวหน้า)
$stmt = $pdo->prepare("SELECT id, lab_code, lab_name, is_active FROM labs WHERE id=:id LIMIT 1");
$stmt->execute([':id' => $lab_id]);
$lab = $stmt->fetch();
if (!$lab || (int)$lab['is_active'] !== 1) {
  // LAB ถูกระงับแล้ว (กันไว้)
  header('Location: logout.php');
  exit;
}

// ===== Create user (LAB_ADMIN creates within own lab) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
  $username  = trim($_POST['username'] ?? '');
  $full_name = trim($_POST['full_name'] ?? '');
  $role      = $_POST['role'] ?? 'LAB_STAFF';
  $password  = (string)($_POST['password'] ?? '');
  $password2 = (string)($_POST['password2'] ?? '');

  // จำกัด role ที่ LAB_ADMIN สร้างได้ (ห้าม SUPER_ADMIN)
  $allowedRoles = ['LAB_ADMIN', 'LAB_STAFF'];
  if (!in_array($role, $allowedRoles, true)) {
    $err = 'Role ไม่ถูกต้อง';
  } elseif ($username === '' || $password === '' || $password2 === '') {
    $err = 'กรุณากรอกข้อมูลให้ครบ (Username / Password / Confirm Password)';
  } elseif (!preg_match('/^[a-zA-Z0-9_.-]{4,50}$/', $username)) {
    $err = 'Username ต้องยาว 4-50 ตัว และใช้ได้เฉพาะ a-z A-Z 0-9 _ . -';
  } elseif (strlen($password) < 10) {
    $err = 'รหัสผ่านต้องยาวอย่างน้อย 10 ตัวอักษร';
  } elseif ($password !== $password2) {
    $err = 'รหัสผ่านไม่ตรงกัน';
  } else {
    // username ต้องไม่ซ้ำทั้งระบบ
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username=:u LIMIT 1");
    $stmt->execute([':u' => $username]);
    if ($stmt->fetch()) {
      $err = 'Username นี้ถูกใช้แล้ว';
    } else {
      $hash = password_hash($password, PASSWORD_DEFAULT);

      $stmt = $pdo->prepare("
        INSERT INTO users (lab_id, username, password_hash, full_name, role, is_active)
        VALUES (:lab_id, :u, :ph, :fn, :role, 1)
      ");
      $stmt->execute([
        ':lab_id' => $lab_id,
        ':u'      => $username,
        ':ph'     => $hash,
        ':fn'     => $full_name !== '' ? $full_name : null,
        ':role'   => $role,
      ]);

      $msg = "สร้างผู้ใช้สำเร็จ — {$username} ({$role})";
    }
  }
}

// ===== Toggle user active within own lab =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_user') {
  $user_id  = (int)($_POST['user_id'] ?? 0);
  $to_state = (int)($_POST['to_state'] ?? -1); // 0 or 1

  if ($user_id <= 0 || ($to_state !== 0 && $to_state !== 1)) {
    $err = 'คำสั่งไม่ถูกต้อง';
  } elseif ($user_id === (int)$u['id']) {
    $err = 'ไม่สามารถระงับ/เปิดใช้งานบัญชีของตัวเองจากหน้านี้ได้';
  } else {
    // ต้องเป็น user ใน lab เดียวกัน และห้ามแตะ SUPER_ADMIN
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id=:id AND lab_id=:lab_id LIMIT 1");
    $stmt->execute([':id' => $user_id, ':lab_id' => $lab_id]);
    $target = $stmt->fetch();

    if (!$target) {
      $err = 'ไม่พบผู้ใช้ (หรือไม่อยู่ใน LAB นี้)';
    } elseif ($target['role'] === 'SUPER_ADMIN') {
      $err = 'ไม่อนุญาตให้แก้ไข SUPER_ADMIN';
    } else {
      $stmt = $pdo->prepare("UPDATE users SET is_active=:s WHERE id=:id AND lab_id=:lab_id");
      $stmt->execute([':s' => $to_state, ':id' => $user_id, ':lab_id' => $lab_id]);
      $msg = $to_state === 1 ? 'เปิดใช้งานผู้ใช้แล้ว' : 'ระงับผู้ใช้แล้ว';
    }
  }
}

// ===== Load users for this lab =====
$stmt = $pdo->prepare("SELECT id, username, full_name, role, is_active, last_login_at, created_at
                       FROM users
                       WHERE lab_id = :lab_id
                       ORDER BY role ASC, username ASC");
$stmt->execute([':lab_id' => $lab_id]);
$users = $stmt->fetchAll();

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>จัดการผู้ใช้ | <?= h($lab['lab_code']) ?></title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f5f5;margin:0}
    .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
    .card{background:#fff;border-radius:14px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.06);margin-bottom:14px}
    h1{font-size:20px;margin:0 0 6px}
    h2{font-size:16px;margin:0 0 10px}
    label{display:block;margin:.65rem 0 .25rem}
    input,select{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:10px;font-size:15px}
    button{padding:9px 12px;border:0;border-radius:10px;font-size:14px;cursor:pointer}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;font-size:14px;vertical-align:top}
    .ok{background:#f0fff4;border:1px solid #b7ebc6;color:#14532d;padding:10px;border-radius:10px;margin-bottom:12px}
    .err{background:#fff2f2;border:1px solid #ffd0d0;color:#9b1c1c;padding:10px;border-radius:10px;margin-bottom:12px}
    .muted{color:#666;font-size:13px;line-height:1.5}
    a{color:#0b57d0;text-decoration:none}
    .topbar{display:flex;justify-content:space-between;align-items:center;gap:10px}
    .grid{display:grid;grid-template-columns:1.2fr 1.2fr 1fr 1fr 1fr;gap:10px;align-items:end}
    .badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;border:1px solid #ddd;background:#fafafa}
    .on{background:#e7f5ff;color:#1c4ed8;border:1px solid #cfe8ff}
    .off{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3}
    .actions{display:flex;gap:8px;flex-wrap:wrap}
    .btn-off{background:#fee2e2}
    .btn-on{background:#dcfce7}
  </style>
  <?php require __DIR__ . '/../includes/pwa_head.php'; ?>
</head>
<body>
  <div class="wrap">

    <div class="card">
      <div class="topbar">
        <div>
          <h1>จัดการผู้ใช้ — <?= h($lab['lab_code']) ?> (<?= h($lab['lab_name']) ?>)</h1>
          <div class="muted">เข้าสู่ระบบโดย: <b><?= h($u['full_name'] ?: $u['username']) ?></b> (LAB_ADMIN)</div>
        </div>
        <div>
          <a href="dashboard.php">Dashboard</a> | <a href="logout.php">ออกจากระบบ</a>
        </div>
      </div>
    </div>

    <?php if ($msg !== ''): ?>
      <div class="ok"><?= h($msg) ?></div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
      <div class="err"><?= h($err) ?></div>
    <?php endif; ?>

    <div class="card">
      <h2>สร้างผู้ใช้ใหม่ (เฉพาะภายในศบสของคุณ)</h2>
      <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="create_user">

        <div class="grid">
          <div>
            <label>Username</label>
            <input name="username" placeholder="เช่น hsbsc01_staff01" required value="<?= h($_POST['username'] ?? '') ?>">
          </div>
          <div>
            <label>Full name</label>
            <input name="full_name" placeholder="ชื่อผู้ใช้" value="<?= h($_POST['full_name'] ?? '') ?>">
          </div>
          <div>
            <label>Role</label>
            <select name="role">
              <option value="LAB_STAFF" <?= (($_POST['role'] ?? '') === 'LAB_STAFF') ? 'selected' : '' ?>>LAB_STAFF</option>
              <option value="LAB_ADMIN" <?= (($_POST['role'] ?? '') === 'LAB_ADMIN') ? 'selected' : '' ?>>LAB_ADMIN</option>
            </select>
          </div>
          <div>
            <label>Password (>=10)</label>
            <input name="password" type="password" required>
          </div>
          <div>
            <label>Confirm</label>
            <input name="password2" type="password" required>
          </div>
        </div>

        <button type="submit" style="margin-top:12px;width:220px">สร้างผู้ใช้</button>

        <div class="muted" style="margin-top:10px">
          * ระบบนี้ไม่ให้สร้าง SUPER_ADMIN จากฝั่งศบส<br>
          * Username ต้องไม่ซ้ำทั้งระบบ
        </div>
      </form>
    </div>

    <div class="card">
      <h2>รายการผู้ใช้ในศบสนี้</h2>
      <table>
        <thead>
          <tr>
            <th>Username</th>
            <th>ชื่อ</th>
            <th>Role</th>
            <th>สถานะ</th>
            <th>Last login</th>
            <th>การทำงาน</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$users): ?>
            <tr><td colspan="6">ยังไม่มีผู้ใช้</td></tr>
          <?php else: ?>
            <?php foreach ($users as $row): ?>
              <tr>
                <td><?= h($row['username']) ?></td>
                <td><?= h((string)($row['full_name'] ?? '')) ?></td>
                <td><span class="badge"><?= h($row['role']) ?></span></td>
                <td>
                  <?php if ((int)$row['is_active'] === 1): ?>
                    <span class="badge on">Active</span>
                  <?php else: ?>
                    <span class="badge off">Suspended</span>
                  <?php endif; ?>
                </td>
                <td class="muted"><?= h((string)($row['last_login_at'] ?? '')) ?></td>
                <td>
                  <?php if ((int)$row['id'] === (int)$u['id']): ?>
                    <span class="muted">บัญชีคุณ</span>
                  <?php else: ?>
                    <div class="actions">
                      <?php if ((int)$row['is_active'] === 1): ?>
                        <form method="post" onsubmit="return confirm('ยืนยันระงับผู้ใช้นี้?');">
                          <input type="hidden" name="action" value="toggle_user">
                          <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                          <input type="hidden" name="to_state" value="0">
                          <button class="btn-off" type="submit">ระงับ</button>
                        </form>
                      <?php else: ?>
                        <form method="post" onsubmit="return confirm('ยืนยันเปิดใช้งานผู้ใช้นี้?');">
                          <input type="hidden" name="action" value="toggle_user">
                          <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                          <input type="hidden" name="to_state" value="1">
                          <button class="btn-on" type="submit">เปิดใช้งาน</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <div class="muted" style="margin-top:10px">
        หมายเหตุ: ระงับผู้ใช้แล้วจะล็อกอินไม่ได้ทันที
      </div>
    </div>

  </div>
<?php require __DIR__ . '/../includes/pwa_footer.php'; ?>
</body>
</html>
