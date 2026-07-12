<?php
// GET   /api/admin/users/:id — single user detail (admin)
// PATCH /api/admin/users/:id — update status or canWritePrescription (admin)
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = trim($_GET['id'] ?? '');

if ($id === '') notFound('User not found');

try {

    // ── GET /api/admin/users/:id ───────────────────────────────────────────────
    if ($method === 'GET') {
        requireAdmin();

        // Fetch base user
        $stmt = $pdo->prepare(
            "SELECT id, name, email, phone, role, status,
                    can_write_prescription AS canWritePrescription,
                    created_at AS createdAt, updated_at AS updatedAt
             FROM users WHERE id = ?"
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) notFound('User not found');

        $user['canWritePrescription'] = (bool) $user['canWritePrescription'];

        // Fetch doctor assignment (if this user is a health worker)
        $stmt = $pdo->prepare(
            "SELECT da.id AS assignmentId, da.assigned_at AS assignedAt,
                    d.id AS doctorId, d.name AS doctorName
             FROM doctor_assignments da
             JOIN users d ON da.doctor_id = d.id
             WHERE da.health_worker_id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $hwAssignment = $stmt->fetch();
        $user['assignedAsWorker'] = $hwAssignment
            ? [
                'id'         => $hwAssignment['assignmentId'],
                'assignedAt' => $hwAssignment['assignedAt'],
                'doctor'     => ['id' => $hwAssignment['doctorId'], 'name' => $hwAssignment['doctorName']],
              ]
            : null;

        // Fetch assigned health workers (if this user is a doctor)
        $stmt = $pdo->prepare(
            "SELECT da.id AS assignmentId, da.assigned_at AS assignedAt,
                    hw.id AS hwId, hw.name AS hwName
             FROM doctor_assignments da
             JOIN users hw ON da.health_worker_id = hw.id
             WHERE da.doctor_id = ?
             ORDER BY da.assigned_at DESC"
        );
        $stmt->execute([$id]);
        $user['assignedAsDoctor'] = array_map(fn($row) => [
            'id'           => $row['assignmentId'],
            'assignedAt'   => $row['assignedAt'],
            'healthWorker' => ['id' => $row['hwId'], 'name' => $row['hwName']],
        ], $stmt->fetchAll());

        ok($user);
        return;
    }

    // ── PATCH /api/admin/users/:id ─────────────────────────────────────────────
    if ($method === 'PATCH') {
        $admin = requireAdmin();

        $body = getJsonInput();
        if (empty($body)) badRequest('Request body is required');

        $stmt = $pdo->prepare(
            "SELECT id, name, role, status, can_write_prescription FROM users WHERE id = ?"
        );
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) notFound('User not found');

        if ($target['role'] === 'ADMIN') {
            forbidden('Cannot modify admin accounts');
        }

        // ── Status update ──────────────────────────────────────────────────────
        if (array_key_exists('status', $body)) {
            $newStatus = (string)$body['status'];
            if (!in_array($newStatus, ['PENDING', 'ACTIVE', 'SUSPENDED'], true)) {
                validationError(['status' => 'Status must be PENDING, ACTIVE, or SUSPENDED']);
            }

            // When activating a health worker, also grant prescription writing
            $grantPrescription = ($newStatus === 'ACTIVE' && $target['role'] === 'HEALTH_WORKER');

            $pdo->prepare(
                "UPDATE users SET status = ?, can_write_prescription = ?, updated_at = NOW() WHERE id = ?"
            )->execute([
                $newStatus,
                $grantPrescription ? 1 : (int) $target['can_write_prescription'],
                $id,
            ]);

            logAudit($admin['id'], "USER_{$newStatus}", 'User', $id, [
                'previousStatus' => $target['status'],
                'newStatus'      => $newStatus,
            ]);

            $stmt = $pdo->prepare(
                "SELECT id, name, role, status, can_write_prescription AS canWritePrescription
                 FROM users WHERE id = ?"
            );
            $stmt->execute([$id]);
            $updated = $stmt->fetch();
            $updated['canWritePrescription'] = (bool) $updated['canWritePrescription'];
            ok($updated);
            return;
        }

        // ── Permission update ──────────────────────────────────────────────────
        if (array_key_exists('canWritePrescription', $body)) {
            if ($target['role'] !== 'HEALTH_WORKER') {
                badRequest('canWritePrescription permission only applies to health workers');
            }

            $canWrite = (bool)$body['canWritePrescription'];

            $pdo->prepare(
                "UPDATE users SET can_write_prescription = ?, updated_at = NOW() WHERE id = ?"
            )->execute([$canWrite ? 1 : 0, $id]);

            logAudit(
                $admin['id'],
                $canWrite ? 'PERMISSION_GRANTED' : 'PERMISSION_REVOKED',
                'User',
                $id,
                ['canWritePrescription' => $canWrite]
            );

            $stmt = $pdo->prepare(
                "SELECT id, name, can_write_prescription AS canWritePrescription FROM users WHERE id = ?"
            );
            $stmt->execute([$id]);
            $updated = $stmt->fetch();
            $updated['canWritePrescription'] = (bool) $updated['canWritePrescription'];
            ok($updated);
            return;
        }

        badRequest("Provide 'status' or 'canWritePrescription' to update");
    }

    badRequest('Method not allowed');

} catch (PDOException $e) {
    error_log('[api/user-detail] DB: ' . $e->getMessage());
    serverError();
} catch (Throwable $e) {
    error_log('[api/user-detail] ' . $e->getMessage());
    serverError();
}
