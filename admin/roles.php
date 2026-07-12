<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

if (!isLoggedIn()) redirect(BASE_URL . '/admin/login.php');
$adminUser = requireAdmin();

$msg   = '';
$error = '';

// ── POST: toggle prescription permission for a specific health worker ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_prescription') {
        $id = $_POST['user_id'] ?? '';
        if ($id) {
            $pdo->prepare(
                "UPDATE users SET can_write_prescription = NOT can_write_prescription WHERE id = ? AND role = 'HEALTH_WORKER'"
            )->execute([$id]);
            $msg = 'Prescription permission updated.';
        }
    } elseif ($action === 'grant_all_hw') {
        $pdo->exec("UPDATE users SET can_write_prescription = 1 WHERE role = 'HEALTH_WORKER'");
        $msg = 'Prescription permission granted to all Health Workers.';
    } elseif ($action === 'revoke_all_hw') {
        $pdo->exec("UPDATE users SET can_write_prescription = 0 WHERE role = 'HEALTH_WORKER'");
        $msg = 'Prescription permission revoked from all Health Workers.';
    }
}

// ── Fetch counts per role/status ──────────────────────────────────────────────
$roleCounts = [];
foreach (['ADMIN','DOCTOR','HEALTH_WORKER'] as $r) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
    $s->execute([$r]);
    $roleCounts[$r] = (int) $s->fetchColumn();
}

// Health workers with prescription data
$healthWorkers = $pdo->query(
    "SELECT u.id, u.name, u.email, u.status, u.can_write_prescription,
            (SELECT COUNT(*) FROM prescriptions p WHERE p.health_worker_id = u.id) AS rx_count
     FROM users u WHERE u.role = 'HEALTH_WORKER' ORDER BY u.name"
)->fetchAll();

// Doctors
$doctors = $pdo->query(
    "SELECT u.id, u.name, u.email, u.status,
            (SELECT COUNT(*) FROM doctor_assignments da WHERE da.doctor_id = u.id) AS assigned_hw_count
     FROM users u WHERE u.role = 'DOCTOR' ORDER BY u.name"
)->fetchAll();

// Role capability definitions (static reference data)
$capabilities = [
    'HEALTH_WORKER' => [
        'View own prescriptions',
        'Create prescriptions (if `can_write_prescription` = 1)',
        'Submit prescriptions for review',
        'View assigned doctor profile',
        'Request video calls',
    ],
    'DOCTOR' => [
        'View all prescriptions from assigned health workers',
        'Review and annotate prescriptions',
        'Mark prescriptions as reviewed',
        'View health worker profiles',
        'Accept/decline video call requests',
        'Cannot write prescriptions directly',
    ],
    'ADMIN' => [
        'Full system access',
        'Approve/suspend users',
        'Manage medicines',
        'Manage assignments',
        'View all prescriptions',
        'Configure system settings',
        'Manage roles & permissions',
    ],
];

$pageTitle = 'Roles & Permissions';
?>
<?php require __DIR__ . '/includes/header.php'; ?>

<?php if ($msg):  ?><div class="alert alert-success">✅ <?= e($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

<!-- Role Overview Cards -->
<div class="stats-grid" style="margin-bottom:24px;">
    <?php
    $roleDisplay = [
        'ADMIN'          => ['label' => 'Administrators', 'icon' => '🛡️', 'class' => 'stat-blue'],
        'DOCTOR'         => ['label' => 'Doctors',        'icon' => '👨‍⚕️', 'class' => 'stat-purple'],
        'HEALTH_WORKER'  => ['label' => 'Health Workers', 'icon' => '🧑‍⚕️', 'class' => 'stat-green'],
    ];
    foreach ($roleDisplay as $role => $info): ?>
    <div class="stat-card <?= $info['class'] ?>">
        <div class="stat-icon"><?= $info['icon'] ?></div>
        <div class="stat-num"><?= $roleCounts[$role] ?></div>
        <div class="stat-label"><?= $info['label'] ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Role Capabilities -->
<div class="card">
    <div class="card-title">Role Capabilities Reference</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px;">
        <?php foreach ($capabilities as $role => $caps): ?>
        <div style="border:1px solid #e0e0e0;border-radius:8px;padding:16px;">
            <div style="font-size:0.85rem;font-weight:700;color:#1a2340;margin-bottom:10px;">
                <span class="badge badge-<?= $role === 'HEALTH_WORKER' ? 'hw' : strtolower($role) ?>"><?= e($role) ?></span>
            </div>
            <ul style="margin:0;padding-left:18px;font-size:0.82rem;color:#455a64;line-height:1.7;">
                <?php foreach ($caps as $cap): ?>
                <li><?= e($cap) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Health Worker Prescription Permissions -->
<div class="card">
    <div class="card-header">
        <h2>Health Worker — Prescription Permissions (<?= count($healthWorkers) ?>)</h2>
        <div class="flex-row">
            <form method="POST" onsubmit="return confirm('Grant prescription permission to ALL health workers?')">
                <input type="hidden" name="action" value="grant_all_hw">
                <button class="btn btn-sm btn-success">✅ Grant All</button>
            </form>
            <form method="POST" onsubmit="return confirm('Revoke prescription permission from ALL health workers?')">
                <input type="hidden" name="action" value="revoke_all_hw">
                <button class="btn btn-sm btn-danger">❌ Revoke All</button>
            </form>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th><th>Email</th><th>Status</th>
                    <th>Can Write Rx</th><th>Prescriptions Written</th><th>Toggle</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($healthWorkers): ?>
                <?php foreach ($healthWorkers as $hw): ?>
                <tr>
                    <td><?= e($hw['name']) ?></td>
                    <td><?= e($hw['email'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= strtolower($hw['status']) ?>"><?= e($hw['status']) ?></span></td>
                    <td>
                        <?php if ($hw['can_write_prescription']): ?>
                            <span style="color:#2e7d32;font-weight:600;">✅ Granted</span>
                        <?php else: ?>
                            <span style="color:#c62828;font-weight:600;">❌ Revoked</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $hw['rx_count'] ?></td>
                    <td>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="toggle_prescription">
                            <input type="hidden" name="user_id" value="<?= e($hw['id']) ?>">
                            <button class="btn btn-xs <?= $hw['can_write_prescription'] ? 'btn-warning' : 'btn-success' ?>">
                                <?= $hw['can_write_prescription'] ? 'Revoke' : 'Grant' ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" class="table-empty">No health workers found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Doctors Overview -->
<div class="card">
    <div class="card-title">Doctors Overview (<?= count($doctors) ?>)</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Name</th><th>Email</th><th>Status</th><th>Assigned Health Workers</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if ($doctors): ?>
                <?php foreach ($doctors as $doc): ?>
                <tr>
                    <td><?= e($doc['name']) ?></td>
                    <td><?= e($doc['email'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= strtolower($doc['status']) ?>"><?= e($doc['status']) ?></span></td>
                    <td><?= (int) $doc['assigned_hw_count'] ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/users.php?view=<?= e($doc['id']) ?>" class="btn btn-xs btn-secondary">View</a>
                        <a href="<?= BASE_URL ?>/admin/assignments.php?filter_doctor=<?= e($doc['id']) ?>" class="btn btn-xs btn-info">Assignments</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5" class="table-empty">No doctors found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
