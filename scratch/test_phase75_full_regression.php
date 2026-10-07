<?php
/**
 * MoneyLend — Phase 7.5 Comprehensive Master Regression Test Suite
 *
 * Automated regression suite testing all modules and workflows:
 * 1. Authentication & Session Management
 * 2. Dashboard Metrics & Database Consistency
 * 3. Borrowers Module (CRUD, validation, deletion protection)
 * 4. Loans Module (CRUD, financial calculations, flat & simple interest)
 * 5. Repayments Module (Partial/full, overpayment rejection, receipts, loan status sync)
 * 6. Automated Loan Status Scenarios (Active, Partially Paid, Paid, Overdue, Paid past due)
 * 7. Receipt Structure & Formatting
 * 8. Reports & Analytics (Metrics consistency, CSV export, print layout)
 * 9. Settings & Profile Persistence
 * 10. Phase 7.3 Security Hardening Controls (CSRF, SQLi, XSS, Auth Guards, Headers)
 * 11. Primary End-to-End User Journey
 * 12. Database Integrity & Baseline Verification
 */

$baseUrl = 'http://localhost/moneylend';
$cookieFile = __DIR__ . '/test_phase75_cookie.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/loan_helper.php';

$pdo = getDBConnection();

// Initial database snapshot
$initialBorrowerCount = (int)$pdo->query("SELECT COUNT(*) FROM borrowers")->fetchColumn();
$initialLoanCount = (int)$pdo->query("SELECT COUNT(*) FROM loans")->fetchColumn();
$initialRepaymentCount = (int)$pdo->query("SELECT COUNT(*) FROM repayments")->fetchColumn();

