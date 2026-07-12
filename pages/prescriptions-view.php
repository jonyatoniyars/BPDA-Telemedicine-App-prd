<?php
// pages/prescriptions-view.php — view a single prescription; doctors can review
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

$user      = requireActiveUser();
$pageTitle = 'View Prescription';
$role      = $user['role'];
$id        = sanitize($_GET['id'] ?? '');
$created   = isset($_GET['created']);
$error     = null;
$success   = null;

if (!$id) {
    redirect(BASE_URL . '/pages/prescriptions.php');
}

// Load prescription with worker info
$stmt = $pdo->prepare(
    "SELECT p.*, u.name AS worker_name, u.email AS worker_email,
            r.name AS reviewer_name
     FROM prescriptions p
     JOIN users u ON u.id = p.health_worker_id
     LEFT JOIN users r ON r.id = p.reviewed_by_id
     WHERE p.id = ?"
);
$stmt->execute([$id]);
$rx = $stmt->fetch();

if (!$rx) {
    redirect(BASE_URL . '/pages/prescriptions.php');
}

// Access control: HW sees own, DOCTOR sees their assigned, ADMIN sees all
if ($role === 'HEALTH_WORKER' && $rx['health_worker_id'] !== $user['id']) {
    redirect(BASE_URL . '/pages/prescriptions.php');
}
if ($role === 'DOCTOR') {
    $check = $pdo->prepare("SELECT 1 FROM doctor_assignments WHERE doctor_id = ? AND health_worker_id = ?");
    $check->execute([$user['id'], $rx['health_worker_id']]);
    if (!$check->fetch()) {
        redirect(BASE_URL . '/pages/prescriptions.php');
    }
}

// Load prescription items
$itemStmt = $pdo->prepare(
    "SELECT pi.*, m.name AS medicine_name, m.generic_name, m.form
     FROM prescription_items pi
     JOIN medicines m ON m.id = pi.medicine_id
     WHERE pi.prescription_id = ?"
);
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

// Handle review POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'DOCTOR' && $rx['status'] === 'SUBMITTED') {
    $reviewNotes = trim($_POST['review_notes'] ?? '');
    $upd = $pdo->prepare(
        "UPDATE prescriptions SET status='REVIEWED', reviewed_by_id=?, reviewed_at=NOW(), review_notes=? WHERE id=?"
    );
    $upd->execute([$user['id'], $reviewNotes ?: null, $id]);
    redirect(BASE_URL . '/pages/prescriptions-view.php?id=' . urlencode($id) . '&reviewed=1');
}

