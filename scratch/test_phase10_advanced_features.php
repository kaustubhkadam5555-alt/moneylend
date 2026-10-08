<?php
/**
 * MoneyLend - Phase 10 Advanced Features Test Suite
 *
 * Validates all Phase 10 additions:
 * - Activity Logging & Audit Trail
 * - Borrower Financial Statement
 * - Loan Agreement Statement
 * - Borrower Profile Enhancements (Ledger, Statements)
 * - Loan View Enhancements (Timeline, Statements, Cancel Action)
 * - Advanced Multi-Criteria Search & Filtering
 * - Dashboard Intelligence (Recovery Rate, Overdue, Due Soon)
 * - Reports Collection Performance Analytics
 * - Database Baseline Verification
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = getDBConnection();
$baseUrl = 'http://localhost/moneylend';
$cookieFile = __DIR__ . '/p10_cookie.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

$total = 0;
$passed = 0;
$failed = 0;

function test_assert($label, $condition, $details = '') {
    global $total, $passed, $failed;
    $total++;
    if ($condition) {
        $passed++;
        echo "[PASS] {$label} - {$details}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label} - {$details}\n";
    }
}

function http_req($url, $method = 'GET', $data = [], $cookie = null, $followLocation = true) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followLocation);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);

    if ($cookie) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($ch);
    curl_close($ch);

    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    return [
        'code' => $httpCode,
        'headers' => $headers,
        'body' => $body,
        'url' => $effectiveUrl,
        'error' => $error
    ];
}

function extract_csrf($html) {
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

echo "========================================================\n";
echo "MoneyLend — Phase 10 Advanced Features Test Suite\n";
echo "========================================================\n\n";

// 1. Authenticate Admin User
$loginPage = http_req("$baseUrl/login.php", 'GET', [], $cookieFile);
$csrf = extract_csrf($loginPage['body']);

$loginRes = http_req("$baseUrl/login.php", 'POST', [
    'email' => 'admin@moneylend.local',
    'password' => 'admin123',
    'csrf_token' => $csrf
], $cookieFile, true);

test_assert("Authentication", strpos($loginRes['body'], 'Dashboard') !== false, "Logged in as Admin");

// 2. Activity Log Table & Entry Verification
$logCount = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
test_assert("Activity Log DB", $logCount > 0, "activity_logs table records exist (Count: {$logCount})");

// 3. Activity Log UI Page
$actRes = http_req("$baseUrl/activity/index.php", 'GET', [], $cookieFile);
test_assert("Activity Log UI", strpos($actRes['body'], 'Activity') !== false && strpos($actRes['body'], 'Audit') !== false, "Activity UI loaded");
test_assert("Activity Log Filter", strpos($actRes['body'], 'All Actions') !== false && strpos($actRes['body'], 'All Module Entities') !== false, "Filter controls present");

// 4. Borrower Statement
$firstBorrower = $pdo->query("SELECT id, full_name FROM borrowers ORDER BY id ASC LIMIT 1")->fetch();
$bStmtRes = http_req("$baseUrl/borrowers/statement.php?id=" . $firstBorrower['id'], 'GET', [], $cookieFile);
test_assert("Borrower Statement Page", strpos($bStmtRes['body'], 'Borrower Financial Statement') !== false, "Statement rendered for {$firstBorrower['full_name']}");
test_assert("Borrower Ledger", strpos($bStmtRes['body'], 'Account Transaction Ledger') !== false, "Ledger table present");
test_assert("Print Controls", strpos($bStmtRes['body'], 'window.print()') !== false, "Print button present");

// 5. Loan Statement
$firstLoan = $pdo->query("SELECT id FROM loans ORDER BY id ASC LIMIT 1")->fetch();
$lStmtRes = http_req("$baseUrl/loans/statement.php?id=" . $firstLoan['id'], 'GET', [], $cookieFile);
test_assert("Loan Statement Page", strpos($lStmtRes['body'], 'Loan Agreement Statement') !== false, "Loan statement rendered for Agreement #{$firstLoan['id']}");
test_assert("Loan Ledger", strpos($lStmtRes['body'], 'Repayment Installment Ledger') !== false, "Installment ledger present");

// 6. Borrower Profile Enhancements
$bViewRes = http_req("$baseUrl/borrowers/view.php?id=" . $firstBorrower['id'], 'GET', [], $cookieFile);
test_assert("Borrower View Statement Link", strpos($bViewRes['body'], 'borrowers/statement.php') !== false, "Statement link present on profile");
test_assert("Borrower Health Progress Bar", strpos($bViewRes['body'], 'Client Repayment Health') !== false, "Health progress bar present");
test_assert("Borrower Repayment History", strpos($bViewRes['body'], 'Borrower Repayment History') !== false, "Repayment history table rendered");

// 7. Loan Details Enhancements
$lViewRes = http_req("$baseUrl/loans/view.php?id=" . $firstLoan['id'], 'GET', [], $cookieFile);
test_assert("Loan View Statement Link", strpos($lViewRes['body'], 'loans/statement.php') !== false, "Statement link present on loan view");
test_assert("Loan Timeline", strpos($lViewRes['body'], 'Timeline') !== false && strpos($lViewRes['body'], 'Lifecycle') !== false, "Timeline component rendered");
test_assert("Cancel Action Modal", strpos($lViewRes['body'], 'cancelLoanModal') !== false, "Safe cancellation modal present");

// 8. Advanced Search & Filter on Loans
$lFilterRes = http_req("$baseUrl/loans/index.php?borrower_id=" . $firstBorrower['id'], 'GET', [], $cookieFile);
test_assert("Loans Borrower Filter", $lFilterRes['code'] === 200 && strpos($lFilterRes['body'], 'Loan Agreements') !== false, "Filtered loans by borrower successfully");

// 9. Advanced Search & Filter on Repayments
$rFilterRes = http_req("$baseUrl/repayments/index.php?borrower_id=" . $firstBorrower['id'], 'GET', [], $cookieFile);
test_assert("Repayments Borrower Filter", $rFilterRes['code'] === 200 && strpos($rFilterRes['body'], 'Repayments') !== false, "Filtered repayments by borrower successfully");

// 10. Dashboard Intelligence
$dashRes = http_req("$baseUrl/dashboard.php", 'GET', [], $cookieFile);
test_assert("Dashboard Recovery Rate", strpos($dashRes['body'], 'Portfolio Recovery Rate') !== false, "Recovery rate widget rendered");
test_assert("Dashboard Overdue Monitor", strpos($dashRes['body'], 'Overdue Receivables') !== false, "Overdue monitor widget rendered");
test_assert("Dashboard Due Soon Widget", strpos($dashRes['body'], 'Upcoming Maturities') !== false, "Due soon widget rendered");
test_assert("Dashboard Audit Trail Link", strpos($dashRes['body'], 'activity/') !== false, "Audit trail link rendered");

// 11. Reports Collection Performance
$repRes = http_req("$baseUrl/reports/index.php", 'GET', [], $cookieFile);
test_assert("Reports Collection Performance", strpos($repRes['body'], 'Portfolio Collection Performance') !== false, "Collection rate progress bar rendered");

// 12. Database Baseline Verification
$cntB = (int)$pdo->query("SELECT COUNT(*) FROM borrowers")->fetchColumn();
$cntL = (int)$pdo->query("SELECT COUNT(*) FROM loans")->fetchColumn();
$cntR = (int)$pdo->query("SELECT COUNT(*) FROM repayments")->fetchColumn();
$sumP = (float)$pdo->query("SELECT COALESCE(SUM(principal_amount), 0) FROM loans")->fetchColumn();
$sumR = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM repayments")->fetchColumn();
$sumO = (float)$pdo->query("SELECT COALESCE(SUM(remaining_balance), 0) FROM loans WHERE status != 'cancelled'")->fetchColumn();

test_assert("Baseline Borrowers", $cntB === 7, "Borrowers count: {$cntB} / 7");
test_assert("Baseline Loans", $cntL === 6, "Loans count: {$cntL} / 6");
test_assert("Baseline Repayments", $cntR === 7, "Repayments count: {$cntR} / 7");
test_assert("Baseline Principal", abs($sumP - 250000.00) < 0.01, "Principal: ₹" . number_format($sumP, 2));
test_assert("Baseline Repaid", abs($sumR - 57345.00) < 0.01, "Repaid: ₹" . number_format($sumR, 2));
test_assert("Baseline Outstanding", abs($sumO - 217655.00) < 0.01, "Outstanding: ₹" . number_format($sumO, 2));

if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

echo "\n--------------------------------------------------------\n";
echo "TOTAL ADVANCED FEATURE TESTS: {$total}\n";
echo "PASSED: {$passed}\n";
echo "FAILED: {$failed}\n";
echo "========================================================\n";
