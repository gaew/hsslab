<?php
// /public/labs_manage.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_role(['SUPER_ADMIN']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$msg = '';
$err = '';

// ===== Handle Create LAB =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_lab') {
  $lab_code = trim($_POST['lab_code'] ?? '');
  $lab_name = trim($_POST['lab_name'] ?? '');

  if ($lab_code === '' || $lab_name === '') {
    $err = 'กรุณากรอก LAB Code และชื่อ LAB ให้ครบ';
  } elseif (!preg_match('/^[A-Z0-9_-]{3,50}$/', $lab_code)) {
    $err = 'LAB Code ต้องเป็นตัวพิมพ์ใหญ่/ตัวเลข/ _ - เท่านั้น (ยาว 3-50 ตัว) เช่น HSBSC-01';
  } else {
    // ป้องกันซ้ำ
    $stmt = $pdo->prepare("SELECT id FROM labs WHERE lab_code = :c LIMIT 1");
    $stmt->execute([':c' => $lab_code]);
    if ($stmt->fetch()) {
      $err = 'LAB Code นี้มีอยู่แล้ว';
    } else {
      $stmt = $pdo->prepare("INSERT INTO labs (lab_code, lab_name, is_active) VALUES (:c, :n, 1)");
      $stmt->execute([':c' => $lab_code, ':n' => $lab_name]);
      $msg = 'สร้าง LAB สำเร็จ';
    }
  }
}

// ===== Handle Toggle Active =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_lab') {
  $lab_id = (int)($_POST['lab_id'] ?? 0);
  $to_state = (int)($_POST['to_state'] ?? -1); // 0 or 1

  if ($lab_id <= 0 || ($to_state !== 0 && $to_state !== 1)) {
    $err = 'คำสั่งไม่ถูกต้อง';
  } else {
    $stmt = $pdo->prepare("UPDATE labs SET is_active = :s WHERE id = :id");
    $stmt->execute([':s' => $to_state, ':id' => $lab_id]);

    // (แนะนำ) ถ้าระงับ LAB ให้ปิด user ใน LAB นั้นด้วยชั่วคราว
    // ถ้าไม่ต้องการ behavior นี้ ให้คอมเมนต์ส่วนนี้ออก
    if ($to_state === 0) {
      $pdo->prepare("UPDATE users SET is_active = 0 WHERE lab_id = :lab_id AND role <> 'SUPER_ADMIN'")
          ->execute([':lab_id' => $lab_id]);
    }

    $msg = $to_state === 1 ? 'เปิดใช้งาน LAB แล้ว' : 'ระงับการใช้งาน LAB แล้ว';
  }
}

