<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

$database = new Database();
$auth = new Auth($database);

if ($auth->isLoggedIn()) {
    $user_info = $auth->getUserInfo();
    switch ($user_info['type']) {
        case 'admin':
            header('Location: /admin/dashboard.php');
            break;
        case 'branch':
            header('Location: /branch/dashboard.php');
            break;
        case 'employee':
            header('Location: /employee/dashboard.php');
            break;
    }
    exit;
} else {
    header('Location: /login.php');
    exit;
}
?>