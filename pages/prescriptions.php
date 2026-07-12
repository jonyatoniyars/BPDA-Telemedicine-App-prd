<?php
// pages/prescriptions.php — list prescriptions with search, status filter, pagination
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

$user      = requireActiveUser();
$pageTitle = 'Prescriptions';
$role      = $user['role'];

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 15;
$offset = ($page - 1) * $limit;
$search = sanitize($_GET['search'] ?? '');
$status = sanitize($_GET['status'] ?? '');
$validStatuses = ['', 'DRAFT', 'SUBMITTED', 'REVIEWED'];
if (!in_array($status, $validStatuses, true)) $status = '';

$where  = ['1=1'];
$params = [];

if ($role === 'HEALTH_WORKER') {
    $where[] = 'p.health_worker_id = ?';
    $params[] = $user['id'];
} elseif ($role === 'DOCTOR') {
    $where[] = 'da.doctor_id = ?';
    $params[] = $user['id'];
}

if ($search) {
    $where[] = '(p.patient_name LIKE ? OR u.name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status) {
    $where[] = 'p.status = ?';
    $params[] = $status;
}

$whereStr = implode(' AND ', $where);

$join = ($role === 'DOCTOR')
    ? "JOIN doctor_assignments da ON da.health_worker_id = p.health_worker_id"
    : "";

$countSql = "SELECT COUNT(DISTINCT p.id) FROM prescriptions p
             JOIN users u ON u.id = p.health_worker_id
             $join
             WHERE $whereStr";

$listSql = "SELECT p.id, p.patient_name, p.patient_age, p.patient_gender, p.status,
                   p.created_at, u.name AS worker_name
            FROM prescriptions p
            JOIN users u ON u.id = p.health_worker_id
            $join
            WHERE $whereStr
            ORDER BY p.created_at DESC
            LIMIT $limit OFFSET $offset";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$prescriptions = $listStmt->fetchAll();

$pagination = paginate($page, $limit, $total);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="app-layout">
    <?php require __DIR__ . '/../includes/sidebar.php' ?>

    <div class="app-main">
        <header class="page-header">
            <div class="page-header__left">
                <button class="btn btn--ghost btn--icon sidebar__toggle" id="sidebarToggle" aria-label="Toggle menu">☰</button>
                <h1 class="page-header__title">Prescriptions</h1>
            </div>
            <?php if ($role === 'HEALTH_WORKER' && $user['can_write_prescription']): ?>
            <div class="page-header__right">
                <a href="<?= BASE_URL ?>/pages/prescriptions-new.php" class="btn btn--primary">
                    ➕ New Prescription
                </a>
            </div>
            <?php endif ?>
        </header>

        <main class="page-content">
            <!-- Filters -->
            <form method="get" class="filter-bar" role="search">
                <input
                    type="search"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search patient or worker…"
                    class="form-control filter-bar__search"
                    aria-label="Search prescriptions"
                >
                <select name="status" class="form-control form-select filter-bar__select" aria-label="Filter by status">
                    <option value="">All Statuses</option>
                    <?php foreach (['DRAFT','SUBMITTED','REVIEWED'] as $s): ?>
                        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach ?>
                </select>
                <button type="submit" class="btn btn--secondary">Filter</button>
                <?php if ($search || $status): ?>
                    <a href="<?= BASE_URL ?>/pages/prescriptions.php" class="btn btn--ghost">Clear</a>
                <?php endif ?>
            </form>

            <!-- Table -->
            <div class="table-card">
                <?php if (empty($prescriptions)): ?>
                    <div class="empty-state">
                        <span class="empty-state__icon">📋</span>
                        <p>No prescriptions found.</p>
                        <?php if ($role === 'HEALTH_WORKER' && $user['can_write_prescription']): ?>
                            <a href="<?= BASE_URL ?>/pages/prescriptions-new.php" class="btn btn--primary">Create your first prescription</a>
                        <?php endif ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Age / Gender</th>
                                    <?php if ($role !== 'HEALTH_WORKER'): ?><th>Health Worker</th><?php endif ?>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($prescriptions as $rx): ?>
                                <tr>
                                    <td><?= e($rx['patient_name']) ?></td>
                                    <td><?= e($rx['patient_age']) ?> / <?= e($rx['patient_gender']) ?></td>
                                    <?php if ($role !== 'HEALTH_WORKER'): ?>
                                        <td><?= e($rx['worker_name']) ?></td>
                                    <?php endif ?>
                                    <td>
                                        <span class="badge badge--status-<?= strtolower($rx['status']) ?>">
                                            <?= e($rx['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= e(date('d M Y', strtotime($rx['created_at']))) ?></td>
                                    <td>
                                        <a href="<?= BASE_URL ?>/pages/prescriptions-view.php?id=<?= urlencode($rx['id']) ?>" class="btn btn--sm btn--secondary">View</a>
                                    </td>
                                </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($pagination['totalPages'] > 1): ?>
                    <nav class="pagination" aria-label="Pagination">
                        <span class="pagination__info">
                            <?= (($page - 1) * $limit + 1) ?>–<?= min($page * $limit, $total) ?> of <?= $total ?>
                        </span>
                        <div class="pagination__controls">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>" class="btn btn--ghost btn--sm">← Prev</a>
                            <?php endif ?>
                            <span class="pagination__current">Page <?= $page ?> of <?= $pagination['totalPages'] ?></span>
                            <?php if ($page < $pagination['totalPages']): ?>
                                <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>" class="btn btn--ghost btn--sm">Next →</a>
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
