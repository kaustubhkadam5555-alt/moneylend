<?php
/**
 * MoneyLend - Authentication & Session Helper
 *
 * Provides session management, authentication status checks,
 * flash messaging, and authorization helpers.
 */

// Include base configuration if not already loaded
if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../config/config.php';
}

// Start PHP session securely if not already active
if (session_status() === PHP_SESSION_NONE) {
    // Configure session cookie parameters
    session_set_cookie_params([
        'lifetime' => 0,               // Session lasts until browser closes
        'path'     => BASE_URL,
        'httponly' => true,            // Mitigate XSS cookie theft
        'samesite' => 'Lax',           // Mitigate CSRF
    ]);

    session_start();
}

/**
 * Check if a user is currently logged in.
 *
 * @return bool
 */
function is_logged_in(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Require user to be logged in to view a page.
 * Redirects to the login page if unauthenticated.
 *
 * @return void
 */
function require_login(): void {
    if (!is_logged_in()) {
        header("Location: " . BASE_URL . "login.php");
        exit;
    }
}

/**
 * Get the currently logged-in user details.
 *
 * @return array|null
 */
function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }

    return [
        'id'    => $_SESSION['user_id'] ?? null,
        'name'  => $_SESSION['user_name'] ?? 'User',
        'email' => $_SESSION['user_email'] ?? '',
        'role'  => $_SESSION['user_role'] ?? 'admin',
    ];
}

/**
 * Establish a logged-in user session.
 *
 * @param array $user User record from database
 * @return void
 */
function login_user(array $user): void {
    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'] ?? 'admin';
}

/**
 * Log out the current user and destroy the session.
 *
 * @return void
 */
function logout_user(): void {
    $_SESSION = [];

    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}

/**
 * Set a flash notification message for the next request.
 *
 * @param string $type alert type ('success', 'danger', 'info', 'warning')
 * @param string $message message text
 * @return void
 */
function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

/**
 * Get and clear the flash notification message if set.
 *
 * @return array|null
 */
function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Helper to safely sanitize and escape text for HTML output.
 *
 * @param mixed $data
 * @return string
 */
function sanitize($data): string {
    return htmlspecialchars((string)($data ?? ''), ENT_QUOTES, 'UTF-8');
}
