<?php
// api/auth-endpoints.php
// Handles BOTH:
//   REST API calls  (when ?_route=login|register|logout|me is set via .htaccess rewrite)
//   HTML form posts (when $_POST['action'] is set by pages/login.php and pages/register.php)
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/auth.php';

// ── REST API mode ─────────────────────────────────────────────────────────────
if (isset($_GET['_route'])) {
    $method = $_SERVER['REQUEST_METHOD'];
    $route  = $_GET['_route'];

    try {
        switch ($route) {

            // POST /api/auth/login
            case 'login':
                if ($method !== 'POST') badRequest('POST required');

                $body       = getJsonInput();
                $identifier = trim((string)($body['identifier'] ?? ''));
                $password   = (string)($body['password'] ?? '');

                if ($identifier === '' || $password === '') {
                    validationError(['identifier' => 'identifier and password are required']);
                }

                $col  = preg_match('/^01/', $identifier) ? 'phone' : 'email';
                $stmt = $pdo->prepare(
                    "SELECT id, name, email, phone, role, status, can_write_prescription, password_hash
                     FROM users WHERE {$col} = ? LIMIT 1"
                );
                $stmt->execute([$identifier]);
                $user = $stmt->fetch() ?: null;

                // Constant-time comparison to prevent user-enumeration timing attacks
                $dummyHash = '$2y$12$invaliddummyhashvalue0000000000000000000000000000000000';
                $valid = verifyPassword($password, $user ? $user['password_hash'] : $dummyHash);

                if (!$user || !$valid) badRequest('Invalid credentials');

                if ($user['status'] === 'PENDING')   forbidden('Your account is pending admin approval.');
                if ($user['status'] === 'SUSPENDED') forbidden('Your account has been suspended. Contact admin.');

                setAuthSession($user);

                ok([
                    'user' => [
                        'id'                   => $user['id'],
                        'name'                 => $user['name'],
                        'email'                => $user['email'],
                        'phone'                => $user['phone'],
                        'role'                 => $user['role'],
                        'canWritePrescription' => (bool) $user['can_write_prescription'],
                    ],
                ]);
                break;

            // POST /api/auth/register
            case 'register':
                if ($method !== 'POST') badRequest('POST required');

                $body     = getJsonInput();
                $name     = trim((string)($body['name'] ?? ''));
                $email    = trim(strtolower((string)($body['email'] ?? '')));
                $phone    = trim((string)($body['phone'] ?? ''));
                $password = (string)($body['password'] ?? '');
                $role     = (string)($body['role'] ?? '');

                $errors = [];
                if ($name === '')
                    $errors['name'] = 'Name is required';
                if (strlen($password) < 6)
                    $errors['password'] = 'Password must be at least 6 characters';
                if (!in_array($role, ['HEALTH_WORKER', 'DOCTOR'], true))
                    $errors['role'] = 'Role must be HEALTH_WORKER or DOCTOR';
                if ($email === '' && $phone === '')
                    $errors['identifier'] = 'Email or phone is required';
                if ($email !== '' && !validateEmail($email))
                    $errors['email'] = 'Invalid email address';
                if ($phone !== '' && !validateBangladeshPhone($phone))
                    $errors['phone'] = 'Invalid BD phone number (e.g. 01712345678)';

                if ($errors) validationError($errors);

                if ($email !== '') {
                    $s = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $s->execute([$email]);
                    if ($s->fetch()) conflict('Email already registered');
                }
                if ($phone !== '') {
                    $s = $pdo->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
                    $s->execute([$phone]);
                    if ($s->fetch()) conflict('Phone number already registered');
                }

                $id           = cuid();
                $passwordHash = hashPassword($password);
                $now          = date('Y-m-d H:i:s');

                $pdo->prepare(
                    "INSERT INTO users (id, name, email, phone, password_hash, role, status, can_write_prescription, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'PENDING', 0, ?, ?)"
                )->execute([$id, $name, $email ?: null, $phone ?: null, $passwordHash, $role, $now, $now]);

                created([
                    'user'    => [
                        'id'        => $id,
                        'name'      => $name,
                        'email'     => $email ?: null,
                        'phone'     => $phone ?: null,
                        'role'      => $role,
                        'status'    => 'PENDING',
                        'createdAt' => $now,
                    ],
                    'message' => 'Registration successful. Please wait for admin approval.',
                ]);
                break;

            // POST /api/auth/logout
            case 'logout':
                if ($method !== 'POST') badRequest('POST required');
                clearAuthSession();
                ok(['message' => 'Logged out successfully']);
                break;

            // GET /api/auth/me
            case 'me':
                if ($method !== 'GET') badRequest('GET required');
                $user = currentUser();
                if (!$user) unauthorized();
                ok([
                    'id'                   => $user['id'],
                    'name'                 => $user['name'],
                    'email'                => $user['email'],
                    'phone'                => $user['phone'],
                    'role'                 => $user['role'],
                    'status'               => $user['status'],
                    'canWritePrescription' => (bool) $user['can_write_prescription'],
                ]);
                break;

            default:
                notFound('Auth endpoint not found');
        }
    } catch (PDOException $e) {
        error_log('[api/auth-endpoints] DB: ' . $e->getMessage());
        serverError();
    } catch (Throwable $e) {
        error_log('[api/auth-endpoints] ' . $e->getMessage());
        serverError();
    }
    exit; // Should not reach here (all branches call exit via response helpers)
}

