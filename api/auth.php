<?php
// Session-based auth helpers for the PHP port.
// Mirrors JWT behavior using secure PHP sessions.

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function currentUser(): ?array {
    global $pdo;
    if (!isLoggedIn()) return null;
    $stmt = $pdo->prepare("SELECT id, name, email, phone, role, status, can_write_prescription, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function requireAuth(): array {
    $user = currentUser();
    if (!$user) {
        if (isApiRequest()) unauthorized();
        redirect(BASE_URL . '/pages/login.php');
    }
    return $user;
}

function requireActiveUser(): array {
    $user = requireAuth();
    if ($user['status'] !== 'ACTIVE') {
        if (isApiRequest()) forbidden('Account pending approval or suspended');
        redirect(BASE_URL . '/pages/login.php?reason=pending');
    }
    return $user;
}

function requireRole(string ...$roles): array {
    $user = requireActiveUser();
    if (!in_array($user['role'], $roles, true)) {
        if (isApiRequest()) forbidden('Insufficient permissions');
        redirect(BASE_URL . '/pages/dashboard.php');
    }
    return $user;
}

function requireAdmin(): array {
    return requireRole('ADMIN');
}

function requireHealthWorker(): array {
    return requireRole('HEALTH_WORKER');
}

function requireDoctor(): array {
    return requireRole('DOCTOR');
}

function setAuthSession(array $user): void {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_status'] = $user['status'];
    $_SESSION['can_write_prescription'] = (bool) $user['can_write_prescription'];
    $_SESSION['login_time'] = time();
    session_regenerate_id(true);
}

function clearAuthSession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}
