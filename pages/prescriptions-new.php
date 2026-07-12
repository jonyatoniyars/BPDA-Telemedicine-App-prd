<?php
// pages/prescriptions-new.php — create a new prescription (health worker with can_write_prescription)
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

$user = requireRole('HEALTH_WORKER');
if (!$user['can_write_prescription']) {
    redirect(BASE_URL . '/pages/dashboard.php');
}

$pageTitle = 'New Prescription';
$error     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientName     = sanitize($_POST['patient_name'] ?? '');
    $patientAge      = (int)($_POST['patient_age'] ?? 0);
    $patientGender   = sanitize($_POST['patient_gender'] ?? '');
    $chiefComplaints = trim($_POST['chief_complaints'] ?? '');
    $onExamination   = trim($_POST['on_examination'] ?? '');
    $advice          = trim($_POST['advice'] ?? '');
    $submitAction    = $_POST['submit_action'] ?? 'draft'; // 'draft' or 'submit'
    $medicines       = $_POST['medicines'] ?? [];

    if (!$patientName || $patientAge <= 0 || !$patientGender || !$chiefComplaints) {
        $error = 'Patient name, age, gender, and chief complaints are required.';
    } else {
        $status = ($submitAction === 'submit') ? 'SUBMITTED' : 'DRAFT';
        $rxId   = cuid();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO prescriptions (id, health_worker_id, patient_name, patient_age, patient_gender,
                 chief_complaints, on_examination, advice, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$rxId, $user['id'], $patientName, $patientAge, $patientGender,
                             $chiefComplaints, $onExamination ?: null, $advice ?: null, $status]);

            foreach ($medicines as $med) {
                $medId   = sanitize($med['medicine_id'] ?? '');
                $dose    = sanitize($med['dose'] ?? '');
                $freq    = sanitize($med['frequency'] ?? '');
                $dur     = sanitize($med['duration'] ?? '');
                $instr   = sanitize($med['instructions'] ?? '');
                if (!$medId || !$dose || !$freq || !$dur) continue;

                $piStmt = $pdo->prepare(
                    "INSERT INTO prescription_items (id, prescription_id, medicine_id, dose, frequency, duration, instructions)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $piStmt->execute([cuid(), $rxId, $medId, $dose, $freq, $dur, $instr ?: null]);
            }

            $pdo->commit();
            redirect(BASE_URL . '/pages/prescriptions-view.php?id=' . urlencode($rxId) . '&created=1');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Failed to save prescription. Please try again.';
        }
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
                <div>
                    <h1 class="page-header__title">New Prescription</h1>
                    <p class="page-header__subtitle">Fill in patient details and medicines</p>
                </div>
            </div>
            <div class="page-header__right">
                <a href="<?= BASE_URL ?>/pages/prescriptions.php" class="btn btn--ghost">← Back</a>
            </div>
        </header>

        <main class="page-content">
            <?php if ($error): ?>
                <div class="alert alert--error"><?= e($error) ?></div>
            <?php endif ?>

            <form method="post" id="prescriptionForm" novalidate>
                <!-- Patient Information -->
                <div class="form-card">
                    <h2 class="form-card__title">Patient Information</h2>
                    <div class="form-grid form-grid--3">
                        <div class="form-group">
                            <label for="patient_name" class="form-label">Patient Name <span class="required">*</span></label>
                            <input type="text" id="patient_name" name="patient_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="patient_age" class="form-label">Age <span class="required">*</span></label>
                            <input type="number" id="patient_age" name="patient_age" class="form-control" min="0" max="150" required>
                        </div>
                        <div class="form-group">
                            <label for="patient_gender" class="form-label">Gender <span class="required">*</span></label>
                            <select id="patient_gender" name="patient_gender" class="form-control form-select" required>
                                <option value="">Select…</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Clinical Notes -->
                <div class="form-card">
                    <h2 class="form-card__title">Clinical Notes</h2>
                    <div class="form-group">
                        <label for="chief_complaints" class="form-label">Chief Complaints <span class="required">*</span></label>
                        <textarea id="chief_complaints" name="chief_complaints" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="on_examination" class="form-label">On Examination</label>
                        <textarea id="on_examination" name="on_examination" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="advice" class="form-label">Advice</label>
                        <textarea id="advice" name="advice" class="form-control" rows="2"></textarea>
                    </div>
                </div>

                <!-- Medicine Rows -->
                <div class="form-card">
                    <div class="form-card__header">
                        <h2 class="form-card__title">Medicines</h2>
                        <button type="button" class="btn btn--secondary btn--sm" id="addMedicineRow">➕ Add Medicine</button>
                    </div>

                    <div id="medicineList">
                        <!-- Rows injected by JS -->
                    </div>
                    <p class="form-hint" id="noMedicineHint">No medicines added yet. Click "Add Medicine" to add one.</p>
                </div>

                <!-- Actions -->
                <div class="form-actions">
                    <button type="submit" name="submit_action" value="draft" class="btn btn--secondary">Save as Draft</button>
                    <button type="submit" name="submit_action" value="submit" class="btn btn--primary">Submit for Review</button>
                </div>
            </form>
        </main>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php' ?>

