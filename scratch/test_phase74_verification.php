<?php
/**
 * MoneyLend - Phase 7.4 Error & Success Handling Verification
 *
 * Verifies:
 * 1. Safe handling of invalid IDs (borrowers/view.php, loans/view.php, repayments/view.php)
 * 2. Flash message and alert rendering without SQL/PHP errors or credential exposure
 * 3. PRG redirects on POST endpoints
 * 4. Empty state presentation on search filter queries with 0 results
 * 5. Preservation of submitted form inputs upon validation failure
 */

$baseUrl = 'http://localhost/moneylend';
$cookieFile = __DIR__ . '/test_phase74_cookie.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

function http_request($url, $method = 'GET', $data = [], $cookieFile = null, $followLocation = true) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followLocation);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
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

$tests = [];
function record_result($testName, $passed, $details = '') {
    global $tests;
    $tests[] = ['name' => $testName, 'passed' => $passed, 'details' => $details];
    echo ($passed ? "[PASS] " : "[FAIL] ") . $testName . ($details ? " - $details" : "") . "\n";
}

echo "========================================================\n";
echo "MoneyLend — Phase 7.4 Verification Test Suite\n";
echo "========================================================\n\n";

// Step 1: Login
$loginPage = http_request("$baseUrl/login.php", 'GET', [], $cookieFile);
preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/i', $loginPage['body'], $csrfMatches);
$csrfToken = $csrfMatches[1] ?? '';

$loginRes = http_request("$baseUrl/login.php", 'POST', [
    'email' => 'admin@moneylend.local',
    'password' => 'admin123',
    'csrf_token' => $csrfToken
], $cookieFile, true);

record_result("Admin authentication", strpos($loginRes['body'], 'Dashboard') !== false, "Logged in as Admin");

// Step 2: Invalid ID handling - Borrowers
$bInvalidRes = http_request("$baseUrl/borrowers/view.php?id=999999", 'GET', [], $cookieFile, true);
$bPassed = strpos($bInvalidRes['body'], 'Borrower not found') !== false && strpos($bInvalidRes['body'], 'Fatal error') === false;
record_result("Invalid borrower ID (/borrowers/view.php?id=999999)", $bPassed, "Gracefully redirected with 'Borrower not found' alert");

// Step 3: Non-numeric borrower ID
$bNegativeRes = http_request("$baseUrl/borrowers/view.php?id=-5", 'GET', [], $cookieFile, true);
$bNegPassed = strpos($bNegativeRes['body'], 'Invalid borrower ID') !== false || strpos($bNegativeRes['body'], 'Borrower not found') !== false;
record_result("Negative borrower ID (/borrowers/view.php?id=-5)", $bNegPassed, "Gracefully handled invalid ID");

// Step 4: Invalid ID handling - Loans
$lInvalidRes = http_request("$baseUrl/loans/view.php?id=999999", 'GET', [], $cookieFile, true);
$lPassed = strpos($lInvalidRes['body'], 'Loan agreement #999999 was not found') !== false && strpos($lInvalidRes['body'], 'Fatal error') === false;
record_result("Invalid loan ID (/loans/view.php?id=999999)", $lPassed, "Gracefully redirected with 'Loan agreement #999999 was not found' alert");

// Step 5: Invalid ID handling - Repayments
$rInvalidRes = http_request("$baseUrl/repayments/view.php?id=999999", 'GET', [], $cookieFile, true);
$rPassed = strpos($rInvalidRes['body'], 'Repayment receipt #999999 was not found') !== false && strpos($rInvalidRes['body'], 'Fatal error') === false;
record_result("Invalid repayment ID (/repayments/view.php?id=999999)", $rPassed, "Gracefully redirected with friendly alert");

// Step 6: Empty States - Search Filter With No Results
$bSearchRes = http_request("$baseUrl/borrowers/index.php?search=xyznonexistentquery999", 'GET', [], $cookieFile, true);
$bSearchPassed = strpos($bSearchRes['body'], 'No borrowers match your search') !== false;
record_result("Borrowers empty state on 0 results search", $bSearchPassed, "Displayed 'No borrowers match your search'");

$lSearchRes = http_request("$baseUrl/loans/index.php?search=xyznonexistentquery999", 'GET', [], $cookieFile, true);
$lSearchPassed = strpos($lSearchRes['body'], 'No loans match your search') !== false || strpos($lSearchRes['body'], 'No loan records found') !== false;
record_result("Loans empty state on 0 results search", $lSearchPassed, "Displayed empty state component");

