<?php
/**
 * MoneyLend - Logout Handler
 * 
 * Securely terminates the user session and cleans up session cookies
 * before redirecting back to the login screen.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Log activity prior to destroying session
if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $uName = $_SESSION['user_name'] ?? 'User';
    log_activity(getDBConnection(), 'logout', 'user', $uid, 'User signed out: ' . $uName, $uid);
}

// Destroy session and remove session cookies
logout_user();

// Redirect back to login with logout feedback
header("Location: " . BASE_URL . "login.php?logged_out=1");
exit;
