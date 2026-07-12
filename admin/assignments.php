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

    if ($action === 'assign') {
        $doctorId = $_POST['doctor_id']       ?? '';
        $hwId     = $_POST['health_worker_id'] ?? '';

        if (!$doctorId || !$hwId) {
            $error = 'Both doctor and health worker are required.';
        } else {
            // Verify both exist with correct roles
            $docCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'DOCTOR'");
            $docCheck->execute([$doctorId]);

            $hwCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'HEALTH_WORKER'");
            $hwCheck->execute([$hwId]);

            if (!$docCheck->fetch()) {
                $error = 'Invalid doctor selected.';
            } elseif (!$hwCheck->fetch()) {
                $error = 'Invalid health worker selected.';
            } else {
                // Check if HW already assigned (UNIQUE on health_worker_id)
                $existing = $pdo->prepare("SELECT id FROM doctor_assignments WHERE health_worker_id = ? LIMIT 1");
                $existing->execute([$hwId]);
                if ($existing->fetch()) {
                    $error = 'This health worker is already assigned to a doctor. Unassign first.';
                } else {
                    $newId = cuid();
                    $pdo->prepare(
                        "INSERT INTO doctor_assignments (id, doctor_id, health_worker_id) VALUES (?,?,?)"
                    )->execute([$newId, $doctorId, $hwId]);
                    $msg = 'Assignment created successfully.';
                }
            }
        }

    } elseif ($action === 'unassign') {
        $assignId = $_POST['assignment_id'] ?? '';
        if ($assignId) {
            $pdo->prepare("DELETE FROM doctor_assignments WHERE id = ?")->execute([$assignId]);
            $msg = 'Assignment removed.';
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterDoctor = $_GET['filter_doctor'] ?? '';
$search       = trim($_GET['search']   ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit        = 25;
$offset       = ($page - 1) * $limit;

$where  = [];
$params = [];

if ($filterDoctor) {
    $where[]  = 'da.doctor_id = ?';
    $params[] = $filterDoctor;
}
if ($search) {
    $where[]  = '(doc.name LIKE ? OR hw.name LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like]);
}

$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM doctor_assignments da
     JOIN users doc ON da.doctor_id = doc.id
     JOIN users hw  ON da.health_worker_id = hw.id
     $whereStr"
);
$totalStmt->execute($params);
$total = (int) $totalStmt->fetchColumn();

$asgStmt = $pdo->prepare(
    "SELECT da.id, da.assigned_at,
            doc.id AS doctor_id, doc.name AS doctor_name, doc.email AS doctor_email,
            hw.id  AS hw_id,     hw.name  AS hw_name,     hw.email  AS hw_email,
            hw.can_write_prescription
     FROM doctor_assignments da
     JOIN users doc ON da.doctor_id = doc.id
     JOIN users hw  ON da.health_worker_id = hw.id
     $whereStr
     ORDER BY da.assigned_at DESC
     LIMIT $limit OFFSET $offset"
);
$asgStmt->execute($params);
$assignments = $asgStmt->fetchAll();
$totalPages = max(1, (int) ceil($total / $limit));

// ── Dropdown data ─────────────────────────────────────────────────────────────
$doctors = $pdo->query(
    "SELECT id, name, email FROM users WHERE role = 'DOCTOR' AND status = 'ACTIVE' ORDER BY name"
)->fetchAll();

// Unassigned active health workers only
$unassignedHW = $pdo->query(
    "SELECT u.id, u.name, u.email FROM users u
     WHERE u.role = 'HEALTH_WORKER' AND u.status = 'ACTIVE'
       AND NOT EXISTS (SELECT 1 FROM doctor_assignments da WHERE da.health_worker_id = u.id)
     ORDER BY u.name"
)->fetchAll();

// All doctors for filter dropdown
$doctorFilter = $pdo->query(
    "SELECT DISTINCT u.id, u.name FROM users u
     JOIN doctor_assignments da ON da.doctor_id = u.id ORDER BY u.name"
)->fetchAll();

$pageTitle = 'Assignments';
?>
<?php require __DIR__ . '/includes/header.php'; ?>

<?php if ($msg):  ?><div class="alert alert-success">✅ <?= e($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

<!-- Summary stats -->
<div class="stats-grid" style="margin-bottom:22px;">
    <div class="stat-card stat-blue">
        <div class="stat-icon">🔗</div>
        <div class="stat-num"><?= $total ?></div>
        <div class="stat-label">Total Assignments</div>
    </div>
    <div class="stat-card stat-orange">
        <div class="stat-icon">🧑‍⚕️</div>
        <div class="stat-num"><?= count($unassignedHW) ?></div>
        <div class="stat-label">Unassigned Health Workers</div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">👨‍⚕️</div>
        <div class="stat-num"><?= count($doctors) ?></div>
        <div class="stat-label">Active Doctors</div>
    </div>
</div>

<!-- Create Assignment -->
<div class="card">
    <div class="card-title">Assign Health Worker to Doctor</div>
    <?php if (!$doctors): ?>
        <div class="alert alert-info">ℹ️ No active doctors available. <a href="<?= BASE_URL ?>/admin/users.php">Add a doctor first.</a></div>
    <?php elseif (!$unassignedHW): ?>
        <div class="alert alert-info">ℹ️ All active health workers are already assigned.</div>
    <?php else: ?>
    <form method="POST" style="max-width:600px;">
        <input type="hidden" name="action" value="assign">
        <div class="form-row">
            <div class="form-group">
                <label>Doctor *</label>
                <select name="doctor_id" class="form-control" required>
                    <option value="">Select Doctor</option>
                    <?php foreach ($doctors as $doc): ?>
                    <option value="<?= e($doc['id']) ?>"<?= ($_POST['doctor_id'] ?? '') === $doc['id'] ? ' selected' : '' ?>>
                        <?= e($doc['name']) ?> (<?= e($doc['email']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Health Worker *</label>
                <select name="health_worker_id" class="form-control" required>
                    <option value="">Select Health Worker</option>
                    <?php foreach ($unassignedHW as $hw): ?>
                    <option value="<?= e($hw['id']) ?>"<?= ($_POST['health_worker_id'] ?? '') === $hw['id'] ? ' selected' : '' ?>>
                        <?= e($hw['name']) ?> (<?= e($hw['email']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">🔗 Create Assignment</button>
    </form>
    <?php endif; ?>
</div>

<!-- Assignment List -->
<div class="card">
    <div class="card-header">
        <h2>Current Assignments (<?= $total ?>)</h2>
    </div>

    <form method="GET" class="filters-bar">
        <div class="form-group">
            <label>Search</label>
            <input type="text" name="search" class="form-control" placeholder="Doctor or HW name" value="<?= e($search) ?>">
        </div>
        <div class="form-group">
            <label>Doctor</label>
            <select name="filter_doctor" class="form-control">
                <option value="">All Doctors</option>
                <?php foreach ($doctorFilter as $doc): ?>
                <option value="<?= e($doc['id']) ?>"<?= $filterDoctor === $doc['id'] ? ' selected' : '' ?>><?= e($doc['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="align-self:flex-end;">
            <button type="submit" class="btn btn-primary">🔍 Filter</button>
            <a href="<?= BASE_URL ?>/admin/assignments.php" class="btn btn-outline">Clear</a>
        </div>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Doctor</th><th>Doctor Email</th>
                    <th>Health Worker</th><th>HW Email</th>
                    <th>Rx Permission</th><th>Assigned</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($assignments): ?>
                <?php foreach ($assignments as $a): ?>
                <tr>
                    <td><?= e($a['doctor_name']) ?></td>
                    <td class="text-muted"><?= e($a['doctor_email'] ?? '—') ?></td>
                    <td><?= e($a['hw_name']) ?></td>
                    <td class="text-muted"><?= e($a['hw_email'] ?? '—') ?></td>
                    <td><?= $a['can_write_prescription'] ? '✅' : '❌' ?></td>
                    <td class="text-muted"><?= date('d M Y', strtotime($a['assigned_at'])) ?></td>
                    <td>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Remove this assignment?')">
                            <input type="hidden" name="action" value="unassign">
                            <input type="hidden" name="assignment_id" value="<?= e($a['id']) ?>">
                            <button class="btn btn-xs btn-danger">Unassign</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" class="table-empty">No assignments found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

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

<?php require __DIR__ . '/includes/footer.php'; ?>
