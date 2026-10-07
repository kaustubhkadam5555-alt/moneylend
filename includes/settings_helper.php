<?php
/**
 * MoneyLend - Settings & Configuration Helper
 * 
 * Provides centralized functions to retrieve, update, and manage
 * persistent system settings, loan defaults, and formatting rules.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Get all system settings as key-value associative array.
 * Cached in memory for the duration of the request.
 *
 * @param bool $refresh Force refresh from database
 * @return array
 */
function get_all_settings(bool $refresh = false): array {
    static $settingsCache = null;

    if ($settingsCache !== null && !$refresh) {
        return $settingsCache;
    }

    $defaultSettings = [
        'app_name'               => 'MoneyLend',
        'currency_symbol'        => '₹',
        'currency_name'          => 'Indian Rupee (₹)',
        'date_format'            => 'd/m/Y',
        'default_interest_rate'  => '10.00',
        'default_interest_model' => 'flat',
        'default_duration_value' => '6',
        'default_duration_unit'  => 'months',
        'default_payment_method' => 'Cash',
    ];

    try {
        $pdo = getDBConnection();
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        $dbRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($dbRows && is_array($dbRows)) {
            $settingsCache = array_merge($defaultSettings, $dbRows);
        } else {
            $settingsCache = $defaultSettings;
        }
    } catch (Throwable $e) {
        error_log("Settings fetch error: " . $e->getMessage());
        $settingsCache = $defaultSettings;
    }

    return $settingsCache;
}

/**
 * Retrieve a specific setting value with fallback default.
 *
 * @param string $key
 * @param mixed $default
 * @return string
 */
function get_setting(string $key, $default = null): string {
    $settings = get_all_settings();
    return $settings[$key] ?? (string)($default ?? '');
}

/**
 * Save or update a single setting.
 *
 * @param string $key
 * @param string $value
 * @return bool
 */
function set_setting(string $key, string $value): bool {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value, updated_at)
            VALUES (:key, :value, NOW())
            ON DUPLICATE KEY UPDATE setting_value = :value_update, updated_at = NOW()
        ");
        $res = $stmt->execute([
            ':key'          => $key,
            ':value'        => $value,
            ':value_update' => $value
        ]);
        // Refresh cache
        get_all_settings(true);
        return $res;
    } catch (Throwable $e) {
        error_log("Error saving setting {$key}: " . $e->getMessage());
        return false;
    }
}

/**
 * Atomically update multiple settings.
 *
 * @param array $settings Key-value array of settings to update
 * @return bool
 */
function update_settings(array $settings): bool {
    try {
        $pdo = getDBConnection();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value, updated_at)
            VALUES (:key, :value, NOW())
            ON DUPLICATE KEY UPDATE setting_value = :value_update, updated_at = NOW()
        ");

        foreach ($settings as $key => $val) {
            $stmt->execute([
                ':key'          => (string)$key,
                ':value'        => (string)$val,
                ':value_update' => (string)$val
            ]);
        }

        $pdo->commit();
        get_all_settings(true);
        return true;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error batch updating settings: " . $e->getMessage());
        return false;
    }
}

/**
 * Format a date string using the system date format.
 *
 * @param string|null $dateStr
 * @param string|null $overrideFormat
 * @return string
 */
function format_app_date(?string $dateStr, ?string $overrideFormat = null): string {
    if (empty($dateStr)) {
        return '—';
    }
    $timestamp = strtotime($dateStr);
    if ($timestamp === false) {
        return $dateStr;
    }
    $format = $overrideFormat ?? get_setting('date_format', 'd/m/Y');
    return date($format, $timestamp);
}
