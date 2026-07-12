<?php
// GET /api/admin/users — paginated user list (admin only, excludes admins)
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') badRequest('GET required');

try {
    requireAdmin();

    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $role   = trim($_GET['role'] ?? '');
    $offset = ($page - 1) * $limit;

    $where  = ['u.role != ?'];
    $params = ['ADMIN'];

    if ($status !== '') {
        $where[]  = 'u.status = ?';
        $params[] = $status;
    }

    // Role filter: never allow filtering by ADMIN via API
    if ($role !== '' && $role !== 'ADMIN') {
        $where[]  = 'u.role = ?';
        $params[] = $role;
    }

    if ($search !== '') {
        $like     = '%' . $search . '%';
        $where[]  = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u {$whereClause}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $listStmt = $pdo->prepare(
        "SELECT u.id, u.name, u.email, u.phone, u.role, u.status,
                u.can_write_prescription AS canWritePrescription,
                u.created_at AS createdAt,
                da.doctor_id,
                d.name AS doctorName
         FROM users u
         LEFT JOIN doctor_assignments da ON da.health_worker_id = u.id
         LEFT JOIN users d ON da.doctor_id = d.id
         {$whereClause}
         ORDER BY u.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $listStmt->execute(array_merge($params, [$limit, $offset]));
    $rows = $listStmt->fetchAll();

    $users = array_map(function (array $row): array {
        $user = [
            'id'                   => $row['id'],
            'name'                 => $row['name'],
            'email'                => $row['email'],
            'phone'                => $row['phone'],
            'role'                 => $row['role'],
            'status'               => $row['status'],
            'canWritePrescription' => (bool) $row['canWritePrescription'],
            'createdAt'            => $row['createdAt'],
            'assignedDoctor'       => $row['doctor_id']
                ? ['id' => $row['doctor_id'], 'name' => $row['doctorName']]
                : null,
        ];
        return $user;
    }, $rows);

    ok($users, paginate($page, $limit, $total));

} catch (PDOException $e) {
    error_log('[api/users] DB: ' . $e->getMessage());
    serverError();
} catch (Throwable $e) {
    error_log('[api/users] ' . $e->getMessage());
    serverError();
}
