<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/app.php';

require_login();

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function fmt_mb(int $bytes): string {
  return number_format($bytes / 1024 / 1024, 1) . " MB";
}

$u = current_user();
$isSuper = in_array(($u['role'] ?? ''), ['SUPER_ADMIN'], true);

$q = trim($_GET['q'] ?? '');
$cat = (int)($_GET['cat'] ?? 0);
$tag = (int)($_GET['tag'] ?? 0);
$sort = $_GET['sort'] ?? 'title';
$page = max(1, (int)($_GET['page'] ?? 1));

$allowedSort = ['title', 'newest', 'docdate'];
if (!in_array($sort, $allowedSort, true)) {
  $sort = 'title';
}

$perPage = 10;
$offset = ($page - 1) * $perPage;

// dropdown data
$cats = $pdo->query("SELECT id, name FROM central_categories ORDER BY sort_order, name")
            ->fetchAll(PDO::FETCH_ASSOC);

$tags = $pdo->query("SELECT id, name FROM central_tags ORDER BY name")
            ->fetchAll(PDO::FETCH_ASSOC);

// build query
$where = ["cd.is_active = 1"];
$params = [];

if ($q !== '') {
  $where[] = "(
    cd.title LIKE :q1 OR
    cd.ref_no LIKE :q2 OR
    cd.doc_type LIKE :q3 OR
    cd.description LIKE :q4 OR
    ct.name LIKE :q5 OR
    cc.name LIKE :q6
  )";
  $like = "%{$q}%";
  $params[':q1'] = $like;
  $params[':q2'] = $like;
  $params[':q3'] = $like;
  $params[':q4'] = $like;
  $params[':q5'] = $like;
  $params[':q6'] = $like;
}

if ($cat > 0) {
  $where[] = "cd.category_id = :cat";
  $params[':cat'] = $cat;
}

if ($tag > 0) {
  $where[] = "cdt.tag_id = :tag";
  $params[':tag'] = $tag;
}

$orderBy = "cd.title ASC";
if ($sort === 'newest') {
  $orderBy = "cd.uploaded_at DESC, cd.id DESC";
} elseif ($sort === 'docdate') {
  $orderBy = "cd.doc_date DESC, cd.title ASC";
}

$baseFrom = "
FROM central_documents cd
LEFT JOIN central_categories cc ON cc.id = cd.category_id
LEFT JOIN central_document_tags cdt ON cdt.central_document_id = cd.id
LEFT JOIN central_tags ct ON ct.id = cdt.tag_id
WHERE " . implode(" AND ", $where);

// count all
$countSql = "
SELECT COUNT(DISTINCT cd.id)
{$baseFrom}
";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalDocs = (int)$stmtCount->fetchColumn();

$totalPages = max(1, (int)ceil($totalDocs / $perPage));
if ($page > $totalPages) {
  $page = $totalPages;
  $offset = ($page - 1) * $perPage;
}

// paged docs
$sql = "
SELECT DISTINCT
  cd.id,
  cd.doc_type,
  cd.title,
  cd.ref_no,
  cd.doc_date,
  cd.file_name,
  cd.file_size,
  cd.uploaded_at,
  cc.name AS category_name
{$baseFrom}
ORDER BY {$orderBy}
LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
  $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// fetch tags for displayed docs
$docTags = [];
if ($docs) {
  $ids = array_map(fn($r) => (int)$r['id'], $docs);
  $in = implode(',', array_fill(0, count($ids), '?'));

  $stmt2 = $pdo->prepare("
    SELECT cdt.central_document_id AS did, ct.name
    FROM central_document_tags cdt
    JOIN central_tags ct ON ct.id = cdt.tag_id
    WHERE cdt.central_document_id IN ($in)
    ORDER BY ct.name
  ");
  $stmt2->execute($ids);

  foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $docTags[(int)$r['did']][] = $r['name'];
  }
}

$startItem = $totalDocs > 0 ? $offset + 1 : 0;
$endItem = min($offset + $perPage, $totalDocs);

