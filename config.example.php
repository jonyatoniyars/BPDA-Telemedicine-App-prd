<?php
/**
 * PalliCare — configuration template.
 *
 * COPY THIS FILE TO `config.php` AND FILL IN YOUR REAL VALUES.
 * `config.php` is gitignored and must never be committed with real secrets.
 *
 * On Namecheap shared hosting, DB_HOST is almost always 'localhost'.
 */

// ── Database ─────────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db');
define('DB_USER', 'your_user');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ── Application ──────────────────────────────────────────────────────────────
// Public base URL, no trailing slash (e.g. https://yourdomain.com or https://yourdomain.com/pallicare)
define('BASE_URL', 'https://yourdomain.com');

// ── Secrets ──────────────────────────────────────────────────────────────────
// Replace every value below with a long, random string (30+ characters).
define('JWT_SECRET', 'CHANGE_ME_TO_A_LONG_RANDOM_STRING');
define('JWT_REFRESH_SECRET', 'CHANGE_ME_TO_A_LONG_RANDOM_STRING');

// Installer token — also settable via the INSTALLER_TOKEN environment variable.
// Set this to your own private secret before running admin/install.php.
define('INSTALLER_TOKEN', 'CHANGE_ME_TO_A_LONG_RANDOM_STRING');
