<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

// Redirect already-logged-in admins straight to dashboard
if (isLoggedIn()) {
    $u = currentUser();
    if ($u && $u['role'] === 'ADMIN') {
        redirect(BASE_URL . '/admin/dashboard.php');
    }
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$email || !$password) {
        $error = 'Email and password are required.';
    } else {
        $stmt = $pdo->prepare("SELECT id, name, email, password_hash, role, status, can_write_prescription FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always run bcrypt to prevent user-enumeration via timing differences.
        $dummyHash = '$2y$12$invaliddummyhashvalue0000000000000000000000000000000000';
        $valid = verifyPassword($password, $user ? $user['password_hash'] : $dummyHash);

        if (!$user || !$valid) {
            $error = 'Invalid email or password.';
        } elseif ($user['role'] !== 'ADMIN') {
            $error = 'Access denied. This portal is for administrators only.';
        } elseif ($user['status'] === 'SUSPENDED') {
            $error = 'This account has been suspended.';
        } else {
            setAuthSession($user);
            redirect(BASE_URL . '/admin/dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — PalliCare</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .login-wrap { width: 100%; max-width: 400px; padding: 20px; }
        .login-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); padding: 36px 32px; }
        .login-brand { text-align: center; margin-bottom: 28px; }
        .login-brand .icon { font-size: 2.4rem; }
        .login-brand h1 { font-size: 1.3rem; font-weight: 700; color: #1a2340; margin: 6px 0 2px; }
        .login-brand p { font-size: 0.82rem; color: #90a4ae; margin: 0; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 0.82rem; font-weight: 600; color: #455a64; margin-bottom: 5px; }
        .form-control { width: 100%; padding: 10px 13px; border: 1px solid #cfd8dc; border-radius: 6px; font-size: 0.9rem; transition: border-color 0.15s, box-shadow 0.15s; }
        .form-control:focus { outline: none; border-color: #1565c0; box-shadow: 0 0 0 3px rgba(21,101,192,0.12); }
        .btn-login { width: 100%; padding: 11px; background: #1a2340; color: #fff; border: none; border-radius: 6px; font-size: 0.95rem; font-weight: 600; cursor: pointer; transition: background 0.15s; margin-top: 4px; }
        .btn-login:hover { background: #253050; }
        .alert { padding: 11px 14px; border-radius: 6px; margin-bottom: 18px; font-size: 0.84rem; }
        .alert-error { background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; }
        .login-footer { text-align: center; margin-top: 18px; font-size: 0.78rem; color: #90a4ae; }
        .login-footer a { color: #1565c0; text-decoration: none; }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-brand">
            <div class="icon">🏥</div>
            <h1>PalliCare Admin</h1>
            <p>Administrator access only</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">⚠️ <?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" class="form-control"
                       value="<?= e($_POST['email'] ?? '') ?>"
                       placeholder="admin@pallicare.dev" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control"
                       placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn-login">Sign In →</button>
        </form>

        <div class="login-footer">
            <a href="<?= BASE_URL ?>/">← Back to main site</a>
        </div>
    </div>
</div>
</body>
</html>
