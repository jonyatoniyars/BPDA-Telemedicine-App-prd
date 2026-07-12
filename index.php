<?php
require_once __DIR__ . '/init.php';

// Landing / router entry point
if (isset($_SESSION['user_id'])) {
    redirect(BASE_URL . '/pages/dashboard.php');
}
redirect(BASE_URL . '/pages/login.php');
