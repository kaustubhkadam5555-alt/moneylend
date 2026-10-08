<?php
/**
 * MoneyLend - Scheduled Automation Runner (Phase 11)
 *
 * Scans active loan agreements for approaching due dates, today's maturities,
 * and overdue accounts. Generates idempotent internal notifications and triggers
 * optional email dispatches in safe log mode.
 *
 * Usage via CLI:
 *   php scripts/generate_notifications.php
 *   php scripts/generate_notifications.php --verbose
 *   php scripts/generate_notifications.php --date=2026-10-15
 *
 * Suitable for macOS/Linux cron and Windows Task Scheduler.
 */

// CLI Security Guard: Block direct browser access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Forbidden: This automation script can only be executed via the Command Line Interface (CLI).\n";
    exit(1);
}

// Ensure working directory is application root
chdir(dirname(__DIR__));

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/loan_helper.php';

// Parse CLI Options
$options = getopt('v', ['verbose', 'date:', 'user:', 'cleanup']);
$verbose = isset($options['v']) || isset($options['verbose']);
$asOfDate = $options['date'] ?? null;
$userId = isset($options['user']) ? (int)$options['user'] : 1;
$runCleanup = isset($options['cleanup']);

echo "============================================================\n";
echo " MoneyLend Automation Engine — Due Date & Overdue Scanner\n";
echo "============================================================\n";
echo "Execution Time : " . date('Y-m-d H:i:s') . "\n";
echo "Evaluation Date: " . ($asOfDate ?? date('Y-m-d')) . "\n";
echo "Target User ID : " . $userId . "\n";
echo "------------------------------------------------------------\n";

try {
    $pdo = getDBConnection();

    // Verify database connection
    $userCheck = $pdo->prepare("SELECT id, name FROM users WHERE id = :id LIMIT 1");
    $userCheck->execute([':id' => $userId]);
    $user = $userCheck->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo "Notice: User #{$userId} not found in database. Using fallback User ID 1.\n";
        $userId = 1;
    }

    // Execute notification generation engine
    $stats = generate_loan_notifications($pdo, $userId, $asOfDate);

    // Optional retention cleanup
    $cleanedCount = 0;
    if ($runCleanup) {
        $cleanedCount = cleanup_old_notifications($pdo, 90);
    }

    echo "Loans Evaluated          : " . $stats['evaluated_loans'] . "\n";
    echo "Due Soon Alerts Created  : " . $stats['due_soon_created'] . "\n";
    echo "Due Today Alerts Created : " . $stats['due_today_created'] . "\n";
    echo "Overdue Alerts Created   : " . $stats['overdue_created'] . "\n";
    echo "Total New Notifications  : " . $stats['total_created'] . "\n";
    echo "Duplicates Skipped       : " . $stats['duplicates_skipped'] . "\n";
    if ($runCleanup) {
        echo "Old Records Purged (90d) : " . $cleanedCount . "\n";
    }
    echo "------------------------------------------------------------\n";
    echo "Status: Automation completed successfully.\n";
    echo "============================================================\n";
    exit(0);

} catch (Throwable $e) {
    echo "ERROR: Notification generation failed: " . $e->getMessage() . "\n";
    error_log("CLI Automation Error: " . $e->getMessage());
    exit(1);
}
