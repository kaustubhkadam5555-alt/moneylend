<?php
/**
 * MoneyLend - Phase 13 Production Readiness & Smoke Test Suite
 *
 * Verifies:
 * 1. App Version & Metadata (v1.3.0)
 * 2. Environment Configuration Decoupling (.env support)
 * 3. Apache .htaccess Web Access Hardening (.env & database 403 Forbidden)
 * 4. Production Error Suppression Logic
 * 5. Secret Protection & Git Exclusions
 * 6. HTTPS & Session Cookie Security Parameters
 * 7. End-to-End Workflow & Smoke Navigation across all 12 major endpoints
 * 8. Financial Integrity Baseline Verification
 */

$test_count = 0;
$pass_count = 0;
$fail_count = 0;

function run_test($name, $assertion, $details = '') {
    global $test_count, $pass_count, $fail_count;
    $test_count++;
    if ($assertion) {
        $pass_count++;
        echo "  [PASS] Test {$test_count}: {$name}\n";
    } else {
        $fail_count++;
        echo "  [FAIL] Test {$test_count}: {$name}\n";
        if ($details) {
            echo "         Details: {$details}\n";
        }
    }
}

echo "============================================================\n";
echo "MoneyLend — Phase 13 Production Readiness & Smoke Test Suite\n";
echo "============================================================\n\n";

// Test 1: Application Version & Constants
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

run_test(
    "Application Version is configured as v1.3.0",
    defined('APP_VERSION') && APP_VERSION === '1.3.0',
    "Actual APP_VERSION: " . (defined('APP_VERSION') ? APP_VERSION : 'undefined')
);

// Test 2: Database Configuration Constants & Connection
$pdo = getDBConnection();
run_test(
    "Database connection established via PDO singleton",
    $pdo instanceof PDO,
    "PDO connection failed"
);

// Test 3: Environment Decoupling & Config Protection
$env_example = file_get_contents(__DIR__ . '/../.env.example');
$has_placeholders_only = strpos($env_example, 'your_db_password') !== false &&
                         strpos($env_example, 'your_smtp_password') !== false &&
                         strpos($env_example, 'admin123') === false;
run_test(
    ".env.example contains placeholders only with zero hardcoded credentials",
    $has_placeholders_only,
    ".env.example contains real credentials or missing placeholders"
);

// Test 4: Git Ignore for .env and sensitive files
$git_check = shell_exec('git check-ignore -q .env 2>&1; echo $?');
run_test(
    ".env is strictly ignored by Git (.gitignore verification)",
    trim($git_check) === '0',
    "git check-ignore returned exit code " . trim($git_check)
);

// Test 5: Apache .htaccess Protection for .env
$ch = curl_init('http://localhost/moneylend/.env');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_exec($ch);
$http_code_env = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

run_test(
    "Apache .htaccess blocks direct HTTP access to .env (HTTP 403 Forbidden)",
    $http_code_env === 403,
    "Expected 403, received HTTP {$http_code_env}"
);

// Test 6: Apache .htaccess Protection for database/schema.sql
$ch = curl_init('http://localhost/moneylend/database/schema.sql');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_exec($ch);
$http_code_schema = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

run_test(
    "Apache .htaccess blocks direct HTTP access to database/*.sql (HTTP 403 Forbidden)",
    $http_code_schema === 403,
    "Expected 403, received HTTP {$http_code_schema}"
);

// Test 7: Production Error Suppression Setting
$prod_suppression_working = true;
// When APP_ENV === 'production' or APP_DEBUG === false, display_errors should be '0' or off
if (!defined('APP_DEBUG') || APP_DEBUG === false) {
    $prod_suppression_working = (ini_get('display_errors') === '0' || ini_get('display_errors') === '');
}
run_test(
    "Production error suppression active (display_errors disabled)",
    $prod_suppression_working,
    "display_errors is " . ini_get('display_errors')
);

// Test 8: Session Security Configuration
$ch = curl_init('http://localhost/moneylend/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
$headers_output = curl_exec($ch);
curl_close($ch);

$has_httponly = (stripos($headers_output, 'HttpOnly') !== false);
$has_samesite = (stripos($headers_output, 'SameSite=Lax') !== false || stripos($headers_output, 'SameSite=Strict') !== false);

run_test(
    "Session cookie flags enforce HttpOnly and SameSite restrictions via HTTP headers",
    $has_httponly && $has_samesite,
    "Headers output: " . substr($headers_output, 0, 300)
);

// Test 9 & 10: End-to-End User Journey / Smoke Navigation
$cookie_file = __DIR__ . '/test_phase13_smoke_cookie.txt';
if (file_exists($cookie_file)) {
    unlink($cookie_file);
}

// 9.1: Fetch login page to get CSRF token
$ch = curl_init('http://localhost/moneylend/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
$login_html = curl_exec($ch);
curl_close($ch);

preg_match('/name="csrf_token"\s+value="([^"]+)"/', $login_html, $matches);
$csrf_token = $matches[1] ?? '';

// 9.2: Submit valid login credentials
$ch = curl_init('http://localhost/moneylend/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'email' => 'admin@moneylend.local',
    'password' => 'admin123',
    'csrf_token' => $csrf_token
]));
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_exec($ch);
$login_post_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 9.3: Verify authenticated smoke navigation across all core application modules
$modules = [
    'dashboard.php' => 'Dashboard',
    'borrowers/index.php' => 'Borrower',
    'loans/index.php' => 'Loan',
    'repayments/index.php' => 'Repayment',
    'reports/index.php' => 'Reports',
    'notifications/index.php' => 'Notification',
    'activity/index.php' => 'Activity',
    'settings/index.php' => 'Settings',
    'settings/index.php?tab=security' => 'Security',
    'settings/users.php' => 'User'
];

