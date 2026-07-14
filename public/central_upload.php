<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_role(['SUPER_ADMIN']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$msg = '';
$err = '';

function ensure_dir(string $dir): void {
  if (!is_dir($dir)) mkdir($dir, 0775, true);
}

/**
 * แปลง input tag เช่น "#ISO17025, GUM, #Calibration" -> ["ISO17025","GUM","Calibration"]
 */
function parse_tags(string $raw): array {
  $raw = trim($raw);
  if ($raw === '') return [];
  $raw = str_replace([';', '，'], [',', ','], $raw);
  $parts = preg_split('/\s*,\s*/', $raw) ?: [];
  $out = [];
  foreach ($parts as $p) {
    $p = trim($p);
    if ($p === '') continue;
    $p = ltrim($p, "# \t\n\r\0\x0B");
    // อนุญาต ไทย/อังกฤษ/ตัวเลข/ขีด/ขีดล่าง
    $p = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $p);
    if ($p === '') continue;
    $out[] = $p;
  }
  $out = array_values(array_unique($out));
  return array_slice($out, 0, 20); // กันใส่เยอะเกิน
}

function safe_filename(string $name): string {
  $name = basename($name);
  return preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
}

// dropdown categories
$cats = $pdo->query("SELECT id,name FROM central_categories ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $doc_type = trim($_POST['doc_type'] ?? 'REF');      // REF/GUIDE/TEMPLATE
  $title = trim($_POST['title'] ?? '');
  $ref_no = trim($_POST['ref_no'] ?? '');
  $doc_date = $_POST['doc_date'] ?? '';              // YYYY-MM-DD
  $description = trim($_POST['description'] ?? '');

  $category_id = (int)($_POST['category_id'] ?? 0);
  $new_category = trim($_POST['new_category'] ?? '');

  $tags_raw = trim($_POST['tags'] ?? '');
  $tags = parse_tags($tags_raw);

  if ($title === '') {
    $err = 'กรุณากรอกชื่อเอกสาร';
  } elseif ($category_id === 0 && $new_category === '') {
    $err = 'กรุณาเลือกหมวดหมู่ หรือพิมพ์หมวดหมู่ใหม่';
  } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $err = 'กรุณาเลือกไฟล์ และอัปโหลดให้สำเร็จ';
  } else {
    $tmp = $_FILES['file']['tmp_name'];
    $origName = $_FILES['file']['name'] ?? 'upload.bin';
    $origName = safe_filename((string)$origName);
    $size = (int)($_FILES['file']['size'] ?? 0);

    // mime
    $mime = 'application/octet-stream';
    if (class_exists('finfo')) {
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime = $finfo->file($tmp) ?: $mime;
    }

    // หมวดหมู่: ถ้าพิมพ์ใหม่ -> upsert
    $pdo->beginTransaction();
    try {
      if ($category_id === 0 && $new_category !== '') {
        $stmt = $pdo->prepare("SELECT id FROM central_categories WHERE name=:n LIMIT 1");
        $stmt->execute([':n'=>$new_category]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          $category_id = (int)$row['id'];
        } else {
          $stmt = $pdo->prepare("INSERT INTO central_categories (name, sort_order) VALUES (:n, 0)");
          $stmt->execute([':n'=>$new_category]);
          $category_id = (int)$pdo->lastInsertId();
        }
      }

     // 📁 เก็บไฟล์นอก public (ปลอดภัย)
$centralDir = 'C:\\inetpub\\wwwroot\\hsslab\\storage\\central';
ensure_dir($centralDir);

$storedName = date('Ymd_His') . '_' . $origName;
$destPath = $centralDir . DIRECTORY_SEPARATOR . $storedName;

if (!move_uploaded_file($tmp, $destPath)) {
  throw new RuntimeException('ย้ายไฟล์ไม่สำเร็จ (เช็คสิทธิ์โฟลเดอร์ storage/central)');
}



      $u = current_user();
      $stmt = $pdo->prepare("
        INSERT INTO central_documents
          (title, doc_type, category_id, ref_no, doc_date, description,
           file_name, file_path, mime_type, file_size, uploaded_by, is_active)
        VALUES
          (:title, :dtype, :cat, :ref, :dd, :desc,
           :fn, :fp, :mime, :fs, :by, 1)
      ");
      $stmt->execute([
        ':title'=>$title,
        ':dtype'=>$doc_type ?: 'REF',
        ':cat'=>$category_id ?: null,
        ':ref'=>$ref_no !== '' ? $ref_no : null,
        ':dd'=>$doc_date !== '' ? $doc_date : null,
        ':desc'=>$description !== '' ? $description : null,
        ':fn'=>$origName,
        ':fp'=>$destPath,
        ':mime'=>$mime,
        ':fs'=>$size,
        ':by'=>(int)$u['id'],
      ]);
      $docId = (int)$pdo->lastInsertId();

      // upsert tags + mapping
      foreach ($tags as $tname) {
        // หา tag id
        $stmt = $pdo->prepare("SELECT id FROM central_tags WHERE name=:n LIMIT 1");
        $stmt->execute([':n'=>$tname]);
        $tr = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tr) {
          $tagId = (int)$tr['id'];
        } else {
          $stmt = $pdo->prepare("INSERT INTO central_tags (name) VALUES (:n)");
          $stmt->execute([':n'=>$tname]);
          $tagId = (int)$pdo->lastInsertId();
        }
        // insert mapping (ignore duplicate)
        $stmt = $pdo->prepare("
          INSERT IGNORE INTO central_document_tags (central_document_id, tag_id)
          VALUES (:did, :tid)
        ");
        $stmt->execute([':did'=>$docId, ':tid'=>$tagId]);
      }

      $pdo->commit();
      $msg = 'เพิ่มรายการเอกสารอ้างอิงสำเร็จ';
      // refresh categories
      $cats = $pdo->query("SELECT id,name FROM central_categories ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
      $pdo->rollBack();
      $err = $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>เพิ่มรายการเอกสารอ้างอิง | SUPER_ADMIN</title>
<style>
  :root{--bg:#f6f8fb;--card:#fff;--line:#e5e7eb;--muted:#64748b;--brand:#0b57d0}
  body{margin:0;background:var(--bg);font-family:system-ui,-apple-system,"Segoe UI",Roboto}
  .wrap{max-width:980px;margin:24px auto;padding:0 16px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.05);margin-bottom:14px}
  .topbar{display:flex;justify-content:space-between;align-items:center}
  h2{margin:0}
  label{display:block;margin:.7rem 0 .3rem;color:#334155;font-size:14px}
  input,select,textarea{width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:14px;font-size:15px;background:#fff}
  textarea{resize:vertical}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .btn{border:0;border-radius:14px;padding:12px 14px;font-size:15px;cursor:pointer}
  .btn.primary{background:#0ea5e9;color:#042f2e;font-weight:800}
  .btn.ghost{background:#fff;border:1px solid var(--line);color:var(--brand);font-weight:800;text-decoration:none;display:inline-flex;align-items:center}
  .ok{background:#f0fff4;border:1px solid #b7ebc6;color:#14532d;padding:10px;border-radius:12px;margin-bottom:12px}
  .err{background:#fff2f2;border:1px solid #ffd0d0;color:#9b1c1c;padding:10px;border-radius:12px;margin-bottom:12px}
  .muted{color:var(--muted);font-size:13px;line-height:1.5}
</style>
</head>
<body>
<div class="wrap">
  <div class="card topbar">
    <div>
      <h2>+ เพิ่มรายการเอกสารอ้างอิงส่วนกลาง</h2>
      <div class="muted">อัปโหลดโดย SUPER_ADMIN • ทุก ศบส. เปิดอ่านได้ • ไม่ stamp</div>
    </div>
    <div style="display:flex;gap:10px">
      <a class="btn ghost" href="central_refs.php">กลับหน้าเอกสารอ้างอิง</a>
      <a class="btn ghost" href="dashboard.php">Dashboard</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <form method="post" enctype="multipart/form-data">
      <div class="grid">
        <div>
          <label>ประเภท (doc_type)</label>
          <select name="doc_type">
            <option value="REF">REF</option>
            <option value="GUIDE">GUIDE</option>
            <option value="TEMPLATE">TEMPLATE</option>
            <option value="STANDARD">STANDARD</option>
          </select>
        </div>
        <div>
          <label>วันที่เอกสาร (doc_date)</label>
          <input type="date" name="doc_date">
        </div>
      </div>

      <label>ชื่อเอกสาร</label>
      <input name="title" required placeholder="เช่น ISO/IEC 17025:2017 — General requirements...">

      <div class="grid">
        <div>
          <label>เลขเอกสาร/รหัสอ้างอิง (ref_no)</label>
          <input name="ref_no" placeholder="เช่น EAL-G26 / DKD R 6-1 / ILAC-G8">
        </div>
        <div>
          <label>หมวดหมู่ (เลือก)</label>
          <select name="category_id">
            <option value="0">-- เลือกหมวดหมู่ --</option>
            <?php foreach($cats as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <label>หรือสร้างหมวดหมู่ใหม่ (ถ้าไม่มี)</label>
      <input name="new_category" placeholder="เช่น EAL / DKD,PTB / ห้องปฏิบัติการด้านความดัน / Uncertainty">

      <label>Tag (พิมพ์คั่นด้วย , เช่น #Calibration, #Uncertainty)</label>
      <input name="tags" placeholder="#ISO17025, #GUM, #ILAC, #Calibration">

      <label>คำอธิบาย/คำสำคัญ (optional)</label>
      <textarea name="description" rows="3" placeholder="สรุปสั้น ๆ เพื่อช่วยค้นหา..."></textarea>

      <label>ไฟล์</label>
      <input type="file" name="file" required>
      <div class="muted" style="margin-top:8px">
        * รองรับ PDF/Word/Excel/Zip ฯลฯ (ไม่ stamp) • ขนาดไฟล์ขึ้นกับ limit ที่ตั้งใน PHP/IIS
      </div>

      <button class="btn primary" type="submit" style="margin-top:14px">บันทึกและอัปโหลด</button>
    </form>
  </div>
</div>
</body>
</html>