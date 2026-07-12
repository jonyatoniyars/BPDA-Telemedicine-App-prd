<?php
// PalliCare cPanel Configuration
// Edit these values with your cPanel MySQL credentials before uploading.

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_cpanel_db_name');
define('DB_USER', 'your_cpanel_db_user');
define('DB_PASS', 'your_cpanel_db_password');
define('DB_CHARSET', 'utf8mb4');

// Application
// Detect base URL automatically; override if needed.
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
// Strip known app subdirectory suffixes so BASE_URL always points at the app root,
// regardless of which file (index.php, admin/login.php, pages/login.php, api/*.php, …) is running.
// e.g.  /pallicare/admin  →  /pallicare
//        /admin            →  (empty = site root)
//        /pallicare        →  /pallicare
$appRoot = preg_replace('#/(admin|pages|api|includes|assets)$#', '', $scriptDir);
define('BASE_URL', $protocol . '://' . $host . $appRoot);

define('APP_NAME', 'PalliCare');
define('APP_VERSION', '1.0.0');

// Security
// Change these to long random strings in production.
define('JWT_ACCESS_SECRET',  getenv('JWT_ACCESS_SECRET')  ?: 'change-this-access-secret-min-32-chars!');
define('JWT_REFRESH_SECRET', getenv('JWT_REFRESH_SECRET') ?: 'change-this-refresh-secret-min-32-chars!');

define('SESSION_LIFETIME', 7 * 24 * 60 * 60); // 7 days
