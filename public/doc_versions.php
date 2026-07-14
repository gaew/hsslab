<?php
// /public/doc_versions.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
$u = current_user();
$lab_id = (int)($u['lab_id'] ?? 0);

$doc_id = (int)($_GET['doc'] ?? 0);
if ($doc_id <= 0) { http_response_code(400); exit("Bad doc"); }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$msg=''; $err='';

// ตรวจว่า doc อยู่ใน LAB เดียวกัน
$stmt = $pdo->prepare("SELECT * FROM documents WHERE id=:id AND lab_id=:lab LIMIT 1");
$stmt->execute([':id'=>$doc_id, ':lab'=>$lab_id]);
$doc = $stmt->fetch();
if (!$doc) { http_response_code(404); exit("Not found"); }

// ยกเลิกเวอร์ชัน (LAB_ADMIN)
if ($_SERVER['REQUEST_METHOD']==='POST' && ($u['role'] ?? '')==='LAB_ADMIN') {
  $ver_id = (int)($_POST['ver_id'] ?? 0);
  $reason = trim($_POST['reason'] ?? 'Cancelled');
  if ($ver_id>0) {
    $stmt = $pdo->prepare("
      UPDATE document_versions v
      JOIN documents d ON d.id = v.document_id
      SET v.status='CANCELLED', v.cancelled_at=NOW(), v.cancelled_reason=:r
      WHERE v.id=:vid AND d.id=:doc AND d.lab_id=:lab
    ");
    $stmt->execute([':r'=>$reason, ':vid'=>$ver_id, ':doc'=>$doc_id, ':lab'=>$lab_id]);
    $msg='ยกเลิกเวอร์ชันแล้ว';
  }
}

$stmt = $pdo->prepare("SELECT * FROM document_versions WHERE document_id=:did ORDER BY version_no DESC");
$stmt->execute([':did'=>$doc_id]);
$vers = $stmt->fetchAll();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Versions | <?= h($doc['doc_code']) ?></title>
  <style>
    body{font-family:system-ui;background:#f5f5f5;margin:0}
    .wrap{max-width:1000px;margin:24px auto;padding:0 16px}
    .card{background:#fff;border-radius:14px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.06);margin-bottom:14px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;font-size:14px;vertical-align:top}
    .badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;border:1px solid #ddd;background:#fafafa}
    .off{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3}
    .ok{background:#f0fff4;border:1px solid #b7ebc6;color:#14532d;padding:10px;border-radius:10px;margin-bottom:12px}
    .err{background:#fff2f2;border:1px solid #ffd0d0;color:#9b1c1c;padding:10px;border-radius:10px;margin-bottom:12px}
    .muted{color:#666;font-size:13px}
    a{color:#0b57d0;text-decoration:none}
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h2 style="margin:0">เวอร์ชันทั้งหมด: <?= h($doc['doc_type'].' '.$doc['doc_code']) ?> — <?= h($doc['doc_title']) ?></h2>
    <div class="muted"><a href="docs_list.php">กลับรายการเอกสาร</a></div>
  </div>

  <?php if ($msg): ?><div class="ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th>เวอร์ชัน</th>
          <th>ประกาศใช้</th>
          <th>อัปโหลด</th>
          <th>สถานะ</th>
          <th>เปิด/ดาวน์โหลด</th>
          <?php if (($u['role'] ?? '')==='LAB_ADMIN'): ?><th>จัดการ</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($vers as $v): ?>
          <tr>
            <td>v<?= (int)$v['version_no'] ?></td>
            <td><?= h((string)$v['effective_date']) ?></td>
            <td><?= h((string)$v['uploaded_at']) ?></td>
            <td>
              <?php if ($v['status']==='CANCELLED'): ?>
                <span class="badge off">CANCELLED</span><br>
                <span class="muted"><?= h((string)$v['cancelled_at']) ?></span><br>
                <span class="muted"><?= h((string)$v['cancelled_reason']) ?></span>
              <?php else: ?>
                <span class="badge">ACTIVE</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (in_array($doc['doc_type'], ['QM','QP','WI'], true)): ?>
                <a href="view_pdf.php?ver=<?= (int)$v['id'] ?>">เปิดอ่าน (stamp)</a>
              <?php else: ?>
                <a href="download_form.php?ver=<?= (int)$v['id'] ?>">ดาวน์โหลด Excel</a>
              <?php endif; ?>
            </td>
            <?php if (($u['role'] ?? '')==='LAB_ADMIN'): ?>
            <td>
              <?php if ($v['status']!=='CANCELLED'): ?>
                <form method="post" onsubmit="return confirm('ยืนยันยกเลิกเวอร์ชันนี้?');">
                  <input type="hidden" name="ver_id" value="<?= (int)$v['id'] ?>">
                  <input name="reason" placeholder="เหตุผล" value="ยกเลิก/ปรับปรุงเวอร์ชัน">
                  <button type="submit">ยกเลิก</button>
                </form>
              <?php else: ?>
                <span class="muted">ยกเลิกแล้ว</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>