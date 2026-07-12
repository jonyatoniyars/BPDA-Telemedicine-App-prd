<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

if (!isLoggedIn()) redirect(BASE_URL . '/admin/login.php');
$adminUser = requireAdmin();

// ── Stats ────────────────────────────────────────────────────────────────────
$stats = [];

$row = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'ADMIN'")->fetchColumn();
$stats['total_users'] = (int) $row;

$row = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'PENDING'")->fetchColumn();
$stats['pending_approvals'] = (int) $row;

$row = $pdo->query("SELECT COUNT(*) FROM prescriptions")->fetchColumn();
$stats['prescriptions'] = (int) $row;

$row = $pdo->query("SELECT COUNT(*) FROM medicines WHERE is_active = 1")->fetchColumn();
$stats['medicines'] = (int) $row;

$row = $pdo->query("SELECT COUNT(*) FROM doctor_assignments")->fetchColumn();
$stats['assignments'] = (int) $row;

$row = $pdo->query("SELECT COUNT(*) FROM prescriptions WHERE status = 'SUBMITTED'")->fetchColumn();
$stats['pending_prescriptions'] = (int) $row;

// ── Recent users ─────────────────────────────────────────────────────────────
$recentUsers = $pdo->query(
    "SELECT id, name, email, role, status, created_at FROM users WHERE role != 'ADMIN' ORDER BY created_at DESC LIMIT 5"
)->fetchAll();

// ── Recent prescriptions ─────────────────────────────────────────────────────
$recentPrescriptions = $pdo->query(
    "SELECT p.id, p.patient_name, p.status, p.created_at, u.name AS hw_name
     FROM prescriptions p JOIN users u ON p.health_worker_id = u.id
     ORDER BY p.created_at DESC LIMIT 5"
)->fetchAll();

$pageTitle = 'Dashboard';
?>
<?php require __DIR__ . '/includes/header.php'; ?>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card stat-blue">
        <div class="stat-icon">👥</div>
        <div class="stat-num"><?= $stats['total_users'] ?></div>
        <div class="stat-label">Total Users</div>
    </div>
    <div class="stat-card stat-orange">
        <div class="stat-icon">⏳</div>
        <div class="stat-num"><?= $stats['pending_approvals'] ?></div>
        <div class="stat-label">Pending Approvals</div>
    </div>
    <div class="stat-card stat-purple">
        <div class="stat-icon">📋</div>
        <div class="stat-num"><?= $stats['prescriptions'] ?></div>
        <div class="stat-label">Total Prescriptions</div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">💊</div>
        <div class="stat-num"><?= $stats['medicines'] ?></div>
        <div class="stat-label">Active Medicines</div>
    </div>
    <div class="stat-card stat-blue">
        <div class="stat-icon">🔗</div>
        <div class="stat-num"><?= $stats['assignments'] ?></div>
        <div class="stat-label">Assignments</div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon">📨</div>
        <div class="stat-num"><?= $stats['pending_prescriptions'] ?></div>
        <div class="stat-label">Awaiting Review</div>
    </div>
</div>

<!-- Quick Links -->
<div class="card">
    <div class="card-title">Quick Actions</div>
    <div class="quick-links">
        <a href="<?= BASE_URL ?>/admin/users.php" class="quick-link">
            <div class="ql-icon">👥</div><div class="ql-label">Manage Users</div>
        </a>
        <a href="<?= BASE_URL ?>/admin/users.php?filter_status=PENDING" class="quick-link">
            <div class="ql-icon">⏳</div><div class="ql-label">Pending Approvals</div>
        </a>
        <a href="<?= BASE_URL ?>/admin/medicines.php" class="quick-link">
            <div class="ql-icon">💊</div><div class="ql-label">Medicines</div>
        </a>
        <a href="<?= BASE_URL ?>/admin/prescriptions.php" class="quick-link">
            <div class="ql-icon">📋</div><div class="ql-label">Prescriptions</div>
        </a>
        <a href="<?= BASE_URL ?>/admin/assignments.php" class="quick-link">
            <div class="ql-icon">🔗</div><div class="ql-label">Assignments</div>
        </a>
        <a href="<?= BASE_URL ?>/admin/roles.php" class="quick-link">
            <div class="ql-icon">🔑</div><div class="ql-label">Roles</div>
        </a>
        <a href="<?= BASE_URL ?>/admin/settings.php" class="quick-link">
            <div class="ql-icon">⚙️</div><div class="ql-label">Settings</div>
        </a>
    </div>
</div>

<!-- Recent Users -->
<div class="card">
    <div class="card-header">
        <h2>Recent Registrations</h2>
        <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-sm btn-outline">View All</a>
    </div>
    <?php if ($recentUsers): ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentUsers as $u): ?>
                <tr>
                    <td><?= e($u['name']) ?></td>
                    <td><?= e($u['email'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= strtolower($u['role'] === 'HEALTH_WORKER' ? 'hw' : $u['role']) ?>"><?= e($u['role']) ?></span></td>
                    <td><span class="badge badge-<?= strtolower($u['status']) ?>"><?= e($u['status']) ?></span></td>
                    <td class="text-muted"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/users.php?view=<?= e($u['id']) ?>" class="btn btn-xs btn-secondary">View</a>
                        <?php if ($u['status'] === 'PENDING'): ?>
                        <form method="POST" action="<?= BASE_URL ?>/admin/users.php" style="display:inline">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                            <button class="btn btn-xs btn-success">Approve</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <p class="table-empty">No users yet.</p>
    <?php endif; ?>
</div>

<!-- Recent Prescriptions -->
<div class="card">
    <div class="card-header">
        <h2>Recent Prescriptions</h2>
        <a href="<?= BASE_URL ?>/admin/prescriptions.php" class="btn btn-sm btn-outline">View All</a>
    </div>
    <?php if ($recentPrescriptions): ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Patient</th><th>Health Worker</th><th>Status</th><th>Date</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($recentPrescriptions as $p): ?>
                <tr>
                    <td><?= e($p['patient_name']) ?></td>
                    <td><?= e($p['hw_name']) ?></td>
                    <td><span class="badge badge-<?= strtolower($p['status']) ?>"><?= e($p['status']) ?></span></td>
                    <td class="text-muted"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
                    <td><a href="<?= BASE_URL ?>/admin/prescriptions.php?view=<?= e($p['id']) ?>" class="btn btn-xs btn-secondary">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <p class="table-empty">No prescriptions yet.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
