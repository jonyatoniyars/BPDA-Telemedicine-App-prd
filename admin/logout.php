<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../api/auth.php';

clearAuthSession();
redirect(BASE_URL . '/admin/login.php');
