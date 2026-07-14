<?php
// /public/download_form.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
$u = current_user();
$lab_id = (int)($u['lab_id'] ?? 0);

$ver_id = (int)($_GET['ver'] ?? 0);
if ($ver_id <= 0) { http_response_code(400); exit("Bad ver"); }

$stmt = $pdo->prepare("
  SELECT v.*, d.lab_id, d.doc_type, d.doc_code
  FROM document_versions v
  JOIN documents d ON d.id = v.document_id
  WHERE v.id=:vid AND d.lab_id=:lab
  LIMIT 1
");
$stmt->execute([':vid'=>$ver_id, ':lab'=>$lab_id]);
$row = $stmt->fetch();

if (!$row) { http_response_code(404); exit("Not found"); }
if ($row['doc_type'] !== 'FORM') { http_response_code(400); exit("Not a FORM"); }

$filePath = (string)$row['file_path'];
if (!is_file($filePath)) { http_response_code(500); exit("File missing"); }

// log download
$pdo->prepare("INSERT INTO document_access_logs (user_id, document_version_id, action, ip_addr, user_agent)
               VALUES (:uid,:vid,'DOWNLOAD_FORM',:ip,:ua)")
    ->execute([
      ':uid'=>(int)$u['id'],
      ':vid'=>$ver_id,
      ':ip'=>$_SERVER['REMOTE_ADDR'] ?? null,
      ':ua'=>substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,255),
    ]);

$downloadName = $row['doc_type'] . '_' . $row['doc_code'] . '_v' . $row['version_no'] . '_' . basename((string)$row['file_name']);

header('Content-Type: ' . $row['mime_type']);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;