<?php
// pages/register.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

if (isLoggedIn()) {
    redirect(BASE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Register';
$errors    = $_SESSION['reg_errors'] ?? [];
$old       = $_SESSION['reg_old']    ?? [];

unset($_SESSION['reg_errors'], $_SESSION['reg_old']);
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
    <div class="auth-card auth-card--wide">
        <div class="auth-card__header">
            <span class="auth-card__logo" aria-hidden="true">🏥</span>
            <h1 class="auth-card__title"><?= e(APP_NAME) ?></h1>
            <p class="auth-card__subtitle">Create a new account</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert--error" role="alert">
                <ul class="alert__list">
                    <?php foreach ($errors as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach ?>
                </ul>
            </div>
        <?php endif ?>

        <form method="post" action="<?= BASE_URL ?>/api/auth-endpoints.php" class="auth-form" novalidate>
            <input type="hidden" name="action" value="register">

            <div class="form-group">
                <label for="name" class="form-label">Full Name <span class="required">*</span></label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    class="form-control"
                    value="<?= e($old['name'] ?? '') ?>"
                    placeholder="e.g. Dr. Fatima Khanam"
                    required
                >
            </div>

            <div class="form-group">
                <label for="email" class="form-label">Email Address <span class="required">*</span></label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-control"
                    value="<?= e($old['email'] ?? '') ?>"
                    placeholder="you@example.com"
                    autocomplete="email"
                    required
                >
            </div>

            <div class="form-group">
                <label for="phone" class="form-label">Phone <span class="text-muted">(optional)</span></label>
                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    class="form-control"
                    value="<?= e($old['phone'] ?? '') ?>"
                    placeholder="01XXXXXXXXX"
                    autocomplete="tel"
                >
            </div>

            <div class="form-group">
                <label for="role" class="form-label">Role <span class="required">*</span></label>
                <select id="role" name="role" class="form-control form-select" required>
                    <option value="" disabled <?= empty($old['role']) ? 'selected' : '' ?>>Select your role…</option>
                    <option value="HEALTH_WORKER" <?= ($old['role'] ?? '') === 'HEALTH_WORKER' ? 'selected' : '' ?>>Health Worker</option>
                    <option value="DOCTOR"        <?= ($old['role'] ?? '') === 'DOCTOR'        ? 'selected' : '' ?>>Doctor</option>
                </select>
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password <span class="required">*</span></label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    placeholder="Minimum 8 characters"
                    autocomplete="new-password"
                    required
                    minlength="8"
                >
                <span class="form-hint">At least 8 characters.</span>
            </div>

            <div class="alert alert--info" role="note">
                <strong>Note:</strong> Your account will be <strong>PENDING</strong> until an administrator approves it.
            </div>

            <button type="submit" class="btn btn--primary btn--full">Create Account</button>
        </form>

        <p class="auth-card__footer-link">
            Already have an account?
            <a href="<?= BASE_URL ?>/pages/login.php">Sign in</a>
        </p>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>