function page_url(int $targetPage): string {
  $query = $_GET;
  $query['page'] = $targetPage;
  return 'central_refs.php?' . http_build_query($query);
}

function compact_pagination(int $current, int $total): array {
  if ($total <= 7) {
    return range(1, $total);
  }

  $pages = [1, $total, $current];
  for ($i = $current - 1; $i <= $current + 1; $i++) {
    if ($i >= 1 && $i <= $total) {
      $pages[] = $i;
    }
  }

  $pages = array_values(array_unique($pages));
  sort($pages);

  $result = [];
  $prev = null;
  foreach ($pages as $p) {
    if ($prev !== null && $p - $prev > 1) {
      $result[] = '...';
    }
    $result[] = $p;
    $prev = $p;
  }
  return $result;
}

$paginationItems = compact_pagination($page, $totalPages);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>เอกสารอ้างอิง</title>
<style>
  :root{
    --bg:#f6f8fb;
    --card:#ffffff;
    --text:#0f172a;
    --muted:#64748b;
    --line:#e5e7eb;
    --brand:#1d4ed8;
    --brand-soft:#e8f0ff;
    --chip:#eef2ff;
    --chip2:#f1f5f9;
    --ok:#35d0b6;
    --shadow:0 10px 30px rgba(2, 6, 23, .06);
  }
  *{box-sizing:border-box}
  body{
    margin:0;
    background:linear-gradient(180deg,#f7f9fc 0%,#f4f7fb 100%);
    color:var(--text);
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans Thai",sans-serif;
  }
  .wrap{
    max-width:1200px;
    margin:28px auto 40px;
    padding:0 18px;
  }
  .hero{
    background:linear-gradient(135deg,#ffffff 0%,#f8fbff 100%);
    border:1px solid #e7edf7;
    border-radius:24px;
    box-shadow:var(--shadow);
    padding:24px;
  }
  h1{
    margin:0;
    font-size:40px;
    letter-spacing:.2px;
    color:#1e60d6;
  }
  .sub{
    margin:10px 0 0;
    color:#334155;
    font-size:17px;
    line-height:1.7;
    max-width:980px;
  }
  .topbar{
    display:flex;
    gap:12px;
    align-items:center;
    flex-wrap:wrap;
    margin-top:18px;
  }
  .btn{
    border:0;
    border-radius:14px;
    padding:12px 16px;
    font-size:15px;
    cursor:pointer;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    transition:.18s ease;
  }
  .btn:hover{transform:translateY(-1px)}
  .btn.primary{
    background:var(--ok);
    color:#053f3a;
    font-weight:800;
  }
  .btn.ghost{
    background:#fff;
    border:1px solid var(--line);
    color:#0b57d0;
    font-weight:700;
  }
  .btn.page{
    min-width:44px;
    height:44px;
    padding:0 12px;
    border-radius:12px;
  }
  .btn.page.active{
    background:var(--brand);
    color:#fff;
    border-color:var(--brand);
  }
  .filters{
    margin-top:18px;
    background:var(--card);
    border:1px solid var(--line);
    border-radius:22px;
    padding:18px;
    box-shadow:var(--shadow);
  }
  .grid{
    display:grid;
    grid-template-columns:2fr 1fr 1fr;
    gap:14px;
  }
  .field label{
    display:block;
    color:#334155;
    font-size:14px;
    margin:0 0 6px;
    font-weight:600;
  }
  input,select{
    width:100%;
    padding:12px 14px;
    border:1px solid var(--line);
    border-radius:14px;
    font-size:16px;
    background:#fff;
    color:var(--text);
    outline:none;
  }
  input:focus,select:focus{
    border-color:#9db8ff;
    box-shadow:0 0 0 4px rgba(29,78,216,.08);
  }
  .toolbar{
    margin-top:16px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
  }
  .summary{
    background:#fff;
    border:1px solid var(--line);
    border-radius:18px;
    box-shadow:var(--shadow);
    padding:14px 18px;
    margin-top:18px;
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:center;
    flex-wrap:wrap;
  }
  .summary .big{
    font-size:15px;
    color:#0f172a;
    font-weight:700;
  }
  .summary .muted{
    color:var(--muted);
    font-size:14px;
  }
  .list{
    margin-top:18px;
    display:flex;
    flex-direction:column;
    gap:12px;
  }
  .item{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    background:var(--card);
    border:1px solid var(--line);
    border-radius:20px;
    padding:16px;
    box-shadow:var(--shadow);
  }
  .left{
    display:flex;
    gap:14px;
    align-items:flex-start;
    min-width:0;
    flex:1;
  }
  .idx{
    width:56px;
    height:56px;
    border-radius:16px;
    background:var(--brand-soft);
    color:#0b3aa6;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:800;
    flex:0 0 auto;
  }
  .content{
    min-width:0;
    flex:1;
  }
  .title{
    font-size:20px;
    font-weight:800;
    color:var(--text);
    margin:0;
    line-height:1.35;
  }
  .meta{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:10px;
  }
  .pill{
    display:inline-flex;
    gap:8px;
    align-items:center;
    border:1px solid var(--line);
    background:var(--chip2);
    border-radius:999px;
    padding:6px 10px;
    font-size:13px;
    color:#0f172a;
  }
  .pill.tag{
    background:var(--chip);
  }
  .fileline{
    color:var(--muted);
    margin-top:8px;
    font-size:14px;
    word-break:break-word;
  }
  .open{
    background:#e9f1ff;
    border:1px solid #dbeafe;
    border-radius:16px;
    padding:12px 14px;
    font-weight:800;
    color:#0b57d0;
    text-decoration:none;
    white-space:nowrap;
    flex:0 0 auto;
  }
  .empty{
    background:#fff;
    border:1px dashed #cbd5e1;
    border-radius:20px;
    padding:28px 18px;
    text-align:center;
    color:var(--muted);
    box-shadow:var(--shadow);
  }
  .pagination{
    margin-top:18px;
    display:flex;
    justify-content:center;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
  }
  .ellipsis{
    color:var(--muted);
    padding:0 6px;
    font-weight:700;
  }

  @media (max-width: 980px){
    .grid{grid-template-columns:1fr}
    .item{
      flex-direction:column;
      align-items:stretch;
    }
    .open{
      width:100%;
      text-align:center;
    }
  }

  @media (max-width: 640px){
    .wrap{padding:0 12px}
    h1{font-size:30px}
    .hero,.filters,.summary,.item{border-radius:18px}
    .idx{
      width:48px;
      height:48px;
      border-radius:14px;
    }
    .title{font-size:18px}
  }
</style>
<?php require __DIR__ . '/../includes/pwa_head.php'; ?>
</head>
<body>
<div class="wrap">

  <div class="hero">
    <h1>เอกสารอ้างอิง</h1>
    <div class="sub">
      รวมลิงก์เอกสาร มาตรฐาน แนวทาง และแหล่งอ้างอิงสำคัญของระบบคุณภาพและงานห้องปฏิบัติการ
      สามารถค้นหาตามคำ หมวดหมู่ และ tag ได้ทันที พร้อมเปิดเอกสารผ่านระบบสิทธิ์ผู้ใช้งาน
    </div>

    <div class="topbar">
      <a class="btn primary" href="dashboard.php">← กลับหน้า “ระบบคุณภาพ”</a>
      <?php if ($isSuper): ?>
        <a class="btn ghost" href="central_upload.php">+ เพิ่มรายการ</a>
      <?php endif; ?>
    </div>
  </div>

  <form class="filters" method="get">
    <div class="grid">
      <div class="field">
        <label>ค้นหา (ชื่อเอกสาร / คำสำคัญ / หน่วยงาน)</label>
        <input
          name="q"
          value="<?= h($q) ?>"
          placeholder="เช่น ISO/IEC 17025, GUM, ILAC, flow calibration..."
        >
      </div>

      <div class="field">
        <label>หมวดหมู่</label>
        <select name="cat">
          <option value="0">ทั้งหมด</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>>
              <?= h($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label>เรียงลำดับ</label>
        <select name="sort">
          <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>ชื่อเอกสาร</option>
          <option value="docdate" <?= $sort === 'docdate' ? 'selected' : '' ?>>วันที่เอกสาร</option>
          <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>อัปโหลดล่าสุด</option>
        </select>
      </div>
    </div>

    <div class="toolbar">
      <div class="field" style="min-width:260px;flex:1;">
        <label>Tag</label>
        <select name="tag">
          <option value="0">ทั้งหมด</option>
          <?php foreach ($tags as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= $tag === (int)$t['id'] ? 'selected' : '' ?>>
              <?= h($t['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display:flex;align-items:end;gap:10px;flex-wrap:wrap;">
        <button class="btn ghost" type="submit">ค้นหา</button>
        <a class="btn ghost" href="central_refs.php">ล้างตัวกรอง</a>
      </div>
    </div>
  </form>

  <div class="summary">
    <div class="big">
      พบเอกสารทั้งหมด <?= number_format($totalDocs) ?> รายการ
    </div>
    <div class="muted">
      แสดง <?= number_format($startItem) ?>–<?= number_format($endItem) ?> จาก <?= number_format($totalDocs) ?> รายการ
      • หน้าที่ <?= number_format($page) ?> / <?= number_format($totalPages) ?>
    </div>
  </div>

  <div class="list">
    <?php if ($docs): ?>
      <?php foreach ($docs as $index => $d): ?>
        <?php
          $did = (int)$d['id'];
          $displayNo = $offset + $index + 1;
          $exp = time() + 600;
          $token = hash_hmac('sha256', $did . '|' . $exp . '|' . (int)$u['id'], APP_SECRET);
        ?>
        <div class="item">
          <div class="left">
            <div class="idx"><?= str_pad((string)$displayNo, 2, '0', STR_PAD_LEFT) ?></div>

            <div class="content">
              <div class="title"><?= h($d['title']) ?></div>

              <div class="meta">
                <?php if (!empty($d['ref_no'])): ?>
                  <span class="pill">📌 <?= h($d['ref_no']) ?></span>
                <?php endif; ?>

                <span class="pill">🏷️ <?= h($d['doc_type'] ?: 'REF') ?></span>

                <?php if (!empty($d['category_name'])): ?>
                  <span class="pill">🗂️ <?= h($d['category_name']) ?></span>
                <?php endif; ?>

                <?php if (!empty($d['doc_date'])): ?>
                  <span class="pill">🕒 <?= h($d['doc_date']) ?></span>
                <?php endif; ?>

                <?php foreach (($docTags[$did] ?? []) as $tn): ?>
                  <span class="pill tag">#<?= h($tn) ?></span>
                <?php endforeach; ?>
              </div>

              <div class="fileline">
                <?= h($d['file_name']) ?> • <?= fmt_mb((int)$d['file_size']) ?>
              </div>
            </div>
          </div>

          <a class="open" href="central_file.php?id=<?= $did ?>&exp=<?= $exp ?>&token=<?= $token ?>" target="_blank">
            เปิดแหล่งอ้างอิง ↗
          </a>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty">
        ไม่พบรายการตามเงื่อนไขที่ค้นหา
      </div>
    <?php endif; ?>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
        <a class="btn ghost page" href="<?= h(page_url($page - 1)) ?>">‹</a>
      <?php endif; ?>

      <?php foreach ($paginationItems as $p): ?>
        <?php if ($p === '...'): ?>
          <span class="ellipsis">...</span>
        <?php else: ?>
          <a
            class="btn ghost page <?= (int)$p === $page ? 'active' : '' ?>"
            href="<?= h(page_url((int)$p)) ?>"
          >
            <?= (int)$p ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if ($page < $totalPages): ?>
        <a class="btn ghost page" href="<?= h(page_url($page + 1)) ?>">›</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
<?php require __DIR__ . '/../includes/pwa_footer.php'; ?>
</body>
</html>
