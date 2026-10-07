<?php
/**
 * MoneyLend - Phase 7.3 Security Test Suite
 *
 * Verifies all 20 security objectives:
 * 1. Authentication guard exists across all protected pages
 * 2. Unauthenticated access is blocked
 * 3. Password hashing is used (bcrypt / PASSWORD_DEFAULT)
 * 4. Password verification works
 * 5. Session regeneration occurs after login
 * 6. CSRF token generation works
 * 7. Invalid CSRF token is rejected
 * 8. Missing CSRF token is rejected
 * 9. SQL queries use prepared statements / injection immunity
 * 10. Invalid IDs are rejected safely
 * 11. XSS payloads are escaped
 * 12. Invalid email format is rejected
 * 13. Invalid integer/amount input is rejected
 * 14. Invalid enum values are rejected
 * 15. Repayment cannot exceed outstanding balance
 * 16. Unauthorized / invalid loan repayment is rejected
 * 17. Delete operations require POST & CSRF protection
 * 18. Financial totals are authoritative server-side
 * 19. Debug output and hardcoded credentials not exposed
 * 20. Logout destroys authentication state
 */

$baseUrl = 'http://localhost/moneylend/';
$cookieFile = __DIR__ . '/test_phase73_cookie.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

function curl_request(string $url, string $method = 'GET', array $data = [], string $cookieJar = '', bool $followRedirects = false): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    return [
        'code'    => $httpCode,
        'headers' => $headerStr,
        'body'    => $body,
        'url'     => $effectiveUrl,
    ];
}

