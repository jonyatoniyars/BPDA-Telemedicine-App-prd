<?php
// GET  /api/admin/medicines — list (any active user; non-admins see active only)
// POST /api/admin/medicines — create (admin only)
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'];

function formatMedicine(array $row): array
{
    return [
        'id'          => $row['id'],
        'name'        => $row['name'],
        'genericName' => $row['genericName'],
        'form'        => $row['form'],
        'isActive'    => (bool) $row['isActive'],
        'createdAt'   => $row['createdAt'],
        'updatedAt'   => $row['updatedAt'],
    ];
}

try {

    // ── GET /api/admin/medicines ───────────────────────────────────────────────
    if ($method === 'GET') {
        $user = currentUser();
        if (!$user) unauthorized();

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $search = trim($_GET['search'] ?? '');
        $offset = ($page - 1) * $limit;

        $activeOnly = ($user['role'] !== 'ADMIN');

        $where  = [];
        $params = [];

        if ($activeOnly) {
            $where[] = 'is_active = 1';
        }

        if ($search !== '') {
            $like     = '%' . $search . '%';
            $where[]  = '(name LIKE ? OR generic_name LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM medicines {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $listStmt = $pdo->prepare(
            "SELECT id, name, generic_name AS genericName, form,
                    is_active AS isActive, created_at AS createdAt, updated_at AS updatedAt
             FROM medicines
             {$whereClause}
             ORDER BY name ASC
             LIMIT ? OFFSET ?"
        );
        $listStmt->execute(array_merge($params, [$limit, $offset]));
        $medicines = array_map('formatMedicine', $listStmt->fetchAll());

        ok($medicines, paginate($page, $limit, $total));
        return;
    }

    // ── POST /api/admin/medicines ──────────────────────────────────────────────
    if ($method === 'POST') {
        $user = requireAdmin();

        $body = getJsonInput();

        $errors    = [];
        $name      = trim((string)($body['name'] ?? ''));
        $generic   = isset($body['genericName']) ? trim((string)$body['genericName']) : null;
        $form      = (string)($body['form'] ?? 'TABLET');
        $isActive  = isset($body['isActive']) ? (bool)$body['isActive'] : true;

        $validForms = ['TABLET','CAPSULE','SYRUP','INJECTION','OINTMENT','DROPS','INHALER','SUPPOSITORY','PATCH','OTHER'];

        if ($name === '') $errors['name'] = 'Medicine name is required';
        if (!in_array($form, $validForms, true)) $errors['form'] = 'Invalid medicine form';

        if ($errors) validationError($errors);

        $id  = cuid();
        $now = date('Y-m-d H:i:s');

        $pdo->prepare(
            "INSERT INTO medicines (id, name, generic_name, form, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$id, $name, $generic ?: null, $form, $isActive ? 1 : 0, $now, $now]);

        logAudit($user['id'], 'MEDICINE_ADDED', 'Medicine', $id, ['name' => $name]);

        $stmt = $pdo->prepare(
            "SELECT id, name, generic_name AS genericName, form,
                    is_active AS isActive, created_at AS createdAt, updated_at AS updatedAt
             FROM medicines WHERE id = ?"
        );
        $stmt->execute([$id]);
        created(formatMedicine($stmt->fetch()));
        return;
    }

    badRequest('Method not allowed');

} catch (PDOException $e) {
    error_log('[api/medicines] DB: ' . $e->getMessage());
    serverError();
} catch (Throwable $e) {
    error_log('[api/medicines] ' . $e->getMessage());
    serverError();
}
