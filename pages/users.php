<?php
// pages/users.php — list users with filters, approve/suspend, permission toggle (admin only)
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

$user      = requireAdmin();
$pageTitle = 'Users';

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;
$search = sanitize($_GET['search'] ?? '');
$roleFilter   = sanitize($_GET['role']   ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');

$validRoles    = ['', 'ADMIN', 'DOCTOR', 'HEALTH_WORKER'];
$validStatuses = ['', 'PENDING', 'ACTIVE', 'SUSPENDED'];
if (!in_array($roleFilter,   $validRoles,    true)) $roleFilter = '';
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = '';

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[]  = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($roleFilter)   { $where[] = 'role = ?';   $params[] = $roleFilter; }
if ($statusFilter) { $where[] = 'status = ?'; $params[] = $statusFilter; }

$whereStr = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $whereStr");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listStmt = $pdo->prepare("SELECT id, name, email, phone, role, status, can_write_prescription, created_at FROM users WHERE $whereStr ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$listStmt->execute($params);
$users = $listStmt->fetchAll();
$pagination = paginate($page, $limit, $total);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="app-layout">
    <?php require __DIR__ . '/../includes/sidebar.php' ?>

    <div class="app-main">
        <header class="page-header">
            <div class="page-header__left">
                <button class="btn btn--ghost btn--icon sidebar__toggle" id="sidebarToggle" aria-label="Toggle menu">☰</button>
                <h1 class="page-header__title">Users</h1>
            </div>
        </header>

        <main class="page-content">
            <!-- Status notifications -->
            <div id="actionAlert" class="alert" hidden></div>

            <!-- Filters -->
            <form method="get" class="filter-bar" role="search">
                <input type="search" name="search" value="<?= e($search) ?>" placeholder="Search name, email, phone…" class="form-control filter-bar__search" aria-label="Search users">
                <select name="role" class="form-control form-select filter-bar__select" aria-label="Filter by role">
                    <option value="">All Roles</option>
                    <?php foreach (['ADMIN','DOCTOR','HEALTH_WORKER'] as $r): ?>
                        <option value="<?= $r ?>" <?= $roleFilter === $r ? 'selected' : '' ?>><?= str_replace('_',' ',$r) ?></option>
                    <?php endforeach ?>
                </select>
                <select name="status" class="form-control form-select filter-bar__select" aria-label="Filter by status">
                    <option value="">All Statuses</option>
                    <?php foreach (['PENDING','ACTIVE','SUSPENDED'] as $s): ?>
                        <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach ?>
                </select>
                <button type="submit" class="btn btn--secondary">Filter</button>
                <?php if ($search || $roleFilter || $statusFilter): ?>
                    <a href="<?= BASE_URL ?>/pages/users.php" class="btn btn--ghost">Clear</a>
                <?php endif ?>
            </form>

            <!-- Table -->
            <div class="table-card">
                <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <span class="empty-state__icon">👥</span>
                        <p>No users found.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email / Phone</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Can Write Rx</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                <tr data-user-id="<?= e($u['id']) ?>">
                                    <td><?= e($u['name']) ?></td>
                                    <td>
                                        <?php if ($u['email']): ?><div><?= e($u['email']) ?></div><?php endif ?>
                                        <?php if ($u['phone']): ?><small class="text-muted"><?= e($u['phone']) ?></small><?php endif ?>
                                    </td>
                                    <td><span class="badge badge--<?= strtolower(str_replace('_','-',$u['role'])) ?>"><?= e(str_replace('_',' ',$u['role'])) ?></span></td>
                                    <td>
                                        <span class="badge badge--status-<?= strtolower($u['status']) ?> js-status-badge">
                                            <?= e($u['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($u['role'] === 'HEALTH_WORKER'): ?>
                                            <label class="toggle-label" title="Toggle prescription writing">
                                                <input type="checkbox"
                                                    class="js-perm-toggle"
                                                    data-user-id="<?= e($u['id']) ?>"
                                                    <?= $u['can_write_prescription'] ? 'checked' : '' ?>
                                                    aria-label="Can write prescription">
                                                <span class="toggle-switch"></span>
                                            </label>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif ?>
                                    </td>
                                    <td><?= e(date('d M Y', strtotime($u['created_at']))) ?></td>
                                    <td class="table-actions">
                                        <?php if ($u['status'] === 'PENDING'): ?>
                                            <button class="btn btn--sm btn--success js-approve-btn" data-user-id="<?= e($u['id']) ?>">Approve</button>
                                        <?php endif ?>
                                        <?php if ($u['status'] === 'ACTIVE' && $u['id'] !== $user['id']): ?>
                                            <button class="btn btn--sm btn--danger js-suspend-btn" data-user-id="<?= e($u['id']) ?>">Suspend</button>
                                        <?php endif ?>
                                        <?php if ($u['status'] === 'SUSPENDED'): ?>
                                            <button class="btn btn--sm btn--success js-approve-btn" data-user-id="<?= e($u['id']) ?>">Reactivate</button>
                                        <?php endif ?>
                                    </td>
                                </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($pagination['totalPages'] > 1): ?>
                    <nav class="pagination" aria-label="Pagination">
                        <span class="pagination__info"><?= (($page-1)*$limit+1) ?>–<?= min($page*$limit,$total) ?> of <?= $total ?></span>
                        <div class="pagination__controls">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($roleFilter) ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn--ghost btn--sm">← Prev</a>
                            <?php endif ?>
                            <span class="pagination__current">Page <?= $page ?> of <?= $pagination['totalPages'] ?></span>
                            <?php if ($page < $pagination['totalPages']): ?>
                                <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($roleFilter) ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn--ghost btn--sm">Next →</a>
                            <?php endif ?>
                        </div>
                    </nav>
                    <?php endif ?>
                <?php endif ?>
            </div>
        </main>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php' ?>

<script>
(function () {
    const BASE_URL = <?= json_encode(BASE_URL) ?>;
    const alertEl  = document.getElementById('actionAlert');

    function showAlert(msg, type = 'success') {
        alertEl.textContent = msg;
        alertEl.className   = `alert alert--${type}`;
        alertEl.hidden      = false;
        setTimeout(() => { alertEl.hidden = true; }, 4000);
    }

    async function patchUser(userId, payload) {
        const res  = await fetch(`${BASE_URL}/api/user-detail.php?id=${encodeURIComponent(userId)}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload),
        });
        return res.json();
    }

    // Approve / Reactivate
    document.querySelectorAll('.js-approve-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const uid = btn.dataset.userId;
            try {
                const data = await patchUser(uid, { status: 'ACTIVE' });
                if (data.success) {
                    const row = btn.closest('tr');
                    row.querySelector('.js-status-badge').textContent = 'ACTIVE';
                    row.querySelector('.js-status-badge').className   = 'badge badge--status-active js-status-badge';
                    btn.remove();
                    showAlert('User activated.');
                } else { showAlert(data.error?.message ?? 'Failed.', 'error'); }
            } catch { showAlert('Network error.', 'error'); }
        });
    });

    // Suspend
    document.querySelectorAll('.js-suspend-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Suspend this user?')) return;
            const uid = btn.dataset.userId;
            try {
                const data = await patchUser(uid, { status: 'SUSPENDED' });
                if (data.success) {
                    const row = btn.closest('tr');
                    row.querySelector('.js-status-badge').textContent = 'SUSPENDED';
                    row.querySelector('.js-status-badge').className   = 'badge badge--status-suspended js-status-badge';
                    btn.remove();
                    showAlert('User suspended.');
                } else { showAlert(data.error?.message ?? 'Failed.', 'error'); }
            } catch { showAlert('Network error.', 'error'); }
        });
    });

    // Permission toggle
    document.querySelectorAll('.js-perm-toggle').forEach(checkbox => {
        checkbox.addEventListener('change', async function () {
            const uid  = this.dataset.userId;
            const val  = this.checked;
            try {
                const data = await patchUser(uid, { canWritePrescription: val });
                if (!data.success) {
                    this.checked = !this.checked; // revert
                    showAlert(data.error?.message ?? 'Failed.', 'error');
                } else { showAlert(`Prescription writing ${val ? 'enabled' : 'disabled'}.`); }
            } catch {
                this.checked = !this.checked;
                showAlert('Network error.', 'error');
            }
        });
    });
})();
</script>
