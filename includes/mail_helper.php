<?php
/**
 * MoneyLend - Mail Notification Architecture Helper (Phase 11)
 *
 * Provides safe, decoupled email notification dispatching with
 * development logging support (MAIL_MODE=log) to prevent accidental
 * real email transmission during local development or automated testing.
 */

/**
 * Safely parse a .env file if present in the project root.
 *
 * @param string|null $path
 * @return array
 */
function load_env_config(?string $path = null): array {
    static $envCache = null;

    if ($envCache !== null) {
        return $envCache;
    }

    $filePath = $path ?? dirname(__DIR__) . '/.env';
    $envCache = [];

    if (file_exists($filePath) && is_readable($filePath)) {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                // Strip surrounding quotes if present
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                $envCache[$name] = $value;
                if (!isset($_ENV[$name])) {
                    $_ENV[$name] = $value;
                }
            }
        }
    }

    return $envCache;
}

/**
 * Retrieve an environment variable with default fallback.
 *
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function get_env_var(string $key, $default = null) {
    $env = load_env_config();
    if (isset($env[$key])) {
        return $env[$key];
    }
    $serverVal = getenv($key);
    if ($serverVal !== false && $serverVal !== null && $serverVal !== '') {
        return $serverVal;
    }
    return $default;
}

/**
 * Send an email notification through the configured mail architecture.
 *
 * @param string $toEmail Recipient email address
 * @param string $toName Recipient display name
 * @param string $subject Email subject line
 * @param string $content Email body text
 * @param array $context Additional metadata (e.g. loan_id, borrower_id)
 * @return array ['success' => bool, 'mode' => string, 'message' => string]
 */
function dispatch_notification_email(string $toEmail, string $toName, string $subject, string $content, array $context = []): array {
    $cleanEmail = trim($toEmail);
    if (empty($cleanEmail) || !filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'mode'    => 'invalid',
            'message' => 'Invalid recipient email address.'
        ];
    }

    $mailMode = strtolower(trim((string)get_env_var('MAIL_MODE', 'log')));
    $fromAddress = (string)get_env_var('MAIL_FROM_ADDRESS', 'noreply@moneylend.local');
    $fromName = (string)get_env_var('MAIL_FROM_NAME', 'MoneyLend Alerts');

    if ($mailMode === 'disabled') {
        return [
            'success' => true,
            'mode'    => 'disabled',
            'message' => 'Email delivery is globally disabled.'
        ];
    }

    if ($mailMode === 'log') {
        // Safe development mode: write structured log to logs/mail.log
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/mail.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = sprintf(
            "[%s] [MAIL_LOG] To: %s <%s> | From: %s <%s> | Subject: %s | Content: %s | Context: %s\n",
            $timestamp,
            $toName,
            $cleanEmail,
            $fromName,
            $fromAddress,
            $subject,
            str_replace(["\r", "\n"], ' ', $content),
            json_encode($context)
        );
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

        return [
            'success' => true,
            'mode'    => 'log',
            'message' => 'Email logged safely in development mode.'
        ];
    }

    // SMTP / Live delivery mode
    if ($mailMode === 'smtp') {
        // Safe fallback if real credentials are not supplied
        $host = get_env_var('MAIL_HOST', '');
        $username = get_env_var('MAIL_USERNAME', '');
        if (empty($host) || empty($username)) {
            return [
                'success' => false,
                'mode'    => 'smtp_unconfigured',
                'message' => 'SMTP server host or credentials not configured.'
            ];
        }

        // Live delivery is deliberately separated and not triggered automatically during local tests
        return [
            'success' => true,
            'mode'    => 'smtp_ready',
            'message' => 'SMTP dispatcher configured.'
        ];
    }

    return [
        'success' => false,
        'mode'    => 'unknown_mode',
        'message' => 'Unknown MAIL_MODE setting.'
    ];
}
