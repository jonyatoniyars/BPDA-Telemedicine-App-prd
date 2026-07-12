<?php
// pages/medicines.php — list medicines; admin can add/edit via modal
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

$user      = requireActiveUser();
$pageTitle = 'Medicines';
$role      = $user['role'];

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;
$search = sanitize($_GET['search'] ?? '');
$activeFilter = $_GET['active'] ?? '';

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(name LIKE ? OR generic_name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($activeFilter === '1') {
    $where[] = 'is_active = 1';
} elseif ($activeFilter === '0') {
    $where[] = 'is_active = 0';
}

$whereStr = implode(' AND ', $where);
$countSql = "SELECT COUNT(*) FROM medicines WHERE $whereStr";
$listSql  = "SELECT * FROM medicines WHERE $whereStr ORDER BY name ASC LIMIT $limit OFFSET $offset";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$medicines = $listStmt->fetchAll();
$pagination = paginate($page, $limit, $total);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="app-layout">
    <?php require __DIR__ . '/../includes/sidebar.php' ?>

    <div class="app-main">
        <header class="page-header">
            <div class="page-header__left">
                <button class="btn btn--ghost btn--icon sidebar__toggle" id="sidebarToggle" aria-label="Toggle menu">☰</button>
                <h1 class="page-header__title">Medicines</h1>
            </div>
            <?php if ($role === 'ADMIN'): ?>
            <div class="page-header__right">
                <button class="btn btn--primary" id="openAddModal">➕ Add Medicine</button>
            </div>
            <?php endif ?>
        </header>

        <main class="page-content">
            <!-- Filters -->
            <form method="get" class="filter-bar" role="search">
                <input type="search" name="search" value="<?= e($search) ?>" placeholder="Search medicines…" class="form-control filter-bar__search" aria-label="Search medicines">
                <select name="active" class="form-control form-select filter-bar__select" aria-label="Filter by status">
                    <option value="">All</option>
                    <option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <button type="submit" class="btn btn--secondary">Filter</button>
                <?php if ($search || $activeFilter !== ''): ?>
                    <a href="<?= BASE_URL ?>/pages/medicines.php" class="btn btn--ghost">Clear</a>
                <?php endif ?>
            </form>

            <!-- Table -->
            <div class="table-card">
                <?php if (empty($medicines)): ?>
                    <div class="empty-state">
                        <span class="empty-state__icon">💊</span>
                        <p>No medicines found.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Generic Name</th>
                                    <th>Form</th>
                                    <th>Status</th>
                                    <?php if ($role === 'ADMIN'): ?><th>Actions</th><?php endif ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($medicines as $med): ?>
                                <tr>
                                    <td><?= e($med['name']) ?></td>
                                    <td><?= e($med['generic_name'] ?? '—') ?></td>
                                    <td><span class="badge badge--form"><?= e($med['form']) ?></span></td>
                                    <td>
                                        <span class="badge badge--<?= $med['is_active'] ? 'active' : 'inactive' ?>">
                                            <?= $med['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <?php if ($role === 'ADMIN'): ?>
                                    <td>
                                        <button
                                            class="btn btn--sm btn--secondary edit-medicine-btn"
                                            data-id="<?= e($med['id']) ?>"
                                            data-name="<?= e($med['name']) ?>"
                                            data-generic="<?= e($med['generic_name'] ?? '') ?>"
                                            data-form="<?= e($med['form']) ?>"
                                            data-active="<?= (int)$med['is_active'] ?>"
                                        >Edit</button>
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
                                <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&active=<?= urlencode($activeFilter) ?>" class="btn btn--ghost btn--sm">← Prev</a>
                            <?php endif ?>
                            <span class="pagination__current">Page <?= $page ?> of <?= $pagination['totalPages'] ?></span>
                            <?php if ($page < $pagination['totalPages']): ?>
                                <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&active=<?= urlencode($activeFilter) ?>" class="btn btn--ghost btn--sm">Next →</a>
                            <?php endif ?>
                        </div>
                    </nav>
                    <?php endif ?>
                <?php endif ?>
            </div>
        </main>
    </div>
</div>

<?php if ($role === 'ADMIN'): ?>
<!-- Add / Edit Medicine Modal -->
<div class="modal" id="medicineModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" hidden>
    <div class="modal__backdrop" id="modalBackdrop"></div>
    <div class="modal__dialog">
        <div class="modal__header">
            <h2 class="modal__title" id="modalTitle">Add Medicine</h2>
            <button class="btn btn--ghost btn--icon modal__close" id="closeModal" aria-label="Close">✕</button>
        </div>
        <form id="medicineForm" class="modal__body">
            <input type="hidden" id="medId" name="id" value="">

            <div class="form-group">
                <label for="medName" class="form-label">Name <span class="required">*</span></label>
                <input type="text" id="medName" name="name" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="medGeneric" class="form-label">Generic Name</label>
                <input type="text" id="medGeneric" name="generic_name" class="form-control">
            </div>
            <div class="form-group">
                <label for="medForm" class="form-label">Form <span class="required">*</span></label>
                <select id="medForm" name="form" class="form-control form-select" required>
                    <?php foreach (['TABLET','CAPSULE','SYRUP','INJECTION','OINTMENT','DROPS','INHALER','SUPPOSITORY','PATCH','OTHER'] as $f): ?>
                        <option value="<?= $f ?>"><?= $f ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="form-group" id="activeToggleGroup">
                <label class="form-label">Status</label>
                <label class="toggle-label">
                    <input type="checkbox" id="medActive" name="is_active" value="1" checked>
                    <span class="toggle-switch"></span>
                    Active
                </label>
            </div>

            <div class="alert alert--error" id="modalError" hidden></div>

            <div class="form-actions">
                <button type="button" class="btn btn--ghost" id="cancelModal">Cancel</button>
                <button type="submit" class="btn btn--primary" id="saveModal">Save Medicine</button>
            </div>
        </form>
    </div>
</div>
<?php endif ?>

<?php require_once __DIR__ . '/../includes/footer.php' ?>

<?php if ($role === 'ADMIN'): ?>
<script>
(function () {
    const BASE_URL   = <?= json_encode(BASE_URL) ?>;
    const modal      = document.getElementById('medicineModal');
    const form       = document.getElementById('medicineForm');
    const titleEl    = document.getElementById('modalTitle');
    const errorEl    = document.getElementById('modalError');

    function openModal(data = {}) {
        document.getElementById('medId').value      = data.id ?? '';
        document.getElementById('medName').value    = data.name ?? '';
        document.getElementById('medGeneric').value = data.generic ?? '';
        document.getElementById('medForm').value    = data.form ?? 'TABLET';
        document.getElementById('medActive').checked = data.active !== undefined ? !!data.active : true;
        titleEl.textContent = data.id ? 'Edit Medicine' : 'Add Medicine';
        errorEl.hidden = true;
        modal.hidden = false;
    }
    function closeModal() { modal.hidden = true; form.reset(); }

    document.getElementById('openAddModal').addEventListener('click', () => openModal());
    document.getElementById('closeModal').addEventListener('click', closeModal);
    document.getElementById('cancelModal').addEventListener('click', closeModal);
    document.getElementById('modalBackdrop').addEventListener('click', closeModal);

    document.querySelectorAll('.edit-medicine-btn').forEach(btn => {
        btn.addEventListener('click', () => openModal({
            id:      btn.dataset.id,
            name:    btn.dataset.name,
            generic: btn.dataset.generic,
            form:    btn.dataset.form,
            active:  parseInt(btn.dataset.active),
        }));
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const id      = document.getElementById('medId').value;
        const payload = {
            name:        document.getElementById('medName').value.trim(),
            genericName: document.getElementById('medGeneric').value.trim() || null,
            form:        document.getElementById('medForm').value,
            isActive:    document.getElementById('medActive').checked,
        };
        if (!payload.name) { showError('Name is required.'); return; }

        const url    = id ? `${BASE_URL}/api/medicine-detail.php?id=${encodeURIComponent(id)}` : `${BASE_URL}/api/medicines.php`;
        const method = id ? 'PATCH' : 'POST';

        try {
            const res  = await fetch(url, { method, headers: {'Content-Type':'application/json','Accept':'application/json'}, body: JSON.stringify(payload) });
            const data = await res.json();
            if (data.success) { location.reload(); }
            else { showError(data.error?.message ?? 'Save failed.'); }
        } catch { showError('Network error. Please try again.'); }
    });

    function showError(msg) {
        errorEl.textContent = msg;
        errorEl.hidden = false;
    }
})();
</script>
<?php endif ?>
