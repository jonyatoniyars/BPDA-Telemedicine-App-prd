<?php
// GET  /api/video-calls — role-scoped list (max 50)
// POST /api/video-calls — health worker requests a call
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'];

function formatVideoCall(array $row): array
{
    return [
        'id'        => $row['id'],
        'note'      => $row['note'],
        'status'    => $row['status'],
        'createdAt' => $row['createdAt'],
        'updatedAt' => $row['updatedAt'],
        'requester' => [
            'id'    => $row['requesterId'],
            'name'  => $row['requesterName'],
            'phone' => $row['requesterPhone'],
        ],
        'receiver'  => [
            'id'   => $row['receiverId'],
            'name' => $row['receiverName'],
        ],
    ];
}

try {

    // ── GET /api/video-calls ───────────────────────────────────────────────────
    if ($method === 'GET') {
        $user = requireActiveUser();

        $where  = [];
        $params = [];

        if ($user['role'] === 'HEALTH_WORKER') {
            $where[]  = 'vc.requester_id = ?';
            $params[] = $user['id'];
        } elseif ($user['role'] === 'DOCTOR') {
            $where[]  = 'vc.receiver_id = ?';
            $params[] = $user['id'];
        }
        // ADMIN: no filter

        $status = trim($_GET['status'] ?? '');
        if ($status !== '') {
            $where[]  = 'vc.status = ?';
            $params[] = $status;
        }

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $pdo->prepare(
            "SELECT vc.id,
                    vc.note,
                    vc.status,
                    vc.created_at AS createdAt,
                    vc.updated_at AS updatedAt,
                    rq.id         AS requesterId,
                    rq.name       AS requesterName,
                    rq.phone      AS requesterPhone,
                    rv.id         AS receiverId,
                    rv.name       AS receiverName
             FROM video_call_requests vc
             JOIN users rq ON vc.requester_id = rq.id
             JOIN users rv ON vc.receiver_id  = rv.id
             {$whereClause}
             ORDER BY vc.created_at DESC
             LIMIT 50"
        );
        $stmt->execute($params);

        ok(array_map('formatVideoCall', $stmt->fetchAll()));
        return;
    }

    // ── POST /api/video-calls ──────────────────────────────────────────────────
    if ($method === 'POST') {
        $user = requireActiveUser();

        if ($user['role'] !== 'HEALTH_WORKER') {
            forbidden('Only health workers can request video calls');
        }

        // Find assigned doctor
        $s = $pdo->prepare(
            "SELECT doctor_id FROM doctor_assignments WHERE health_worker_id = ? LIMIT 1"
        );
        $s->execute([$user['id']]);
        $assignment = $s->fetch();

        if (!$assignment) {
            badRequest('You are not assigned to any doctor. Ask admin to assign you.');
        }

        // No duplicate pending requests allowed
        $s = $pdo->prepare(
            "SELECT id FROM video_call_requests WHERE requester_id = ? AND status = 'PENDING' LIMIT 1"
        );
        $s->execute([$user['id']]);
        if ($s->fetch()) {
            badRequest('You already have a pending video call request.');
        }

        $body = getJsonInput();
        $note = isset($body['note']) ? trim((string)$body['note']) : null;

        $id  = cuid();
        $now = date('Y-m-d H:i:s');

        $pdo->prepare(
            "INSERT INTO video_call_requests (id, requester_id, receiver_id, note, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'PENDING', ?, ?)"
        )->execute([$id, $user['id'], $assignment['doctor_id'], $note ?: null, $now, $now]);

        $stmt = $pdo->prepare(
            "SELECT vc.id,
                    vc.note,
                    vc.status,
                    vc.created_at AS createdAt,
                    vc.updated_at AS updatedAt,
                    rq.id         AS requesterId,
                    rq.name       AS requesterName,
                    rq.phone      AS requesterPhone,
                    rv.id         AS receiverId,
                    rv.name       AS receiverName
             FROM video_call_requests vc
             JOIN users rq ON vc.requester_id = rq.id
             JOIN users rv ON vc.receiver_id  = rv.id
             WHERE vc.id = ?"
        );
        $stmt->execute([$id]);
        created(formatVideoCall($stmt->fetch()));
        return;
    }

    badRequest('Method not allowed');

} catch (PDOException $e) {
    error_log('[api/video-calls] DB: ' . $e->getMessage());
    serverError();
} catch (Throwable $e) {
    error_log('[api/video-calls] ' . $e->getMessage());
    serverError();
}
