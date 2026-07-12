<?php
// Admin entry point — redirect appropriately without triggering init.php routing.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

$proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$adminDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'), '/');

$isAdmin = isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])
        && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'ADMIN';

$dest = $isAdmin ? $adminDir . '/dashboard.php' : $adminDir . '/login.php';

header('Location: ' . $proto . '://' . $host . $dest);
exit;
