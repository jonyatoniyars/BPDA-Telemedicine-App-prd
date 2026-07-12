<?php
// GET /api/dashboard — returns role-scoped stats
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') badRequest('GET required');

try {
    $user = requireActiveUser();

    // ── ADMIN ──────────────────────────────────────────────────────────────────
    if ($user['role'] === 'ADMIN') {
        $totalUsers = (int) $pdo->query(
            "SELECT COUNT(*) FROM users WHERE role != 'ADMIN'"
        )->fetchColumn();

        $pendingApprovals = (int) $pdo->query(
            "SELECT COUNT(*) FROM users WHERE status = 'PENDING'"
        )->fetchColumn();

        $totalPrescriptions = (int) $pdo->query(
            "SELECT COUNT(*) FROM prescriptions"
        )->fetchColumn();

        $activeMedicines = (int) $pdo->query(
            "SELECT COUNT(*) FROM medicines WHERE is_active = 1"
        )->fetchColumn();

        ok([
            'totalUsers'         => $totalUsers,
            'pendingApprovals'   => $pendingApprovals,
            'totalPrescriptions' => $totalPrescriptions,
            'activeMedicines'    => $activeMedicines,
        ]);
    }

    // ── DOCTOR ─────────────────────────────────────────────────────────────────
    if ($user['role'] === 'DOCTOR') {
        $stmt = $pdo->prepare(
            "SELECT health_worker_id FROM doctor_assignments WHERE doctor_id = ?"
        );
        $stmt->execute([$user['id']]);
        $hwIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $assignedWorkers = count($hwIds);
        $pendingReviews  = 0;

        if (!empty($hwIds)) {
            $ph   = implode(',', array_fill(0, count($hwIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM prescriptions
                 WHERE health_worker_id IN ({$ph}) AND status = 'SUBMITTED'"
            );
            $stmt->execute($hwIds);
            $pendingReviews = (int) $stmt->fetchColumn();
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM prescriptions WHERE reviewed_by_id = ? AND status = 'REVIEWED'"
        );
        $stmt->execute([$user['id']]);
        $totalReviewed = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM video_call_requests WHERE receiver_id = ? AND status = 'PENDING'"
        );
        $stmt->execute([$user['id']]);
        $pendingVideoCalls = (int) $stmt->fetchColumn();

        ok([
            'assignedWorkers'   => $assignedWorkers,
            'pendingReviews'    => $pendingReviews,
            'totalReviewed'     => $totalReviewed,
            'pendingVideoCalls' => $pendingVideoCalls,
        ]);
    }

    // ── HEALTH_WORKER ──────────────────────────────────────────────────────────
    if ($user['role'] === 'HEALTH_WORKER') {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM prescriptions WHERE health_worker_id = ?"
        );
        $stmt->execute([$user['id']]);
        $totalPrescriptions = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM prescriptions WHERE health_worker_id = ? AND status = 'SUBMITTED'"
        );
        $stmt->execute([$user['id']]);
        $submittedPrescriptions = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM prescriptions WHERE health_worker_id = ? AND status = 'REVIEWED'"
        );
        $stmt->execute([$user['id']]);
        $reviewedPrescriptions = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM video_call_requests WHERE requester_id = ? AND status = 'PENDING'"
        );
        $stmt->execute([$user['id']]);
        $pendingVideoCalls = (int) $stmt->fetchColumn();

        ok([
            'totalPrescriptions'     => $totalPrescriptions,
            'submittedPrescriptions' => $submittedPrescriptions,
            'reviewedPrescriptions'  => $reviewedPrescriptions,
            'pendingVideoCalls'      => $pendingVideoCalls,
        ]);
    }

    forbidden();

} catch (PDOException $e) {
    error_log('[api/dashboard] DB: ' . $e->getMessage());
    serverError();
} catch (Throwable $e) {
    error_log('[api/dashboard] ' . $e->getMessage());
    serverError();
}