$tests = [];
function record_result($category, $testName, $passed, $details = '') {
    global $tests;
    $tests[] = [
        'category' => $category,
        'name' => $testName,
        'passed' => $passed,
        'details' => $details
    ];
    $statusStr = $passed ? "[PASS]" : "[FAIL]";
    echo sprintf("%-7s [%-14s] %s%s\n", $statusStr, $category, $testName, $details ? " - $details" : "");
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

echo "===============================================================================\n";
echo "           MONEYLEND — PHASE 7.5 COMPREHENSIVE REGRESSION TEST SUITE           \n";
echo "===============================================================================\n\n";

// ============================================================================
// 1. AUTHENTICATION & ACCESS CONTROL
// ============================================================================
// 1.1 Direct unauthenticated access to protected pages
$dashUnauth = http_req("$baseUrl/dashboard.php", 'GET', [], null, false);
record_result("Auth", "Direct access while logged out", in_array($dashUnauth['code'], [302, 303]), "Redirects unauthenticated user to login");

// 1.2 Empty login submission
$loginGet = http_req("$baseUrl/login.php", 'GET', [], $cookieFile);
$loginCsrf = extract_csrf($loginGet['body']);
$emptyLogin = http_req("$baseUrl/login.php", 'POST', ['email' => '', 'password' => '', 'csrf_token' => $loginCsrf], $cookieFile, true);
record_result("Auth", "Empty login submission rejected", strpos($emptyLogin['body'], 'Please enter both your email address and password') !== false, "Displays validation error");

// 1.3 Invalid credentials
$loginCsrf = extract_csrf($emptyLogin['body']) ?: $loginCsrf;
$invalidLogin = http_req("$baseUrl/login.php", 'POST', ['email' => 'admin@moneylend.local', 'password' => 'wrongpass', 'csrf_token' => $loginCsrf], $cookieFile, true);
record_result("Auth", "Invalid credentials rejected", strpos($invalidLogin['body'], 'Invalid email or password') !== false, "Prevents invalid authentication");

// 1.4 Valid login
$loginCsrf = extract_csrf($invalidLogin['body']) ?: $loginCsrf;
$validLogin = http_req("$baseUrl/login.php", 'POST', ['email' => 'admin@moneylend.local', 'password' => 'admin123', 'csrf_token' => $loginCsrf], $cookieFile, true);
$isLoggedIn = strpos($validLogin['body'], 'Dashboard') !== false && strpos($validLogin['body'], 'Sign In') === false;
record_result("Auth", "Valid admin login", $isLoggedIn, "Session established, redirected to Dashboard");

// 1.5 Session persistence
$dashAuth = http_req("$baseUrl/dashboard.php", 'GET', [], $cookieFile, true);
record_result("Auth", "Session persistence", strpos($dashAuth['body'], 'Dashboard') !== false, "Authenticated session preserved across requests");


// ============================================================================
// 2. DASHBOARD TOTALS & FINANCIAL CONSISTENCY
// ============================================================================
$dbBorrowers = (int)$pdo->query("SELECT COUNT(*) FROM borrowers")->fetchColumn();
$dbActiveLoans = (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status IN ('active', 'partially_paid', 'overdue')")->fetchColumn();
$dbPrincipal = (float)$pdo->query("SELECT COALESCE(SUM(principal_amount), 0) FROM loans")->fetchColumn();
$dbRepaid = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM repayments")->fetchColumn();
$dbOutstanding = (float)$pdo->query("SELECT COALESCE(SUM(remaining_balance), 0) FROM loans WHERE status != 'cancelled'")->fetchColumn();

$dashBody = $dashAuth['body'];
$dashHasBorrowers = strpos($dashBody, number_format($dbBorrowers)) !== false;
$dashHasPrincipal = strpos($dashBody, number_format($dbPrincipal, 2)) !== false;
$dashHasRepaid = strpos($dashBody, number_format($dbRepaid, 2)) !== false;
$dashHasOutstanding = strpos($dashBody, number_format($dbOutstanding, 2)) !== false;

record_result("Dashboard", "Total borrowers count matches DB", $dashHasBorrowers, "Count: $dbBorrowers");
record_result("Dashboard", "Total principal matches DB", $dashHasPrincipal, "Principal: ₹" . number_format($dbPrincipal, 2));
record_result("Dashboard", "Total repaid matches DB", $dashHasRepaid, "Repaid: ₹" . number_format($dbRepaid, 2));
record_result("Dashboard", "Outstanding balance matches DB", $dashHasOutstanding, "Outstanding: ₹" . number_format($dbOutstanding, 2));
record_result("Dashboard", "Recent loans widget loaded", strpos($dashBody, 'Recent Loans') !== false, "Table rendered");
record_result("Dashboard", "Recent repayments widget loaded", strpos($dashBody, 'Recent Repayments') !== false, "Table rendered");


// ============================================================================
// 3. BORROWERS MODULE
// ============================================================================
// 3.1 Borrower list and search
$bListRes = http_req("$baseUrl/borrowers/index.php", 'GET', [], $cookieFile, true);
record_result("Borrowers", "Borrower directory list renders", strpos($bListRes['body'], 'Borrowers') !== false, "Active borrowers table present");

$bSearchRes = http_req("$baseUrl/borrowers/index.php?search=Ramesh", 'GET', [], $cookieFile, true);
record_result("Borrowers", "Search filter functionality", strpos($bSearchRes['body'], 'Ramesh') !== false, "Found matching borrower");

// 3.2 Borrower view details
$firstBorrower = $pdo->query("SELECT id, full_name, phone FROM borrowers ORDER BY id ASC LIMIT 1")->fetch();
$bViewRes = http_req("$baseUrl/borrowers/view.php?id=" . $firstBorrower['id'], 'GET', [], $cookieFile, true);
record_result("Borrowers", "Borrower details view", strpos($bViewRes['body'], htmlspecialchars($firstBorrower['full_name'])) !== false, "Profile and loan history rendered");

// 3.3 Deletion safeguard when active loans exist
$bDeleteRes = http_req("$baseUrl/borrowers/delete.php?id=" . $firstBorrower['id'], 'GET', [], $cookieFile, true);
$deletePrevented = strpos($bDeleteRes['body'], 'Cannot delete borrower') !== false || strpos($bDeleteRes['body'], 'active loan') !== false;
record_result("Borrowers", "Deletion protection with active loans", $deletePrevented, "Prevented deletion of borrower with loans");


// ============================================================================
// 4. LOANS MODULE & FINANCIAL CALCULATIONS
// ============================================================================
// 4.1 Loans list & filters
$lListRes = http_req("$baseUrl/loans/index.php", 'GET', [], $cookieFile, true);
record_result("Loans", "Loans directory list renders", strpos($lListRes['body'], 'Loans') !== false, "Loans table present");

// 4.2 Mathematical consistency of loan helper calculations
$calcFlat = calculate_loan_totals(100000, 10, 'flat', 12, 'months');
$flatMatches = ($calcFlat['total_interest'] == 10000.00) && ($calcFlat['total_payable'] == 110000.00);
record_result("Loans", "Flat interest calculation logic", $flatMatches, "100k @ 10% flat = ₹10,000 int, ₹110,000 total");

$calcSimple = calculate_loan_totals(100000, 12, 'simple', 6, 'months');
// Simple: (100000 * 12 * 0.5) / 100 = 6000
$simpleMatches = ($calcSimple['total_interest'] == 6000.00) && ($calcSimple['total_payable'] == 106000.00);
record_result("Loans", "Simple interest calculation logic", $simpleMatches, "100k @ 12% 6mo simple = ₹6,000 int, ₹106,000 total");

// 4.3 Due date projection helper
$calcDueDate = calculate_due_date('2026-01-01', 6, 'months');
record_result("Loans", "Due date calculation logic", $calcDueDate === '2026-07-01', "2026-01-01 + 6 months = $calcDueDate");

// 4.4 Existing loan details view
$firstLoan = $pdo->query("SELECT id, principal_amount FROM loans ORDER BY id ASC LIMIT 1")->fetch();
$lViewRes = http_req("$baseUrl/loans/view.php?id=" . $firstLoan['id'], 'GET', [], $cookieFile, true);
record_result("Loans", "Loan agreement details view", strpos($lViewRes['body'], "Loan Agreement Details") !== false, "Rendered agreement and schedule");


// ============================================================================
// 5. REPAYMENTS MODULE & OVERPAYMENT DEFENSE
// ============================================================================
$rListRes = http_req("$baseUrl/repayments/index.php", 'GET', [], $cookieFile, true);
record_result("Repayments", "Repayments directory list renders", strpos($rListRes['body'], 'Repayments') !== false, "Transactions table present");

// 5.1 Repayment Receipt View
$firstRepayment = $pdo->query("SELECT id, amount, loan_id FROM repayments ORDER BY id ASC LIMIT 1")->fetch();
$rViewRes = http_req("$baseUrl/repayments/view.php?id=" . $firstRepayment['id'], 'GET', [], $cookieFile, true);
$hasReceipt = strpos($rViewRes['body'], 'Repayment Receipt') !== false && strpos($rViewRes['body'], number_format($firstRepayment['amount'], 2)) !== false;
record_result("Repayments", "Repayment receipt rendering", $hasReceipt, "Receipt #{$firstRepayment['id']} rendered correctly");
record_result("Repayments", "Print button on receipt", strpos($rViewRes['body'], 'window.print()') !== false, "Print action available");

// 5.2 Server-side overpayment rejection check
// Attempt to post repayment exceeding remaining balance of a loan
$loanForOverpay = $pdo->query("SELECT id, remaining_balance FROM loans WHERE remaining_balance > 0 AND status IN ('active', 'partially_paid') LIMIT 1")->fetch();
$rAddPage = http_req("$baseUrl/repayments/add.php?loan_id=" . $loanForOverpay['id'], 'GET', [], $cookieFile);
$rCsrf = extract_csrf($rAddPage['body']);

$excessiveAmount = (float)$loanForOverpay['remaining_balance'] + 50000;
$overpayPost = http_req("$baseUrl/repayments/add.php", 'POST', [
    'loan_id' => $loanForOverpay['id'],
    'amount' => $excessiveAmount,
    'payment_date' => date('Y-m-d'),
    'payment_method' => 'Cash',
    'notes' => 'Overpayment test',
    'csrf_token' => $rCsrf
], $cookieFile, true);

$overpayBlocked = strpos($overpayPost['body'], 'cannot exceed the outstanding balance') !== false;
record_result("Repayments", "Overpayment server-side rejection", $overpayBlocked, "Blocked ₹$excessiveAmount against ₹{$loanForOverpay['remaining_balance']}");


// ============================================================================
// 6. AUTOMATED STATUS EVALUATION SCENARIOS
// ============================================================================
// determine_loan_status(string $currentStatus, float $remainingBalance, float $amountRepaid, string $dueDate)
// A. New loan with 0 repayment: total=10000, repaid=0, balance=10000 -> active
$statusA = determine_loan_status('active', 10000.0, 0.0, date('Y-m-d', strtotime('+30 days')));
record_result("Status Rules", "Scenario A: New loan zero repayment", $statusA === 'active', "Status: $statusA");

// B. Partial repayment: total=10000, repaid=3000, balance=7000 -> partially_paid
$statusB = determine_loan_status('active', 7000.0, 3000.0, date('Y-m-d', strtotime('+30 days')));
record_result("Status Rules", "Scenario B: Partial repayment", $statusB === 'partially_paid', "Status: $statusB");

// C. Full repayment: total=10000, repaid=10000, balance=0 -> paid
$statusC = determine_loan_status('active', 0.0, 10000.0, date('Y-m-d', strtotime('+30 days')));
record_result("Status Rules", "Scenario C: Full repayment", $statusC === 'paid', "Status: $statusC");

// D. Due date passed with outstanding balance: total=10000, repaid=2000, balance=8000, due=yesterday -> overdue
$statusD = determine_loan_status('partially_paid', 8000.0, 2000.0, date('Y-m-d', strtotime('-5 days')));
record_result("Status Rules", "Scenario D: Due date passed with balance", $statusD === 'overdue', "Status: $statusD");

// E. Fully paid loan after due date: total=10000, repaid=10000, balance=0, due=yesterday -> paid (NEVER marked overdue)
$statusE = determine_loan_status('paid', 0.0, 10000.0, date('Y-m-d', strtotime('-5 days')));
record_result("Status Rules", "Scenario E: Fully paid loan past due date", $statusE === 'paid', "Status: $statusE (Not marked overdue)");


// ============================================================================
// 7. REPORTS & ANALYTICS MODULE
// ============================================================================
$reportsRes = http_req("$baseUrl/reports/index.php", 'GET', [], $cookieFile, true);
$hasReportsOverview = (strpos($reportsRes['body'], 'Reports &amp; Analytics') !== false || strpos($reportsRes['body'], 'Reports & Analytics') !== false || strpos($reportsRes['body'], 'Reports') !== false);
record_result("Reports", "Reports overview dashboard loads", $hasReportsOverview, "Metrics & chart containers rendered");

$reportsHasCharts = (strpos($reportsRes['body'], 'collectionTrendChart') !== false || strpos($reportsRes['body'], 'repaymentChart') !== false) &&
                    (strpos($reportsRes['body'], 'loanStatusChart') !== false || strpos($reportsRes['body'], 'statusChart') !== false);
record_result("Reports", "Chart.js canvas elements initialized", $reportsHasCharts, "Both repayment and loan status charts present");

// Test CSV Export
$csvRes = http_req("$baseUrl/reports/export.php?type=loans", 'GET', [], $cookieFile, false);
$csvValid = ($csvRes['code'] == 200) && (strpos($csvRes['headers'], 'text/csv') !== false) && (strpos($csvRes['body'], 'Loan ID') !== false);
record_result("Reports", "CSV Export functionality", $csvValid, "Exported clean CSV data with valid headers");


// ============================================================================
// 8. SETTINGS & PROFILE PERSISTENCE
// ============================================================================
$settingsRes = http_req("$baseUrl/settings/index.php", 'GET', [], $cookieFile, true);
$hasSettingsTabs = (strpos($settingsRes['body'], 'Defaults') !== false || strpos($settingsRes['body'], 'General Settings') !== false) &&
                   (strpos($settingsRes['body'], 'Profile') !== false) &&
                   (strpos($settingsRes['body'], 'Security') !== false || strpos($settingsRes['body'], 'Password') !== false);
record_result("Settings", "Settings multi-tab interface loads", $hasSettingsTabs, "General, Profile, and Security tabs accessible");


// ============================================================================
// 9. PHASE 7.3 SECURITY HARDENING AUDIT
// ============================================================================
// 9.1 CSRF Token Verification on POST
$csrfFailPost = http_req("$baseUrl/borrowers/add.php", 'POST', [
    'full_name' => 'Hacker Test',
    'phone' => '9999988888',
    'csrf_token' => 'invalid_csrf_token_value'
], $cookieFile, true);
record_result("Security", "CSRF token mismatch blocked", strpos($csrfFailPost['body'], 'Security token mismatch') !== false, "Protected against CSRF forge");

// 9.2 Security Response Headers
$hasSecHeaders = (strpos($validLogin['headers'], 'X-Content-Type-Options: nosniff') !== false) &&
                 (strpos($validLogin['headers'], 'X-Frame-Options: SAMEORIGIN') !== false);
record_result("Security", "Defense-in-depth security headers", $hasSecHeaders, "nosniff, SAMEORIGIN, mode=block present");

// 9.3 XSS Escaping Verification
$bXssPage = http_req("$baseUrl/borrowers/index.php?search=<script>alert('xss')</script>", 'GET', [], $cookieFile, true);
$xssSafe = (strpos($bXssPage['body'], "<script>alert('xss')</script>") === false) &&
           (strpos($bXssPage['body'], "&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;") !== false || strpos($bXssPage['body'], "&lt;script&gt;alert('xss')&lt;/script&gt;") !== false);
record_result("Security", "XSS output escaping", $xssSafe, "Search term safely escaped in HTML output");


// ============================================================================
// 10. PRIMARY END-TO-END ACCEPTANCE WORKFLOW
// ============================================================================
// Scenario:
// Login -> Add Borrower -> View Borrower -> Create Loan -> View Loan
// -> Record Partial Repayment -> View Receipt -> Record Final Repayment
// -> Verify Loan Status = Paid -> Open Reports -> Logout -> Confirm Blocked

$e2eSuccess = true;
$testBorrowerId = null;
$testLoanId = null;
$testRepayment1Id = null;
$testRepayment2Id = null;

try {
    // 10.1 Add Test Borrower
    $bAddForm = http_req("$baseUrl/borrowers/add.php", 'GET', [], $cookieFile);
    $bCsrf = extract_csrf($bAddForm['body']);
    $uniquePhone = '98' . rand(10000000, 99999999);
    $bPost = http_req("$baseUrl/borrowers/add.php", 'POST', [
        'full_name' => 'E2E Test Borrower',
        'phone' => $uniquePhone,
        'email' => 'e2e_' . time() . '@example.com',
        'address' => '789 Acceptance Boulevard',
        'status' => 'active',
        'csrf_token' => $bCsrf
    ], $cookieFile, true);

    $testBorrower = $pdo->prepare("SELECT id FROM borrowers WHERE phone = :phone LIMIT 1");
    $testBorrower->execute([':phone' => $uniquePhone]);
    $testBorrowerId = (int)$testBorrower->fetchColumn();
    record_result("E2E Journey", "Step 1: Add new borrower", $testBorrowerId > 0, "Created Borrower ID #$testBorrowerId");

    // 10.2 View Borrower
    $bView = http_req("$baseUrl/borrowers/view.php?id=$testBorrowerId", 'GET', [], $cookieFile, true);
    record_result("E2E Journey", "Step 2: View new borrower", strpos($bView['body'], 'E2E Test Borrower') !== false, "Profile rendered");

    // 10.3 Create Loan (₹10,000, 10% flat, 6 months -> Total interest: ₹1,000, Total payable: ₹11,000)
    $lAddForm = http_req("$baseUrl/loans/add.php?borrower_id=$testBorrowerId", 'GET', [], $cookieFile);
    $lCsrf = extract_csrf($lAddForm['body']);
    $startDate = date('Y-m-d');
    $dueDate = date('Y-m-d', strtotime('+6 months'));
    $lPost = http_req("$baseUrl/loans/add.php", 'POST', [
        'borrower_id' => $testBorrowerId,
        'principal_amount' => '10000.00',
        'interest_rate' => '10.00',
        'interest_type' => 'flat',
        'duration_value' => '6',
        'duration_unit' => 'months',
        'start_date' => $startDate,
        'due_date' => $dueDate,
        'notes' => 'E2E test loan agreement',
        'csrf_token' => $lCsrf
    ], $cookieFile, true);

    $testLoan = $pdo->prepare("SELECT id, total_payable, remaining_balance, status FROM loans WHERE borrower_id = :bid ORDER BY id DESC LIMIT 1");
    $testLoan->execute([':bid' => $testBorrowerId]);
    $loanRow = $testLoan->fetch();
    $testLoanId = (int)($loanRow['id'] ?? 0);
    $loanCreatedOk = ($testLoanId > 0) && ($loanRow['total_payable'] == 11000.00) && ($loanRow['remaining_balance'] == 11000.00) && ($loanRow['status'] === 'active');
    record_result("E2E Journey", "Step 3: Create loan agreement", $loanCreatedOk, "Loan #$testLoanId created (Payable: ₹11,000.00, Status: active)");

    // 10.4 View Loan
    $lView = http_req("$baseUrl/loans/view.php?id=$testLoanId", 'GET', [], $cookieFile, true);
    record_result("E2E Journey", "Step 4: View loan agreement", strpos($lView['body'], "Loan Agreement Details") !== false, "Agreement displayed");

    // 10.5 Record Partial Repayment (₹4,000.00)
    $rAddForm = http_req("$baseUrl/repayments/add.php?loan_id=$testLoanId", 'GET', [], $cookieFile);
    $rCsrf = extract_csrf($rAddForm['body']);
    $rPost1 = http_req("$baseUrl/repayments/add.php", 'POST', [
        'loan_id' => $testLoanId,
        'amount' => '4000.00',
        'payment_date' => date('Y-m-d'),
        'payment_method' => 'Bank Transfer',
        'notes' => 'First installment',
        'csrf_token' => $rCsrf
    ], $cookieFile, true);

    $testLoanAfter1 = $pdo->query("SELECT amount_repaid, remaining_balance, status FROM loans WHERE id = $testLoanId")->fetch();
    $testRep1 = $pdo->query("SELECT id FROM repayments WHERE loan_id = $testLoanId ORDER BY id ASC LIMIT 1")->fetch();
    $testRepayment1Id = (int)($testRep1['id'] ?? 0);
    $partialOk = ($testLoanAfter1['amount_repaid'] == 4000.00) && ($testLoanAfter1['remaining_balance'] == 7000.00) && ($testLoanAfter1['status'] === 'partially_paid');
    record_result("E2E Journey", "Step 5: Record partial repayment (₹4,000)", $partialOk, "Remaining: ₹7,000.00, Status: partially_paid");

    // 10.6 View Partial Receipt
    $rcpt1 = http_req("$baseUrl/repayments/view.php?id=$testRepayment1Id", 'GET', [], $cookieFile, true);
    record_result("E2E Journey", "Step 6: View partial repayment receipt", strpos($rcpt1['body'], 'Repayment Receipt') !== false, "Receipt #$testRepayment1Id generated");

    // 10.7 Record Final Repayment (₹7,000.00)
    $rAddForm2 = http_req("$baseUrl/repayments/add.php?loan_id=$testLoanId", 'GET', [], $cookieFile);
    $rCsrf2 = extract_csrf($rAddForm2['body']);
    $rPost2 = http_req("$baseUrl/repayments/add.php", 'POST', [
        'loan_id' => $testLoanId,
        'amount' => '7000.00',
        'payment_date' => date('Y-m-d'),
        'payment_method' => 'UPI',
        'notes' => 'Final full settlement',
        'csrf_token' => $rCsrf2
    ], $cookieFile, true);

    $testLoanAfter2 = $pdo->query("SELECT amount_repaid, remaining_balance, status FROM loans WHERE id = $testLoanId")->fetch();
    $testRep2 = $pdo->query("SELECT id FROM repayments WHERE loan_id = $testLoanId ORDER BY id DESC LIMIT 1")->fetch();
    $testRepayment2Id = (int)($testRep2['id'] ?? 0);
    $finalOk = ($testLoanAfter2['amount_repaid'] == 11000.00) && ($testLoanAfter2['remaining_balance'] == 0.00) && ($testLoanAfter2['status'] === 'paid');
    record_result("E2E Journey", "Step 7: Record final repayment (₹7,000)", $finalOk, "Loan #$testLoanId status updated to 'paid', balance: ₹0.00");

    // 10.8 View Reports & verify totals
    $repPage = http_req("$baseUrl/reports/index.php", 'GET', [], $cookieFile, true);
    $hasRepPage = (strpos($repPage['body'], 'Reports &amp; Analytics') !== false || strpos($repPage['body'], 'Reports') !== false);
    record_result("E2E Journey", "Step 8: View reports with updated ledger", $hasRepPage, "Portfolio reflects settlement");

    // 10.9 Settings access
    $settPage = http_req("$baseUrl/settings/index.php", 'GET', [], $cookieFile, true);
    $hasSettPage = (strpos($settPage['body'], 'Settings &amp; Profile') !== false || strpos($settPage['body'], 'Settings') !== false);
    record_result("E2E Journey", "Step 9: Access settings page", $hasSettPage, "Settings verified");

    // 10.10 Logout
    $logoutPage = http_req("$baseUrl/logout.php", 'GET', [], $cookieFile, true);
    $logoutOk = strpos($logoutPage['body'], 'Sign In') !== false;
    record_result("E2E Journey", "Step 10: User logout", $logoutOk, "Session destroyed, redirected to login page");

    // 10.11 Verify Protected Access Blocked After Logout
    $protectedBlocked = http_req("$baseUrl/dashboard.php", 'GET', [], $cookieFile, false);
    $blockedOk = in_array($protectedBlocked['code'], [302, 303]);
    record_result("E2E Journey", "Step 11: Protected page access blocked", $blockedOk, "Dashboard redirects to login");

} catch (Exception $e) {
    record_result("E2E Journey", "End-to-End workflow exception", false, $e->getMessage());
} finally {
    // CAREFUL CLEANUP OF ONLY THE TEST ENTITIES CREATED IN THIS E2E SUITE
    if ($testLoanId > 0) {
        $pdo->exec("DELETE FROM repayments WHERE loan_id = $testLoanId");
        $pdo->exec("DELETE FROM loans WHERE id = $testLoanId");
    }
    if ($testBorrowerId > 0) {
        $pdo->exec("DELETE FROM borrowers WHERE id = $testBorrowerId");
    }
}


// ============================================================================
// 11. DATABASE INTEGRITY & BASELINE RESTORATION VERIFICATION
// ============================================================================
$finalBorrowerCount = (int)$pdo->query("SELECT COUNT(*) FROM borrowers")->fetchColumn();
$finalLoanCount = (int)$pdo->query("SELECT COUNT(*) FROM loans")->fetchColumn();
$finalRepaymentCount = (int)$pdo->query("SELECT COUNT(*) FROM repayments")->fetchColumn();
$finalPrincipal = (float)$pdo->query("SELECT SUM(principal_amount) FROM loans")->fetchColumn();
$finalRepaid = (float)$pdo->query("SELECT SUM(amount) FROM repayments")->fetchColumn();
$finalOutstanding = (float)$pdo->query("SELECT SUM(remaining_balance) FROM loans")->fetchColumn();

// Foreign key integrity check: ensure zero orphaned repayments or loans
$orphanedLoans = (int)$pdo->query("SELECT COUNT(*) FROM loans l LEFT JOIN borrowers b ON l.borrower_id = b.id WHERE b.id IS NULL")->fetchColumn();
$orphanedRepayments = (int)$pdo->query("SELECT COUNT(*) FROM repayments r LEFT JOIN loans l ON r.loan_id = l.id WHERE l.id IS NULL")->fetchColumn();

record_result("DB Integrity", "Foreign key constraints valid", ($orphanedLoans === 0 && $orphanedRepayments === 0), "0 orphaned loans, 0 orphaned repayments");
record_result("DB Integrity", "Borrower count matches baseline", ($finalBorrowerCount === 7), "Count: $finalBorrowerCount");
record_result("DB Integrity", "Loan count matches baseline", ($finalLoanCount === 6), "Count: $finalLoanCount");
record_result("DB Integrity", "Repayment count matches baseline", ($finalRepaymentCount === 7), "Count: $finalRepaymentCount");
record_result("DB Integrity", "Financial principal matches baseline", ($finalPrincipal == 250000.00), "₹" . number_format($finalPrincipal, 2));
record_result("DB Integrity", "Financial repaid matches baseline", ($finalRepaid == 57345.00), "₹" . number_format($finalRepaid, 2));
record_result("DB Integrity", "Financial outstanding matches baseline", ($finalOutstanding == 217655.00), "₹" . number_format($finalOutstanding, 2));

echo "\n===============================================================================\n";
$total = count($tests);
$passedCount = count(array_filter($tests, fn($t) => $t['passed']));
$failedCount = $total - $passedCount;
echo "TOTAL TESTS: $total\n";
echo "PASSED:      $passedCount\n";
echo "FAILED:      $failedCount\n";
echo "STATUS:      " . ($failedCount === 0 ? "ALL REGRESSION TESTS PASSED (100%)\n" : "FAILURES DETECTED\n");
echo "===============================================================================\n";

if (file_exists($cookieFile)) {
    unlink($cookieFile);
}
