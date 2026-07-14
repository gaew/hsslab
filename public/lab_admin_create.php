<?php
// /public/lab_admin_create.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_role(['SUPER_ADMIN']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$msg = '';
$err = '';

// โหลดรายการ LAB ที่ยัง Active
$labs = $pdo->query("SELECT id, lab_code, lab_name
                     FROM labs
                     WHERE is_active = 1
                     ORDER BY lab_code ASC")->fetchAll();

// ===== Handle Create LAB_ADMIN =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $lab_id    = (int)($_POST['lab_id'] ?? 0);
  $username  = trim($_POST['username'] ?? '');
  $full_name = trim($_POST['full_name'] ?? '');
  $password  = (string)($_POST['password'] ?? '');
  $password2 = (string)($_POST['password2'] ?? '');

  if ($lab_id <= 0) {
    $err = 'กรุณาเลือก LAB';
  } elseif ($username === '' || $password === '' || $password2 === '') {
    $err = 'กรุณากรอกข้อมูลให้ครบ (Username / Password / Confirm Password)';
  } elseif (!preg_match('/^[a-zA-Z0-9_.-]{4,50}$/', $username)) {
    $err = 'Username ต้องยาว 4-50 ตัว และใช้ได้เฉพาะ a-z A-Z 0-9 _ . -';
  } elseif (strlen($password) < 10) {
    $err = 'รหัสผ่านต้องยาวอย่างน้อย 10 ตัวอักษร';
  } elseif ($password !== $password2) {
    $err = 'รหัสผ่านไม่ตรงกัน';
  } else {
    // ตรวจว่า LAB ยัง active อยู่จริง
    $stmt = $pdo->prepare("SELECT id, lab_code, lab_name, is_active FROM labs WHERE id=:id LIMIT 1");
    $stmt->execute([':id' => $lab_id]);
    $lab = $stmt->fetch();

    if (!$lab || (int)$lab['is_active'] !== 1) {
      $err = 'LAB ที่เลือกไม่พร้อมใช้งาน (อาจถูกระงับ)';
    } else {
      // ตรวจ username ซ้ำ
      $stmt = $pdo->prepare("SELECT id FROM users WHERE username=:u LIMIT 1");
      $stmt->execute([':u' => $username]);
      if ($stmt->fetch()) {
        $err = 'Username นี้ถูกใช้แล้ว';
      } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
          INSERT INTO users (lab_id, username, password_hash, full_name, role, is_active)
          VALUES (:lab_id, :u, :ph, :fn, 'LAB_ADMIN', 1)
        ");
        $stmt->execute([
          ':lab_id' => $lab_id,
          ':u'      => $username,
          ':ph'     => $hash,
          ':fn'     => $full_name !== '' ? $full_name : ('LAB ADMIN ' . $lab['lab_code']),
        ]);

        $msg = "สร้าง LAB_ADMIN สำเร็จสำหรับ {$lab['lab_code']} ({$lab['lab_name']}) — Username: {$username}";
      }
    }
  }
}

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>สร้าง LAB_ADMIN | SUPER_ADMIN</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f5f5;margin:0}
    .wrap{max-width:760px;margin:24px auto;padding:0 16px}
    .card{background:#fff;border-radius:14px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.06);margin-bottom:14px}
    h1{font-size:20px;margin:0 0 8px}
    label{display:block;margin:.65rem 0 .25rem}
    input,select{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:10px;font-size:15px}
    button{padding:10px 12px;border:0;border-radius:10px;font-size:15px;cursor:pointer;margin-top:14px;width:100%}
    .ok{background:#f0fff4;border:1px solid #b7ebc6;color:#14532d;padding:10px;border-radius:10px;margin-bottom:12px}
    .err{background:#fff2f2;border:1px solid #ffd0d0;color:#9b1c1c;padding:10px;border-radius:10px;margin-bottom:12px}
    .muted{color:#666;font-size:13px;line-height:1.5}
    a{color:#0b57d0;text-decoration:none}
    .topbar{display:flex;justify-content:space-between;align-items:center;gap:10px}
  </style>
  <?php require __DIR__ . '/../includes/pwa_head.php'; ?>
</head>
<body>
  <div class="wrap">

    <div class="card">
      <div class="topbar">
        <div>
          <h1>สร้างผู้ใช้ LAB_ADMIN (เฉพาะ SUPER_ADMIN)</h1>
          <div class="muted">เลือกศบส (LAB) แล้วกำหนด Username/Password ระบบจะ hash ให้อัตโนมัติ</div>
        </div>
        <div>
          <a href="labs_manage.php">จัดการ LAB</a> | <a href="dashboard.php">Dashboard</a> | <a href="logout.php">ออกจากระบบ</a>
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
      <?php if (!$labs): ?>
        <div class="muted">
          ยังไม่มี LAB ที่ Active หรืออาจถูกระงับทั้งหมด<br>
          ไปที่ <a href="labs_manage.php">จัดการ LAB</a> เพื่อสร้าง/เปิดใช้งานก่อน
        </div>
      <?php else: ?>
        <form method="post" autocomplete="off">
          <label>เลือก LAB (ศบส)</label>
          <select name="lab_id" required>
            <option value="">-- เลือก --</option>
            <?php foreach ($labs as $lab): ?>
              <option value="<?= (int)$lab['id'] ?>"
                <?= ((int)($_POST['lab_id'] ?? 0) === (int)$lab['id']) ? 'selected' : '' ?>>
                <?= h($lab['lab_code'] . ' — ' . $lab['lab_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label>ชื่อผู้ใช้ (Username)</label>
          <input name="username" placeholder="เช่น hsbsc01_admin" required value="<?= h($_POST['username'] ?? '') ?>">

          <label>ชื่อ-สกุล / ชื่อผู้ดูแล (Full name)</label>
          <input name="full_name" placeholder="เช่น ผู้ดูแลระบบ ศบส1" value="<?= h($_POST['full_name'] ?? '') ?>">

          <label>รหัสผ่าน (อย่างน้อย 10 ตัว)</label>
          <input name="password" type="password" required>

          <label>ยืนยันรหัสผ่าน</label>
          <input name="password2" type="password" required>

          <button type="submit">สร้าง LAB_ADMIN</button>

          <div class="muted" style="margin-top:10px">
            Username ใช้ได้เฉพาะ a-z A-Z 0-9 _ . - และต้องไม่ซ้ำในระบบ<br>
            แนะนำตั้งรูปแบบ: <b>hsbsc01_admin</b>, <b>hsbsc02_admin</b> ...
          </div>
        </form>
      <?php endif; ?>
    </div>

  </div>
<?php require __DIR__ . '/../includes/pwa_footer.php'; ?>
</body>
</html>
