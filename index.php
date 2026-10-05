<?php
/**
 * MoneyLend - Application Entry Point
 * 
 * Directs traffic based on authentication status:
 * - Authenticated users are directed to dashboard.php
 * - Unauthenticated visitors are redirected to login.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header("Location: " . BASE_URL . "dashboard.php");
    exit;
} else {
    header("Location: " . BASE_URL . "login.php");
    exit;
}
