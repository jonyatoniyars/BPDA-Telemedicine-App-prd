<?php
/**
 * admin/install.php — PalliCare Setup Wizard
 *
 * ⚠️  SECURITY: Delete or rename this file after a successful install.
 *     e.g., rename install.php to install.php.done
 *
 * What this wizard does:
 *  1. Verifies DB connection
 *  2. Runs install.sql (creates/recreates all tables + seeds medicines)
 *  3. Seeds users: admin, 2 doctors, 2 health workers (bcrypt password123)
 *  4. Reports success or errors
 */

// Minimal bootstrap — don't use init.php to avoid DB crash before install
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    session_start();
}

// ── Simple installer auth: require a one-time token or allow if no users exist ──
// (Prevents public access if someone forgets to delete this file post-install)
$installerSecret = 'install-pallicare-2024'; // change this before deploying

// Gate: require ?token=<secret> in the URL to prevent unauthorized access.
if (!isset($_GET['token']) || !hash_equals($installerSecret, $_GET['token'])) {
    http_response_code(403);
    die('<!DOCTYPE html><html><body style="font-family:sans-serif;text-align:center;padding:60px">'
      . '<h2>403 Forbidden</h2>'
      . '<p>Access denied. Installer token required.<br>'
      . 'Provide the token defined in <code>admin/install.php</code> as the <code>?token=</code> query parameter.</p>'
      . '</body></html>');
}

$step    = (int) ($_GET['step'] ?? 1);
$msgs    = [];
$errors  = [];
$dbOk    = false;
$pdo     = null;

// ── Try DB connection ─────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $dbOk = true;
} catch (PDOException $e) {
    $errors[] = 'Database connection failed: ' . $e->getMessage();
    $errors[] = 'Please edit config.php with the correct DB credentials and try again.';
}