<script>
(function () {
    const BASE_URL = <?= json_encode(BASE_URL) ?>;
    let medicines = [];
    let rowIndex  = 0;

    // Load medicines from API
    fetch(BASE_URL + '/api/medicines.php?active=1&limit=200')
        .then(r => r.json())
        .then(res => {
            medicines = Array.isArray(res.data) ? res.data : [];
        })
        .catch(() => { medicines = []; });

    function buildMedicineOptions() {
        let opts = '<option value="">Select medicine…</option>';
        medicines.forEach(m => {
            opts += `<option value="${m.id}">${m.name}${m.genericName ? ' (' + m.genericName + ')' : ''} — ${m.form}</option>`;
        });
        return opts;
    }

    function addRow() {
        const hint = document.getElementById('noMedicineHint');
        if (hint) hint.style.display = 'none';

        const idx  = rowIndex++;
        const div  = document.createElement('div');
        div.className = 'medicine-row';
        div.dataset.idx = idx;
        div.innerHTML = `
            <div class="form-grid form-grid--medicine">
                <div class="form-group">
                    <label class="form-label">Medicine <span class="required">*</span></label>
                    <select name="medicines[${idx}][medicine_id]" class="form-control form-select med-select" required>
                        ${buildMedicineOptions()}
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Dose <span class="required">*</span></label>
                    <input type="text" name="medicines[${idx}][dose]" class="form-control" placeholder="e.g. 500mg" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Frequency <span class="required">*</span></label>
                    <input type="text" name="medicines[${idx}][frequency]" class="form-control" placeholder="e.g. Twice daily" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Duration <span class="required">*</span></label>
                    <input type="text" name="medicines[${idx}][duration]" class="form-control" placeholder="e.g. 7 days" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Instructions</label>
                    <input type="text" name="medicines[${idx}][instructions]" class="form-control" placeholder="e.g. After meal">
                </div>
                <div class="form-group form-group--action">
                    <button type="button" class="btn btn--ghost btn--sm btn--danger remove-row" aria-label="Remove row">✕</button>
                </div>
            </div>`;
        document.getElementById('medicineList').appendChild(div);
    }

    document.getElementById('addMedicineRow').addEventListener('click', addRow);

    document.getElementById('medicineList').addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-row')) {
            e.target.closest('.medicine-row').remove();
            if (!document.querySelector('.medicine-row')) {
                const hint = document.getElementById('noMedicineHint');
                if (hint) hint.style.display = '';
            }
        }
    });

    // Reload medicine options if they weren't ready when first row was added
    document.getElementById('medicineList').addEventListener('focusin', function (e) {
        if (e.target.classList.contains('med-select') && e.target.options.length <= 1 && medicines.length > 0) {
            e.target.innerHTML = buildMedicineOptions();
        }
    });
})();
</script>
