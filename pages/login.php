<?php
// pages/login.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

// Already logged in? Go to dashboard.
if (isLoggedIn()) {
    redirect(BASE_URL . '/pages/dashboard.php');
}

$pageTitle   = 'Login';
$error       = $_SESSION['auth_error'] ?? null;
$success     = $_SESSION['reg_success'] ?? null;
$registered  = isset($_GET['registered']);
$pending     = isset($_GET['reason']) && $_GET['reason'] === 'pending';

unset($_SESSION['auth_error'], $_SESSION['reg_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="auth-page">
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-card__header">
            <span class="auth-card__logo" aria-hidden="true">🏥</span>
            <h1 class="auth-card__title"><?= e(APP_NAME) ?></h1>
            <p class="auth-card__subtitle">Sign in to your account</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert--error" role="alert"><?= e($error) ?></div>
        <?php endif ?>
        <?php if ($success || $registered): ?>
            <div class="alert alert--success" role="status">
                <?= e($success ?: 'Registration submitted. Please wait for approval.') ?>
            </div>
        <?php endif ?>
        <?php if ($pending): ?>
            <div class="alert alert--warning" role="status">
                Your account is awaiting admin approval.
            </div>
        <?php endif ?>

        <form method="post" action="<?= BASE_URL ?>/api/auth-endpoints.php" class="auth-form" novalidate>
            <input type="hidden" name="action" value="login">

            <div class="form-group">
                <label for="identifier" class="form-label">Email or Phone</label>
                <input
                    type="text"
                    id="identifier"
                    name="identifier"
                    class="form-control"
                    placeholder="email@example.com or 01XXXXXXXXX"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        required
                    >
                    <button type="button" class="btn btn--ghost btn--sm input-group__addon" id="togglePassword" aria-label="Toggle password visibility">👁</button>
                </div>
            </div>

            <button type="submit" class="btn btn--primary btn--full">Sign In</button>
        </form>

        <p class="auth-card__footer-link">
            Don't have an account?
            <a href="<?= BASE_URL ?>/pages/register.php">Register here</a>
        </p>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<script>
document.getElementById('togglePassword').addEventListener('click', function () {
    const pw = document.getElementById('password');
    pw.type = pw.type === 'password' ? 'text' : 'password';
});
</script>
</body>
</html>