// ── HTML form mode (used by pages/login.php and pages/register.php) ───────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'logout') {
    clearAuthSession();
    redirect(BASE_URL . '/pages/login.php');
}

if ($action === 'login') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    if (!$identifier || !$password) {
        $_SESSION['auth_error'] = 'Email/phone and password are required.';
        redirect(BASE_URL . '/pages/login.php');
    }

    $stmt = $pdo->prepare(
        "SELECT id, name, email, phone, role, status, can_write_prescription, password_hash
         FROM users
         WHERE email = ? OR phone = ?
         LIMIT 1"
    );
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    // Always run bcrypt to prevent user-enumeration via timing differences.
    $dummyHash = '$2y$12$invaliddummyhashvalue0000000000000000000000000000000000';
    $valid = verifyPassword($password, $user ? $user['password_hash'] : $dummyHash);

    if (!$user || !$valid) {
        $_SESSION['auth_error'] = 'Invalid credentials. Please try again.';
        redirect(BASE_URL . '/pages/login.php');
    }

    if ($user['status'] === 'SUSPENDED') {
        $_SESSION['auth_error'] = 'Your account has been suspended. Contact an administrator.';
        redirect(BASE_URL . '/pages/login.php');
    }

    if ($user['status'] === 'PENDING') {
        $_SESSION['auth_error'] = 'Your account is pending approval. Please wait for an admin to activate it.';
        redirect(BASE_URL . '/pages/login.php?reason=pending');
    }

    setAuthSession($user);
    redirect(BASE_URL . '/pages/dashboard.php');
}

if ($action === 'register') {
    $name     = sanitize($_POST['name'] ?? '');
    $email    = sanitize($_POST['email'] ?? '');
    $phone    = sanitize($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? '';

    $errors = [];
    if (!$name)                                    $errors[] = 'Full name is required.';
    if (!$email || !validateEmail($email))          $errors[] = 'A valid email address is required.';
    if ($phone && !validateBangladeshPhone($phone)) $errors[] = 'Phone must be a valid Bangladesh mobile number (01XXXXXXXXX).';
    if (strlen($password) < 8)                     $errors[] = 'Password must be at least 8 characters.';
    if (!in_array($role, ['HEALTH_WORKER', 'DOCTOR'], true)) $errors[] = 'Please select a valid role.';

    if ($errors) {
        $_SESSION['reg_errors'] = $errors;
        $_SESSION['reg_old']    = compact('name', 'email', 'phone', 'role');
        redirect(BASE_URL . '/pages/register.php');
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR (phone IS NOT NULL AND phone = ?) LIMIT 1");
    $stmt->execute([$email, $phone ?: null]);
    if ($stmt->fetch()) {
        $_SESSION['reg_errors'] = ['An account with that email or phone already exists.'];
        $_SESSION['reg_old']    = compact('name', 'email', 'phone', 'role');
        redirect(BASE_URL . '/pages/register.php');
    }

    $id   = cuid();
    $hash = hashPassword($password);
    $pdo->prepare(
        "INSERT INTO users (id, name, email, phone, password_hash, role, status)
         VALUES (?, ?, ?, ?, ?, ?, 'PENDING')"
    )->execute([$id, $name, $email, $phone ?: null, $hash, $role]);

    $_SESSION['reg_success'] = 'Registration successful! Your account is pending admin approval.';
    redirect(BASE_URL . '/pages/login.php?registered=1');
}

// Unknown action — fall back to login page
redirect(BASE_URL . '/pages/login.php');
