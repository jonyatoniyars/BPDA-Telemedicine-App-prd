<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

if (!isLoggedIn()) redirect(BASE_URL . '/admin/login.php');
$adminUser = requireAdmin();

$msg   = '';
$error = '';

$medicinesForms = ['TABLET','CAPSULE','SYRUP','INJECTION','OINTMENT','DROPS','INHALER','SUPPOSITORY','PATCH','OTHER'];

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_medicine') {
        $name        = trim($_POST['name']         ?? '');
        $genericName = trim($_POST['generic_name'] ?? '') ?: null;
        $form        = $_POST['form']              ?? 'TABLET';
        $isActive    = isset($_POST['is_active'])  ? 1 : 0;

        if (!$name) {
            $error = 'Medicine name is required.';
        } elseif (!in_array($form, $medicinesForms, true)) {
            $error = 'Invalid medicine form.';
        } else {
            $exists = $pdo->prepare("SELECT id FROM medicines WHERE name = ? LIMIT 1");
            $exists->execute([$name]);
            if ($exists->fetch()) {
                $error = 'A medicine with that name already exists.';
            } else {
                $newId = cuid();
                $pdo->prepare(
                    "INSERT INTO medicines (id, name, generic_name, form, is_active) VALUES (?,?,?,?,?)"
                )->execute([$newId, $name, $genericName, $form, $isActive]);
                $msg = "Medicine '{$name}' added successfully.";
            }
        }

    } elseif ($action === 'edit_medicine') {
        $id          = $_POST['medicine_id']       ?? '';
        $name        = trim($_POST['name']         ?? '');
        $genericName = trim($_POST['generic_name'] ?? '') ?: null;
        $form        = $_POST['form']              ?? 'TABLET';

        if (!$id || !$name) {
            $error = 'Medicine ID and name are required.';
        } elseif (!in_array($form, $medicinesForms, true)) {
            $error = 'Invalid medicine form.';
        } else {
            $pdo->prepare(
                "UPDATE medicines SET name = ?, generic_name = ?, form = ? WHERE id = ?"
            )->execute([$name, $genericName, $form, $id]);
            $msg = "Medicine updated.";
        }

    } elseif ($action === 'toggle_active') {
        $id = $_POST['medicine_id'] ?? '';
        if ($id) {
            $pdo->prepare("UPDATE medicines SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            $msg = 'Medicine status toggled.';
        }

    } elseif ($action === 'delete_medicine') {
        $id = $_POST['medicine_id'] ?? '';
        if ($id) {
            // Only delete if not used in prescription_items
            $used = $pdo->prepare("SELECT COUNT(*) FROM prescription_items WHERE medicine_id = ?");
            $used->execute([$id]);
            if ((int) $used->fetchColumn() > 0) {
                $error = 'Cannot delete: this medicine is used in existing prescriptions. Deactivate it instead.';
            } else {
                $pdo->prepare("DELETE FROM medicines WHERE id = ?")->execute([$id]);
                $msg = 'Medicine deleted.';
            }
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterActive = $_GET['filter_active'] ?? '';
$filterForm   = $_GET['filter_form']   ?? '';
$search       = trim($_GET['search']   ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit        = 25;
$offset       = ($page - 1) * $limit;

$editId  = $_GET['edit'] ?? '';
$editMed = null;
if ($editId) {
    $es = $pdo->prepare("SELECT * FROM medicines WHERE id = ?");
    $es->execute([$editId]);
    $editMed = $es->fetch() ?: null;
}

$where  = [];
$params = [];

if ($filterActive !== '' && in_array($filterActive, ['0','1'], true)) {
    $where[]  = 'is_active = ?';
    $params[] = (int) $filterActive;
}
if ($filterForm && in_array($filterForm, $medicinesForms, true)) {
    $where[]  = 'form = ?';
    $params[] = $filterForm;
}
if ($search) {
    $where[]  = '(name LIKE ? OR generic_name LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like]);
}

$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM medicines $whereStr");
$totalStmt->execute($params);
$total = (int) $totalStmt->fetchColumn();

$medsStmt = $pdo->prepare("SELECT * FROM medicines $whereStr ORDER BY name ASC LIMIT $limit OFFSET $offset");
$medsStmt->execute($params);
$medicines = $medsStmt->fetchAll();
$totalPages = max(1, (int) ceil($total / $limit));

$pageTitle = 'Medicine Management';
?>
<?php require __DIR__ . '/includes/header.php'; ?>

<?php if ($msg):  ?><div class="alert alert-success">✅ <?= e($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

<?php if ($editMed): ?>
<!-- ── Edit Medicine ─────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h2>Edit Medicine — <?= e($editMed['name']) ?></h2>
        <a href="<?= BASE_URL ?>/admin/medicines.php" class="btn btn-sm btn-outline">← Cancel</a>
    </div>
    <form method="POST" style="max-width:480px;">
        <input type="hidden" name="action" value="edit_medicine">
        <input type="hidden" name="medicine_id" value="<?= e($editMed['id']) ?>">
        <div class="form-group">
            <label>Medicine Name *</label>
            <input type="text" name="name" class="form-control" value="<?= e($editMed['name']) ?>" required>
        </div>
        <div class="form-group">
            <label>Generic / Active Ingredient</label>
            <input type="text" name="generic_name" class="form-control" value="<?= e($editMed['generic_name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Form *</label>
            <select name="form" class="form-control" required>
                <?php foreach ($medicinesForms as $f): ?>
                <option value="<?= $f ?>"<?= $editMed['form'] === $f ? ' selected' : '' ?>><?= $f ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Save Changes</button>
    </form>
</div>
<?php endif; ?>

<!-- ── Medicine List ─────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h2>Medicines (<?= $total ?>)</h2>
        <button class="btn btn-primary btn-sm" onclick="openModal('modalAdd')">+ Add Medicine</button>
    </div>

    <form method="GET" class="filters-bar">
        <div class="form-group">
            <label>Search</label>
            <input type="text" name="search" class="form-control" placeholder="Name / Generic" value="<?= e($search) ?>">
        </div>
        <div class="form-group">
            <label>Status</label>
            <select name="filter_active" class="form-control">
                <option value="">All</option>
                <option value="1"<?= $filterActive === '1' ? ' selected' : '' ?>>Active</option>
                <option value="0"<?= $filterActive === '0' ? ' selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="form-group">
            <label>Form</label>
            <select name="filter_form" class="form-control">
                <option value="">All Forms</option>
                <?php foreach ($medicinesForms as $f): ?>
                <option value="<?= $f ?>"<?= $filterForm === $f ? ' selected' : '' ?>><?= $f ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="align-self:flex-end;">
            <button type="submit" class="btn btn-primary">🔍 Filter</button>
            <a href="<?= BASE_URL ?>/admin/medicines.php" class="btn btn-outline">Clear</a>
        </div>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Name</th><th>Generic Name</th><th>Form</th><th>Status</th><th>Added</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if ($medicines): ?>
                <?php foreach ($medicines as $m): ?>
                <tr>
                    <td><?= e($m['name']) ?></td>
                    <td><?= e($m['generic_name'] ?? '—') ?></td>
                    <td><span style="font-size:0.78rem;background:#f5f5f5;padding:2px 8px;border-radius:4px;"><?= e($m['form']) ?></span></td>
                    <td>
                        <?php if ($m['is_active']): ?>
                            <span class="badge badge-active">Active</span>
                        <?php else: ?>
                            <span class="badge badge-suspended">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= date('d M Y', strtotime($m['created_at'])) ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/medicines.php?edit=<?= e($m['id']) ?>" class="btn btn-xs btn-secondary">Edit</a>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="medicine_id" value="<?= e($m['id']) ?>">
                            <button class="btn btn-xs <?= $m['is_active'] ? 'btn-warning' : 'btn-success' ?>">
                                <?= $m['is_active'] ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this medicine?')">
                            <input type="hidden" name="action" value="delete_medicine">
                            <input type="hidden" name="medicine_id" value="<?= e($m['id']) ?>">
                            <button class="btn btn-xs btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" class="table-empty">No medicines found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination" style="margin-top:14px;">
        <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹ Prev</a>
        <?php else: ?><span class="disabled">‹ Prev</span><?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
            <?php elseif (abs($i - $page) <= 2 || $i === 1 || $i === $totalPages): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
            <?php elseif (abs($i - $page) === 3): ?><span>…</span><?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next ›</a>
        <?php else: ?><span class="disabled">Next ›</span><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Add Medicine Modal ────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalAdd"<?= ($error && ($_POST['action'] ?? '') === 'add_medicine') ? ' style="display:flex"' : '' ?>>
    <div class="modal-box">
        <div class="modal-header">
            <h3>Add New Medicine</h3>
            <button class="modal-close" onclick="closeModal('modalAdd')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_medicine">
            <div class="form-group">
                <label>Medicine Name *</label>
                <input type="text" name="name" class="form-control" value="<?= e($_POST['name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Generic / Active Ingredient</label>
                <input type="text" name="generic_name" class="form-control" value="<?= e($_POST['generic_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Form *</label>
                <select name="form" class="form-control" required>
                    <?php foreach ($medicinesForms as $f): ?>
                    <option value="<?= $f ?>"<?= ($_POST['form'] ?? 'TABLET') === $f ? ' selected' : '' ?>><?= $f ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_active" value="1" checked>
                    Active (available for prescriptions)
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalAdd')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Medicine</button>
            </div>
        </form>
    </div>
</div>

<?php if ($error && ($_POST['action'] ?? '') === 'add_medicine'): ?>
<script>openModal('modalAdd');</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
