<?php
/*
 * admin/settings.php — System settings
 *
 * Auto-creates `settings` table if it doesn't exist:
 *
 * CREATE TABLE IF NOT EXISTS `settings` (
 *   `key`        VARCHAR(100) NOT NULL PRIMARY KEY,
 *   `value`      TEXT         DEFAULT NULL,
 *   `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 */

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

if (!isLoggedIn()) redirect(BASE_URL . '/admin/login.php');
$adminUser = requireAdmin();

// ── Ensure settings table exists ──────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `settings` (
        `key`        VARCHAR(100) NOT NULL PRIMARY KEY,
        `value`      TEXT         DEFAULT NULL,
        `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Helper: get / set setting ─────────────────────────────────────────────────
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ?");
    $s->execute([$key]);
    $val = $s->fetchColumn();
    return $val !== false ? (string) $val : $default;
}

function setSetting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare(
        "INSERT INTO settings (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()"
    )->execute([$key, $value]);
}

// ── Seed defaults if missing ──────────────────────────────────────────────────
$defaults = [
    'site_name'         => 'PalliCare',
    'admin_email'       => 'admin@pallicare.dev',
    'registration_open' => '1',
    'max_upload_mb'     => '5',
    'maintenance_mode'  => '0',
];
foreach ($defaults as $k => $v) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE `key` = ?");
    $check->execute([$k]);
    if (!(int) $check->fetchColumn()) {
        setSetting($pdo, $k, $v);
    }
}

$msg   = '';
$error = '';

// ── POST: save settings ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_settings') {
        $siteName          = trim($_POST['site_name']         ?? '');
        $adminEmail        = trim($_POST['admin_email']       ?? '');
        $registrationOpen  = isset($_POST['registration_open'])  ? '1' : '0';
        $maintenanceMode   = isset($_POST['maintenance_mode'])   ? '1' : '0';
        $maxUpload         = (int) ($_POST['max_upload_mb']   ?? 5);

        if (!$siteName) {
            $error = 'Site name is required.';
        } elseif ($adminEmail && !validateEmail($adminEmail)) {
            $error = 'Invalid admin email address.';
        } else {
            setSetting($pdo, 'site_name',         $siteName);
            setSetting($pdo, 'admin_email',        $adminEmail);
            setSetting($pdo, 'registration_open',  $registrationOpen);
            setSetting($pdo, 'maintenance_mode',   $maintenanceMode);
            setSetting($pdo, 'max_upload_mb',      (string) max(1, min(50, $maxUpload)));
            $msg = 'Settings saved successfully.';
        }
    }
}

// ── Load current settings ─────────────────────────────────────────────────────
$settings = [];
$rows = $pdo->query("SELECT `key`, `value`, updated_at FROM settings ORDER BY `key`")->fetchAll();
foreach ($rows as $row) {
    $settings[$row['key']] = $row;
}

function sv(array $settings, string $key, string $default = ''): string {
    return isset($settings[$key]) ? (string) $settings[$key]['value'] : $default;
}

$pageTitle = 'System Settings';
?>
<?php require __DIR__ . '/includes/header.php'; ?>

<?php if ($msg):  ?><div class="alert alert-success">✅ <?= e($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

<div class="card">
    <div class="card-title">System Settings</div>
    <form method="POST" style="max-width:580px;">
        <input type="hidden" name="action" value="save_settings">

        <fieldset style="border:none;padding:0;margin:0 0 20px;">
            <legend style="font-size:0.85rem;font-weight:700;color:#1a2340;padding:0;margin-bottom:12px;border-bottom:1px solid #f0f0f0;width:100%;padding-bottom:8px;">
                🏥 Application
            </legend>
            <div class="form-group">
                <label>Site Name *</label>
                <input type="text" name="site_name" class="form-control"
                       value="<?= e(sv($settings, 'site_name', 'PalliCare')) ?>" required maxlength="100">
                <span class="text-muted">Displayed in the browser title and emails.</span>
            </div>
            <div class="form-group">
                <label>Admin Contact Email</label>
                <input type="email" name="admin_email" class="form-control"
                       value="<?= e(sv($settings, 'admin_email', '')) ?>" maxlength="255">
                <span class="text-muted">Used for system notifications and support.</span>
            </div>
        </fieldset>

        <fieldset style="border:none;padding:0;margin:0 0 20px;">
            <legend style="font-size:0.85rem;font-weight:700;color:#1a2340;padding:0;margin-bottom:12px;border-bottom:1px solid #f0f0f0;width:100%;padding-bottom:8px;">
                🔒 Access Control
            </legend>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:500;">
                    <input type="checkbox" name="registration_open" value="1"
                           <?= sv($settings, 'registration_open', '1') === '1' ? 'checked' : '' ?>>
                    Open Registration
                </label>
                <span class="text-muted">When unchecked, new users cannot register. Existing users are unaffected.</span>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:500;">
                    <input type="checkbox" name="maintenance_mode" value="1"
                           <?= sv($settings, 'maintenance_mode', '0') === '1' ? 'checked' : '' ?>>
                    Maintenance Mode
                </label>
                <span class="text-muted">Displays a maintenance notice to non-admin users.</span>
            </div>
        </fieldset>

        <fieldset style="border:none;padding:0;margin:0 0 20px;">
            <legend style="font-size:0.85rem;font-weight:700;color:#1a2340;padding:0;margin-bottom:12px;border-bottom:1px solid #f0f0f0;width:100%;padding-bottom:8px;">
                📁 Upload Limits
            </legend>
            <div class="form-group">
                <label>Max Upload Size (MB)</label>
                <input type="number" name="max_upload_mb" class="form-control"
                       value="<?= e(sv($settings, 'max_upload_mb', '5')) ?>"
                       min="1" max="50" style="width:140px;">
                <span class="text-muted">Maximum file upload size per request.</span>
            </div>
        </fieldset>

        <button type="submit" class="btn btn-primary">💾 Save Settings</button>
    </form>
</div>

<!-- All Settings (raw view) -->
<div class="card">
    <div class="card-title">All Settings (<?= count($settings) ?>)</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Key</th><th>Value</th><th>Last Updated</th></tr></thead>
            <tbody>
            <?php if ($settings): ?>
                <?php foreach ($settings as $key => $row): ?>
                <tr>
                    <td><code style="font-size:0.82rem;background:#f5f5f5;padding:2px 6px;border-radius:3px;"><?= e($key) ?></code></td>
                    <td><?= e($row['value'] ?? '') ?></td>
                    <td class="text-muted"><?= date('d M Y H:i', strtotime($row['updated_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="3" class="table-empty">No settings.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
