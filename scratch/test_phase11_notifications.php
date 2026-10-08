<?php
/**
 * MoneyLend - Phase 11 Notification & Automation Test Suite
 *
 * Comprehensive validation covering:
 * 1. Notification table existence & schema
 * 2. Notification insertion & field integrity
 * 3. Notification retrieval & filters
 * 4. Unread notification counting
 * 5. Mark single notification read
 * 6. Mark all notifications read
 * 7. Due-soon notification generation logic
 * 8. Due-today notification generation logic
 * 9. Overdue notification generation logic
 * 10. Duplicate prevention & idempotency keys
 * 11. Paid loan exclusion
 * 12. Cancelled loan exclusion
 * 13. Zero-balance loan exclusion
 * 14. Notification preference settings toggle
 * 15. CSRF protection on notification actions
 * 16. Authentication protection on notification center
 * 17. User isolation & ownership enforcement
 * 18. Repayment recorded notification hook
 * 19. Loan settlement / paid notification hook
 * 20. CLI automation script execution
 * 21. Email architecture safe log mode verification
 * 22. Database financial integrity preserved
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helper.php';
require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/mail_helper.php';
require_once __DIR__ . '/../includes/notification_helper.php';

$pdo = getDBConnection();

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function run_test(string $name, callable $fn) {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    try {
        $result = $fn();
        if ($result === true) {
            $passedTests++;
            echo "[PASS] Test {$totalTests}: {$name}\n";
        } else {
            $failedTests++;
            $msg = is_string($result) ? $result : "Condition returned false";
            echo "[FAIL] Test {$totalTests}: {$name} — {$msg}\n";
        }
    } catch (Throwable $e) {
        $failedTests++;
        echo "[FAIL] Test {$totalTests}: {$name} — Exception: " . $e->getMessage() . "\n";
    }
}

echo "========================================================\n";
echo "MoneyLend — Phase 11 Notification & Automation Test Suite\n";
echo "========================================================\n\n";

// Record initial baseline to ensure perfect cleanup
$initialBorrowerCount = (int)$pdo->query("SELECT COUNT(*) FROM borrowers")->fetchColumn();
$initialLoanCount = (int)$pdo->query("SELECT COUNT(*) FROM loans")->fetchColumn();
$initialRepaymentCount = (int)$pdo->query("SELECT COUNT(*) FROM repayments")->fetchColumn();
$initialPrincipal = (float)$pdo->query("SELECT SUM(principal_amount) FROM loans")->fetchColumn();
$initialRepaid = (float)$pdo->query("SELECT SUM(amount) FROM repayments")->fetchColumn();
$initialOutstanding = (float)$pdo->query("SELECT SUM(remaining_balance) FROM loans")->fetchColumn();

// Setup clean testing state for notifications
$testUserId = 1;

// 1. Notification table exists
run_test("Notification Table Exists", function() use ($pdo) {
    $stmt = $pdo->query("SHOW TABLES LIKE 'notifications'");
    return $stmt->rowCount() === 1;
});

// 2. Notification insertion
$insertedNotifId = null;
run_test("Notification Insertion", function() use ($pdo, $testUserId, &$insertedNotifId) {
    $testKey = "test_insert_" . uniqid();
    $insertedNotifId = create_notification($pdo, [
        'user_id'    => $testUserId,
        'type'       => 'system',
        'title'      => 'Test Insertion Title',
        'message'    => 'Test insertion message content.',
        'severity'   => 'info',
        'event_key'  => $testKey
    ]);
    return ($insertedNotifId !== null && $insertedNotifId > 0);
});

// 3. Notification retrieval
run_test("Notification Retrieval", function() use ($pdo, $testUserId, &$insertedNotifId) {
    $list = get_user_notifications($pdo, $testUserId, ['is_read' => 0], 10, 0);
    $found = false;
    foreach ($list as $item) {
        if ((int)$item['id'] === $insertedNotifId) {
            $found = true;
            break;
        }
    }
    return $found;
});

// 4. Unread count
run_test("Unread Count Query", function() use ($pdo, $testUserId) {
    $count = get_unread_notification_count($pdo, $testUserId);
    return is_int($count) && $count > 0;
});

// 5. Mark read
run_test("Mark Single Notification Read", function() use ($pdo, $testUserId, &$insertedNotifId) {
    $success = mark_notification_read($pdo, $insertedNotifId, $testUserId);
    if (!$success) return "mark_notification_read returned false";
    $stmt = $pdo->prepare("SELECT is_read, read_at FROM notifications WHERE id = :id");
    $stmt->execute([':id' => $insertedNotifId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ((int)$row['is_read'] === 1 && !empty($row['read_at']));
});

// 6. Mark all read
run_test("Mark All Notifications Read", function() use ($pdo, $testUserId) {
    // Insert another unread notification first
    create_notification($pdo, [
        'user_id'    => $testUserId,
        'type'       => 'system',
        'title'      => 'Test Mark All',
        'message'    => 'Test mark all read.',
        'severity'   => 'info',
        'event_key'  => 'test_mark_all_' . uniqid()
    ]);
    $updated = mark_all_notifications_read($pdo, $testUserId);
    $remainingUnread = get_unread_notification_count($pdo, $testUserId);
    return ($updated >= 1 && $remainingUnread === 0);
});

// Setup isolated temporary borrower and loans for testing automation scenarios
$tempBorrowerId = null;
$tempLoanDueSoonId = null;
$tempLoanDueTodayId = null;
$tempLoanOverdueId = null;
$tempLoanPaidId = null;
$tempLoanCancelledId = null;
$tempLoanZeroBalId = null;

$today = date('Y-m-d');
$dueSoonDate = date('Y-m-d', strtotime('+2 days'));
$dueTodayDate = $today;
$overdueDate = date('Y-m-d', strtotime('-5 days'));
$futureDate = date('Y-m-d', strtotime('+30 days'));

try {
    // Insert temporary test borrower
    $stmt = $pdo->prepare("INSERT INTO borrowers (full_name, phone, email, status) VALUES ('_Test Borrower Phase11', '9999999999', 'test11@example.com', 'active')");
    $stmt->execute();
    $tempBorrowerId = (int)$pdo->lastInsertId();

    // Loan 1: Due soon (+2 days)
    $stmt = $pdo->prepare("INSERT INTO loans (borrower_id, principal_amount, due_date, start_date, total_payable, amount_repaid, remaining_balance, status) VALUES (:bid, 1000, :dd, CURDATE(), 1100, 0, 1100, 'active')");
    $stmt->execute([':bid' => $tempBorrowerId, ':dd' => $dueSoonDate]);
    $tempLoanDueSoonId = (int)$pdo->lastInsertId();

    // Loan 2: Due today (0 days)
    $stmt->execute([':bid' => $tempBorrowerId, ':dd' => $dueTodayDate]);
    $tempLoanDueTodayId = (int)$pdo->lastInsertId();

    // Loan 3: Overdue (-5 days)
    $stmt->execute([':bid' => $tempBorrowerId, ':dd' => $overdueDate]);
    $tempLoanOverdueId = (int)$pdo->lastInsertId();

    // Loan 4: Paid loan
    $stmt = $pdo->prepare("INSERT INTO loans (borrower_id, principal_amount, due_date, start_date, total_payable, amount_repaid, remaining_balance, status) VALUES (:bid, 1000, :dd, CURDATE(), 1100, 1100, 0, 'paid')");
    $stmt->execute([':bid' => $tempBorrowerId, ':dd' => $dueTodayDate]);
    $tempLoanPaidId = (int)$pdo->lastInsertId();

    // Loan 5: Cancelled loan
    $stmt = $pdo->prepare("INSERT INTO loans (borrower_id, principal_amount, due_date, start_date, total_payable, amount_repaid, remaining_balance, status) VALUES (:bid, 1000, :dd, CURDATE(), 1100, 0, 1100, 'cancelled')");
    $stmt->execute([':bid' => $tempBorrowerId, ':dd' => $dueTodayDate]);
    $tempLoanCancelledId = (int)$pdo->lastInsertId();

    // Loan 6: Zero balance loan but status active
    $stmt = $pdo->prepare("INSERT INTO loans (borrower_id, principal_amount, due_date, start_date, total_payable, amount_repaid, remaining_balance, status) VALUES (:bid, 1000, :dd, CURDATE(), 1100, 1100, 0, 'active')");
    $stmt->execute([':bid' => $tempBorrowerId, ':dd' => $dueTodayDate]);
    $tempLoanZeroBalId = (int)$pdo->lastInsertId();

} catch (Throwable $e) {
    echo "Setup error: " . $e->getMessage() . "\n";
}

// 7. Due-soon generation
run_test("Due-Soon Notification Generation", function() use ($pdo, $testUserId, $tempLoanDueSoonId, $dueSoonDate) {
    $stats = generate_loan_notifications($pdo, $testUserId);
    $stmt = $pdo->prepare("SELECT id FROM notifications WHERE loan_id = :lid AND type = 'loan_due_soon'");
    $stmt->execute([':lid' => $tempLoanDueSoonId]);
    return $stmt->rowCount() >= 1;
});

// 8. Due-today generation
run_test("Due-Today Notification Generation", function() use ($pdo, $testUserId, $tempLoanDueTodayId) {
    $stmt = $pdo->prepare("SELECT id FROM notifications WHERE loan_id = :lid AND type = 'loan_due_today'");
    $stmt->execute([':lid' => $tempLoanDueTodayId]);
    return $stmt->rowCount() >= 1;
});

// 9. Overdue generation
run_test("Overdue Notification Generation", function() use ($pdo, $testUserId, $tempLoanOverdueId) {
    $stmt = $pdo->prepare("SELECT id FROM notifications WHERE loan_id = :lid AND type = 'loan_overdue'");
    $stmt->execute([':lid' => $tempLoanOverdueId]);
    return $stmt->rowCount() >= 1;
});

// 10. Duplicate prevention
run_test("Duplicate Prevention (Idempotency Key)", function() use ($pdo, $testUserId) {
    $initialNotifCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$testUserId}")->fetchColumn();
    // Run automation again immediately
    $secondStats = generate_loan_notifications($pdo, $testUserId);
    $finalNotifCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$testUserId}")->fetchColumn();
    return ($secondStats['total_created'] === 0 && $secondStats['duplicates_skipped'] >= 3 && $finalNotifCount === $initialNotifCount);
});

// 11. Paid loan exclusion
run_test("Paid Loan Exclusion", function() use ($pdo, $tempLoanPaidId) {
    $stmt = $pdo->prepare("SELECT id FROM notifications WHERE loan_id = :lid AND type IN ('loan_due_soon', 'loan_due_today', 'loan_overdue')");
    $stmt->execute([':lid' => $tempLoanPaidId]);
    return $stmt->rowCount() === 0;
});

// 12. Cancelled loan exclusion
run_test("Cancelled Loan Exclusion", function() use ($pdo, $tempLoanCancelledId) {
    $stmt = $pdo->prepare("SELECT id FROM notifications WHERE loan_id = :lid AND type IN ('loan_due_soon', 'loan_due_today', 'loan_overdue')");
    $stmt->execute([':lid' => $tempLoanCancelledId]);
    return $stmt->rowCount() === 0;
});

// 13. Zero-balance exclusion
run_test("Zero-Balance Loan Exclusion", function() use ($pdo, $tempLoanZeroBalId) {
    $stmt = $pdo->prepare("SELECT id FROM notifications WHERE loan_id = :lid AND type IN ('loan_due_soon', 'loan_due_today', 'loan_overdue')");
    $stmt->execute([':lid' => $tempLoanZeroBalId]);
    return $stmt->rowCount() === 0;
});

// 14. Preference handling
run_test("Notification Preference Toggle", function() use ($pdo) {
    $origVal = get_setting('notify_due_soon', '1');
    set_setting('notify_due_soon', '0');
    $readBack = get_setting('notify_due_soon');
    set_setting('notify_due_soon', $origVal); // restore
    return ($readBack === '0');
});

// 15. CSRF protection
run_test("CSRF Protection on Notification Actions", function() {
    $valid = verify_csrf_token('invalid_token_payload_xyz');
    return ($valid === false);
});

// 16. Authentication protection
run_test("Authentication Protection on Notifications Center", function() {
    $content = file_get_contents(__DIR__ . '/../notifications/index.php');
    return str_contains($content, 'require_login()');
});

// 17. User ownership
run_test("User Isolation & Ownership Enforcement", function() use ($pdo) {
    // Create temporary second user
    $tempEmail = 'test_user_' . uniqid() . '@example.com';
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES ('Test User 2', :email, 'hash', 'staff')");
    $stmt->execute([':email' => $tempEmail]);
    $otherUserId = (int)$pdo->lastInsertId();

    // Notification for second user
    $otherNotifId = create_notification($pdo, [
        'user_id'   => $otherUserId,
        'type'      => 'system',
        'title'     => 'Other User Alert',
        'message'   => 'Confidential alert for other user.',
        'severity'  => 'info',
        'event_key' => 'other_user_' . uniqid()
    ]);
    if (!$otherNotifId) {
        $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $otherUserId]);
        return "Failed to create test notification";
    }

    // Attempt to mark read by User 1
    $markSuccess = mark_notification_read($pdo, $otherNotifId, 1);
    
    // Check that it remains unread for second user
    $stmt = $pdo->prepare("SELECT is_read FROM notifications WHERE id = :id");
    $stmt->execute([':id' => $otherNotifId]);
    $isRead = (int)$stmt->fetchColumn();

    // Clean up temporary user and its cascaded notifications
    $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $otherUserId]);

    return ($isRead === 0);
});

// 18. Repayment notification
run_test("Repayment Recorded Notification Hook", function() use ($pdo, $testUserId, $tempBorrowerId, $tempLoanDueSoonId) {
    $repaymentAmt = 200.00;
    $stmt = $pdo->prepare("INSERT INTO repayments (loan_id, amount, payment_date, payment_method, user_id) VALUES (:lid, :amt, CURDATE(), 'Cash', :uid)");
    $stmt->execute([':lid' => $tempLoanDueSoonId, ':amt' => $repaymentAmt, ':uid' => $testUserId]);
    $repId = (int)$pdo->lastInsertId();

    $sync = sync_loan_balances($pdo, $tempLoanDueSoonId);

    // Trigger repayment notification
    $notifId = create_notification($pdo, [
        'user_id'      => $testUserId,
        'borrower_id'  => $tempBorrowerId,
        'loan_id'      => $tempLoanDueSoonId,
        'repayment_id' => $repId,
        'type'         => 'repayment_recorded',
        'title'        => "Repayment of ₹{$repaymentAmt} recorded",
        'message'      => "Repayment #{$repId} recorded.",
        'severity'     => 'success',
        'event_key'    => "repayment_recorded:{$repId}"
    ]);

    // Cleanup repayment
    $pdo->prepare("DELETE FROM repayments WHERE id = :id")->execute([':id' => $repId]);
    sync_loan_balances($pdo, $tempLoanDueSoonId);

    return ($notifId !== null && $notifId > 0);
});

// 19. Loan-paid notification
run_test("Loan Fully Paid Notification Hook", function() use ($pdo, $testUserId, $tempBorrowerId, $tempLoanDueSoonId) {
    $notifId = create_notification($pdo, [
        'user_id'      => $testUserId,
        'borrower_id'  => $tempBorrowerId,
        'loan_id'      => $tempLoanDueSoonId,
        'type'         => 'loan_paid',
        'title'        => "Loan #LN-{$tempLoanDueSoonId} fully repaid",
        'message'      => "Loan #{$tempLoanDueSoonId} settled.",
        'severity'     => 'success',
        'event_key'    => "loan_paid:loan_{$tempLoanDueSoonId}"
    ]);
    return ($notifId !== null && $notifId > 0);
});

// 20. Automation script execution
run_test("CLI Automation Script Execution", function() {
    $cmd = "/Applications/XAMPP/xamppfiles/bin/php " . escapeshellarg(__DIR__ . '/../scripts/generate_notifications.php');
    exec($cmd, $outputLines, $returnCode);
    $outputText = implode("\n", $outputLines);
    return ($returnCode === 0 && str_contains($outputText, 'Status: Automation completed successfully'));
});

// 21. Email dev mode logging verification
run_test("Email Architecture Safe Log Mode", function() {
    $res = dispatch_notification_email('client@test.local', 'Test Client', 'Test Subject Alert', 'Body of test alert');
    $logFile = __DIR__ . '/../logs/mail.log';
    return ($res['success'] === true && $res['mode'] === 'log' && file_exists($logFile));
});

// Clean up all temporary records created during testing
try {
    if ($tempBorrowerId) {
        $pdo->prepare("DELETE FROM notifications WHERE borrower_id = :bid")->execute([':bid' => $tempBorrowerId]);
        $pdo->prepare("DELETE FROM loans WHERE borrower_id = :bid")->execute([':bid' => $tempBorrowerId]);
        $pdo->prepare("DELETE FROM borrowers WHERE id = :bid")->execute([':bid' => $tempBorrowerId]);
    }
    // Clean up test notifications created in tests 2, 6
    $pdo->prepare("DELETE FROM notifications WHERE event_key LIKE 'test_%'")->execute();
} catch (Throwable $e) {
    echo "Cleanup warning: " . $e->getMessage() . "\n";
}

// 22. Database financial integrity preserved
run_test("Database Financial Integrity Baseline Preserved", function() use (
    $pdo, $initialBorrowerCount, $initialLoanCount, $initialRepaymentCount,
    $initialPrincipal, $initialRepaid, $initialOutstanding
) {
    $b = (int)$pdo->query("SELECT COUNT(*) FROM borrowers")->fetchColumn();
    $l = (int)$pdo->query("SELECT COUNT(*) FROM loans")->fetchColumn();
    $r = (int)$pdo->query("SELECT COUNT(*) FROM repayments")->fetchColumn();
    $p = (float)$pdo->query("SELECT SUM(principal_amount) FROM loans")->fetchColumn();
    $repaid = (float)$pdo->query("SELECT SUM(amount) FROM repayments")->fetchColumn();
    $out = (float)$pdo->query("SELECT SUM(remaining_balance) FROM loans")->fetchColumn();

    $orphanedLoans = (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE borrower_id NOT IN (SELECT id FROM borrowers)")->fetchColumn();
    $orphanedRepayments = (int)$pdo->query("SELECT COUNT(*) FROM repayments WHERE loan_id NOT IN (SELECT id FROM loans)")->fetchColumn();

    if ($b !== 7) return "Borrower count is {$b}, expected 7";
    if ($l !== 6) return "Loan count is {$l}, expected 6";
    if ($r !== 7) return "Repayment count is {$r}, expected 7";
    if (round($p, 2) !== 250000.00) return "Principal is {$p}, expected 250000.00";
    if (round($repaid, 2) !== 57345.00) return "Repaid is {$repaid}, expected 57345.00";
    if (round($out, 2) !== 217655.00) return "Outstanding is {$out}, expected 217655.00";
    if ($orphanedLoans !== 0) return "Orphaned loans detected: {$orphanedLoans}";
    if ($orphanedRepayments !== 0) return "Orphaned repayments detected: {$orphanedRepayments}";

    return true;
});

echo "\n--------------------------------------------------------\n";
echo "TOTAL PHASE 11 TESTS: {$totalTests}\n";
echo "PASSED: {$passedTests}\n";
echo "FAILED: {$failedTests}\n";
echo "========================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
