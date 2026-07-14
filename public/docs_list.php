<?php
// /public/docs_list.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$u = current_user();
$lab_id = (int)($u['lab_id'] ?? 0);
if ($lab_id <= 0) { http_response_code(400); exit("LAB not assigned"); }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$q = trim($_GET['q'] ?? '');
$type = strtoupper(trim($_GET['type'] ?? 'ALL'));

$allowedType = ['ALL','QM','QP','WI','FORM'];
if (!in_array($type, $allowedType, true)) $type = 'ALL';

$where = ["d.lab_id = :lab"];
$params = [':lab' => $lab_id];

if ($type !== 'ALL') {
  $where[] = "d.doc_type = :type";
  $params[':type'] = $type;
}

if ($q !== '') {
  $where[] = "(d.doc_code LIKE :q_code OR d.doc_title LIKE :q_title)";
  $params[':q_code'] = "%{$q}%";
  $params[':q_title'] = "%{$q}%";
}

$stmt = $pdo->prepare("
  SELECT d.id AS doc_id, d.doc_type, d.doc_code, d.doc_title,
         v.id AS ver_id, v.version_no, v.effective_date, v.uploaded_at,
         v.status, v.cancelled_at
  FROM documents d
  JOIN document_versions v ON v.document_id = d.id
  JOIN (
    SELECT document_id, MAX(version_no) AS maxver
    FROM document_versions
    GROUP BY document_id
  ) mv ON mv.document_id = v.document_id AND mv.maxver = v.version_no
  WHERE " . implode(" AND ", $where) . "
  ORDER BY FIELD(d.doc_type,'QM','QP','WI','FORM'), d.doc_code
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$groups = [
  'QM'   => ['label'=>'QM', 'desc'=>'คู่มือคุณภาพ', 'items'=>[]],
  'QP'   => ['label'=>'QP', 'desc'=>'ระเบียบปฏิบัติงาน / Procedure', 'items'=>[]],
  'WI'   => ['label'=>'WI', 'desc'=>'วิธีปฏิบัติงาน / Work Instruction', 'items'=>[]],
  'FORM' => ['label'=>'FM', 'desc'=>'แบบฟอร์ม Excel / Form', 'items'=>[]],
];

foreach ($rows as $r) {
  if (isset($groups[$r['doc_type']])) {
    $groups[$r['doc_type']]['items'][] = $r;
  }
}

function activeTab(string $now, string $type): string {
  return $now === $type ? 'active' : '';
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>รายการเอกสาร | QDocs</title>
<style>
body{font-family:system-ui;background:#f5f5f5;margin:0;color:#111827}
.wrap{max-width:1200px;margin:24px auto;padding:0 16px}
.card{background:#fff;border-radius:18px;padding:20px;box-shadow:0 10px 30px rgba(0,0,0,.06);margin-bottom:16px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:12px}
h1{margin:0;font-size:26px}
.muted{color:#667085;font-size:13px}
a{color:#0b57d0;text-decoration:none}
.tabs{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.tab{padding:10px 16px;border-radius:999px;background:#eef2f7;color:#111827;font-weight:700}
.tab.active{background:#0b57d0;color:#fff}
.search{display:grid;grid-template-columns:1fr auto;gap:10px;margin-top:16px}
input{padding:12px 14px;border:1px solid #d0d5dd;border-radius:14px;font-size:15px}
button,.btn{border:0;border-radius:14px;padding:12px 16px;font-size:15px;cursor:pointer;background:#e8f1ff;color:#0b57d0;font-weight:700}
.section-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.section-title h2{margin:0;font-size:22px}
.count{background:#eef2f7;border-radius:999px;padding:5px 10px;font-size:13px;color:#344054}
.doc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
.doc{border:1px solid #e5e7eb;border-radius:18px;padding:16px;background:#fff}
.doc-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.code{font-size:18px;font-weight:800;color:#111827}
.badge{display:inline-block;padding:4px 9px;border-radius:999px;border:1px solid #d0d5dd;font-size:12px;background:#fafafa}
.off{background:#fff1f2;color:#9f1239;border-color:#fecdd3}
.title{margin-top:8px;font-size:15px;line-height:1.45;min-height:42px}
.meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.actions{display:flex;gap:8px;align-items:center;margin-top:14px;flex-wrap:wrap}
.open{background:#0b57d0;color:#fff;border-radius:12px;padding:10px 12px;font-weight:800}
.version{font-size:13px;color:#667085}
.empty{padding:20px;border:1px dashed #d0d5dd;border-radius:16px;color:#667085;background:#fafafa}
</style>
</head>
<body>
<div class="wrap">

  <div class="card">
    <div class="topbar">
      <div>
        <h1>รายการเอกสาร</h1>
        <div class="muted">
          ผู้ใช้: <?= h($u['full_name'] ?: $u['username']) ?> |
          Role: <?= h($u['role']) ?>
        </div>
      </div>
      <div>
        <?php if ($u['role'] === 'LAB_ADMIN'): ?>
          <a href="doc_upload.php">อัปโหลดเอกสาร</a> |
        <?php endif; ?>
        <a href="dashboard.php">Dashboard</a>
      </div>
    </div>

    <div class="tabs">
      <a class="tab <?= activeTab($type,'ALL') ?>" href="?type=ALL">ทั้งหมด</a>
      <a class="tab <?= activeTab($type,'QM') ?>" href="?type=QM">QM</a>
      <a class="tab <?= activeTab($type,'QP') ?>" href="?type=QP">QP</a>
      <a class="tab <?= activeTab($type,'WI') ?>" href="?type=WI">WI</a>
      <a class="tab <?= activeTab($type,'FORM') ?>" href="?type=FORM">FM / FORM</a>
    </div>

    <form class="search" method="get">
      <input type="hidden" name="type" value="<?= h($type) ?>">
      <input name="q" value="<?= h($q) ?>" placeholder="ค้นหาด้วย Code หรือชื่อเอกสาร เช่น WI-GAS-01, calibration, flow...">
      <button type="submit">ค้นหา</button>
    </form>
  </div>

  <?php foreach ($groups as $key => $g): ?>
    <?php if ($type !== 'ALL' && $type !== $key) continue; ?>

    <div class="card">
      <div class="section-title">
        <div>
          <h2><?= h($g['label']) ?></h2>
          <div class="muted"><?= h($g['desc']) ?></div>
        </div>
        <div class="count"><?= count($g['items']) ?> รายการ</div>
      </div>

      <?php if (!$g['items']): ?>
        <div class="empty">ยังไม่มีเอกสารในหมวดนี้</div>
      <?php else: ?>
        <div class="doc-grid">
          <?php foreach ($g['items'] as $r): ?>
            <div class="doc">
              <div class="doc-head">
                <div class="code"><?= h($r['doc_code']) ?></div>
                <?php if ($r['status'] === 'CANCELLED'): ?>
                  <span class="badge off">CANCELLED</span>
                <?php else: ?>
                  <span class="badge">ACTIVE</span>
                <?php endif; ?>
              </div>

              <div class="title"><?= h($r['doc_title']) ?></div>

              <div class="meta">
                <span class="badge">v<?= (int)$r['version_no'] ?></span>
                <span class="badge">ประกาศใช้: <?= h((string)$r['effective_date']) ?></span>
                <span class="badge">อัปโหลด: <?= h((string)$r['uploaded_at']) ?></span>
              </div>

              <?php if ($r['status'] === 'CANCELLED'): ?>
                <div class="muted" style="margin-top:8px">
                  ยกเลิกเมื่อ: <?= h((string)$r['cancelled_at']) ?>
                </div>
              <?php endif; ?>

              <div class="actions">
                <?php if (in_array($r['doc_type'], ['QM','QP','WI'], true)): ?>
                  <a class="open" href="view_pdf.php?ver=<?= (int)$r['ver_id'] ?>" target="_blank">เปิดอ่าน (stamp)</a>
                <?php else: ?>
                  <a class="open" href="download_form.php?ver=<?= (int)$r['ver_id'] ?>">ดาวน์โหลด FORM</a>
                <?php endif; ?>

                <a class="version" href="doc_versions.php?doc=<?= (int)$r['doc_id'] ?>">ดูเวอร์ชันทั้งหมด</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

</div>
</body>
</html>