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
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    // Session hardening directives
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    // Configure session cookie parameters
    session_set_cookie_params([
        'lifetime' => 0,               // Session lasts until browser closes
        'path'     => BASE_URL,
        'httponly' => true,            // Mitigate XSS cookie theft
        'samesite' => 'Lax',           // Mitigate CSRF
        'secure'   => $isHttps,        // Enable Secure flag when HTTPS is active
    ]);

    session_start();
}

// Send standard defense-in-depth security headers if not already sent
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('X-XSS-Protection: 1; mode=block');
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
 * Safely redirect to a URL and terminate script execution.
 *
 * @param string $url
 * @return void
 */
function redirect(string $url): void {
    // Sanitize destination to prevent CRLF header injection
    $cleanUrl = str_replace(["\r", "\n"], '', $url);

    // Prevent open redirects via protocol-relative URLs (e.g. //attacker.com)
    if (str_starts_with($cleanUrl, '//') || str_starts_with($cleanUrl, '/\\')) {
        $cleanUrl = BASE_URL;
    }

    header("Location: " . $cleanUrl);
    exit;
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


/**
 * Generate or retrieve the CSRF token for the session.
 *
 * @return string
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Alias for csrf_token() for consistency across modules.
 *
 * @return string
 */
function generate_csrf_token(): string {
    return csrf_token();
}


/**
 * Validate a submitted CSRF token.
 *
 * @param string|null $token
 * @return bool
 */
function verify_csrf_token(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate hidden input HTML field for CSRF token.
 *
 * @return string
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
