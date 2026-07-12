<?php
// includes/sidebar.php — role-aware responsive sidebar
// Expects $user (array) to be set by the including page.
$role    = $user['role'] ?? '';
$curPath = basename($_SERVER['PHP_SELF']);

function sidebarLink(string $href, string $icon, string $label, string $cur): string {
    $active = (basename($href) === $cur) ? ' sidebar-link--active' : '';
    return '<a href="' . $href . '" class="sidebar-link' . $active . '">'
         . '<span class="sidebar-link__icon">' . $icon . '</span>'
         . '<span class="sidebar-link__label">' . e($label) . '</span>'
         . '</a>';
}
?>
<aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">
    <div class="sidebar__header">
        <a href="<?= BASE_URL ?>/pages/dashboard.php" class="sidebar__brand">
            <span class="sidebar__brand-icon">🏥</span>
            <span class="sidebar__brand-name"><?= e(APP_NAME) ?></span>
        </a>
        <button class="sidebar__close btn btn--ghost" id="sidebarClose" aria-label="Close menu">✕</button>
    </div>

    <nav class="sidebar__nav">
        <?= sidebarLink(BASE_URL . '/pages/dashboard.php',       '📊', 'Dashboard',    $curPath) ?>
        <?= sidebarLink(BASE_URL . '/pages/prescriptions.php',   '📋', 'Prescriptions', $curPath) ?>
        <?= sidebarLink(BASE_URL . '/pages/video-calls.php',     '📹', 'Video Calls',   $curPath) ?>

        <?php if ($role === 'ADMIN'): ?>
            <?= sidebarLink(BASE_URL . '/pages/medicines.php',   '💊', 'Medicines',     $curPath) ?>
            <?= sidebarLink(BASE_URL . '/pages/users.php',       '👥', 'Users',         $curPath) ?>
            <?= sidebarLink(BASE_URL . '/pages/assignments.php', '🔗', 'Assignments',   $curPath) ?>
        <?php endif ?>
    </nav>

    <div class="sidebar__footer">
        <div class="sidebar__user-card">
            <div class="sidebar__user-avatar" aria-hidden="true">
                <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
            </div>
            <div class="sidebar__user-info">
                <span class="sidebar__user-name"><?= e($user['name'] ?? '') ?></span>
                <span class="sidebar__user-role badge badge--<?= strtolower(str_replace('_', '-', $role)) ?>">
                    <?= e(str_replace('_', ' ', $role)) ?>
                </span>
            </div>
        </div>
        <form method="post" action="<?= BASE_URL ?>/api/auth-endpoints.php" class="sidebar__logout">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn btn--ghost btn--danger btn--sm sidebar__logout-btn">
                <span>🚪</span> Logout
            </button>
        </form>
    </div>
</aside>
<div class="sidebar__overlay" id="sidebarOverlay"></div>
