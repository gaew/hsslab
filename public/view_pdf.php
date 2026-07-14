<?php
declare(strict_types=1);

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

use setasign\Fpdi\Fpdi;

// ---- 1) ดึงข้อมูลเอกสารตาม ver ที่ส่งมา (ตัวอย่าง) ----
$verId = (int)($_GET['ver'] ?? 0);
if ($verId <= 0) { http_response_code(400); exit('Missing ver'); }

// TODO: ปรับ query ให้ตรง schema คุณ
$stmt = $pdo->prepare("
  SELECT dv.id, dv.file_path, dv.status, dv.effective_date,
         d.doc_type, d.doc_code, d.doc_title
  FROM document_versions dv
  JOIN documents d ON d.id = dv.document_id
  WHERE dv.id = :id
  LIMIT 1
");
$stmt->execute([':id' => $verId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Not found'); }

$filePath = (string)$row['file_path'];
if (!is_file($filePath) || !is_readable($filePath)) {
  http_response_code(404); exit('File not readable');
}

$u = current_user();
$stamp = sprintf(
  'CONTROLLED COPY | %s %s v%s | Effective: %s | Viewed: %s | User: %s (%s) | Status: %s',
  $row['doc_type'],
  $row['doc_code'],
  (string)($_GET['vno'] ?? ''), // ถ้าคุณมี version_no ใน query ให้ใช้จาก DB แทน
  $row['effective_date'],
  date('Y-m-d H:i:s'),
  $u['username'] ?? '-',
  $u['full_name'] ?? '-',
  $row['status'] ?? 'ACTIVE'
);

// ---- 2) สร้าง PDF แบบ overlay (ไม่เพิ่มหน้า) ----
$pdf = new Fpdi();
$pageCount = $pdf->setSourceFile($filePath);

for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
  $tplId = $pdf->importPage($pageNo);
  $size = $pdf->getTemplateSize($tplId);

  // สร้างหน้าใหม่ “ขนาดเท่าต้นฉบับ” แล้ววางต้นฉบับลงไป
  $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
  $pdf->useTemplate($tplId, 0, 0, $size['width'], $size['height'], true);

  // ----- วาง stamp ด้านบนทุกหน้า -----
  $pdf->SetFont('Helvetica', '', 8);

  // ระยะห่างจากขอบบน (หน่วยเป็น mm)
  $topMargin = 6;

  // วางชิดบนซ้าย
  $pdf->SetXY(8, $topMargin);
  $pdf->Cell($size['width'] - 16, 4, $stamp, 0, 0, 'L');

  // ถ้าอยากให้มีเส้นบางๆ ใต้ header
  // $pdf->Line(8, $topMargin + 5, $size['width'] - 8, $topMargin + 5);
}

// ส่งออกเป็น PDF
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="view.pdf"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (ob_get_length()) { ob_clean(); }
$pdf->Output('I');
exit;