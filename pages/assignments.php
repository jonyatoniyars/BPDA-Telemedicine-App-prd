<?php
// pages/assignments.php — list doctor–health worker assignments; admin can create/delete
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

$user      = requireAdmin();
$pageTitle = 'Assignments';

// Fetch existing assignments with names
$stmt = $pdo->query(
    "SELECT da.id, da.assigned_at,
            d.id AS doctor_id, d.name AS doctor_name,  d.email AS doctor_email,
            h.id AS worker_id, h.name AS worker_name,  h.email AS worker_email
     FROM doctor_assignments da
     JOIN users d ON d.id = da.doctor_id
     JOIN users h ON h.id = da.health_worker_id
     ORDER BY da.assigned_at DESC"
);
$assignments = $stmt->fetchAll();

// Fetch doctors and unassigned health workers for the create form
$doctors = $pdo->query("SELECT id, name FROM users WHERE role='DOCTOR' AND status='ACTIVE' ORDER BY name")->fetchAll();
$unassignedWorkers = $pdo->query(
    "SELECT u.id, u.name FROM users u
     WHERE u.role='HEALTH_WORKER' AND u.status='ACTIVE'
       AND NOT EXISTS (SELECT 1 FROM doctor_assignments da WHERE da.health_worker_id = u.id)
     ORDER BY u.name"
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="app-layout">
    <?php require __DIR__ . '/../includes/sidebar.php' ?>

    <div class="app-main">
        <header class="page-header">
            <div class="page-header__left">
                <button class="btn btn--ghost btn--icon sidebar__toggle" id="sidebarToggle" aria-label="Toggle menu">☰</button>
                <h1 class="page-header__title">Assignments</h1>
            </div>
        </header>

        <main class="page-content">
            <div id="actionAlert" class="alert" hidden></div>

            <!-- Create Assignment Form -->
            <div class="form-card">
                <h2 class="form-card__title">Assign Health Worker to Doctor</h2>
                <?php if (empty($doctors)): ?>
                    <p class="alert alert--warning">No active doctors found. Activate a doctor first.</p>
                <?php elseif (empty($unassignedWorkers)): ?>
                    <p class="alert alert--info">All active health workers are already assigned to a doctor.</p>
                <?php else: ?>
                    <form id="assignForm" class="form-inline">
                        <div class="form-group">
                            <label for="doctor_id" class="form-label">Doctor <span class="required">*</span></label>
                            <select id="doctor_id" name="doctor_id" class="form-control form-select" required>
                                <option value="">Select doctor…</option>
                                <?php foreach ($doctors as $doc): ?>
                                    <option value="<?= e($doc['id']) ?>"><?= e($doc['name']) ?></option>
                                <?php endforeach ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="health_worker_id" class="form-label">Health Worker <span class="required">*</span></label>
                            <select id="health_worker_id" name="health_worker_id" class="form-control form-select" required>
                                <option value="">Select health worker…</option>
                                <?php foreach ($unassignedWorkers as $hw): ?>
                                    <option value="<?= e($hw['id']) ?>"><?= e($hw['name']) ?></option>
                                <?php endforeach ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn--primary">Create Assignment</button>
                    </form>
                <?php endif ?>
            </div>

            <!-- Assignments Table -->
            <div class="table-card">
                <h2 class="table-card__title">Current Assignments</h2>
                <?php if (empty($assignments)): ?>
                    <div class="empty-state">
                        <span class="empty-state__icon">🔗</span>
                        <p>No assignments yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table" id="assignmentsTable">
                            <thead>
                                <tr>
                                    <th>Doctor</th>
                                    <th>Health Worker</th>
                                    <th>Assigned On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $asgn): ?>
                                <tr data-assignment-id="<?= e($asgn['id']) ?>">
                                    <td>
                                        <?= e($asgn['doctor_name']) ?>
                                        <small class="text-muted d-block"><?= e($asgn['doctor_email']) ?></small>
                                    </td>
                                    <td>
                                        <?= e($asgn['worker_name']) ?>
                                        <small class="text-muted d-block"><?= e($asgn['worker_email']) ?></small>
                                    </td>
                                    <td><?= e(date('d M Y', strtotime($asgn['assigned_at']))) ?></td>
                                    <td>
                                    <button class="btn btn--sm btn--danger js-delete-btn"
                                        data-id="<?= e($asgn['id']) ?>"
                                        data-hw-id="<?= e($asgn['worker_id']) ?>"
                                    >
                                        🗑 Remove
                                    </button>
                                    </td>
                                </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
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

    const form = document.getElementById('assignForm');
    if (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const doctorId       = document.getElementById('doctor_id').value;
            const healthWorkerId = document.getElementById('health_worker_id').value;
            if (!doctorId || !healthWorkerId) { showAlert('Please select both doctor and health worker.', 'error'); return; }
            try {
                const res  = await fetch(`${BASE_URL}/api/assignments.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ doctorId, healthWorkerId }),
                });
                const data = await res.json();
                if (data.success) { location.reload(); }
                else { showAlert(data.error?.message ?? 'Failed to create assignment.', 'error'); }
            } catch { showAlert('Network error.', 'error'); }
        });
    }

    document.querySelectorAll('.js-delete-btn').forEach(btn => {
        btn.addEventListener('click', async function () {
            if (!confirm('Remove this assignment?')) return;
            const hwId = this.dataset.hwId;
            try {
                const res  = await fetch(`${BASE_URL}/api/assignments.php?healthWorkerId=${encodeURIComponent(hwId)}`, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (data.success) {
                    this.closest('tr').remove();
                    showAlert('Assignment removed.');
                } else { showAlert(data.error?.message ?? 'Failed.', 'error'); }
            } catch { showAlert('Network error.', 'error'); }
        });
    });
})();
</script>
