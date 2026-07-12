<?php
// Admin shared header — included after requireAdmin() so $adminUser is available
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?>PalliCare Admin</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; margin: 0; color: #37474f; }
        .admin-layout { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar { width: 240px; background: #1a2340; color: #fff; flex-shrink: 0; display: flex; flex-direction: column; }
        .sidebar-brand { padding: 20px 22px 18px; font-size: 1.15rem; font-weight: 700; color: #fff; border-bottom: 1px solid #2d3a5c; line-height: 1.3; }
        .sidebar-brand small { display: block; font-size: 0.68rem; font-weight: 400; color: #78909c; text-transform: uppercase; letter-spacing: 1px; margin-top: 2px; }
        .sidebar-nav { list-style: none; padding: 10px 0; margin: 0; flex: 1; }
        .sidebar-nav a { display: block; padding: 10px 22px; color: #b0bec5; text-decoration: none; font-size: 0.875rem; transition: background 0.15s, color 0.15s; border-left: 3px solid transparent; }
        .sidebar-nav a:hover { background: #253050; color: #e0e0e0; }
        .sidebar-nav a.active { background: #253050; color: #fff; border-left-color: #4fc3f7; }
        .nav-section { padding: 14px 22px 4px; font-size: 0.68rem; text-transform: uppercase; color: #546e7a; letter-spacing: 1.2px; }
        .sidebar-footer { padding: 14px 22px; border-top: 1px solid #2d3a5c; font-size: 0.78rem; color: #546e7a; }

        /* Main content */
        .main-content { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .topbar { background: #fff; padding: 13px 28px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e0e0e0; box-shadow: 0 1px 3px rgba(0,0,0,0.07); position: sticky; top: 0; z-index: 100; }
        .topbar h1 { font-size: 1.05rem; font-weight: 600; color: #1a2340; margin: 0; }
        .topbar-user { font-size: 0.82rem; color: #546e7a; display: flex; align-items: center; gap: 14px; }
        .topbar-user a { color: #c62828; text-decoration: none; font-weight: 500; }
        .topbar-user a:hover { text-decoration: underline; }
        .content-area { padding: 26px 28px; flex: 1; }

        /* Cards */
        .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.07); padding: 22px 24px; margin-bottom: 22px; }
        .card-title { font-size: 0.95rem; font-weight: 600; color: #1a2340; margin: 0 0 16px; padding-bottom: 12px; border-bottom: 1px solid #f0f0f0; }
        .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #f0f0f0; }
        .card-header h2 { font-size: 0.95rem; font-weight: 600; color: #1a2340; margin: 0; }

        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.07); display: flex; flex-direction: column; align-items: flex-start; }
        .stat-card .stat-icon { font-size: 1.6rem; margin-bottom: 8px; }
        .stat-card .stat-num { font-size: 1.9rem; font-weight: 700; line-height: 1; }
        .stat-card .stat-label { font-size: 0.78rem; color: #78909c; margin-top: 5px; }
        .stat-blue .stat-num { color: #1565c0; }
        .stat-green .stat-num { color: #2e7d32; }
        .stat-orange .stat-num { color: #e65100; }
        .stat-purple .stat-num { color: #6a1b9a; }
        .stat-red .stat-num { color: #c62828; }

        /* Quick links */
        .quick-links { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 14px; }
        .quick-link { display: flex; flex-direction: column; align-items: center; background: #fff; border-radius: 8px; padding: 20px 14px; text-align: center; text-decoration: none; color: #1a2340; box-shadow: 0 1px 4px rgba(0,0,0,0.07); transition: transform 0.15s, box-shadow 0.15s; }
        .quick-link:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.12); }
        .quick-link .ql-icon { font-size: 1.7rem; margin-bottom: 8px; }
        .quick-link .ql-label { font-size: 0.82rem; font-weight: 600; }

        /* Table */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.86rem; }
        table th { background: #f8f9fa; padding: 10px 13px; text-align: left; color: #455a64; font-weight: 600; border-bottom: 2px solid #e9ecef; white-space: nowrap; }
        table td { padding: 9px 13px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
        table tr:last-child td { border-bottom: none; }
        table tr:hover td { background: #fafbfc; }
        .table-empty { text-align: center; color: #90a4ae; padding: 28px; font-size: 0.88rem; }

        /* Buttons */
        .btn { display: inline-block; padding: 7px 15px; border-radius: 5px; font-size: 0.82rem; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: opacity 0.15s, transform 0.1s; line-height: 1.4; vertical-align: middle; }
        .btn:hover { opacity: 0.85; }
        .btn:active { transform: scale(0.98); }
        .btn-primary   { background: #1565c0; color: #fff; }
        .btn-success   { background: #2e7d32; color: #fff; }
        .btn-warning   { background: #f57c00; color: #fff; }
        .btn-danger    { background: #c62828; color: #fff; }
        .btn-secondary { background: #607d8b; color: #fff; }
        .btn-info      { background: #0277bd; color: #fff; }
        .btn-outline   { background: transparent; color: #546e7a; border: 1px solid #cfd8dc; }
        .btn-outline:hover { background: #f5f5f5; }
        .btn-sm { padding: 4px 10px; font-size: 0.77rem; }
        .btn-xs { padding: 2px 7px; font-size: 0.73rem; }

        /* Badges */
        .badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 0.73rem; font-weight: 600; }
        .badge-active    { background: #e8f5e9; color: #2e7d32; }
        .badge-pending   { background: #fff8e1; color: #f57f17; }
        .badge-suspended { background: #ffebee; color: #c62828; }
        .badge-draft     { background: #f3e5f5; color: #6a1b9a; }
        .badge-submitted { background: #e3f2fd; color: #1565c0; }
        .badge-reviewed  { background: #e8f5e9; color: #2e7d32; }
        .badge-admin { background: #e3f2fd; color: #1565c0; }
        .badge-doctor { background: #f3e5f5; color: #6a1b9a; }
        .badge-hw { background: #e0f7fa; color: #00695c; }

        /* Forms */
        .form-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 0.82rem; font-weight: 600; color: #455a64; margin-bottom: 5px; }
        .form-control { width: 100%; padding: 8px 11px; border: 1px solid #cfd8dc; border-radius: 5px; font-size: 0.88rem; background: #fff; transition: border-color 0.15s, box-shadow 0.15s; }
        .form-control:focus { outline: none; border-color: #1565c0; box-shadow: 0 0 0 2px rgba(21,101,192,0.12); }
        select.form-control { cursor: pointer; }
        textarea.form-control { resize: vertical; min-height: 80px; }

        /* Alerts */
        .alert { padding: 11px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 0.86rem; display: flex; align-items: flex-start; gap: 8px; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border: 1px solid #a5d6a7; }
        .alert-error   { background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; }
        .alert-info    { background: #e3f2fd; color: #0d47a1; border: 1px solid #90caf9; }
        .alert-warning { background: #fff8e1; color: #e65100; border: 1px solid #ffcc02; }

        /* Filters bar */
        .filters-bar { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 16px; padding: 14px 16px; background: #f8f9fa; border-radius: 6px; }
        .filters-bar .form-group { margin-bottom: 0; }
        .filters-bar .form-control { min-width: 140px; }

        /* Modals */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .modal-box { background: #fff; border-radius: 10px; padding: 26px 28px; width: 90%; max-width: 520px; max-height: 90vh; overflow-y: auto; box-shadow: 0 8px 32px rgba(0,0,0,0.18); }
        .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid #f0f0f0; }
        .modal-header h3 { margin: 0; font-size: 1rem; color: #1a2340; }
        .modal-close { background: none; border: none; font-size: 1.3rem; cursor: pointer; color: #90a4ae; line-height: 1; padding: 0; }
        .modal-close:hover { color: #546e7a; }
        .modal-footer { margin-top: 18px; padding-top: 14px; border-top: 1px solid #f0f0f0; display: flex; justify-content: flex-end; gap: 10px; }

        /* Detail view */
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; font-size: 0.86rem; }
        .detail-item label { display: block; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.6px; color: #90a4ae; margin-bottom: 2px; }
        .detail-item span { color: #1a2340; font-weight: 500; }
        .detail-full { grid-column: 1 / -1; }

        /* Pagination */
        .pagination { display: flex; gap: 6px; align-items: center; margin-top: 16px; }
        .pagination a, .pagination span { display: inline-block; padding: 5px 11px; border-radius: 4px; font-size: 0.82rem; text-decoration: none; }
        .pagination a { background: #fff; border: 1px solid #cfd8dc; color: #546e7a; }
        .pagination a:hover { background: #f5f5f5; }
        .pagination span.current { background: #1565c0; color: #fff; border: 1px solid #1565c0; }
        .pagination span.disabled { color: #bdbdbd; border: 1px solid #eeeeee; }

        /* Utils */
        .flex-between { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .flex-row { display: flex; align-items: center; gap: 10px; }
        .text-muted { color: #90a4ae; font-size: 0.8rem; }
        .text-right { text-align: right; }
        .mt-0 { margin-top: 0; }
        .mb-0 { margin-bottom: 0; }
        .w-100 { width: 100%; }
        hr.divider { border: none; border-top: 1px solid #f0f0f0; margin: 16px 0; }

        @media (max-width: 768px) {
            .sidebar { width: 200px; }
            .content-area { padding: 16px; }
            .detail-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .sidebar { display: none; }
        }
    </style>
</head>
<body>
<div class="admin-layout">
    <aside class="sidebar">
        <div class="sidebar-brand">🏥 PalliCare <small>Admin Panel</small></div>
        <ul class="sidebar-nav">
            <li class="nav-section">Overview</li>
            <li><a href="<?= BASE_URL ?>/admin/dashboard.php"<?= $currentPage === 'dashboard.php' ? ' class="active"' : '' ?>>📊 Dashboard</a></li>
            <li class="nav-section">Management</li>
            <li><a href="<?= BASE_URL ?>/admin/users.php"<?= $currentPage === 'users.php' ? ' class="active"' : '' ?>>👥 Users</a></li>
            <li><a href="<?= BASE_URL ?>/admin/roles.php"<?= $currentPage === 'roles.php' ? ' class="active"' : '' ?>>🔑 Roles & Permissions</a></li>
            <li><a href="<?= BASE_URL ?>/admin/assignments.php"<?= $currentPage === 'assignments.php' ? ' class="active"' : '' ?>>🔗 Assignments</a></li>
            <li><a href="<?= BASE_URL ?>/admin/medicines.php"<?= $currentPage === 'medicines.php' ? ' class="active"' : '' ?>>💊 Medicines</a></li>
            <li><a href="<?= BASE_URL ?>/admin/prescriptions.php"<?= $currentPage === 'prescriptions.php' ? ' class="active"' : '' ?>>📋 Prescriptions</a></li>
            <li class="nav-section">System</li>
            <li><a href="<?= BASE_URL ?>/admin/settings.php"<?= $currentPage === 'settings.php' ? ' class="active"' : '' ?>>⚙️ Settings</a></li>
            <li><a href="<?= BASE_URL ?>/admin/logout.php">🚪 Logout</a></li>
        </ul>
        <div class="sidebar-footer">v1.0.0 · PalliCare</div>
    </aside>

    <div class="main-content">
        <div class="topbar">
            <h1><?= isset($pageTitle) ? e($pageTitle) : 'Admin Panel' ?></h1>
            <div class="topbar-user">
                👤 <?= e($adminUser['name'] ?? 'Admin') ?>
                <a href="<?= BASE_URL ?>/admin/logout.php">Logout</a>
            </div>
        </div>
        <div class="content-area">