// ===== Load LAB list =====
$labs = $pdo->query("SELECT id, lab_code, lab_name, is_active, created_at
                     FROM labs
                     ORDER BY lab_code ASC")->fetchAll();

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>จัดการ LAB | SUPER_ADMIN</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f5f5;margin:0}
    .wrap{max-width:980px;margin:24px auto;padding:0 16px}
    .card{background:#fff;border-radius:14px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.06);margin-bottom:14px}
    h1{font-size:20px;margin:0 0 8px}
    h2{font-size:16px;margin:0 0 10px}
    label{display:block;margin:.6rem 0 .25rem}
    input{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:10px;font-size:15px}
    button{padding:9px 12px;border:0;border-radius:10px;font-size:14px;cursor:pointer}
    .row{display:grid;grid-template-columns:1fr 2fr auto;gap:10px;align-items:end}
    .ok{background:#f0fff4;border:1px solid #b7ebc6;color:#14532d;padding:10px;border-radius:10px;margin-bottom:12px}
    .err{background:#fff2f2;border:1px solid #ffd0d0;color:#9b1c1c;padding:10px;border-radius:10px;margin-bottom:12px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;font-size:14px}
    .badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px}
    .on{background:#e7f5ff;color:#1c4ed8;border:1px solid #cfe8ff}
    .off{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3}
    .actions{display:flex;gap:8px}
    .btn-off{background:#fee2e2}
    .btn-on{background:#dcfce7}
    .btn{background:#eaeaea}
    a{color:#0b57d0;text-decoration:none}
    .topbar{display:flex;justify-content:space-between;align-items:center;gap:10px}
    .muted{color:#666;font-size:13px;line-height:1.5}
  </style>
</head>
<body>
  <div class="wrap">

    <div class="card">
      <div class="topbar">
        <div>
          <h1>จัดการ LAB (ศบส) — SUPER_ADMIN</h1>
          <div class="muted">สร้างศูนย์ทีละแห่ง และระงับ/เปิดใช้งานได้ (is_active)</div>
        </div>
        <div>
          <a href="dashboard.php">กลับ Dashboard</a> | <a href="logout.php">ออกจากระบบ</a>
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
      <h2>สร้าง LAB (ศบส) ทีละศูนย์</h2>
      <form method="post">
        <input type="hidden" name="action" value="create_lab">
        <div class="row">
          <div>
            <label>LAB Code (เช่น HSBSC-01)</label>
            <input name="lab_code" placeholder="HSBSC-01" required>
          </div>
          <div>
            <label>ชื่อ LAB</label>
            <input name="lab_name" placeholder="ศูนย์สนับสนุนบริการสุขภาพที่ 1" required>
          </div>
          <div>
            <button type="submit">สร้าง</button>
          </div>
        </div>
        <div class="muted" style="margin-top:10px">
          * แนะนำให้ใช้ code มาตรฐานเดียวกันทุกศูนย์ เช่น HSBSC-01 ถึง HSBSC-12 (หรือใช้ “ศบส01” ก็ได้ แต่โค้ดนี้บังคับตัวพิมพ์ใหญ่/ตัวเลข)
        </div>
      </form>
    </div>

    <div class="card">
      <h2>รายการ LAB</h2>
      <table>
        <thead>
          <tr>
            <th>LAB Code</th>
            <th>ชื่อ LAB</th>
            <th>สถานะ</th>
            <th>การทำงาน</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$labs): ?>
            <tr><td colspan="4">ยังไม่มี LAB</td></tr>
          <?php else: ?>
            <?php foreach ($labs as $lab): ?>
              <tr>
                <td><?= h($lab['lab_code']) ?></td>
                <td><?= h($lab['lab_name']) ?></td>
                <td>
                  <?php if ((int)$lab['is_active'] === 1): ?>
                    <span class="badge on">Active</span>
                  <?php else: ?>
                    <span class="badge off">Suspended</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="actions">
                    <?php if ((int)$lab['is_active'] === 1): ?>
                      <form method="post" onsubmit="return confirm('ยืนยันระงับการใช้งาน LAB นี้ชั่วคราว?');">
                        <input type="hidden" name="action" value="toggle_lab">
                        <input type="hidden" name="lab_id" value="<?= (int)$lab['id'] ?>">
                        <input type="hidden" name="to_state" value="0">
                        <button class="btn-off" type="submit">ระงับ</button>
                      </form>
                    <?php else: ?>
                      <form method="post" onsubmit="return confirm('ยืนยันเปิดใช้งาน LAB นี้?');">
                        <input type="hidden" name="action" value="toggle_lab">
                        <input type="hidden" name="lab_id" value="<?= (int)$lab['id'] ?>">
                        <input type="hidden" name="to_state" value="1">
                        <button class="btn-on" type="submit">เปิดใช้งาน</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <div class="muted" style="margin-top:10px">
        หมายเหตุ: ในโค้ดนี้ ถ้ากด “ระงับ” จะตั้ง <b>users.is_active=0</b> ของผู้ใช้ใน LAB นั้น (ยกเว้น SUPER_ADMIN) เพื่อกันล็อกอินเข้าไปทำงาน
        — ถ้าอยากให้ “ยังล็อกอินได้แต่ห้ามอ่าน/อัปโหลดเอกสาร” บอกผม เดี๋ยวปรับ logic ให้เหมาะกับ policy ที่ต้องการ
      </div>
    </div>

  </div>
</body>
</html>