// ── POST: run installation ────────────────────────────────────────────────────
$installed   = false;
$tableReport = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbOk) {
    $action = $_POST['action'] ?? '';

    if ($action === 'run_install') {
        $force = isset($_POST['force_reinstall']);

        // ── Step 1: Run install.sql ──────────────────────────────────────────
        $sqlFile = __DIR__ . '/../install.sql';
        if (!file_exists($sqlFile)) {
            $errors[] = 'install.sql not found at: ' . $sqlFile;
        } else {
            $sql = file_get_contents($sqlFile);

            if (!$force) {
                // Check if users table already exists
                $tableExists = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
                if ($tableExists) {
                    $errors[] = 'Tables already exist. Check "Force Reinstall" to drop and recreate all tables (⚠️ ALL DATA WILL BE LOST).';
                }
            }

            if (empty($errors)) {
                // Split SQL into individual statements.
                // Remove standalone comment lines, then split on semicolons.
                $sqlClean   = preg_replace('/^--[^\n]*\n?/m', '', $sql);
                $statements = array_filter(
                    array_map('trim', explode(';', $sqlClean)),
                    fn($s) => $s !== ''
                );

                foreach ($statements as $stmt) {
                    if (trim($stmt) === '') continue;
                    try {
                        $pdo->exec($stmt);
                        // Extract table name from CREATE TABLE for report
                        if (preg_match('/CREATE TABLE.*?`(\w+)`/i', $stmt, $m)) {
                            $tableReport[] = '✅ Created table: ' . $m[1];
                        }
                    } catch (PDOException $e) {
                        // Non-fatal: log and continue (e.g., INSERT duplicates)
                        if (strpos($e->getMessage(), 'Duplicate') !== false ||
                            strpos($e->getMessage(), 'duplicate') !== false) {
                            continue;
                        }
                        $errors[] = 'SQL error: ' . $e->getMessage();
                        $errors[] = 'Statement: ' . substr($stmt, 0, 200);
                        break;
                    }
                }
            }
        }

        // ── Step 2: Seed users (only if no SQL errors) ───────────────────────
        if (empty($errors)) {
            $hash = password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]);

            $seedUsers = [
                [
                    'id'       => 'admin_001',
                    'name'     => 'System Admin',
                    'email'    => 'admin@pallicare.dev',
                    'phone'    => null,
                    'role'     => 'ADMIN',
                    'status'   => 'ACTIVE',
                    'can_rx'   => 0,
                ],
                [
                    'id'       => 'doc_001',
                    'name'     => 'Dr. Farhan Rahman',
                    'email'    => 'doctor1@pallicare.dev',
                    'phone'    => '01711111111',
                    'role'     => 'DOCTOR',
                    'status'   => 'ACTIVE',
                    'can_rx'   => 0,
                ],
                [
                    'id'       => 'doc_002',
                    'name'     => 'Dr. Nusrat Jahan',
                    'email'    => 'doctor2@pallicare.dev',
                    'phone'    => '01722222222',
                    'role'     => 'DOCTOR',
                    'status'   => 'ACTIVE',
                    'can_rx'   => 0,
                ],
                [
                    'id'       => 'hw_001',
                    'name'     => 'Karim Uddin',
                    'email'    => 'hw1@pallicare.dev',
                    'phone'    => '01833333333',
                    'role'     => 'HEALTH_WORKER',
                    'status'   => 'ACTIVE',
                    'can_rx'   => 1,
                ],
                [
                    'id'       => 'hw_002',
                    'name'     => 'Sultana Begum',
                    'email'    => 'hw2@pallicare.dev',
                    'phone'    => '01844444444',
                    'role'     => 'HEALTH_WORKER',
                    'status'   => 'ACTIVE',
                    'can_rx'   => 0,
                ],
            ];

            $insertUser = $pdo->prepare(
                "INSERT IGNORE INTO users (id, name, email, phone, password_hash, role, status, can_write_prescription)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            foreach ($seedUsers as $u) {
                $insertUser->execute([
                    $u['id'], $u['name'], $u['email'], $u['phone'],
                    $hash, $u['role'], $u['status'], $u['can_rx'],
                ]);
                $msgs[] = "👤 Seeded user: {$u['name']} ({$u['email']}) — {$u['role']}";
            }

            // ── Step 3: Sample assignment ─────────────────────────────────────
            $pdo->prepare(
                "INSERT IGNORE INTO doctor_assignments (id, doctor_id, health_worker_id) VALUES (?,?,?)"
            )->execute(['asg_001', 'doc_001', 'hw_001']);
            $pdo->prepare(
                "INSERT IGNORE INTO doctor_assignments (id, doctor_id, health_worker_id) VALUES (?,?,?)"
            )->execute(['asg_002', 'doc_002', 'hw_002']);
            $msgs[] = '🔗 Created sample assignments.';

            // ── Step 4: Create settings table ────────────────────────────────
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `settings` (
                    `key`        VARCHAR(100) NOT NULL PRIMARY KEY,
                    `value`      TEXT         DEFAULT NULL,
                    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $defaultSettings = [
                'site_name'         => 'PalliCare',
                'admin_email'       => 'admin@pallicare.dev',
                'registration_open' => '1',
                'max_upload_mb'     => '5',
                'maintenance_mode'  => '0',
            ];
            $insertSetting = $pdo->prepare(
                "INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)"
            );
            foreach ($defaultSettings as $k => $v) {
                $insertSetting->execute([$k, $v]);
            }
            $msgs[] = '⚙️ Settings table created with defaults.';

            $installed = true;
        }
    }

    // ── Self-rename after successful install ──────────────────────────────────
    if ($installed && isset($_POST['self_delete'])) {
        $thisFile = __FILE__;
        $donePath = $thisFile . '.done';
        if (!file_exists($donePath)) {
            rename($thisFile, $donePath);
            // After rename, redirect to admin
            header('Location: ' . BASE_URL . '/admin/login.php');
            exit;
        }
    }
}

