<?php
// pages/video-calls.php — list video call requests; HW can request, Doctor can accept/decline
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

$user      = requireActiveUser();
$pageTitle = 'Video Calls';
$role      = $user['role'];

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 15;
$offset = ($page - 1) * $limit;
$statusFilter = sanitize($_GET['status'] ?? '');
$validStatuses = ['', 'PENDING', 'ACCEPTED', 'DECLINED'];
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = '';

$where  = ['1=1'];
$params = [];

if ($role === 'HEALTH_WORKER') {
    $where[]  = 'vcr.requester_id = ?';
    $params[] = $user['id'];
} elseif ($role === 'DOCTOR') {
    $where[]  = 'vcr.receiver_id = ?';
    $params[] = $user['id'];
}
if ($statusFilter) {
    $where[]  = 'vcr.status = ?';
    $params[] = $statusFilter;
}

$whereStr = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM video_call_requests vcr WHERE $whereStr");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listStmt = $pdo->prepare(
    "SELECT vcr.id, vcr.status, vcr.note, vcr.created_at,
            req.name AS requester_name, rec.name AS receiver_name
     FROM video_call_requests vcr
     JOIN users req ON req.id = vcr.requester_id
     JOIN users rec ON rec.id = vcr.receiver_id
     WHERE $whereStr
     ORDER BY vcr.created_at DESC
     LIMIT $limit OFFSET $offset"
);
$listStmt->execute($params);
$calls = $listStmt->fetchAll();
$pagination = paginate($page, $limit, $total);

