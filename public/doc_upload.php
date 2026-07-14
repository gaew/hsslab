<?php
// /public/doc_upload.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';


//////////////
function dlog(string $m): void {
  @file_put_contents(__DIR__ . '/../storage/upload_debug.log',
    date('c') . ' ' . $m . PHP_EOL, FILE_APPEND);
}


////////////////
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  dlog("POST start");

  dlog("POST doc_type=" . ($_POST['doc_type'] ?? 'NULL'));
  dlog("POST doc_code=" . ($_POST['doc_code'] ?? 'NULL'));

  dlog("FILES file exists=" . (isset($_FILES['file']) ? 'YES' : 'NO'));
  if (isset($_FILES['file'])) {
    dlog("FILES error=" . $_FILES['file']['error']);
    dlog("FILES name=" . $_FILES['file']['name']);
    dlog("FILES size=" . $_FILES['file']['size']);
    dlog("FILES tmp=" . $_FILES['file']['tmp_name']);
  }

  // ก่อน finfo
  dlog("class finfo exists=" . (class_exists('finfo') ? 'YES' : 'NO'));
}
///////////////

require_login();
require_role(['LAB_ADMIN']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$u = current_user();
$lab_id = (int)($u['lab_id'] ?? 0);
if ($lab_id <= 0) { http_response_code(400); exit("LAB not assigned"); }

$msg = '';
$err = '';

function ensure_dir(string $dir): void {
  if (!is_dir($dir)) mkdir($dir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $doc_type = $_POST['doc_type'] ?? '';
  $doc_code = trim($_POST['doc_code'] ?? '');
  $doc_title = trim($_POST['doc_title'] ?? '');
  $effective_date = $_POST['effective_date'] ?? '';
  $upload_mode = $_POST['upload_mode'] ?? 'NEW'; // NEW or NEW_VERSION

  $allowedTypes = ['QM','QP','WI','FORM'];
  if (!in_array($doc_type, $allowedTypes, true)) {
    $err = 'ประเภทเอกสารไม่ถูกต้อง';
  } elseif ($doc_code === '' || $doc_title === '' || $effective_date === '') {
    $err = 'กรุณากรอกข้อมูลให้ครบ (Type/Code/Title/Effective date)';
  } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $err = 'กรุณาเลือกไฟล์ และอัปโหลดให้สำเร็จ';
  } else {
    $tmp = $_FILES['file']['tmp_name'];
    $origName = basename($_FILES['file']['name']);

    // ตรวจ mime แบบง่าย + ตรวจนามสกุลเพิ่ม
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: 'application/octet-stream';

    $isControlled = in_array($doc_type, ['QM','QP','WI'], true);

    if ($isControlled) {
      if ($ext !== 'pdf' || $mime !== 'application/pdf') {
        $err = 'QM/QP/WI ต้องเป็นไฟล์ PDF เท่านั้น';
      }
    } else {
      $okExt = in_array($ext, ['xlsx','xls','docx','doc'], true);
        if (!$okExt) {
          $err = 'FORM ต้องเป็นไฟล์ Excel หรือ Word (.xlsx/.xls/.docx/.doc)';
        }
    }

    if ($err === '') {
      // หา/สร้าง documents row
      $pdo->beginTransaction();
      try {
        $stmt = $pdo->prepare("SELECT id FROM documents WHERE lab_id=:lab AND doc_type=:t AND doc_code=:c LIMIT 1");
        $stmt->execute([':lab'=>$lab_id, ':t'=>$doc_type, ':c'=>$doc_code]);
        $doc = $stmt->fetch();

        if ($upload_mode === 'NEW' && $doc) {
          throw new RuntimeException('มีเอกสาร Code นี้แล้ว (ถ้าจะอัปโหลดเวอร์ชันใหม่ เลือกโหมด “เพิ่มเวอร์ชัน”)');
        }
        if ($upload_mode === 'NEW_VERSION' && !$doc) {
          throw new RuntimeException('ยังไม่มีเอกสาร Code นี้ (ถ้าจะสร้างใหม่ เลือกโหมด “สร้างเอกสารใหม่”)');
        }

        if (!$doc) {
          $stmt = $pdo->prepare("INSERT INTO documents (lab_id, doc_type, doc_code, doc_title, created_by)
                                 VALUES (:lab,:t,:c,:title,:by)");
          $stmt->execute([':lab'=>$lab_id, ':t'=>$doc_type, ':c'=>$doc_code, ':title'=>$doc_title, ':by'=>(int)$u['id']]);
          $document_id = (int)$pdo->lastInsertId();
          $version_no = 1;
        } else {
          $document_id = (int)$doc['id'];

          // อัปเดต title เผื่อเปลี่ยนชื่อ
          $pdo->prepare("UPDATE documents SET doc_title=:title WHERE id=:id AND lab_id=:lab")
              ->execute([':title'=>$doc_title, ':id'=>$document_id, ':lab'=>$lab_id]);

          $stmt = $pdo->prepare("SELECT COALESCE(MAX(version_no),0) AS mv FROM document_versions WHERE document_id=:did");
          $stmt->execute([':did'=>$document_id]);
          $version_no = (int)($stmt->fetch()['mv'] ?? 0) + 1;

          // ตั้งเวอร์ชันเก่าที่ ACTIVE เป็น CANCELLED อัตโนมัติ (ถ้าต้องการ)
          // ถ้าอยากให้เวอร์ชันเก่ายัง ACTIVE ได้พร้อมกัน -> บอกผม จะปรับ
          $pdo->prepare("UPDATE document_versions
                         SET status='CANCELLED', cancelled_at=NOW(), cancelled_reason='Replaced by new version'
                         WHERE document_id=:did AND status='ACTIVE'")
              ->execute([':did'=>$document_id]);
        }

        // เก็บไฟล์ลง storage
        $labDir = STORAGE_ROOT . "/original/lab_" . $lab_id;
        ensure_dir($labDir);

        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $origName);
        $storedName = $doc_type . "_" . $doc_code . "_v" . $version_no . "_" . date('Ymd_His') . "_" . $safeName;
        $destPath = $labDir . "/" . $storedName;

        if (!move_uploaded_file($tmp, $destPath)) {
          throw new RuntimeException('ย้ายไฟล์ไม่สำเร็จ (เช็คสิทธิ์โฟลเดอร์ storage)');
        }

        // insert version
        $stmt = $pdo->prepare("
          INSERT INTO document_versions
            (document_id, version_no, file_name, file_path, mime_type, effective_date, status, uploaded_by)
          VALUES
            (:did, :vno, :fn, :fp, :mime, :eff, 'ACTIVE', :by)
        ");
        $stmt->execute([
          ':did'=>$document_id,
          ':vno'=>$version_no,
          ':fn'=>$origName,
          ':fp'=>$destPath,
          ':mime'=>$mime,
          ':eff'=>$effective_date,
          ':by'=>(int)$u['id'],
        ]);

        $pdo->commit();
        $msg = "อัปโหลดสำเร็จ: {$doc_type} {$doc_code} (v{$version_no})";
      } catch (Throwable $e) {
        $pdo->rollBack();
        $err = $e->getMessage();
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
  <title>อัปโหลดเอกสาร | LAB_ADMIN</title>
  <style>
    body{font-family:system-ui;background:#f5f5f5;margin:0}
    .wrap{max-width:900px;margin:24px auto;padding:0 16px}
    .card{background:#fff;border-radius:14px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.06);margin-bottom:14px}
    label{display:block;margin:.65rem 0 .25rem}
    input,select{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:10px;font-size:15px}
    button{padding:10px 12px;border:0;border-radius:10px;font-size:15px;cursor:pointer}
    .ok{background:#f0fff4;border:1px solid #b7ebc6;color:#14532d;padding:10px;border-radius:10px;margin-bottom:12px}
    .err{background:#fff2f2;border:1px solid #ffd0d0;color:#9b1c1c;padding:10px;border-radius:10px;margin-bottom:12px}
    .muted{color:#666;font-size:13px;line-height:1.5}
    a{color:#0b57d0;text-decoration:none}
    .topbar{display:flex;justify-content:space-between;align-items:center}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
</style>
<?php require __DIR__ . '/../includes/pwa_head.php'; ?>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="topbar">
      <div>
        <h2 style="margin:0">อัปโหลดเอกสาร (QM/QP/WI/FORM)</h2>
        <div class="muted">ผู้ดูแล: <?= h($u['full_name'] ?: $u['username']) ?> | LAB ID: <?= (int)$lab_id ?></div>
      </div>
      <div><a href="dashboard.php">Dashboard</a></div>
    </div>
  </div>

  <?php if ($msg): ?><div class="ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <form method="post" enctype="multipart/form-data">
      <div class="grid">
        <div>
          <label>โหมดอัปโหลด</label>
          <select name="upload_mode">
            <option value="NEW">สร้างเอกสารใหม่</option>
            <option value="NEW_VERSION">เพิ่มเวอร์ชัน (แทนที่เวอร์ชันเดิม)</option>
          </select>
        </div>
        <div>
          <label>ประเภทเอกสาร</label>
          <select name="doc_type" required>
            <option value="QM">QM</option>
            <option value="QP">QP</option>
            <option value="WI">WI</option>
            <option value="FORM">FORM</option>
          </select>
        </div>
      </div>

      <div class="grid">
        <div>
          <label>Code</label>
          <input name="doc_code" placeholder="เช่น QM-01 / QP-07 / WI-12 / FORM-03" required>
        </div>
        <div>
          <label>วันที่ประกาศใช้ (Effective date)</label>
          <input type="date" name="effective_date" required>
        </div>
      </div>

      <label>ชื่อเอกสาร (Title)</label>
      <input name="doc_title" required>

      <label>ไฟล์</label>
      <input type="file" name="file" required>

      <div class="muted" style="margin-top:10px">
        - QM/QP/WI: ต้องเป็น PDF<br>
        - FORM: ต้องเป็น Excel หรือ Word (.xlsx/.xls/.docx/.doc)
      </div>

      <button type="submit" style="margin-top:14px">อัปโหลด</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/pwa_footer.php'; ?>
</body>
</html>
