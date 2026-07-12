<?php
// GET    /api/admin/assignments — list all assignments (admin)
// POST   /api/admin/assignments — create/update assignment (admin)
// DELETE /api/admin/assignments?healthWorkerId=... — remove assignment (admin)
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'];

function formatAssignment(array $row): array
{
    return [
        'id'           => $row['id'],
        'assignedAt'   => $row['assignedAt'],
        'doctor'       => [
            'id'    => $row['doctorId'],
            'name'  => $row['doctorName'],
            'email' => $row['doctorEmail'],
        ],
        'healthWorker' => [
            'id'     => $row['hwId'],
            'name'   => $row['hwName'],
            'email'  => $row['hwEmail'],
            'phone'  => $row['hwPhone'],
            'status' => $row['hwStatus'],
        ],
    ];
}

try {

    // ── GET /api/admin/assignments ─────────────────────────────────────────────
    if ($method === 'GET') {
        requireAdmin();

        $stmt = $pdo->query(
            "SELECT da.id,
                    da.assigned_at  AS assignedAt,
                    d.id            AS doctorId,
                    d.name          AS doctorName,
                    d.email         AS doctorEmail,
                    hw.id           AS hwId,
                    hw.name         AS hwName,
                    hw.email        AS hwEmail,
                    hw.phone        AS hwPhone,
                    hw.status       AS hwStatus
             FROM doctor_assignments da
             JOIN users d  ON da.doctor_id       = d.id
             JOIN users hw ON da.health_worker_id = hw.id
             ORDER BY da.assigned_at DESC"
        );

        ok(array_map('formatAssignment', $stmt->fetchAll()));
        return;
    }

    // ── POST /api/admin/assignments ────────────────────────────────────────────
    if ($method === 'POST') {
        $admin = requireAdmin();

        $body           = getJsonInput();
        $doctorId       = trim((string)($body['doctorId'] ?? ''));
        $healthWorkerId = trim((string)($body['healthWorkerId'] ?? ''));

        $errors = [];
        if ($doctorId === '')       $errors['doctorId']       = 'doctorId is required';
        if ($healthWorkerId === '') $errors['healthWorkerId'] = 'healthWorkerId is required';
        if ($errors) validationError($errors);

        // Validate doctor
        $s = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ? LIMIT 1");
        $s->execute([$doctorId]);
        $doctor = $s->fetch();
        if (!$doctor || $doctor['role'] !== 'DOCTOR') notFound('Doctor not found');

        // Validate health worker
        $s = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ? LIMIT 1");
        $s->execute([$healthWorkerId]);
        $hw = $s->fetch();
        if (!$hw || $hw['role'] !== 'HEALTH_WORKER') notFound('Health worker not found');

        // Upsert: update if assignment exists, otherwise insert
        $s = $pdo->prepare(
            "SELECT id FROM doctor_assignments WHERE health_worker_id = ? LIMIT 1"
        );
        $s->execute([$healthWorkerId]);
        $existing = $s->fetch();

        if ($existing) {
            $pdo->prepare(
                "UPDATE doctor_assignments SET doctor_id = ?, assigned_at = NOW() WHERE health_worker_id = ?"
            )->execute([$doctorId, $healthWorkerId]);
            $assignmentId = $existing['id'];
        } else {
            $assignmentId = cuid();
            $pdo->prepare(
                "INSERT INTO doctor_assignments (id, doctor_id, health_worker_id, assigned_at)
                 VALUES (?, ?, ?, NOW())"
            )->execute([$assignmentId, $doctorId, $healthWorkerId]);
        }

        logAudit($admin['id'], 'ASSIGNMENT_CREATED', 'DoctorAssignment', $assignmentId, [
            'doctorName' => $doctor['name'],
            'hwName'     => $hw['name'],
        ]);

        $stmt = $pdo->prepare(
            "SELECT da.id,
                    da.assigned_at  AS assignedAt,
                    d.id            AS doctorId,
                    d.name          AS doctorName,
                    d.email         AS doctorEmail,
                    hw.id           AS hwId,
                    hw.name         AS hwName,
                    hw.email        AS hwEmail,
                    hw.phone        AS hwPhone,
                    hw.status       AS hwStatus
             FROM doctor_assignments da
             JOIN users d  ON da.doctor_id       = d.id
             JOIN users hw ON da.health_worker_id = hw.id
             WHERE da.id = ?"
        );
        $stmt->execute([$assignmentId]);
        created(formatAssignment($stmt->fetch()));
        return;
    }

    // ── DELETE /api/admin/assignments?healthWorkerId=... ──────────────────────
    if ($method === 'DELETE') {
        $admin          = requireAdmin();
        $healthWorkerId = trim($_GET['healthWorkerId'] ?? '');

        if ($healthWorkerId === '') {
            badRequest('healthWorkerId query param required');
        }

        $s = $pdo->prepare(
            "SELECT id FROM doctor_assignments WHERE health_worker_id = ? LIMIT 1"
        );
        $s->execute([$healthWorkerId]);
        $assignment = $s->fetch();
        if (!$assignment) notFound('Assignment not found');

        $pdo->prepare(
            "DELETE FROM doctor_assignments WHERE health_worker_id = ?"
        )->execute([$healthWorkerId]);

        logAudit($admin['id'], 'ASSIGNMENT_REMOVED', 'DoctorAssignment', $assignment['id'], [
            'healthWorkerId' => $healthWorkerId,
        ]);

        ok(['message' => 'Assignment removed']);
        return;
    }

    badRequest('Method not allowed');

} catch (PDOException $e) {
    error_log('[api/assignments] DB: ' . $e->getMessage());
    serverError();
} catch (Throwable $e) {
    error_log('[api/assignments] ' . $e->getMessage());
    serverError();
}
