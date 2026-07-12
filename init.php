<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/api/response.php';

// Log all errors but never display them to the browser in production.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Session security
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    if (isApiRequest()) {
        jsonResponse(['success' => false, 'error' => ['code' => 'DB_ERROR', 'message' => 'Database connection failed']], 500);
    }
    die('<p style="color:red;text-align:center;margin-top:40px">Database connection failed. Please check config.php credentials.</p>');
}

// Timezone
date_default_timezone_set('Asia/Dhaka');

// Request helpers
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestPath = str_replace(dirname($_SERVER['SCRIPT_NAME']) === '/' ? '' : dirname($_SERVER['SCRIPT_NAME']), '', $requestUri);
$requestPath = trim($requestPath, '/');

// Default route: landing page / login
if ($requestPath === '' || $requestPath === 'index.php') {
    requireAuthOrGuest();
}
