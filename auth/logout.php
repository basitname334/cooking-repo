<?php
/**
 * Logout Page
 * Handles user logout
 */
require_once __DIR__ . '/../config/auth.php';

// Destroy session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit();
?>
