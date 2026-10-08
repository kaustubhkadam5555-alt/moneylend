<?php
/**
 * MoneyLend - Application Configuration
 *
 * Tagline: "Simple Lending. Smarter Tracking."
 *
 * Central configuration file for application settings, URLs,
 * timezone, and regional settings.
 */

// Prevent duplicate execution
if (!defined('APP_NAME')) {
    require_once __DIR__ . '/database.php';
    require_once __DIR__ . '/../includes/settings_helper.php';
    require_once __DIR__ . '/../includes/activity_helper.php';
    require_once __DIR__ . '/../includes/mail_helper.php';
    require_once __DIR__ . '/../includes/notification_helper.php';

    $appName = get_setting('app_name', 'MoneyLend');
    $currencySymbol = get_setting('currency_symbol', '₹');
    $currencyCode = ($currencySymbol === '₹') ? 'INR' : 'USD';
    $dateFormat = get_setting('date_format', 'd/m/Y');

    // Application branding
    define('APP_NAME', !empty($appName) ? $appName : 'MoneyLend');
    define('APP_TAGLINE', 'Simple Lending. Smarter Tracking.');
    define('APP_VERSION', '1.3.0');

    // Base URL configuration (Points to the web root of this application)
    // Adjust via environment variable APP_URL or default to /moneylend/
    if (!defined('BASE_URL')) {
        $envAppUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '');
        if (!empty($envAppUrl)) {
            $parsedPath = parse_url($envAppUrl, PHP_URL_PATH);
            $baseUrlPath = !empty($parsedPath) ? rtrim($parsedPath, '/') . '/' : '/';
            define('BASE_URL', $baseUrlPath);
        } else {
            define('BASE_URL', '/moneylend/');
        }
    }

    // Timezone Configuration (India Standard Time)
    date_default_timezone_set('Asia/Kolkata');

    // Currency Configuration
    define('CURRENCY_SYMBOL', !empty($currencySymbol) ? $currencySymbol : '₹');
    define('CURRENCY_CODE', $currencyCode);
    define('APP_DATE_FORMAT', !empty($dateFormat) ? $dateFormat : 'd/m/Y');

    // Environment and Error Reporting
    // Set APP_ENV to 'production' for live deployment
    $envAppEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'development');
    if (!defined('APP_ENV')) {
        define('APP_ENV', in_array($envAppEnv, ['production', 'development', 'testing'], true) ? $envAppEnv : 'development');
    }

    $envAppDebug = getenv('APP_DEBUG');
    if ($envAppDebug === false && isset($_ENV['APP_DEBUG'])) {
        $envAppDebug = $_ENV['APP_DEBUG'];
    }
    $isDebug = (APP_ENV === 'development') && ($envAppDebug !== 'false' && $envAppDebug !== '0');
    if (!defined('APP_DEBUG')) {
        define('APP_DEBUG', $isDebug);
    }

    if (APP_DEBUG) {
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);
    } else {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        error_reporting(0);
    }
}