// For HW request form: find assigned doctor
$assignedDoctor = null;
if ($role === 'HEALTH_WORKER') {
    $da = $pdo->prepare("SELECT doctor_id FROM doctor_assignments WHERE health_worker_id = ? LIMIT 1");
    $da->execute([$user['id']]);
    $row = $da->fetch();
    if ($row) {
        $ds = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
        $ds->execute([$row['doctor_id']]);
        $assignedDoctor = $ds->fetch();
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="app-layout">
    <?php require __DIR__ . '/../includes/sidebar.php' ?>

    <div class="app-main">
        <header class="page-header">
            <div class="page-header__left">
                <button class="btn btn--ghost btn--icon sidebar__toggle" id="sidebarToggle" aria-label="Toggle menu">☰</button>
                <h1 class="page-header__title">Video Calls</h1>
            </div>
            <?php if ($role === 'HEALTH_WORKER' && $assignedDoctor): ?>
            <div class="page-header__right">
                <button class="btn btn--primary" id="openRequestModal">📹 Request Video Call</button>
            </div>
            <?php endif ?>
        </header>

        <main class="page-content">
            <div id="actionAlert" class="alert" hidden></div>

            <?php if ($role === 'HEALTH_WORKER' && !$assignedDoctor): ?>
                <div class="alert alert--warning">
                    You are not yet assigned to a doctor. Contact your administrator to be assigned before requesting video calls.
                </div>
            <?php endif ?>

            <!-- Filters -->
            <form method="get" class="filter-bar" role="search">
                <select name="status" class="form-control form-select filter-bar__select" aria-label="Filter by status">
                    <option value="">All Statuses</option>
                    <?php foreach (['PENDING','ACCEPTED','DECLINED'] as $s): ?>
                        <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach ?>
                </select>
                <button type="submit" class="btn btn--secondary">Filter</button>
                <?php if ($statusFilter): ?>
                    <a href="<?= BASE_URL ?>/pages/video-calls.php" class="btn btn--ghost">Clear</a>
                <?php endif ?>
            </form>

            <!-- Table -->
            <div class="table-card">
                <?php if (empty($calls)): ?>
                    <div class="empty-state">
                        <span class="empty-state__icon">📹</span>
                        <p>No video call requests found.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><?= $role === 'DOCTOR' ? 'Requested by' : 'Doctor' ?></th>
                                    <th>Note</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <?php if ($role === 'DOCTOR'): ?><th>Actions</th><?php endif ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($calls as $call): ?>
                                <tr data-call-id="<?= e($call['id']) ?>">
                                    <td><?= e($role === 'DOCTOR' ? $call['requester_name'] : $call['receiver_name']) ?></td>
                                    <td><?= e($call['note'] ?? '—') ?></td>
                                    <td>
                                        <span class="badge badge--call-<?= strtolower($call['status']) ?> js-call-status-badge">
                                            <?= e($call['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= e(date('d M Y, H:i', strtotime($call['created_at']))) ?></td>
                                    <?php if ($role === 'DOCTOR'): ?>
                                    <td>
                                        <?php if ($call['status'] === 'PENDING'): ?>
                                            <button class="btn btn--sm btn--success js-accept-btn" data-id="<?= e($call['id']) ?>">✅ Accept</button>
                                            <button class="btn btn--sm btn--danger  js-decline-btn" data-id="<?= e($call['id']) ?>">❌ Decline</button>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif ?>
                                    </td>
                                    <?php endif ?>
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
                                <a href="?page=<?= $page-1 ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn--ghost btn--sm">← Prev</a>
                            <?php endif ?>
                            <span class="pagination__current">Page <?= $page ?> of <?= $pagination['totalPages'] ?></span>
                            <?php if ($page < $pagination['totalPages']): ?>
                                <a href="?page=<?= $page+1 ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn--ghost btn--sm">Next →</a>
                            <?php endif ?>
                        </div>
                    </nav>
                    <?php endif ?>
                <?php endif ?>
            </div>
        </main>
    </div>
</div>

<?php if ($role === 'HEALTH_WORKER' && $assignedDoctor): ?>
<!-- Request Modal -->
<div class="modal" id="requestModal" role="dialog" aria-modal="true" aria-labelledby="requestModalTitle" hidden>
    <div class="modal__backdrop" id="requestModalBackdrop"></div>
    <div class="modal__dialog">
        <div class="modal__header">
            <h2 class="modal__title" id="requestModalTitle">Request Video Call</h2>
            <button class="btn btn--ghost btn--icon modal__close" id="closeRequestModal" aria-label="Close">✕</button>
        </div>
        <form id="requestCallForm" class="modal__body">
            <p class="text-muted">Sending request to: <strong><?= e($assignedDoctor['name']) ?></strong></p>
            <div class="form-group">
                <label for="callNote" class="form-label">Note <span class="text-muted">(optional)</span></label>
                <textarea id="callNote" name="note" class="form-control" rows="3" placeholder="Briefly describe the reason for this call…"></textarea>
            </div>
            <div class="alert alert--error" id="callModalError" hidden></div>
            <div class="form-actions">
                <button type="button" class="btn btn--ghost" id="cancelCallRequest">Cancel</button>
                <button type="submit" class="btn btn--primary">Send Request</button>
            </div>
        </form>
    </div>
</div>
<?php endif ?>

<?php require_once __DIR__ . '/../includes/footer.php' ?>

<script>
(function () {
    const BASE_URL       = <?= json_encode(BASE_URL) ?>;
    const ROLE           = <?= json_encode($role) ?>;
    const alertEl        = document.getElementById('actionAlert');

    function showAlert(msg, type = 'success') {
        alertEl.textContent = msg;
        alertEl.className   = `alert alert--${type}`;
        alertEl.hidden      = false;
        setTimeout(() => { alertEl.hidden = true; }, 4500);
    }

    // HW: request modal
    if (ROLE === 'HEALTH_WORKER') {
        const modal      = document.getElementById('requestModal');
        const openBtn    = document.getElementById('openRequestModal');
        const closeBtn   = document.getElementById('closeRequestModal');
        const cancelBtn  = document.getElementById('cancelCallRequest');
        const backdrop   = document.getElementById('requestModalBackdrop');
        const form       = document.getElementById('requestCallForm');
        const errorEl    = document.getElementById('callModalError');

        if (openBtn) openBtn.addEventListener('click', () => { modal.hidden = false; errorEl.hidden = true; });
        if (closeBtn)   closeBtn.addEventListener('click', () => { modal.hidden = true; form.reset(); });
        if (cancelBtn) cancelBtn.addEventListener('click', () => { modal.hidden = true; form.reset(); });
        if (backdrop)  backdrop.addEventListener('click', () => { modal.hidden = true; form.reset(); });

        if (form) {
            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                const note = document.getElementById('callNote').value.trim();
                try {
                    const res  = await fetch(`${BASE_URL}/api/video-calls.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ note: note || null }),
                    });
                    const data = await res.json();
                    if (data.success) { location.reload(); }
                    else {
                        errorEl.textContent = data.error?.message ?? 'Failed to send request.';
                        errorEl.hidden = false;
                    }
                } catch {
                    errorEl.textContent = 'Network error. Please try again.';
                    errorEl.hidden = false;
                }
            });
        }
    }

    // Doctor: accept/decline
    if (ROLE === 'DOCTOR') {
        async function updateCall(id, status) {
            const res  = await fetch(`${BASE_URL}/api/video-call-detail.php?id=${encodeURIComponent(id)}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ status }),
            });
            return res.json();
        }

        document.querySelectorAll('.js-accept-btn').forEach(btn => {
            btn.addEventListener('click', async function () {
                const id = this.dataset.id;
                const data = await updateCall(id, 'ACCEPTED').catch(() => null);
                if (data?.success) {
                    const row   = this.closest('tr');
                    const badge = row.querySelector('.js-call-status-badge');
                    badge.textContent = 'ACCEPTED';
                    badge.className   = 'badge badge--call-accepted js-call-status-badge';
                    row.querySelector('td:last-child').innerHTML = '<span class="text-muted">—</span>';
                    showAlert('Video call accepted.');
                } else { showAlert(data?.error?.message ?? 'Failed.', 'error'); }
            });
        });

        document.querySelectorAll('.js-decline-btn').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm('Decline this video call request?')) return;
                const id = this.dataset.id;
                const data = await updateCall(id, 'DECLINED').catch(() => null);
                if (data?.success) {
                    const row   = this.closest('tr');
                    const badge = row.querySelector('.js-call-status-badge');
                    badge.textContent = 'DECLINED';
                    badge.className   = 'badge badge--call-declined js-call-status-badge';
                    row.querySelector('td:last-child').innerHTML = '<span class="text-muted">—</span>';
                    showAlert('Video call declined.');
                } else { showAlert(data?.error?.message ?? 'Failed.', 'error'); }
            });
        });
    }
})();
</script>
