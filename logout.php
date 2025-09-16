<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$database = new Database();
$auth = new Auth($database);
$auth->logout();
?>