$reviewed = isset($_GET['reviewed']);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="app-layout">
    <?php require __DIR__ . '/../includes/sidebar.php' ?>

    <div class="app-main">
        <header class="page-header">
            <div class="page-header__left">
                <button class="btn btn--ghost btn--icon sidebar__toggle" id="sidebarToggle" aria-label="Toggle menu">☰</button>
                <div>
                    <h1 class="page-header__title">Prescription Details</h1>
                    <p class="page-header__subtitle">#<?= e(strtoupper(substr($rx['id'], -8))) ?></p>
                </div>
            </div>
            <div class="page-header__right">
                <a href="<?= BASE_URL ?>/pages/prescriptions.php" class="btn btn--ghost">← Back</a>
                <span class="badge badge--status-<?= strtolower($rx['status']) ?>"><?= e($rx['status']) ?></span>
            </div>
        </header>

        <main class="page-content">
            <?php if ($created): ?>
                <div class="alert alert--success">Prescription created successfully.</div>
            <?php endif ?>
            <?php if ($reviewed): ?>
                <div class="alert alert--success">Prescription reviewed successfully.</div>
            <?php endif ?>
            <?php if ($error): ?>
                <div class="alert alert--error"><?= e($error) ?></div>
            <?php endif ?>

            <!-- Patient Details -->
            <div class="detail-card">
                <h2 class="detail-card__title">Patient Information</h2>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-item__label">Name</span>
                        <span class="detail-item__value"><?= e($rx['patient_name']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Age</span>
                        <span class="detail-item__value"><?= e($rx['patient_age']) ?> years</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Gender</span>
                        <span class="detail-item__value"><?= e($rx['patient_gender']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Health Worker</span>
                        <span class="detail-item__value"><?= e($rx['worker_name']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Created</span>
                        <span class="detail-item__value"><?= e(date('d M Y, H:i', strtotime($rx['created_at']))) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Status</span>
                        <span class="detail-item__value">
                            <span class="badge badge--status-<?= strtolower($rx['status']) ?>"><?= e($rx['status']) ?></span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Clinical Notes -->
            <div class="detail-card">
                <h2 class="detail-card__title">Clinical Notes</h2>
                <div class="detail-section">
                    <h3 class="detail-section__label">Chief Complaints</h3>
                    <p><?= e($rx['chief_complaints']) ?></p>
                </div>
                <?php if ($rx['on_examination']): ?>
                <div class="detail-section">
                    <h3 class="detail-section__label">On Examination</h3>
                    <p><?= e($rx['on_examination']) ?></p>
                </div>
                <?php endif ?>
                <?php if ($rx['advice']): ?>
                <div class="detail-section">
                    <h3 class="detail-section__label">Advice</h3>
                    <p><?= e($rx['advice']) ?></p>
                </div>
                <?php endif ?>
            </div>

            <!-- Medicines -->
            <div class="detail-card">
                <h2 class="detail-card__title">Medicines Prescribed</h2>
                <?php if (empty($items)): ?>
                    <p class="text-muted">No medicines added.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Medicine</th>
                                    <th>Form</th>
                                    <th>Dose</th>
                                    <th>Frequency</th>
                                    <th>Duration</th>
                                    <th>Instructions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <?= e($item['medicine_name']) ?>
                                        <?php if ($item['generic_name']): ?>
                                            <small class="text-muted">(<?= e($item['generic_name']) ?>)</small>
                                        <?php endif ?>
                                    </td>
                                    <td><span class="badge badge--form"><?= e($item['form']) ?></span></td>
                                    <td><?= e($item['dose']) ?></td>
                                    <td><?= e($item['frequency']) ?></td>
                                    <td><?= e($item['duration']) ?></td>
                                    <td><?= e($item['instructions'] ?? '—') ?></td>
                                </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif ?>
            </div>

            <!-- Review section (already reviewed) -->
            <?php if ($rx['status'] === 'REVIEWED'): ?>
            <div class="detail-card detail-card--reviewed">
                <h2 class="detail-card__title">Doctor's Review</h2>
                <div class="detail-section">
                    <h3 class="detail-section__label">Reviewed by</h3>
                    <p><?= e($rx['reviewer_name'] ?? '—') ?> on <?= e($rx['reviewed_at'] ? date('d M Y, H:i', strtotime($rx['reviewed_at'])) : '—') ?></p>
                </div>
                <?php if ($rx['review_notes']): ?>
                <div class="detail-section">
                    <h3 class="detail-section__label">Review Notes</h3>
                    <p><?= e($rx['review_notes']) ?></p>
                </div>
                <?php endif ?>
            </div>
            <?php endif ?>

            <!-- Review form for doctor when status = SUBMITTED -->
            <?php if ($role === 'DOCTOR' && $rx['status'] === 'SUBMITTED'): ?>
            <div class="detail-card detail-card--action">
                <h2 class="detail-card__title">Submit Review</h2>
                <form method="post">
                    <div class="form-group">
                        <label for="review_notes" class="form-label">Review Notes <span class="text-muted">(optional)</span></label>
                        <textarea id="review_notes" name="review_notes" class="form-control" rows="4" placeholder="Add your clinical review notes…"></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn--primary">✅ Mark as Reviewed</button>
                    </div>
                </form>
            </div>
            <?php endif ?>

        </main>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php' ?>
