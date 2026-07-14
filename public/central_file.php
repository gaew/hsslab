<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$id = (int)($_GET['id'] ?? 0);
$exp = (int)($_GET['exp'] ?? 0);
$token = (string)($_GET['token'] ?? '');

if ($id <= 0 || $exp <= 0 || $token === '') {
    http_response_code(400);
    exit('Bad request');
}

if ($exp < time()) {
    http_response_code(403);
    exit('Link expired');
}

// ดึงข้อมูลไฟล์
$stmt = $pdo->prepare("
    SELECT id, file_name, file_path, mime_type
    FROM central_documents
    WHERE id = :id AND is_active = 1
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    http_response_code(404);
    exit('Not found');
}

$u = current_user();
$userId = (int)($u['id'] ?? 0);

// ตรวจ token
$data = $id . '|' . $exp . '|' . $userId;
$expected = hash_hmac('sha256', $data, APP_SECRET);

if (!hash_equals($expected, $token)) {
    http_response_code(403);
    exit('Invalid token');
}

// path จริงของไฟล์จากฐานข้อมูล
$filePath = (string)($r['file_path'] ?? '');
$fileName = (string)($r['file_name'] ?? 'download.bin');
$dbMime   = (string)($r['mime_type'] ?? '');

// กำหนด base directory ที่อนุญาต
$baseDir = realpath('C:\\inetpub\\wwwroot\\hsslab\\storage\\central');
$realFile = realpath($filePath);

if ($baseDir === false || $realFile === false) {
    http_response_code(404);
    exit('File not found');
}

// กัน path traversal / path หลุดออกนอก storage
$baseDirNorm = rtrim(str_replace('\\', '/', $baseDir), '/');
$realFileNorm = str_replace('\\', '/', $realFile);

if (strpos($realFileNorm, $baseDirNorm . '/') !== 0) {
    http_response_code(403);
    exit('Invalid file path');
}

if (!is_file($realFile) || !is_readable($realFile)) {
    http_response_code(404);
    exit('File not found or not readable');
}

// หา mime type
$mime = 'application/octet-stream';
if ($dbMime !== '') {
    $mime = $dbMime;
} elseif (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $detected = finfo_file($finfo, $realFile);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
        finfo_close($finfo);
    }
}

// กัน output ก่อนหน้าไปรบกวนไฟล์
while (ob_get_level() > 0) {
    ob_end_clean();
}

// ชื่อไฟล์สำหรับ browser
$downloadName = basename($fileName);
$downloadName = str_replace(["\r", "\n", '"'], '', $downloadName);

// headers
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($realFile));
header('Cache-Control: private, max-age=3600');

// แสดงใน browser ถ้าเปิดได้ เช่น PDF
header("Content-Disposition: inline; filename=\"{$downloadName}\"");

// ส่งไฟล์
$fp = fopen($realFile, 'rb');
if ($fp === false) {
    http_response_code(500);
    exit('Unable to open file');
}

fpassthru($fp);
fclose($fp);
exit;