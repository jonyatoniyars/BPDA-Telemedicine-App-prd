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

    if ($action === 'approve') {
        $id = $_POST['user_id'] ?? '';
        // Also grant prescription-writing permission when activating a health worker.
        $pdo->prepare(
            "UPDATE users SET status = 'ACTIVE',
             can_write_prescription = IF(role = 'HEALTH_WORKER', 1, can_write_prescription)
             WHERE id = ? AND role != 'ADMIN'"
        )->execute([$id]);
        $msg = 'User approved successfully.';

    } elseif ($action === 'suspend') {
        $id = $_POST['user_id'] ?? '';
        $pdo->prepare("UPDATE users SET status = 'SUSPENDED' WHERE id = ? AND role != 'ADMIN'")->execute([$id]);
        $msg = 'User suspended.';

    } elseif ($action === 'activate') {
        $id = $_POST['user_id'] ?? '';
        $pdo->prepare(
            "UPDATE users SET status = 'ACTIVE',
             can_write_prescription = IF(role = 'HEALTH_WORKER', 1, can_write_prescription)
             WHERE id = ? AND role != 'ADMIN'"
        )->execute([$id]);
        $msg = 'User re-activated.';

    } elseif ($action === 'toggle_prescription') {
        $id = $_POST['user_id'] ?? '';
        $pdo->prepare(
            "UPDATE users SET can_write_prescription = NOT can_write_prescription WHERE id = ? AND role = 'HEALTH_WORKER'"
        )->execute([$id]);
        $msg = 'Prescription permission toggled.';

    } elseif ($action === 'create_user') {
        $name     = trim($_POST['name']     ?? '');
        $email    = trim($_POST['email']    ?? '');
        $phone    = trim($_POST['phone']    ?? '') ?: null;
        $role     = $_POST['role']          ?? '';
        $status   = $_POST['status']        ?? 'PENDING';
        $password = trim($_POST['password'] ?? '');
        $canRx    = isset($_POST['can_write_prescription']) ? 1 : 0;

        $allowedRoles    = ['DOCTOR', 'HEALTH_WORKER', 'ADMIN'];
        $allowedStatuses = ['ACTIVE', 'PENDING'];

        if (!$name || !$email || !$role || !$password) {
            $error = 'Name, email, role, and password are required.';
        } elseif (!validateEmail($email)) {
            $error = 'Invalid email address.';
        } elseif (!in_array($role, $allowedRoles, true)) {
            $error = 'Invalid role.';
        } elseif (!in_array($status, $allowedStatuses, true)) {
            $error = 'Invalid status.';
        } else {
            // Check email uniqueness
            $exists = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $exists->execute([$email]);
            if ($exists->fetch()) {
                $error = 'A user with that email already exists.';
            } else {
                $newId = cuid();
                $hash  = hashPassword($password);
                $pdo->prepare(
                    "INSERT INTO users (id, name, email, phone, password_hash, role, status, can_write_prescription) VALUES (?,?,?,?,?,?,?,?)"
                )->execute([$newId, $name, $email, $phone, $hash, $role, $status, $role === 'HEALTH_WORKER' ? $canRx : 0]);
                $msg = "User '{$name}' created successfully.";
            }
        }

    } elseif ($action === 'delete_user') {
        $id = $_POST['user_id'] ?? '';
        // Safety: don't delete own account
        if ($id === $adminUser['id']) {
            $error = 'You cannot delete your own account.';
        } else {
            $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'ADMIN'")->execute([$id]);
            $msg = 'User deleted.';
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterStatus = $_GET['filter_status'] ?? '';
$filterRole   = $_GET['filter_role']   ?? '';
$search       = trim($_GET['search']   ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit        = 20;
$offset       = ($page - 1) * $limit;

$viewId = $_GET['view'] ?? '';

// ── Build query ───────────────────────────────────────────────────────────────
$where  = ["role != 'ADMIN'"];
$params = [];

if ($filterStatus && in_array($filterStatus, ['PENDING','ACTIVE','SUSPENDED'], true)) {
    $where[]  = 'status = ?';
    $params[] = $filterStatus;
}
if ($filterRole && in_array($filterRole, ['DOCTOR','HEALTH_WORKER'], true)) {
    $where[]  = 'role = ?';
    $params[] = $filterRole;
}
if ($search) {
    $where[]  = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like]);
}

$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM users $whereStr");
$totalStmt->execute($params);
$total = (int) $totalStmt->fetchColumn();

$usersStmt = $pdo->prepare("SELECT id, name, email, phone, role, status, can_write_prescription, created_at FROM users $whereStr ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$usersStmt->execute($params);
$users = $usersStmt->fetchAll();

$totalPages = max(1, (int) ceil($total / $limit));

// ── View single user ──────────────────────────────────────────────────────────
$viewUser       = null;
$viewAssignment = null;
if ($viewId) {
    $vs = $pdo->prepare("SELECT id, name, email, phone, role, status, can_write_prescription, created_at FROM users WHERE id = ?");
    $vs->execute([$viewId]);
    $viewUser = $vs->fetch() ?: null;

    if ($viewUser) {
        if ($viewUser['role'] === 'HEALTH_WORKER') {
            $as = $pdo->prepare(
                "SELECT da.id, da.assigned_at, u.name AS doctor_name, u.email AS doctor_email
                 FROM doctor_assignments da JOIN users u ON da.doctor_id = u.id
                 WHERE da.health_worker_id = ? LIMIT 1"
            );
            $as->execute([$viewId]);
            $viewAssignment = $as->fetch() ?: null;
        } elseif ($viewUser['role'] === 'DOCTOR') {
            $as = $pdo->prepare(
                "SELECT da.id, da.assigned_at, u.name AS hw_name, u.email AS hw_email
                 FROM doctor_assignments da JOIN users u ON da.health_worker_id = u.id
                 WHERE da.doctor_id = ?"
            );
            $as->execute([$viewId]);
            $viewAssignment = $as->fetchAll();
        }
    }
}

$pageTitle = 'User Management';
?>
<?php require __DIR__ . '/includes/header.php'; ?>

<?php if ($msg):  ?><div class="alert alert-success">✅ <?= e($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

<!-- View User Detail (shown as card if ?view= param) -->
<?php if ($viewUser): ?>
<div class="card">
    <div class="card-header">
        <h2>User Details — <?= e($viewUser['name']) ?></h2>
        <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-sm btn-outline">← Back to list</a>
    </div>
    <div class="detail-grid">
        <div class="detail-item"><label>Name</label><span><?= e($viewUser['name']) ?></span></div>
        <div class="detail-item"><label>Email</label><span><?= e($viewUser['email'] ?? '—') ?></span></div>
        <div class="detail-item"><label>Phone</label><span><?= e($viewUser['phone'] ?? '—') ?></span></div>
        <div class="detail-item"><label>Role</label><span><span class="badge badge-<?= $viewUser['role'] === 'HEALTH_WORKER' ? 'hw' : strtolower($viewUser['role']) ?>"><?= e($viewUser['role']) ?></span></span></div>
        <div class="detail-item"><label>Status</label><span><span class="badge badge-<?= strtolower($viewUser['status']) ?>"><?= e($viewUser['status']) ?></span></span></div>
        <div class="detail-item"><label>Can Write Prescription</label><span><?= $viewUser['can_write_prescription'] ? '✅ Yes' : '❌ No' ?></span></div>
        <div class="detail-item"><label>Joined</label><span><?= date('d M Y H:i', strtotime($viewUser['created_at'])) ?></span></div>
        <div class="detail-item"><label>User ID</label><span class="text-muted" style="font-size:0.75rem"><?= e($viewUser['id']) ?></span></div>
    </div>

    <?php if ($viewUser['role'] === 'HEALTH_WORKER' && $viewAssignment): ?>
    <hr class="divider">
    <div class="detail-item"><label>Assigned Doctor</label><span><?= e($viewAssignment['doctor_name']) ?> (<?= e($viewAssignment['doctor_email']) ?>)</span></div>
    <div class="detail-item"><label>Assigned At</label><span><?= date('d M Y', strtotime($viewAssignment['assigned_at'])) ?></span></div>
    <?php elseif ($viewUser['role'] === 'DOCTOR' && $viewAssignment): ?>
    <hr class="divider">
    <div class="detail-item detail-full"><label>Assigned Health Workers (<?= count($viewAssignment) ?>)</label>
        <?php if ($viewAssignment): ?>
        <ul style="margin:6px 0 0;padding-left:20px;font-size:0.86rem;">
            <?php foreach ($viewAssignment as $a): ?>
            <li><?= e($a['hw_name']) ?> — <?= e($a['hw_email']) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?><span class="text-muted">None assigned</span><?php endif; ?>
    </div>
    <?php endif; ?>

    <hr class="divider">
    <div class="flex-row">
        <?php if ($viewUser['status'] === 'PENDING'): ?>
        <form method="POST">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="user_id" value="<?= e($viewUser['id']) ?>">
            <button class="btn btn-success btn-sm">✅ Approve</button>
        </form>
        <?php endif; ?>
        <?php if ($viewUser['status'] === 'ACTIVE'): ?>
        <form method="POST" onsubmit="return confirm('Suspend this user?')">
            <input type="hidden" name="action" value="suspend">
            <input type="hidden" name="user_id" value="<?= e($viewUser['id']) ?>">
            <button class="btn btn-warning btn-sm">⛔ Suspend</button>
        </form>
        <?php elseif ($viewUser['status'] === 'SUSPENDED'): ?>
        <form method="POST">
            <input type="hidden" name="action" value="activate">
            <input type="hidden" name="user_id" value="<?= e($viewUser['id']) ?>">
            <button class="btn btn-success btn-sm">▶️ Re-activate</button>
        </form>
        <?php endif; ?>
        <?php if ($viewUser['role'] === 'HEALTH_WORKER'): ?>
        <form method="POST">
            <input type="hidden" name="action" value="toggle_prescription">
            <input type="hidden" name="user_id" value="<?= e($viewUser['id']) ?>">
            <button class="btn btn-info btn-sm">💊 Toggle Rx Permission</button>
        </form>
        <?php endif; ?>
        <form method="POST" onsubmit="return confirm('Permanently delete this user? This cannot be undone.')">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" value="<?= e($viewUser['id']) ?>">
            <button class="btn btn-danger btn-sm">🗑️ Delete User</button>
        </form>
    </div>
</div>
<?php else: ?>

<!-- Filters + Create User -->
<div class="card">
    <div class="card-header">
        <h2>All Users (<?= $total ?>)</h2>
        <button class="btn btn-primary btn-sm" onclick="openModal('modalCreate')">+ New User</button>
    </div>

    <form method="GET" class="filters-bar">
        <div class="form-group">
            <label>Search</label>
            <input type="text" name="search" class="form-control" placeholder="Name / Email / Phone" value="<?= e($search) ?>">
        </div>
        <div class="form-group">
            <label>Status</label>
            <select name="filter_status" class="form-control">
                <option value="">All Statuses</option>
                <?php foreach (['PENDING','ACTIVE','SUSPENDED'] as $s): ?>
                <option value="<?= $s ?>"<?= $filterStatus === $s ? ' selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Role</label>
            <select name="filter_role" class="form-control">
                <option value="">All Roles</option>
                <option value="DOCTOR"<?= $filterRole === 'DOCTOR' ? ' selected' : '' ?>>Doctor</option>
                <option value="HEALTH_WORKER"<?= $filterRole === 'HEALTH_WORKER' ? ' selected' : '' ?>>Health Worker</option>
            </select>
        </div>
        <div class="form-group" style="align-self:flex-end;">
            <button type="submit" class="btn btn-primary">🔍 Filter</button>
            <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-outline">Clear</a>
        </div>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th>
                    <th>Rx Perm.</th><th>Joined</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($users): ?>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['name']) ?></td>
                    <td><?= e($u['email'] ?? '—') ?></td>
                    <td><?= e($u['phone'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= $u['role'] === 'HEALTH_WORKER' ? 'hw' : strtolower($u['role']) ?>"><?= e($u['role']) ?></span></td>
                    <td><span class="badge badge-<?= strtolower($u['status']) ?>"><?= e($u['status']) ?></span></td>
                    <td><?= $u['role'] === 'HEALTH_WORKER' ? ($u['can_write_prescription'] ? '✅' : '❌') : '—' ?></td>
                    <td class="text-muted"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/users.php?view=<?= e($u['id']) ?>" class="btn btn-xs btn-secondary">View</a>
                        <?php if ($u['status'] === 'PENDING'): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                            <button class="btn btn-xs btn-success">Approve</button>
                        </form>
                        <?php elseif ($u['status'] === 'ACTIVE'): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Suspend user?')">
                            <input type="hidden" name="action" value="suspend">
                            <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                            <button class="btn btn-xs btn-warning">Suspend</button>
                        </form>
                        <?php elseif ($u['status'] === 'SUSPENDED'): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                            <button class="btn btn-xs btn-success">Activate</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($u['role'] === 'HEALTH_WORKER'): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="toggle_prescription">
                            <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                            <button class="btn btn-xs btn-info" title="Toggle prescription permission">Rx</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" class="table-empty">No users found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination" style="margin-top:14px;">
        <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹ Prev</a>
        <?php else: ?>
            <span class="disabled">‹ Prev</span>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="current"><?= $i ?></span>
            <?php elseif (abs($i - $page) <= 2 || $i === 1 || $i === $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
            <?php elseif (abs($i - $page) === 3): ?>
                <span>…</span>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next ›</a>
        <?php else: ?>
            <span class="disabled">Next ›</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Create User Modal ────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalCreate"<?= $error ? ' style="display:flex"' : '' ?>>
    <div class="modal-box">
        <div class="modal-header">
            <h3>Create New User</h3>
            <button class="modal-close" onclick="closeModal('modalCreate')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_user">
            <div class="form-row">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="name" class="form-control" value="<?= e($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" class="form-control" value="<?= e($_POST['email'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= e($_POST['phone'] ?? '') ?>" placeholder="01XXXXXXXXX">
                </div>
                <div class="form-group">
                    <label>Role *</label>
                    <select name="role" class="form-control" required>
                        <option value="">Select Role</option>
                        <option value="DOCTOR"<?= ($_POST['role'] ?? '') === 'DOCTOR' ? ' selected' : '' ?>>Doctor</option>
                        <option value="HEALTH_WORKER"<?= ($_POST['role'] ?? '') === 'HEALTH_WORKER' ? ' selected' : '' ?>>Health Worker</option>
                        <option value="ADMIN"<?= ($_POST['role'] ?? '') === 'ADMIN' ? ' selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="ACTIVE"<?= ($_POST['status'] ?? 'ACTIVE') === 'ACTIVE' ? ' selected' : '' ?>>Active</option>
                        <option value="PENDING"<?= ($_POST['status'] ?? '') === 'PENDING' ? ' selected' : '' ?>>Pending</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" class="form-control" required minlength="6">
                </div>
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="can_write_prescription" value="1"<?= !empty($_POST['can_write_prescription']) ? ' checked' : '' ?>>
                    Can Write Prescription (Health Worker only)
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalCreate')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<?php if ($error): ?>
<script>openModal('modalCreate');</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
