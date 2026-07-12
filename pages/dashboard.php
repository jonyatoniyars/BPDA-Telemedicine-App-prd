<?php
// pages/dashboard.php — role-based stat cards and quick actions
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

$user      = requireActiveUser();
$pageTitle = 'Dashboard';
$role      = $user['role'];

// Fetch stats inline (avoids extra HTTP round-trip)
$stats = [];
try {
    if ($role === 'ADMIN') {
        $stats['total_users']         = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $stats['pending_users']       = $pdo->query("SELECT COUNT(*) FROM users WHERE status='PENDING'")->fetchColumn();
        $stats['total_prescriptions'] = $pdo->query("SELECT COUNT(*) FROM prescriptions")->fetchColumn();
        $stats['total_medicines']     = $pdo->query("SELECT COUNT(*) FROM medicines WHERE is_active=1")->fetchColumn();
        $stats['total_assignments']   = $pdo->query("SELECT COUNT(*) FROM doctor_assignments")->fetchColumn();
        $stats['pending_calls']       = $pdo->query("SELECT COUNT(*) FROM video_call_requests WHERE status='PENDING'")->fetchColumn();
    } elseif ($role === 'DOCTOR') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM prescriptions p JOIN doctor_assignments da ON da.health_worker_id = p.health_worker_id WHERE da.doctor_id = ?");
        $stmt->execute([$user['id']]);
        $stats['assigned_prescriptions'] = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM prescriptions p JOIN doctor_assignments da ON da.health_worker_id = p.health_worker_id WHERE da.doctor_id = ? AND p.status='SUBMITTED'");
        $stmt->execute([$user['id']]);
        $stats['pending_review'] = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM doctor_assignments WHERE doctor_id = ?");
        $stmt->execute([$user['id']]);
        $stats['assigned_workers'] = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM video_call_requests WHERE receiver_id = ? AND status='PENDING'");
        $stmt->execute([$user['id']]);
        $stats['pending_calls'] = $stmt->fetchColumn();
    } else {
        // HEALTH_WORKER
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM prescriptions WHERE health_worker_id = ?");
        $stmt->execute([$user['id']]);
        $stats['my_prescriptions'] = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM prescriptions WHERE health_worker_id = ? AND status='DRAFT'");
        $stmt->execute([$user['id']]);
        $stats['drafts'] = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM prescriptions WHERE health_worker_id = ? AND status='REVIEWED'");
        $stmt->execute([$user['id']]);
        $stats['reviewed'] = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM video_call_requests WHERE requester_id = ? AND status='PENDING'");
        $stmt->execute([$user['id']]);
        $stats['pending_calls'] = $stmt->fetchColumn();
    }
} catch (PDOException $e) {
    // Non-fatal: show dashboard with empty stats
    $stats = [];
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="app-layout">
    <?php require __DIR__ . '/../includes/sidebar.php' ?>

    <div class="app-main">
        <header class="page-header">
            <div class="page-header__left">
                <button class="btn btn--ghost btn--icon sidebar__toggle" id="sidebarToggle" aria-label="Toggle menu">☰</button>
                <div>
                    <h1 class="page-header__title">Dashboard</h1>
                    <p class="page-header__subtitle">Welcome back, <?= e($user['name']) ?></p>
                </div>
            </div>
            <div class="page-header__right">
                <span class="badge badge--<?= strtolower(str_replace('_', '-', $role)) ?>"><?= e(str_replace('_', ' ', $role)) ?></span>
            </div>
        </header>

        <main class="page-content">

            <!-- Stat Cards -->
            <section class="stats-grid">
                <?php if ($role === 'ADMIN'): ?>
                    <?php
                    $cards = [
                        ['label' => 'Total Users',          'value' => $stats['total_users'] ?? 0,         'icon' => '👥', 'color' => 'blue'],
                        ['label' => 'Pending Approvals',    'value' => $stats['pending_users'] ?? 0,       'icon' => '⏳', 'color' => 'yellow'],
                        ['label' => 'Total Prescriptions',  'value' => $stats['total_prescriptions'] ?? 0, 'icon' => '📋', 'color' => 'green'],
                        ['label' => 'Active Medicines',     'value' => $stats['total_medicines'] ?? 0,     'icon' => '💊', 'color' => 'purple'],
                        ['label' => 'Assignments',          'value' => $stats['total_assignments'] ?? 0,   'icon' => '🔗', 'color' => 'indigo'],
                        ['label' => 'Pending Video Calls',  'value' => $stats['pending_calls'] ?? 0,       'icon' => '📹', 'color' => 'red'],
                    ];
                    ?>
                <?php elseif ($role === 'DOCTOR'): ?>
                    <?php
                    $cards = [
                        ['label' => 'Total Prescriptions',  'value' => $stats['assigned_prescriptions'] ?? 0, 'icon' => '📋', 'color' => 'blue'],
                        ['label' => 'Pending Reviews',      'value' => $stats['pending_review'] ?? 0,         'icon' => '🔍', 'color' => 'yellow'],
                        ['label' => 'Assigned Workers',     'value' => $stats['assigned_workers'] ?? 0,       'icon' => '👤', 'color' => 'green'],
                        ['label' => 'Pending Video Calls',  'value' => $stats['pending_calls'] ?? 0,          'icon' => '📹', 'color' => 'red'],
                    ];
                    ?>
                <?php else: ?>
                    <?php
                    $cards = [
                        ['label' => 'My Prescriptions', 'value' => $stats['my_prescriptions'] ?? 0, 'icon' => '📋', 'color' => 'blue'],
                        ['label' => 'Drafts',           'value' => $stats['drafts'] ?? 0,           'icon' => '✏️', 'color' => 'yellow'],
                        ['label' => 'Reviewed',         'value' => $stats['reviewed'] ?? 0,         'icon' => '✅', 'color' => 'green'],
                        ['label' => 'Pending Calls',    'value' => $stats['pending_calls'] ?? 0,    'icon' => '📹', 'color' => 'red'],
                    ];
                    ?>
                <?php endif ?>

                <?php foreach ($cards as $card): ?>
                    <div class="stat-card stat-card--<?= e($card['color']) ?>">
                        <div class="stat-card__icon" aria-hidden="true"><?= $card['icon'] ?></div>
                        <div class="stat-card__body">
                            <span class="stat-card__value"><?= (int)$card['value'] ?></span>
                            <span class="stat-card__label"><?= e($card['label']) ?></span>
                        </div>
                    </div>
                <?php endforeach ?>
            </section>

            <!-- Quick Actions -->
            <section class="quick-actions">
                <h2 class="section-title">Quick Actions</h2>
                <div class="quick-actions__grid">
                    <?php if ($role === 'HEALTH_WORKER' && $user['can_write_prescription']): ?>
                        <a href="<?= BASE_URL ?>/pages/prescriptions-new.php" class="quick-action-card">
                            <span class="quick-action-card__icon">➕</span>
                            <span>New Prescription</span>
                        </a>
                    <?php endif ?>
                    <a href="<?= BASE_URL ?>/pages/prescriptions.php" class="quick-action-card">
                        <span class="quick-action-card__icon">📋</span>
                        <span>View Prescriptions</span>
                    </a>
                    <a href="<?= BASE_URL ?>/pages/video-calls.php" class="quick-action-card">
                        <span class="quick-action-card__icon">📹</span>
                        <span>Video Calls</span>
                    </a>
                    <?php if ($role === 'ADMIN'): ?>
                        <a href="<?= BASE_URL ?>/pages/users.php" class="quick-action-card">
                            <span class="quick-action-card__icon">👥</span>
                            <span>Manage Users</span>
                        </a>
                        <a href="<?= BASE_URL ?>/pages/medicines.php" class="quick-action-card">
                            <span class="quick-action-card__icon">💊</span>
                            <span>Manage Medicines</span>
                        </a>
                        <a href="<?= BASE_URL ?>/pages/assignments.php" class="quick-action-card">
                            <span class="quick-action-card__icon">🔗</span>
                            <span>Assignments</span>
                        </a>
                    <?php endif ?>
                </div>
            </section>

        </main>
    </div><!-- /.app-main -->
</div><!-- /.app-layout -->

<?php require_once __DIR__ . '/../includes/footer.php' ?>