$rSearchRes = http_request("$baseUrl/repayments/index.php?search=xyznonexistentquery999", 'GET', [], $cookieFile, true);
$rSearchPassed = strpos($rSearchRes['body'], 'No repayments match your search criteria') !== false;
record_result("Repayments empty state on 0 results search", $rSearchPassed, "Displayed 'No repayments match your search criteria'");

// Step 7: Form state preservation on validation failure (Borrower Add)
// Get CSRF from borrowers/add.php
$bAddPage = http_request("$baseUrl/borrowers/add.php", 'GET', [], $cookieFile);
preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/i', $bAddPage['body'], $bCsrfMatches);
$bCsrf = $bCsrfMatches[1] ?? '';

// Post invalid phone but valid name and address
$bFailRes = http_request("$baseUrl/borrowers/add.php", 'POST', [
    'full_name' => 'Preserved Name Test',
    'phone' => '12345', // invalid phone
    'email' => 'validtest@example.com',
    'address' => '123 Preservation Street',
    'status' => 'active',
    'csrf_token' => $bCsrf
], $cookieFile, true);

$preservedName = strpos($bFailRes['body'], 'value="Preserved Name Test"') !== false;
$preservedEmail = strpos($bFailRes['body'], 'value="validtest@example.com"') !== false;
$preservedAddress = strpos($bFailRes['body'], '123 Preservation Street') !== false;
$hasValidationError = strpos($bFailRes['body'], 'Please provide a valid 10-digit Indian mobile number') !== false;
$formPreserved = $preservedName && $preservedEmail && $preservedAddress && $hasValidationError;
record_result("Form state preservation on validation error", $formPreserved, "Valid fields preserved, specific phone error displayed");

// Step 8: PRG Pattern verification - check Location header on successful POST
// Fetch loan add CSRF
$lAddPage = http_request("$baseUrl/loans/add.php", 'GET', [], $cookieFile);
preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/i', $lAddPage['body'], $lCsrfMatches);
$lCsrf = $lCsrfMatches[1] ?? '';

// Query one valid borrower
require_once __DIR__ . '/../config/database.php';
$pdo = getDBConnection();
$borrower = $pdo->query("SELECT id, full_name FROM borrowers WHERE status='active' LIMIT 1")->fetch();

// Test PRG on settings update (without changing crucial settings)
$settingsPage = http_request("$baseUrl/settings/index.php", 'GET', [], $cookieFile);
preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/i', $settingsPage['body'], $sCsrfMatches);
$sCsrf = $sCsrfMatches[1] ?? '';

$settingsPost = http_request("$baseUrl/settings/index.php", 'POST', [
    'form_action' => 'update_settings',
    'app_name' => 'MoneyLend',
    'currency_symbol' => '₹',
    'currency_name' => 'Indian Rupee (₹)',
    'date_format' => 'd/m/Y',
    'default_interest_rate' => '10.00',
    'default_interest_model' => 'flat',
    'default_duration_value' => '6',
    'default_duration_unit' => 'months',
    'default_payment_method' => 'Cash',
    'csrf_token' => $sCsrf
], $cookieFile, false); // Do not follow redirects so we can inspect the 302 / Location header!

$isPrg = in_array($settingsPost['code'], [302, 303]) && strpos($settingsPost['headers'], 'Location:') !== false;
record_result("POST/Redirect/GET pattern implementation", $isPrg, "HTTP {$settingsPost['code']} redirect with Location header");

// Step 9: Verify no raw database errors or credentials in responses
$bCheckText = $bInvalidRes['body'] . $lInvalidRes['body'] . $rInvalidRes['body'] . $bFailRes['body'];
$noSqlExposed = (strpos($bCheckText, 'SQLSTATE') === false) && 
                (strpos($bCheckText, 'PDOException') === false) && 
                (strpos($bCheckText, 'DB_PASS') === false) &&
                (strpos($bCheckText, 'Fatal error') === false);
record_result("Information leakage audit", $noSqlExposed, "No SQLSTATE, PDOException, DB credentials or PHP Fatal errors exposed");

echo "\n--------------------------------------------------------\n";
$total = count($tests);
$passedCount = count(array_filter($tests, fn($t) => $t['passed']));
$failedCount = $total - $passedCount;
echo "TOTAL TESTS: $total\n";
echo "PASSED: $passedCount\n";
echo "FAILED: $failedCount\n";
echo "========================================================\n";

if (file_exists($cookieFile)) {
    unlink($cookieFile);
}
