<?php
session_start();

$has_session = isset($_SESSION['user_id']) || (isset($_SESSION['guest']) && $_SESSION['guest'] === true);

if (!$has_session) {
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    header('Location: ' . $base . '/auth/login.php');
    exit;
}
?>