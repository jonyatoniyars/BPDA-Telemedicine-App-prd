<?php
// Common helpers

function uuid(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function cuid(): string {
    // Simple CUID-like unique id compatible with existing Prisma IDs (starts with letter)
    $chars = 'abcdefghijklmnopqrstuvwxyz';
    $prefix = $chars[mt_rand(0, 25)];
    return $prefix . substr(str_replace(['-', '_'], '', base64_encode(random_bytes(16))), 0, 23);
}

function isApiRequest(): bool {
    return isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
        || strpos($_SERVER['REQUEST_URI'], '/api/') !== false;
}

function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function e(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function requirePost(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        jsonResponse(['success' => false, 'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required']], 405);
    }
}

function validateBangladeshPhone(string $phone): bool {
    return preg_match('/^01[3-9]\d{8}$/', $phone) === 1;
}

function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function paginate(int $page, int $limit, int $total): array {
    return [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'totalPages' => max(1, (int) ceil($total / $limit)),
    ];
}

function requireAuthOrGuest(): void {
    // Landing page: redirect logged-in users to dashboard, otherwise show login
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        redirect(BASE_URL . '/pages/dashboard.php');
    }
    redirect(BASE_URL . '/pages/login.php');
}

/**
 * Insert an entry into audit_logs for admin mutations.
 */
function logAudit(string $adminId, string $action, ?string $entityType = null, ?string $entityId = null, ?array $metadata = null): void {
    global $pdo;
    $stmt = $pdo->prepare(
        "INSERT INTO audit_logs (id, admin_id, action, target_entity_type, target_entity_id, metadata, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([
        cuid(),
        $adminId,
        $action,
        $entityType,
        $entityId,
        $metadata !== null ? json_encode($metadata) : null,
    ]);
}
