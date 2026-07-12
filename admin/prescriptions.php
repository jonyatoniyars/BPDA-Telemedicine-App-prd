<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

if (!isLoggedIn()) redirect(BASE_URL . '/admin/login.php');
$adminUser = requireAdmin();

$msg   = '';
$error = '';

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_prescription') {
        $id = $_POST['prescription_id'] ?? '';
        if ($id) {
            // Cascade deletes items due to FK ON DELETE CASCADE
            $pdo->prepare("DELETE FROM prescriptions WHERE id = ?")->execute([$id]);
            $msg = 'Prescription deleted.';
        }

    } elseif ($action === 'update_status') {
        $id     = $_POST['prescription_id'] ?? '';
        $status = $_POST['status']          ?? '';
        $notes  = trim($_POST['review_notes'] ?? '') ?: null;
        $allowedStatuses = ['DRAFT','SUBMITTED','REVIEWED'];

        if ($id && in_array($status, $allowedStatuses, true)) {
            if ($status === 'REVIEWED') {
                $pdo->prepare(
                    "UPDATE prescriptions SET status = ?, review_notes = ?, reviewed_by_id = ?, reviewed_at = NOW() WHERE id = ?"
                )->execute([$status, $notes, $adminUser['id'], $id]);
            } else {
                $pdo->prepare("UPDATE prescriptions SET status = ?, review_notes = ? WHERE id = ?")->execute([$status, $notes, $id]);
            }
            $msg = 'Prescription status updated.';
        } else {
            $error = 'Invalid status.';
        }
    }
}

// ── View single prescription ──────────────────────────────────────────────────
$viewId = $_GET['view'] ?? '';
$viewPrescription  = null;
$viewItems         = [];
$viewReviewer      = null;

