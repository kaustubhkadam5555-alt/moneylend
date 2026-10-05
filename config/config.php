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
    // Application branding
    define('APP_NAME', 'MoneyLend');
    define('APP_TAGLINE', 'Simple Lending. Smarter Tracking.');
    define('APP_VERSION', '1.0.0');

    // Base URL configuration (Points to the web root of this application)
    // Adjust if deployed to a virtual host or subfolder
    if (!defined('BASE_URL')) {
        define('BASE_URL', '/moneylend/');
    }

    // Timezone Configuration (India Standard Time)
    date_default_timezone_set('Asia/Kolkata');

    // Currency Configuration
    define('CURRENCY_SYMBOL', '₹');
    define('CURRENCY_CODE', 'INR');

    // Environment and Error Reporting
    // Set to 'development' during local development, change to 'production' for live deployment
    define('APP_ENV', 'development');

    if (APP_ENV === 'development') {
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);
    } else {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        error_reporting(0);
    }
}