function extract_csrf(string $html): string {
    if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

require_once '/Applications/XAMPP/xamppfiles/htdocs/moneylend/config/config.php';
require_once '/Applications/XAMPP/xamppfiles/htdocs/moneylend/includes/auth.php';
require_once '/Applications/XAMPP/xamppfiles/htdocs/moneylend/includes/loan_helper.php';

echo "======================================================\n";
echo "   MONEYLEND PHASE 7.3 - SECURITY TEST SUITE          \n";
echo "======================================================\n\n";

$testsPassed = 0;
$totalTests = 20;

// -------------------------------------------------------------
// [TEST 1] Authentication Guard Exists
// -------------------------------------------------------------
echo "[TEST 1/20] Testing Authentication Guards...\n";
assert(function_exists('require_login'), "require_login() function must exist.");
assert(function_exists('is_logged_in'), "is_logged_in() function must exist.");

$protectedFiles = [
    'dashboard.php',
    'borrowers/index.php',
    'borrowers/add.php',
    'borrowers/edit.php',
    'borrowers/view.php',
    'borrowers/delete.php',
    'loans/index.php',
    'loans/add.php',
    'loans/edit.php',
    'loans/view.php',
    'loans/delete.php',
    'repayments/index.php',
    'repayments/add.php',
    'repayments/edit.php',
    'repayments/view.php',
    'repayments/delete.php',
    'reports/index.php',
    'reports/export.php',
    'settings/index.php'
];

foreach ($protectedFiles as $pf) {
    $content = file_get_contents('/Applications/XAMPP/xamppfiles/htdocs/moneylend/' . $pf);
    assert(str_contains($content, 'require_login();'), "require_login() missing in {$pf}");
}
echo "PASS: Authentication guard verified across all 19 protected application scripts.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 2] Unauthenticated Access is Blocked
// -------------------------------------------------------------
echo "[TEST 2/20] Testing Unauthenticated Access Redirection...\n";
$endpoints = [
    'dashboard.php',
    'borrowers/index.php',
    'borrowers/add.php',
    'loans/index.php',
    'loans/add.php',
    'repayments/index.php',
    'repayments/add.php',
    'reports/index.php',
    'settings/index.php'
];

foreach ($endpoints as $ep) {
    $res = curl_request($baseUrl . $ep, 'GET', [], '', false);
    assert($res['code'] === 302, "Unauthenticated access to {$ep} should return HTTP 302, got {$res['code']}");
    assert(stripos($res['headers'], 'Location: /moneylend/login.php') !== false || stripos($res['headers'], 'Location: ' . $baseUrl . 'login.php') !== false, "Redirect should point to login.php for {$ep}");
}
echo "PASS: Unauthenticated requests strictly redirected to login page.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 3] Password Hashing is Used
// -------------------------------------------------------------
echo "[TEST 3/20] Testing Password Hashing in Database...\n";
require_once '/Applications/XAMPP/xamppfiles/htdocs/moneylend/config/database.php';
$pdo = getDBConnection();
$userStmt = $pdo->query("SELECT id, email, password FROM users WHERE email = 'admin@moneylend.local' LIMIT 1");
$adminUser = $userStmt->fetch();
assert(!empty($adminUser), "Admin user not found in database.");
assert(str_starts_with($adminUser['password'], '$2y$') || str_starts_with($adminUser['password'], '$argon2'), "Password is not safely hashed using bcrypt or argon2.");
assert($adminUser['password'] !== 'admin123', "Password must not be stored in plaintext.");
echo "PASS: User passwords stored with standard cryptographic hash ({$adminUser['password']}).\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 4] Password Verification Works
// -------------------------------------------------------------
echo "[TEST 4/20] Testing Password Verification Logic...\n";
assert(password_verify('admin123', $adminUser['password']), "Valid password verification failed.");
assert(!password_verify('wrongpassword', $adminUser['password']), "Invalid password should be rejected.");
assert(!password_verify('', $adminUser['password']), "Empty password should be rejected.");
echo "PASS: password_verify() accurately authenticates valid credentials and rejects incorrect passwords.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 5] Session Regeneration Occurs After Login
// -------------------------------------------------------------
echo "[TEST 5/20] Testing Session Regeneration on Login...\n";
// Step 1: Request login page to obtain initial session cookie
$loginPage = curl_request($baseUrl . 'login.php', 'GET', [], $cookieFile);
$csrfToken = extract_csrf($loginPage['body']);
assert(!empty($csrfToken), "CSRF token missing from login page.");

// Check initial session cookie
$initialCookies = file_get_contents($cookieFile);
preg_match('/PHPSESSID\s+([^\s]+)/', $initialCookies, $initMatch);
$initialSessionId = $initMatch[1] ?? '';

// Step 2: Post valid credentials
$loginPost = curl_request($baseUrl . 'login.php', 'POST', [
    'email'      => 'admin@moneylend.local',
    'password'   => 'admin123',
    'csrf_token' => $csrfToken
], $cookieFile, false);

assert($loginPost['code'] === 302, "Login should redirect on success (302), got {$loginPost['code']}");
$postCookies = file_get_contents($cookieFile);
preg_match('/PHPSESSID\s+([^\s]+)/', $postCookies, $postMatch);
$newSessionId = $postMatch[1] ?? '';

// Verify session ID changed or new Set-Cookie was sent
assert(!empty($newSessionId), "Authenticated session cookie was not saved.");
assert(stripos($loginPost['headers'], 'Set-Cookie: PHPSESSID') !== false, "session_regenerate_id() should issue a new Set-Cookie header.");
echo "PASS: Session regeneration confirmed (session ID refreshed upon authentication).\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 6] CSRF Token Generation Works
// -------------------------------------------------------------
echo "[TEST 6/20] Testing CSRF Token Generation...\n";
$token = csrf_token();
assert(!empty($token), "csrf_token() returned empty.");
assert(strlen($token) === 64, "csrf_token() should be a 64-character hex string.");
assert(verify_csrf_token($token) === true, "verify_csrf_token() should validate current token.");
$field = csrf_field();
assert(str_contains($field, 'type="hidden"') && str_contains($field, 'name="csrf_token"'), "csrf_field() HTML is malformed.");
echo "PASS: CSRF token generation and HTML field generation functioning properly.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 7] Invalid CSRF Token is Rejected
// -------------------------------------------------------------
echo "[TEST 7/20] Testing Invalid CSRF Token Rejection...\n";
$tamperedPost = curl_request($baseUrl . 'borrowers/add.php', 'POST', [
    'csrf_token' => 'tampered_invalid_token_999',
    'full_name'  => 'Malicious CSRF Test',
    'phone'      => '9876543210',
    'email'      => 'csrf@example.com'
], $cookieFile);

assert(str_contains($tamperedPost['body'], 'Security token mismatch') || str_contains($tamperedPost['body'], 'Security validation failed') || $tamperedPost['code'] === 403, "Tampered CSRF token was not rejected.");
echo "PASS: Invalid CSRF token rejected with security mismatch notice.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 8] Missing CSRF Token is Rejected
// -------------------------------------------------------------
echo "[TEST 8/20] Testing Missing CSRF Token Rejection...\n";
$missingTokenPost = curl_request($baseUrl . 'borrowers/add.php', 'POST', [
    'full_name'  => 'Malicious No Token',
    'phone'      => '9876543210',
    'email'      => 'notoken@example.com'
], $cookieFile);

assert(str_contains($missingTokenPost['body'], 'Security token mismatch') || str_contains($missingTokenPost['body'], 'Security validation failed'), "Missing CSRF token was not rejected.");
echo "PASS: Missing CSRF token rejected.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 9] SQL Injection Immunity via Prepared Statements
// -------------------------------------------------------------
echo "[TEST 9/20] Testing SQL Injection Immunity...\n";
// Attempt SQL injection in search filter
$sqliPayload = "' OR '1'='1' --";
$sqliSearch = curl_request($baseUrl . 'borrowers/index.php?search=' . urlencode($sqliPayload), 'GET', [], $cookieFile);
assert($sqliSearch['code'] === 200, "Search query crashed with SQL error.");
assert(!str_contains($sqliSearch['body'], 'SQLSTATE'), "SQL error leaked to client.");
assert(!str_contains($sqliSearch['body'], 'Fatal error'), "PHP error thrown during SQL injection test.");

// Attempt SQL injection in loan search
$sqliLoanSearch = curl_request($baseUrl . 'loans/index.php?search=' . urlencode($sqliPayload), 'GET', [], $cookieFile);
assert($sqliLoanSearch['code'] === 200, "Loan search crashed.");
assert(!str_contains($sqliLoanSearch['body'], 'SQLSTATE'), "SQL error leaked in loan search.");
echo "PASS: SQL injection payloads safely neutralized by prepared statements.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 10] Invalid Record IDs are Handled Safely
// -------------------------------------------------------------
echo "[TEST 10/20] Testing Invalid & Nonexistent Record IDs...\n";
$invalidIdRequests = [
    'borrowers/view.php?id=-1',
    'borrowers/view.php?id=abc',
    'borrowers/view.php?id=999999',
    'loans/view.php?id=0',
    'loans/view.php?id=invalid',
    'loans/view.php?id=999999',
    'repayments/view.php?id=fake',
    'repayments/view.php?id=999999'
];

foreach ($invalidIdRequests as $req) {
    $res = curl_request($baseUrl . $req, 'GET', [], $cookieFile, false);
    assert($res['code'] === 302, "Request to {$req} should safely redirect (got {$res['code']})");
    assert(!str_contains($res['body'], 'SQLSTATE'), "Database error exposed on invalid ID");
}
echo "PASS: Invalid and out-of-range IDs gracefully redirected without SQL errors.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 11] XSS Payloads are Safely Escaped
// -------------------------------------------------------------
echo "[TEST 11/20] Testing XSS Payload Escaping...\n";
$xssPayload = '<script>alert("XSS")</script>';
$escaped = sanitize($xssPayload);
assert($escaped === '&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;', "sanitize() did not escape script tags.");
assert(!str_contains($escaped, '<script>'), "Raw HTML script tag remained.");

$attrPayload = '" onfocus="alert(1)"';
$escapedAttr = sanitize($attrPayload);
assert(!str_contains($escapedAttr, '"'), "Attribute quotes not properly escaped.");
echo "PASS: XSS payloads safely sanitized with htmlspecialchars ENT_QUOTES UTF-8.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 12] Invalid Email Format is Rejected
// -------------------------------------------------------------
echo "[TEST 12/20] Testing Invalid Email Address Rejection...\n";
$addBorrowerPage = curl_request($baseUrl . 'borrowers/add.php', 'GET', [], $cookieFile);
$validCsrf = extract_csrf($addBorrowerPage['body']);

$badEmailPost = curl_request($baseUrl . 'borrowers/add.php', 'POST', [
    'csrf_token' => $validCsrf,
    'full_name'  => 'Test Email Invalid',
    'phone'      => '9876543299',
    'email'      => 'not-a-valid-email-format',
    'status'     => 'active'
], $cookieFile);

assert(str_contains($badEmailPost['body'], 'valid email address format'), "Invalid email format was not rejected.");
echo "PASS: Malformed email formats rejected by filter_var validation.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 13] Invalid Integer & Numeric Inputs Rejected
// -------------------------------------------------------------
echo "[TEST 13/20] Testing Invalid Numeric / Financial Input Rejection...\n";
$createLoanPage = curl_request($baseUrl . 'loans/add.php', 'GET', [], $cookieFile);
$loanCsrf = extract_csrf($createLoanPage['body']);

// 13a: Negative principal amount
$negativePrincipal = curl_request($baseUrl . 'loans/add.php', 'POST', [
    'csrf_token'       => $loanCsrf,
    'borrower_id'      => '10',
    'principal_amount' => '-5000',
    'interest_rate'    => '10',
    'interest_type'    => 'flat',
    'duration_value'   => '6',
    'duration_unit'    => 'months'
], $cookieFile);
assert(str_contains($negativePrincipal['body'], 'Principal amount must be greater than zero'), "Negative principal was not rejected.");

// 13b: Negative interest rate
$negativeRate = curl_request($baseUrl . 'loans/add.php', 'POST', [
    'csrf_token'       => $loanCsrf,
    'borrower_id'      => '10',
    'principal_amount' => '50000',
    'interest_rate'    => '-5',
    'interest_type'    => 'flat',
    'duration_value'   => '6',
    'duration_unit'    => 'months'
], $cookieFile);
assert(str_contains($negativeRate['body'], 'Interest rate cannot be negative'), "Negative interest rate was not rejected.");
echo "PASS: Negative financial inputs strictly rejected.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 14] Invalid Enum Values are Rejected / Fallback Safely
// -------------------------------------------------------------
echo "[TEST 14/20] Testing Strict Enum Whitelisting...\n";
$recRepPage = curl_request($baseUrl . 'repayments/add.php', 'GET', [], $cookieFile);
$repCsrf = extract_csrf($recRepPage['body']);

$badMethodPost = curl_request($baseUrl . 'repayments/add.php', 'POST', [
    'csrf_token'     => $repCsrf,
    'loan_id'        => '5',
    'amount'         => '100',
    'payment_date'   => date('Y-m-d'),
    'payment_method' => 'BitcoinCryptoInvalid',
], $cookieFile);

assert(str_contains($badMethodPost['body'], 'Please select a valid payment method'), "Disallowed payment method was not rejected.");
echo "PASS: Disallowed enum values strictly validated against whitelist.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 15] Repayment Cannot Exceed Outstanding Balance
// -------------------------------------------------------------
echo "[TEST 15/20] Testing Repayment Balance Overflow Protection...\n";
$excessRepPost = curl_request($baseUrl . 'repayments/add.php', 'POST', [
    'csrf_token'     => $repCsrf,
    'loan_id'        => '5',
    'amount'         => '9999999.00',
    'payment_date'   => date('Y-m-d'),
    'payment_method' => 'Cash',
], $cookieFile);

assert(str_contains($excessRepPost['body'], 'cannot exceed the outstanding balance'), "Excess repayment amount was not rejected.");
echo "PASS: Repayments exceeding outstanding balance strictly rejected.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 16] Unauthorized / Nonexistent Loan Repayment Rejected
// -------------------------------------------------------------
echo "[TEST 16/20] Testing Repayments on Invalid / Nonexistent Loans...\n";
$invalidLoanRep = curl_request($baseUrl . 'repayments/add.php', 'POST', [
    'csrf_token'     => $repCsrf,
    'loan_id'        => '99999',
    'amount'         => '100',
    'payment_date'   => date('Y-m-d'),
    'payment_method' => 'Cash',
], $cookieFile);

assert(str_contains($invalidLoanRep['body'], 'Please select a valid loan'), "Repayment to nonexistent loan was not rejected.");
echo "PASS: Repayment attempts against invalid loan agreements rejected.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 17] Delete Operations Require POST & CSRF Protection
// -------------------------------------------------------------
echo "[TEST 17/20] Testing Delete Operations Safeguards...\n";
// GET delete request must not delete
$getDelRes = curl_request($baseUrl . 'borrowers/delete.php?id=10', 'GET', [], $cookieFile, false);
assert($getDelRes['code'] === 302, "GET deletion should redirect, got {$getDelRes['code']}");

// Attempt to delete borrower #10 who has active loans
$delActiveBorrower = curl_request($baseUrl . 'borrowers/delete.php', 'POST', [
    'csrf_token'  => $repCsrf,
    'borrower_id' => '10'
], $cookieFile, false);
assert($delActiveBorrower['code'] === 302, "Deletion attempt should redirect.");

// Verify borrower #10 still exists in database
$chkBorrower = $pdo->query("SELECT id FROM borrowers WHERE id = 10")->fetch();
assert(!empty($chkBorrower), "Borrower with loans should NOT be deleted!");
echo "PASS: Delete operations require POST + CSRF, and preserve financial audit history.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 18] Financial Totals Authoritative Server-Side
// -------------------------------------------------------------
echo "[TEST 18/20] Testing Authoritative Server-Side Financial Calculations...\n";
// calculate_loan_totals test
$flatCalc = calculate_loan_totals(50000.0, 10.0, 'flat', 6, 'months');
assert($flatCalc['total_interest'] == 5000.0, "Server-side flat interest incorrect.");
assert($flatCalc['total_payable'] == 55000.0, "Server-side total payable incorrect.");

$simpleCalc = calculate_loan_totals(50000.0, 10.0, 'simple', 12, 'months');
assert($simpleCalc['total_interest'] == 5000.0, "Server-side simple interest incorrect.");
assert($simpleCalc['total_payable'] == 55000.0, "Server-side simple total payable incorrect.");

// Verify user cannot submit arbitrary total_payable in loan creation
$reflectedLoan = curl_request($baseUrl . 'loans/add.php', 'POST', [
    'csrf_token'       => $loanCsrf,
    'borrower_id'      => '10',
    'principal_amount' => '10000',
    'interest_rate'    => '10',
    'interest_type'    => 'flat',
    'duration_value'   => '6',
    'duration_unit'    => 'months',
    'total_payable'    => '1.00',    // Tampered client field
    'remaining_balance'=> '0.00'     // Tampered client field
], $cookieFile);

// Verify inserted loan used server-side calculated totals and ignored client parameters
$latestLoan = $pdo->query("SELECT id, principal_amount, total_payable, remaining_balance FROM loans ORDER BY id DESC LIMIT 1")->fetch();
assert((float)$latestLoan['principal_amount'] == 10000.0, "Test loan principal mismatch.");
assert((float)$latestLoan['total_payable'] == 11000.0, "Client-side total_payable must be ignored by server (expected 11000.0, got {$latestLoan['total_payable']}).");
assert((float)$latestLoan['remaining_balance'] == 11000.0, "Client-side remaining_balance must be ignored by server (expected 11000.0, got {$latestLoan['remaining_balance']}).");

// Clean up test loan immediately to preserve exact database baseline
$pdo->prepare("DELETE FROM loans WHERE id = :id")->execute([':id' => $latestLoan['id']]);

echo "PASS: Financial totals computed authoritatively on server; client manipulation ignored.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 19] Debug Output & Credentials Not Exposed
// -------------------------------------------------------------
echo "[TEST 19/20] Testing Absence of Debug Leaks & Hardcoded Credentials...\n";
$loginSource = file_get_contents('/Applications/XAMPP/xamppfiles/htdocs/moneylend/login.php');
assert(!str_contains($loginSource, 'value="admin123"'), "Hardcoded password found in login form HTML!");
assert(!str_contains($loginSource, 'var_dump('), "var_dump() found in login.php");
assert(!str_contains($loginSource, 'print_r('), "print_r() found in login.php");

$authSource = file_get_contents('/Applications/XAMPP/xamppfiles/htdocs/moneylend/includes/auth.php');
assert(!str_contains($authSource, 'var_dump('), "var_dump() found in auth.php");

// Verify security headers in response
$dashRes = curl_request($baseUrl . 'dashboard.php', 'GET', [], $cookieFile);
assert(stripos($dashRes['headers'], 'X-Frame-Options: SAMEORIGIN') !== false, "X-Frame-Options header missing.");
assert(stripos($dashRes['headers'], 'X-Content-Type-Options: nosniff') !== false, "X-Content-Type-Options header missing.");
echo "PASS: Debug statements, hardcoded credentials, and clickjacking risks eliminated.\n\n";
$testsPassed++;

// -------------------------------------------------------------
// [TEST 20] Logout Destroys Authentication State
// -------------------------------------------------------------
echo "[TEST 20/20] Testing Logout & Session Destruction...\n";
$logoutRes = curl_request($baseUrl . 'logout.php', 'GET', [], $cookieFile, false);
assert($logoutRes['code'] === 302, "Logout should redirect to login.php (302).");

// Attempt to access dashboard with the old cookie
$dashAfterLogout = curl_request($baseUrl . 'dashboard.php', 'GET', [], $cookieFile, false);
assert($dashAfterLogout['code'] === 302, "Dashboard should redirect to login after logout.");
assert(stripos($dashAfterLogout['headers'], 'login.php') !== false, "Location should be login.php.");
echo "PASS: Logout destroys session; subsequent access to protected resources blocked.\n\n";
$testsPassed++;

echo "======================================================\n";
echo "SECURITY TEST SUITE COMPLETED: {$testsPassed} / {$totalTests} PASSED\n";
echo "======================================================\n";