// ── Check existing tables ─────────────────────────────────────────────────────
$existingTables = [];
if ($dbOk) {
    $existingTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PalliCare — Installation Wizard</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f2f5; margin: 0; padding: 30px 16px; }
        .container { max-width: 720px; margin: 0 auto; }
        h1 { font-size: 1.4rem; color: #1a2340; margin: 0 0 6px; }
        .subtitle { font-size: 0.88rem; color: #78909c; margin-bottom: 28px; }
        .card { background: #fff; border-radius: 10px; padding: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.09); margin-bottom: 22px; }
        .card h2 { font-size: 1rem; color: #1a2340; margin: 0 0 16px; padding-bottom: 10px; border-bottom: 1px solid #f0f0f0; }
        .status-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f8f8f8; font-size: 0.88rem; }
        .status-row:last-child { border-bottom: none; }
        .status-ok   { color: #2e7d32; font-weight: 600; }
        .status-fail { color: #c62828; font-weight: 600; }
        .status-warn { color: #e65100; font-weight: 600; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 14px; font-size: 0.86rem; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border: 1px solid #a5d6a7; }
        .alert-error   { background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; }
        .alert-warning { background: #fff8e1; color: #e65100; border: 1px solid #ffcc02; }
        .alert-info    { background: #e3f2fd; color: #0d47a1; border: 1px solid #90caf9; }
        ul.log { list-style: none; padding: 0; margin: 0; font-size: 0.85rem; }
        ul.log li { padding: 5px 0; border-bottom: 1px solid #f8f8f8; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: flex; align-items: center; gap: 8px; font-size: 0.88rem; cursor: pointer; }
        .btn { display: inline-block; padding: 10px 22px; background: #1a2340; color: #fff; border: none; border-radius: 6px; font-size: 0.92rem; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #253050; }
        .btn-danger { background: #c62828; }
        .btn-success { background: #2e7d32; }
        .warn-box { background: #fff8e1; border: 1px solid #ffcc02; border-radius: 8px; padding: 14px 16px; font-size: 0.86rem; color: #5d4037; margin-bottom: 18px; }
        .credentials { background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 6px; padding: 14px; font-size: 0.88rem; }
        .credentials table { width: 100%; border-collapse: collapse; }
        .credentials td { padding: 5px 8px; }
        .credentials td:first-child { font-weight: 600; color: #455a64; width: 40%; }
        code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-size: 0.84rem; }
        .badge-green  { display: inline-block; background: #e8f5e9; color: #2e7d32; padding: 1px 8px; border-radius: 10px; font-size: 0.76rem; font-weight: 600; }
        .badge-red    { display: inline-block; background: #ffebee; color: #c62828; padding: 1px 8px; border-radius: 10px; font-size: 0.76rem; font-weight: 600; }
        .badge-orange { display: inline-block; background: #fff8e1; color: #e65100; padding: 1px 8px; border-radius: 10px; font-size: 0.76rem; font-weight: 600; }
    </style>
</head>
<body>
<div class="container">
    <h1>🏥 PalliCare Installation Wizard</h1>
    <p class="subtitle">This wizard will set up your database tables and seed initial data.</p>

    <?php if ($installed): ?>
    <!-- ── SUCCESS ───────────────────────────────────────────────────────── -->
    <div class="alert alert-success">
        <strong>✅ Installation completed successfully!</strong>
    </div>

    <div class="card">
        <h2>Installation Log</h2>
        <?php if ($tableReport): ?>
        <ul class="log">
            <?php foreach ($tableReport as $r): ?><li><?= e($r) ?></li><?php endforeach; ?>
        </ul>
        <hr style="border:none;border-top:1px solid #f0f0f0;margin:12px 0;">
        <?php endif; ?>
        <ul class="log">
            <?php foreach ($msgs as $m): ?><li><?= e($m) ?></li><?php endforeach; ?>
        </ul>
    </div>

    <div class="card">
        <h2>Default Login Credentials</h2>
        <p style="font-size:0.84rem;color:#78909c;margin-top:0;">All users are seeded with the password <code>password123</code>. Change them immediately after login.</p>
        <div class="credentials">
            <table>
                <tr><td>Admin</td><td><code>admin@pallicare.dev</code> / <code>password123</code></td></tr>
                <tr><td>Doctor 1</td><td><code>doctor1@pallicare.dev</code> / <code>password123</code></td></tr>
                <tr><td>Doctor 2</td><td><code>doctor2@pallicare.dev</code> / <code>password123</code></td></tr>
                <tr><td>Health Worker 1</td><td><code>hw1@pallicare.dev</code> / <code>password123</code> (Rx: enabled)</td></tr>
                <tr><td>Health Worker 2</td><td><code>hw2@pallicare.dev</code> / <code>password123</code> (Rx: disabled)</td></tr>
            </table>
        </div>
    </div>

    <div class="warn-box">
        ⚠️ <strong>Security:</strong> Please rename or delete <code>admin/install.php</code> now to prevent unauthorized reinstallation.
    </div>

    <form method="POST" style="display:inline" onsubmit="return confirm('Rename install.php to install.php.done and go to admin login?')">
        <input type="hidden" name="action" value="run_install">
        <input type="hidden" name="self_delete" value="1">
        <button type="submit" class="btn btn-danger">🔒 Rename install.php and Go to Admin →</button>
    </form>
    &nbsp;
    <a href="<?= BASE_URL ?>/admin/login.php" class="btn btn-success">Go to Admin Login →</a>

    <?php else: ?>

    <!-- ── PRE-INSTALL CHECKS ─────────────────────────────────────────────── -->
    <div class="card">
        <h2>Environment Checks</h2>

        <?php
        $checks = [
            ['PHP >= 7.4',    PHP_VERSION_ID >= 70400,   phpversion()],
            ['PDO extension', extension_loaded('pdo'),    null],
            ['PDO MySQL',     extension_loaded('pdo_mysql'), null],
            ['DB Connection', $dbOk,                      $dbOk ? DB_HOST . '/' . DB_NAME : 'Failed'],
            ['install.sql',   file_exists(__DIR__ . '/../install.sql'), null],
        ];
        foreach ($checks as [$label, $ok, $detail]): ?>
        <div class="status-row">
            <span><?= $ok ? '✅' : '❌' ?></span>
            <span><?= $label ?></span>
            <?php if ($detail): ?><span class="text-muted" style="font-size:0.8rem;color:#90a4ae;"><?= e($detail) ?></span><?php endif; ?>
            <span class="<?= $ok ? 'status-ok' : 'status-fail' ?>"><?= $ok ? 'OK' : 'FAIL' ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($existingTables)): ?>
    <div class="alert alert-warning">
        ⚠️ <strong>Existing tables detected:</strong>
        <?php foreach ($existingTables as $t): ?>
            <span class="badge-orange"><?= e($t) ?></span>
        <?php endforeach; ?>
        <br><small>Running the installer will DROP and recreate these tables unless "Force Reinstall" is unchecked (installer will abort if tables exist).</small>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <strong>⚠️ Errors:</strong>
        <ul style="margin:8px 0 0;padding-left:20px;">
            <?php foreach ($errors as $e_): ?><li><?= e($e_) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($dbOk): ?>

    <div class="card">
        <h2>Run Installation</h2>

        <div class="warn-box">
            ⚠️ <strong>Warning:</strong> The installer will execute <code>install.sql</code> which <strong>drops all existing tables</strong> before recreating them if "Force Reinstall" is checked.
            All existing data will be permanently lost. Only proceed on a fresh installation or when you intentionally want to reset the database.
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="run_install">

            <div class="form-group">
                <label>
                    <input type="checkbox" name="force_reinstall" value="1">
                    <strong>Force Reinstall</strong> — drop and recreate all tables (destroys existing data)
                </label>
            </div>

            <p style="font-size:0.86rem;color:#546e7a;margin-bottom:18px;">
                The following will be installed:
            </p>
            <ul style="font-size:0.85rem;color:#455a64;margin:0 0 18px;padding-left:20px;line-height:1.8;">
                <li>Database tables: <code>users</code>, <code>medicines</code>, <code>prescriptions</code>, <code>prescription_items</code>, <code>doctor_assignments</code>, <code>refresh_tokens</code>, <code>video_call_requests</code>, <code>audit_logs</code>, <code>settings</code></li>
                <li>20 sample medicines</li>
                <li>Admin user: <code>admin@pallicare.dev</code> / <code>password123</code></li>
                <li>2 sample doctors, 2 sample health workers (all <code>password123</code>)</li>
                <li>Sample doctor–HW assignments</li>
                <li>Default system settings</li>
            </ul>

            <button type="submit" class="btn"
                    onclick="return confirm('This will modify your database. Are you sure you want to proceed?')">
                🚀 Run Installation
            </button>
        </form>
    </div>

    <?php else: ?>
    <div class="alert alert-error">
        Cannot proceed — database connection failed. Please fix <code>config.php</code> and try again.
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
</body>
</html>