if ($viewId) {
    $vs = $pdo->prepare(
        "SELECT p.*, u.name AS hw_name, u.email AS hw_email
         FROM prescriptions p JOIN users u ON p.health_worker_id = u.id
         WHERE p.id = ?"
    );
    $vs->execute([$viewId]);
    $viewPrescription = $vs->fetch() ?: null;

    if ($viewPrescription) {
        $is = $pdo->prepare(
            "SELECT pi.*, m.name AS medicine_name, m.form AS medicine_form
             FROM prescription_items pi JOIN medicines m ON pi.medicine_id = m.id
             WHERE pi.prescription_id = ?"
        );
        $is->execute([$viewId]);
        $viewItems = $is->fetchAll();

        if ($viewPrescription['reviewed_by_id']) {
            $rs = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $rs->execute([$viewPrescription['reviewed_by_id']]);
            $viewReviewer = $rs->fetchColumn();
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterStatus = $_GET['filter_status'] ?? '';
$filterHw     = $_GET['filter_hw']     ?? '';
$search       = trim($_GET['search']   ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit        = 20;
$offset       = ($page - 1) * $limit;

$where  = [];
$params = [];

if ($filterStatus && in_array($filterStatus, ['DRAFT','SUBMITTED','REVIEWED'], true)) {
    $where[]  = 'p.status = ?';
    $params[] = $filterStatus;
}
if ($filterHw) {
    $where[]  = 'p.health_worker_id = ?';
    $params[] = $filterHw;
}
if ($search) {
    $where[]  = '(p.patient_name LIKE ? OR u.name LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like]);
}

$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM prescriptions p JOIN users u ON p.health_worker_id = u.id $whereStr"
);
$totalStmt->execute($params);
$total = (int) $totalStmt->fetchColumn();

$presStmt = $pdo->prepare(
    "SELECT p.id, p.patient_name, p.patient_age, p.patient_gender, p.status, p.created_at, p.reviewed_at,
            u.name AS hw_name
     FROM prescriptions p JOIN users u ON p.health_worker_id = u.id
     $whereStr ORDER BY p.created_at DESC LIMIT $limit OFFSET $offset"
);
$presStmt->execute($params);
$prescriptions = $presStmt->fetchAll();
$totalPages = max(1, (int) ceil($total / $limit));

// Health workers for filter dropdown
$hwList = $pdo->query(
    "SELECT id, name FROM users WHERE role = 'HEALTH_WORKER' ORDER BY name"
)->fetchAll();

$pageTitle = 'Prescription Oversight';
?>
<?php require __DIR__ . '/includes/header.php'; ?>

<?php if ($msg):  ?><div class="alert alert-success">✅ <?= e($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

<?php if ($viewPrescription): ?>
<!-- ── Prescription Detail View ─────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h2>Prescription — <?= e($viewPrescription['patient_name']) ?></h2>
        <a href="<?= BASE_URL ?>/admin/prescriptions.php" class="btn btn-sm btn-outline">← Back to list</a>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 24px;margin-bottom:18px;">
        <div class="detail-item"><label>Patient Name</label><span><?= e($viewPrescription['patient_name']) ?></span></div>
        <div class="detail-item"><label>Age / Gender</label><span><?= e($viewPrescription['patient_age']) ?> yrs / <?= e($viewPrescription['patient_gender']) ?></span></div>
        <div class="detail-item"><label>Written By</label><span><?= e($viewPrescription['hw_name']) ?> (<?= e($viewPrescription['hw_email']) ?>)</span></div>
        <div class="detail-item"><label>Status</label><span><span class="badge badge-<?= strtolower($viewPrescription['status']) ?>"><?= e($viewPrescription['status']) ?></span></span></div>
        <div class="detail-item"><label>Date</label><span><?= date('d M Y H:i', strtotime($viewPrescription['created_at'])) ?></span></div>
        <?php if ($viewPrescription['reviewed_at']): ?>
        <div class="detail-item"><label>Reviewed At</label><span><?= date('d M Y H:i', strtotime($viewPrescription['reviewed_at'])) ?></span></div>
        <?php endif; ?>
        <?php if ($viewReviewer): ?>
        <div class="detail-item"><label>Reviewed By</label><span><?= e($viewReviewer) ?></span></div>
        <?php endif; ?>
    </div>

    <hr class="divider">
    <div class="form-group">
        <label style="font-size:0.8rem;text-transform:uppercase;color:#90a4ae;letter-spacing:0.5px;">Chief Complaints</label>
        <p style="font-size:0.88rem;color:#37474f;margin:4px 0 0;"><?= nl2br(e($viewPrescription['chief_complaints'])) ?></p>
    </div>
    <?php if ($viewPrescription['on_examination']): ?>
    <div class="form-group">
        <label style="font-size:0.8rem;text-transform:uppercase;color:#90a4ae;letter-spacing:0.5px;">On Examination</label>
        <p style="font-size:0.88rem;color:#37474f;margin:4px 0 0;"><?= nl2br(e($viewPrescription['on_examination'])) ?></p>
    </div>
    <?php endif; ?>
    <?php if ($viewPrescription['advice']): ?>
    <div class="form-group">
        <label style="font-size:0.8rem;text-transform:uppercase;color:#90a4ae;letter-spacing:0.5px;">Advice</label>
        <p style="font-size:0.88rem;color:#37474f;margin:4px 0 0;"><?= nl2br(e($viewPrescription['advice'])) ?></p>
    </div>
    <?php endif; ?>

    <?php if ($viewItems): ?>
    <hr class="divider">
    <div class="card-title" style="font-size:0.85rem;margin-bottom:10px;">Prescribed Medicines (<?= count($viewItems) ?>)</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Medicine</th><th>Form</th><th>Dose</th><th>Frequency</th><th>Duration</th><th>Instructions</th></tr></thead>
            <tbody>
            <?php foreach ($viewItems as $i => $item): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= e($item['medicine_name']) ?></td>
                <td><span style="font-size:0.75rem;background:#f5f5f5;padding:1px 6px;border-radius:3px;"><?= e($item['medicine_form']) ?></span></td>
                <td><?= e($item['dose']) ?></td>
                <td><?= e($item['frequency']) ?></td>
                <td><?= e($item['duration']) ?></td>
                <td><?= e($item['instructions'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($viewPrescription['review_notes']): ?>
    <hr class="divider">
    <div class="form-group">
        <label style="font-size:0.8rem;text-transform:uppercase;color:#90a4ae;letter-spacing:0.5px;">Review Notes</label>
        <p style="font-size:0.88rem;color:#37474f;margin:4px 0 0;"><?= nl2br(e($viewPrescription['review_notes'])) ?></p>
    </div>
    <?php endif; ?>

    <hr class="divider">
    <div class="flex-row" style="flex-wrap:wrap;gap:10px;">
        <form method="POST">
            <input type="hidden" name="prescription_id" value="<?= e($viewPrescription['id']) ?>">
            <input type="hidden" name="action" value="update_status">
            <div class="flex-row">
                <select name="status" class="form-control" style="width:auto;">
                    <?php foreach (['DRAFT','SUBMITTED','REVIEWED'] as $s): ?>
                    <option value="<?= $s ?>"<?= $viewPrescription['status'] === $s ? ' selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="review_notes" class="form-control" style="width:280px;"
                       placeholder="Review notes (optional)"
                       value="<?= e($viewPrescription['review_notes'] ?? '') ?>">
                <button class="btn btn-primary btn-sm">Update Status</button>
            </div>
        </form>
        <form method="POST" onsubmit="return confirm('Delete this prescription permanently?')">
            <input type="hidden" name="action" value="delete_prescription">
            <input type="hidden" name="prescription_id" value="<?= e($viewPrescription['id']) ?>">
            <button class="btn btn-danger btn-sm">🗑️ Delete</button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ── Prescription List ──────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h2>All Prescriptions (<?= $total ?>)</h2>
    </div>

    <form method="GET" class="filters-bar">
        <div class="form-group">
            <label>Search Patient / HW</label>
            <input type="text" name="search" class="form-control" placeholder="Patient name..." value="<?= e($search) ?>">
        </div>
        <div class="form-group">
            <label>Status</label>
            <select name="filter_status" class="form-control">
                <option value="">All Statuses</option>
                <?php foreach (['DRAFT','SUBMITTED','REVIEWED'] as $s): ?>
                <option value="<?= $s ?>"<?= $filterStatus === $s ? ' selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Health Worker</label>
            <select name="filter_hw" class="form-control">
                <option value="">All HWs</option>
                <?php foreach ($hwList as $hw): ?>
                <option value="<?= e($hw['id']) ?>"<?= $filterHw === $hw['id'] ? ' selected' : '' ?>><?= e($hw['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="align-self:flex-end;">
            <button type="submit" class="btn btn-primary">🔍 Filter</button>
            <a href="<?= BASE_URL ?>/admin/prescriptions.php" class="btn btn-outline">Clear</a>
        </div>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Patient</th><th>Age</th><th>Gender</th><th>Health Worker</th>
                    <th>Status</th><th>Date</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($prescriptions): ?>
                <?php foreach ($prescriptions as $p): ?>
                <tr>
                    <td><?= e($p['patient_name']) ?></td>
                    <td><?= e($p['patient_age']) ?></td>
                    <td><?= e($p['patient_gender']) ?></td>
                    <td><?= e($p['hw_name']) ?></td>
                    <td><span class="badge badge-<?= strtolower($p['status']) ?>"><?= e($p['status']) ?></span></td>
                    <td class="text-muted"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/prescriptions.php?view=<?= e($p['id']) ?>" class="btn btn-xs btn-secondary">View</a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this prescription?')">
                            <input type="hidden" name="action" value="delete_prescription">
                            <input type="hidden" name="prescription_id" value="<?= e($p['id']) ?>">
                            <button class="btn btn-xs btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" class="table-empty">No prescriptions found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination" style="margin-top:14px;">
        <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹ Prev</a><?php else: ?><span class="disabled">‹ Prev</span><?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
            <?php elseif (abs($i - $page) <= 2 || $i === 1 || $i === $totalPages): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
            <?php elseif (abs($i - $page) === 3): ?><span>…</span><?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next ›</a><?php else: ?><span class="disabled">Next ›</span><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
