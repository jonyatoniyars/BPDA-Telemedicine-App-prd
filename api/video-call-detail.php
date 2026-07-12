<?php
// PATCH /api/video-calls/:id — doctor or admin accepts/declines a request
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = trim($_GET['id'] ?? '');

if ($id === '') notFound('Video call request not found');

if ($method !== 'PATCH') badRequest('PATCH required');

try {
    $user = requireActiveUser();

    if ($user['role'] !== 'DOCTOR' && $user['role'] !== 'ADMIN') {
        forbidden('Only doctors can respond to video call requests');
    }

    $body      = getJsonInput();
    $newStatus = trim((string)($body['status'] ?? ''));

    if (!in_array($newStatus, ['ACCEPTED', 'DECLINED'], true)) {
        validationError(['status' => 'Status must be ACCEPTED or DECLINED']);
    }

    // Fetch the request
    $stmt = $pdo->prepare(
        "SELECT id, receiver_id AS receiverId, status FROM video_call_requests WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$id]);
    $callReq = $stmt->fetch();

    if (!$callReq) notFound('Video call request not found');

    if ($user['role'] === 'DOCTOR' && $callReq['receiverId'] !== $user['id']) {
        forbidden('This request was not sent to you');
    }

    if ($callReq['status'] !== 'PENDING') {
        badRequest('Request is already ' . strtolower($callReq['status']));
    }

    $pdo->prepare(
        "UPDATE video_call_requests SET status = ?, updated_at = NOW() WHERE id = ?"
    )->execute([$newStatus, $id]);

    $stmt = $pdo->prepare(
        "SELECT vc.id,
                vc.note,
                vc.status,
                vc.created_at AS createdAt,
                vc.updated_at AS updatedAt,
                rq.id         AS requesterId,
                rq.name       AS requesterName,
                rv.id         AS receiverId,
                rv.name       AS receiverName
         FROM video_call_requests vc
         JOIN users rq ON vc.requester_id = rq.id
         JOIN users rv ON vc.receiver_id  = rv.id
         WHERE vc.id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    ok([
        'id'        => $row['id'],
        'note'      => $row['note'],
        'status'    => $row['status'],
        'createdAt' => $row['createdAt'],
        'updatedAt' => $row['updatedAt'],
        'requester' => ['id' => $row['requesterId'], 'name' => $row['requesterName']],
        'receiver'  => ['id' => $row['receiverId'],  'name' => $row['receiverName']],
    ]);

} catch (PDOException $e) {
    error_log('[api/video-call-detail] DB: ' . $e->getMessage());
    serverError();
} catch (Throwable $e) {
    error_log('[api/video-call-detail] ' . $e->getMessage());
    serverError();
}
