<?php
// /public/dashboard.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$u = current_user();

// ดึงชื่อ LAB ถ้าเป็น LAB_ADMIN/LAB_STAFF
$lab = null;
if (!empty($u['lab_id'])) {
  $stmt = $pdo->prepare("SELECT id, lab_code, lab_name, is_active FROM labs WHERE id=:id LIMIT 1");
  $stmt->execute([':id' => $u['lab_id']]);
  $lab = $stmt->fetch();

  // ถ้า LAB ถูกระงับ ให้บังคับออก (เพราะเราตั้งระงับแบบล็อกอินไม่ได้อยู่แล้ว แต่กันเผื่อ)
  if ($lab && (int)$lab['is_active'] === 0) {
    header('Location: logout.php');
    exit;
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard | QDocs</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f5f5;margin:0}
    .wrap{max-width:980px;margin:24px auto;padding:0 16px}
    .card{background:#fff;border-radius:14px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.06);margin-bottom:14px}
    h1{font-size:20px;margin:0 0 6px}
    .muted{color:#666;font-size:13px}
    ul{margin:10px 0 0 18px}
    a{color:#0b57d0;text-decoration:none}
    .topbar{display:flex;justify-content:space-between;align-items:center;gap:10px}
    .badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;border:1px solid #ddd;background:#fafafa}
  </style>
  <?php require __DIR__ . '/../includes/pwa_head.php'; ?>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="topbar">
        <div>
          <h1>Dashboard</h1>
          <div class="muted">
            ผู้ใช้: <b><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></b>
            <span class="badge"><?= htmlspecialchars($u['role']) ?></span>
            <?php if ($lab): ?>
              <span class="badge"><?= htmlspecialchars($lab['lab_code']) ?> — <?= htmlspecialchars($lab['lab_name']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div><a href="logout.php">ออกจากระบบ</a></div>
      </div>
    </div>

    <?php if ($u['role'] === 'SUPER_ADMIN'): ?>
      <div class="card">
        <h2 style="margin:0 0 8px;font-size:16px;">เมนู SUPER_ADMIN</h2>
        <ul>
          <li><a href="labs_manage.php">จัดการ LAB (สร้าง/ระงับ)</a></li>
          <li><a href="lab_admin_create.php">สร้าง LAB_ADMIN ให้ศบส</a></li>
          <li><a href="central_refs.php">รายการเอกสารอ้างอิง</a></li>
        </ul>
      </div>

    <?php elseif ($u['role'] === 'LAB_ADMIN'): ?>
      <div class="card">
        <h2 style="margin:0 0 8px;font-size:16px;">เมนูผู้ดูแลศบส (LAB_ADMIN)</h2>
        <ul>
          <li><a href="lab_users_manage.php">จัดการผู้ใช้ของศบส (สร้าง/ระงับ)</a></li>
          <li><a href="docs_list.php">อ่าน/ค้นหาเอกสาร</a></li>
          <li><a href="doc_upload.php">อัปโหลดเอกสาร</a></li>
          <li><a href="central_refs.php">รายการเอกสารอ้างอิง</a></li>
          <li><a href="change_password.php">เปลี่ยนรหัสผ่าน</a></li>
        </ul>
      </div>

    <?php else: ?>
      <div class="card">
        <h2 style="margin:0 0 8px;font-size:16px;">เมนูผู้ใช้ศบส (LAB_STAFF)</h2>
        <ul>
          <li><a href="docs_list.php">อ่าน/ดาวน์โหลดเอกสาร</a></li>
          <li><a href="central_refs.php">รายการเอกสารอ้างอิง</a></li>
          <li><a href="change_password.php">เปลี่ยนรหัสผ่าน</a></li>
        </ul>
      </div>
    <?php endif; ?>

  </div>
<?php require __DIR__ . '/../includes/pwa_footer.php'; ?>
</body>
</html>
