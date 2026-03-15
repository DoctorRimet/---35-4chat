<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';

if (isset($_COOKIE['auth_token'])) {
    $db = new Database();
    $conn = $db->getConnection();
    $user = new User($conn);
    $user->deleteSession($_COOKIE['auth_token']);
    setcookie('auth_token', '', time() - 3600, '/');
}

session_destroy();
header('Location: login.php?loggedout=1');
exit;
?>