$all_modules_ok = ($login_post_code === 302);
$module_failures = [];

foreach ($modules as $uri => $keyword) {
    $ch = curl_init('http://localhost/moneylend/' . $uri);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || stripos($html, $keyword) === false) {
        $all_modules_ok = false;
        $module_failures[] = "{$uri} (code: {$code})";
    }
}

run_test(
    "End-to-End authenticated smoke navigation succeeds across all 10 core views",
    $all_modules_ok,
    "Module failures: " . implode(', ', $module_failures)
);

// Test 10: Clean Logout and Unauthenticated Redirection
$ch = curl_init('http://localhost/moneylend/logout.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_exec($ch);
$logout_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$ch = curl_init('http://localhost/moneylend/dashboard.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_exec($ch);
$dash_guard_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (file_exists($cookie_file)) {
    unlink($cookie_file);
}

run_test(
    "Logout destroys session and unauthenticated route guard redirects to login (HTTP 302)",
    $logout_code === 302 && $dash_guard_code === 302,
    "Logout code: {$logout_code}, Guard code: {$dash_guard_code}"
);

// Test 11: Financial Data & Portfolio Totals Baseline Integrity
$borrower_count = (int)$pdo->query("SELECT COUNT(*) FROM borrowers")->fetchColumn();
$loan_count = (int)$pdo->query("SELECT COUNT(*) FROM loans")->fetchColumn();
$repayment_count = (int)$pdo->query("SELECT COUNT(*) FROM repayments")->fetchColumn();

$financial_totals = $pdo->query("
    SELECT 
        COALESCE(SUM(principal_amount), 0) AS total_principal,
        COALESCE(SUM(amount_repaid), 0) AS total_repaid,
        COALESCE(SUM(remaining_balance), 0) AS total_outstanding
    FROM loans
")->fetch(PDO::FETCH_ASSOC);

$total_principal = (float)$financial_totals['total_principal'];
$total_repaid = (float)$financial_totals['total_repaid'];
$total_outstanding = (float)$financial_totals['total_outstanding'];

$financial_integrity_pass = (
    $borrower_count === 7 &&
    $loan_count === 6 &&
    $repayment_count === 7 &&
    abs($total_principal - 250000.00) < 0.01 &&
    abs($total_repaid - 57345.00) < 0.01 &&
    abs($total_outstanding - 217655.00) < 0.01
);

run_test(
    "Financial baseline integrity intact (7 borrowers, 6 loans, 7 repayments, ₹250k / ₹57,345 / ₹217,655)",
    $financial_integrity_pass,
    "Borrowers: {$borrower_count}, Loans: {$loan_count}, Repayments: {$repayment_count}, Principal: {$total_principal}, Repaid: {$total_repaid}, Outstanding: {$total_outstanding}"
);

// Test 12: Referential Integrity (Zero Orphaned Records)
$orphaned_loans = (int)$pdo->query("
    SELECT COUNT(*) FROM loans l 
    LEFT JOIN borrowers b ON l.borrower_id = b.id 
    WHERE b.id IS NULL
")->fetchColumn();

$orphaned_repayments = (int)$pdo->query("
    SELECT COUNT(*) FROM repayments r 
    LEFT JOIN loans l ON r.loan_id = l.id 
    WHERE l.id IS NULL
")->fetchColumn();

run_test(
    "Zero orphaned loans or repayments in relational database",
    $orphaned_loans === 0 && $orphaned_repayments === 0,
    "Orphaned loans: {$orphaned_loans}, Orphaned repayments: {$orphaned_repayments}"
);

echo "\n------------------------------------------------------------\n";
echo "Phase 13 Production Readiness Suite: {$pass_count} / {$test_count} Passed";
if ($fail_count > 0) {
    echo " ({$fail_count} FAILED)";
}
echo "\n------------------------------------------------------------\n\n";

exit($fail_count === 0 ? 0 : 1);
