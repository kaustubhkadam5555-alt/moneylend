<?php
/**
 * MoneyLend - Settings & Admin Profile
 * 
 * Central administrative interface for:
 * - Application Branding & Regional Settings (Name, Currency, Date Format)
 * - Loan & Repayment Defaults (Interest Rate, Model, Duration, Method)
 * - Administrator Profile Management (Name, Email, Avatar)
 * - Security & Password Updates (Current Password, New Password >= 8 chars)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings_helper.php';

// Enforce authentication
require_login();

$pageTitle = "Settings & Profile";
$activePage = "settings";

$pdo = getDBConnection();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

// Fetch fresh user data from database
try {
    $userStmt = $pdo->prepare("SELECT id, name, email, password, role, created_at FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $currentUserId]);
    $dbUser = $userStmt->fetch();
    if (!$dbUser) {
        logout_user();
        redirect(BASE_URL . "login.php");
    }
} catch (PDOException $e) {
    error_log("Settings user lookup error: " . $e->getMessage());
    $dbUser = [
        'id' => $currentUserId,
        'name' => $_SESSION['user_name'] ?? 'Admin User',
        'email' => $_SESSION['user_email'] ?? 'admin@moneylend.local',
        'role' => $_SESSION['user_role'] ?? 'admin',
        'created_at' => date('Y-m-d H:i:s')
    ];
}

// Current System Settings
$settings = get_all_settings();

$errors = [];
$successMessage = '';
$activeTab = $_GET['tab'] ?? 'general';

// Process POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        $errors[] = "Security token mismatch. Please reload the page and try again.";
    } else {
        $action = $_POST['form_action'] ?? '';

        // -------------------------------------------------------------
        // ACTION 1: UPDATE APPLICATION & DEFAULT SETTINGS
        // -------------------------------------------------------------
        if ($action === 'update_settings') {
            $activeTab = 'general';
            if (!is_admin()) {
                $errors[] = "Access denied. Only administrators can modify application settings.";
            } else {
                $appName         = trim($_POST['app_name'] ?? '');
            $currencySymbol  = trim($_POST['currency_symbol'] ?? '₹');
            $currencyName    = trim($_POST['currency_name'] ?? 'Indian Rupee (₹)');
            $dateFormat      = trim($_POST['date_format'] ?? 'd/m/Y');
            $defaultRate     = trim($_POST['default_interest_rate'] ?? '10.00');
            $defaultModel    = in_array($_POST['default_interest_model'] ?? '', ['flat', 'simple']) ? $_POST['default_interest_model'] : 'flat';
            $defaultDuration = (int)($_POST['default_duration_value'] ?? 6);
            $defaultUnit     = in_array($_POST['default_duration_unit'] ?? '', ['months', 'years']) ? $_POST['default_duration_unit'] : 'months';
            $defaultMethod   = in_array($_POST['default_payment_method'] ?? '', ['Cash', 'UPI', 'Bank Transfer', 'Cheque']) ? $_POST['default_payment_method'] : 'Cash';

            if (empty($appName)) {
                $errors[] = "Application Name is required.";
            } elseif (mb_strlen($appName) > 50) {
                $errors[] = "Application Name must not exceed 50 characters.";
            }

            if (empty($currencySymbol)) {
                $errors[] = "Currency symbol is required.";
            }

            if (!is_numeric($defaultRate) || (float)$defaultRate < 0) {
                $errors[] = "Default interest rate must be a valid non-negative percentage.";
            }

            if ($defaultDuration < 1) {
                $errors[] = "Default duration value must be at least 1.";
            }

            if (empty($errors)) {
                $newSettings = [
                    'app_name'               => $appName,
                    'currency_symbol'        => $currencySymbol,
                    'currency_name'          => $currencyName,
                    'date_format'            => $dateFormat,
                    'default_interest_rate'  => number_format((float)$defaultRate, 2, '.', ''),
                    'default_interest_model' => $defaultModel,
                    'default_duration_value' => (string)$defaultDuration,
                    'default_duration_unit'  => $defaultUnit,
                    'default_payment_method' => $defaultMethod
                ];

                if (update_settings($newSettings)) {
                    log_activity($pdo, 'settings_update', 'settings', null, 'Updated system application settings');
                    set_flash('success', "Settings saved successfully.");
                    redirect(BASE_URL . "settings/index.php?tab=general");
                } else {
                    $errors[] = "Unable to save settings. Please try again.";
                }
            }
        }
    }

        // -------------------------------------------------------------
        // ACTION 2: UPDATE ADMIN PROFILE
        // -------------------------------------------------------------
        elseif ($action === 'update_profile') {
            $activeTab = 'profile';
            $adminName  = trim($_POST['admin_name'] ?? '');
            $adminEmail = trim($_POST['admin_email'] ?? '');

            if (empty($adminName)) {
                $errors[] = "Admin Name is required.";
            } elseif (mb_strlen($adminName) > 100) {
                $errors[] = "Admin Name must not exceed 100 characters.";
            }

            if (empty($adminEmail)) {
                $errors[] = "Admin Email is required.";
            } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Please provide a valid email address.";
            } elseif (mb_strlen($adminEmail) > 150) {
                $errors[] = "Admin Email must not exceed 150 characters.";
            } else {
                // Check email uniqueness among other accounts
                try {
                    $chkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :my_id LIMIT 1");
                    $chkStmt->execute([':email' => $adminEmail, ':my_id' => $currentUserId]);
                    if ($chkStmt->fetch()) {
                        $errors[] = "The email address '{$adminEmail}' is already registered to another user.";
                    }
                } catch (PDOException $e) {
                    error_log("Email uniqueness check error: " . $e->getMessage());
                    $errors[] = "Database validation failed.";
                }
            }

            if (empty($errors)) {
                try {
                    $updateStmt = $pdo->prepare("UPDATE users SET name = :name, email = :email WHERE id = :id");
                    $updateStmt->execute([
                        ':name'  => $adminName,
                        ':email' => $adminEmail,
                        ':id'    => $currentUserId
                    ]);

                    // Update active session details
                    $_SESSION['user_name']  = $adminName;
                    $_SESSION['user_email'] = $adminEmail;

                    log_activity($pdo, 'profile_update', 'user', $currentUserId, 'Updated user profile: ' . $adminName);

                    set_flash('success', "Profile updated successfully.");
                    redirect(BASE_URL . "settings/index.php?tab=profile");
                } catch (PDOException $e) {
                    error_log("Profile update error: " . $e->getMessage());
                    $errors[] = "Unable to update profile. Please try again.";
                }
            }
        }

        // -------------------------------------------------------------
        // ACTION 3: CHANGE PASSWORD
        // -------------------------------------------------------------
        elseif ($action === 'change_password') {
            $activeTab = 'security';
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword     = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($currentPassword)) {
                $errors[] = "Please enter your current password.";
            } elseif (!password_verify($currentPassword, $dbUser['password'])) {
                $errors[] = "Current password is incorrect.";
            }

            $minPwdPolicy = (int)($settings['min_password_length'] ?? 8);
            if (empty($newPassword)) {
                $errors[] = "Please enter a new password.";
            } elseif (mb_strlen($newPassword) < $minPwdPolicy) {
                $errors[] = "New password must be at least {$minPwdPolicy} characters long.";
            }

            if ($newPassword !== $confirmPassword) {
                $errors[] = "New password and password confirmation do not match.";
            }

            if (empty($errors)) {
                try {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pwdStmt = $pdo->prepare("UPDATE users SET password = :pwd WHERE id = :id");
                    $pwdStmt->execute([
                        ':pwd' => $hashedPassword,
                        ':id'  => $currentUserId
                    ]);

                    log_activity($pdo, 'password_change', 'user', $currentUserId, 'Changed user account password');

                    set_flash('success', "Password changed successfully.");
                    redirect(BASE_URL . "settings/index.php?tab=security");
                } catch (PDOException $e) {
                    error_log("Password update error: " . $e->getMessage());
                    $errors[] = "Unable to change password. Please try again.";
                }
            }
        }

        // -------------------------------------------------------------
        // ACTION 3B: UPDATE SECURITY CONFIGURATION (Admin only)
        // -------------------------------------------------------------
        elseif ($action === 'update_security_settings') {
            $activeTab = 'security';
            if (!is_admin()) {
                $errors[] = "Access denied. Only administrators can modify security configurations.";
            } else {
                $sessionTimeout   = max(5, min(1440, (int)($_POST['session_timeout_minutes'] ?? 30)));
                $loginMaxAttempts = max(3, min(20, (int)($_POST['login_max_attempts'] ?? 5)));
                $loginLockout     = max(1, min(1440, (int)($_POST['login_lockout_minutes'] ?? 15)));
                $minPwdLength     = max(6, min(64, (int)($_POST['min_password_length'] ?? 8)));
                $auditRetention   = max(7, min(3650, (int)($_POST['audit_retention_days'] ?? 90)));

                $secSettings = [
                    'session_timeout_minutes' => (string)$sessionTimeout,
                    'login_max_attempts'      => (string)$loginMaxAttempts,
                    'login_lockout_minutes'   => (string)$loginLockout,
                    'min_password_length'     => (string)$minPwdLength,
                    'audit_retention_days'    => (string)$auditRetention,
                ];

                if (update_settings($secSettings)) {
                    log_activity($pdo, 'security_settings_update', 'settings', null, 'Updated system security policies and session configuration');
                    set_flash('success', "Security configurations saved successfully.");
                    redirect(BASE_URL . "settings/index.php?tab=security");
                } else {
                    $errors[] = "Unable to save security configurations. Please try again.";
                }
            }
        }

        // -------------------------------------------------------------
        // ACTION 4: UPDATE NOTIFICATION PREFERENCES
        // -------------------------------------------------------------
        elseif ($action === 'update_notification_settings') {
            $activeTab = 'notifications';
            $notifyDueSoon    = isset($_POST['notify_due_soon']) ? '1' : '0';
            $notifyDueToday   = isset($_POST['notify_due_today']) ? '1' : '0';
            $notifyOverdue    = isset($_POST['notify_overdue']) ? '1' : '0';
            $notifyRepayments = isset($_POST['notify_repayments']) ? '1' : '0';
            $notifyLoanPaid   = isset($_POST['notify_loan_paid']) ? '1' : '0';
            $dueDays          = max(1, min(30, (int)($_POST['reminder_due_days'] ?? 3)));
            $overdueInterval  = max(1, min(60, (int)($_POST['reminder_overdue_interval'] ?? 7)));

            $notifSettings = [
                'notify_due_soon'           => $notifyDueSoon,
                'notify_due_today'          => $notifyDueToday,
                'notify_overdue'            => $notifyOverdue,
                'notify_repayments'         => $notifyRepayments,
                'notify_loan_paid'          => $notifyLoanPaid,
                'reminder_due_days'         => (string)$dueDays,
                'reminder_overdue_interval' => (string)$overdueInterval
            ];

            if (update_settings($notifSettings)) {
                log_activity($pdo, 'settings_update', 'settings', null, 'Updated notification preferences');
                set_flash('success', "Notification preferences saved successfully.");
                redirect(BASE_URL . "settings/index.php?tab=notifications");
            } else {
                $errors[] = "Unable to save notification preferences. Please try again.";
            }
        }
    }
}

// Refresh settings for rendering
$settings = get_all_settings();

// Load layout header and top navbar
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="content-container">

            <!-- Breadcrumb Navigation -->
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb small">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard.php" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Settings</li>
                </ol>
            </nav>
    <!-- Page Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="page-title">Settings & Profile</h1>
            <p class="page-subtitle">Configure application preferences and administrator settings</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-secondary border px-3 py-2">
                <i class="fa-solid fa-server me-1 text-primary"></i> Production Ready
            </span>
        </div>
    </div>

    <!-- Flash Notifications -->
    <?php
    $flash = get_flash();
    if ($flash):
    ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="fa-solid <?php echo ($flash['type'] === 'success') ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger'; ?> fs-5"></i>
            <div><?php echo $flash['message']; ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <div class="d-flex align-items-center gap-2 fw-semibold mb-1">
                <i class="fa-solid fa-triangle-exclamation"></i> Action Required
            </div>
            <ul class="mb-0 ps-3 small">
                <?php foreach ($errors as $err): ?>
                    <li><?php echo htmlspecialchars($err); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills custom-settings-tabs mb-4 gap-2" id="settingsTab" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo ($activeTab === 'general') ? 'active' : ''; ?>" href="?tab=general">
                <i class="fa-solid fa-sliders me-2"></i> Application & Defaults
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo ($activeTab === 'profile') ? 'active' : ''; ?>" href="?tab=profile">
                <i class="fa-solid fa-user-gear me-2"></i> Admin Profile
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo ($activeTab === 'security') ? 'active' : ''; ?>" href="?tab=security">
                <i class="fa-solid fa-shield-halved me-2"></i> Security & Password
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo ($activeTab === 'notifications') ? 'active' : ''; ?>" href="?tab=notifications">
                <i class="fa-solid fa-bell me-2"></i> Notifications & Automation
            </a>
        </li>
    </ul>

    <!-- Tab 1: Application & Defaults -->
    <?php if ($activeTab === 'general'): ?>
        <div class="row g-4">
            <!-- Left Column: Form -->
            <div class="col-lg-8">
                <div class="card content-card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <div class="d-flex align-items-center gap-2">
                            <span class="brand-icon-box" style="width: 32px; height: 32px; font-size: 0.85rem;">
                                <i class="fa-solid fa-sliders"></i>
                            </span>
                            <div>
                                <h2 class="content-card-title mb-0">System Preferences & Lending Defaults</h2>
                                <span class="text-muted small">Configure default parameters applied to new loan agreements and regional formats.</span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="<?php echo BASE_URL; ?>settings/index.php?tab=general" class="needs-validation" novalidate>
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="form_action" value="update_settings">

                            <!-- Section A: Application Settings -->
                            <h5 class="fw-bold text-dark mb-3 pb-2 border-bottom d-flex align-items-center gap-2">
                                <i class="fa-solid fa-building-columns text-primary"></i> Application Settings
                            </h5>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="app_name" class="form-label fw-semibold">Application Name <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-tag"></i></span>
                                        <input type="text" class="form-control" id="app_name" name="app_name" 
                                               value="<?php echo htmlspecialchars($settings['app_name'] ?? 'MoneyLend'); ?>" 
                                               required maxlength="50">
                                    </div>
                                    <div class="form-text">Displayed on the navbar, browser tab, reports, and payment vouchers.</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="currency_symbol" class="form-label fw-semibold">Currency <span class="text-danger">*</span></label>
                                    <select class="form-select" id="currency_symbol" name="currency_symbol" onchange="syncCurrencyName(this)">
                                        <option value="₹" data-name="Indian Rupee (₹)" <?php echo (($settings['currency_symbol'] ?? '₹') === '₹') ? 'selected' : ''; ?>>₹ - Indian Rupee (INR)</option>
                                        <option value="$" data-name="US Dollar ($)" <?php echo (($settings['currency_symbol'] ?? '') === '$') ? 'selected' : ''; ?>>$ - US Dollar (USD)</option>
                                        <option value="€" data-name="Euro (€)" <?php echo (($settings['currency_symbol'] ?? '') === '€') ? 'selected' : ''; ?>>€ - Euro (EUR)</option>
                                        <option value="£" data-name="British Pound (£)" <?php echo (($settings['currency_symbol'] ?? '') === '£') ? 'selected' : ''; ?>>£ - British Pound (GBP)</option>
                                        <option value="¥" data-name="Japanese Yen (¥)" <?php echo (($settings['currency_symbol'] ?? '') === '¥') ? 'selected' : ''; ?>>¥ - Japanese Yen (JPY)</option>
                                        <option value="AED" data-name="UAE Dirham (AED)" <?php echo (($settings['currency_symbol'] ?? '') === 'AED') ? 'selected' : ''; ?>>AED - UAE Dirham</option>
                                    </select>
                                    <input type="hidden" name="currency_name" id="currency_name" value="<?php echo htmlspecialchars($settings['currency_name'] ?? 'Indian Rupee (₹)'); ?>">
                                    <div class="form-text">Active currency symbol for dashboard totals, balances, and receipts.</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="date_format" class="form-label fw-semibold">Date Format <span class="text-danger">*</span></label>
                                    <select class="form-select" id="date_format" name="date_format">
                                        <option value="d/m/Y" <?php echo (($settings['date_format'] ?? 'd/m/Y') === 'd/m/Y') ? 'selected' : ''; ?>>DD/MM/YYYY (e.g. <?php echo date('d/m/Y'); ?>)</option>
                                        <option value="Y-m-d" <?php echo (($settings['date_format'] ?? '') === 'Y-m-d') ? 'selected' : ''; ?>>YYYY-MM-DD (e.g. <?php echo date('Y-m-d'); ?>)</option>
                                        <option value="d-m-Y" <?php echo (($settings['date_format'] ?? '') === 'd-m-Y') ? 'selected' : ''; ?>>DD-MM-YYYY (e.g. <?php echo date('d-m-Y'); ?>)</option>
                                        <option value="M d, Y" <?php echo (($settings['date_format'] ?? '') === 'M d, Y') ? 'selected' : ''; ?>>Month DD, YYYY (e.g. <?php echo date('M d, Y'); ?>)</option>
                                    </select>
                                    <div class="form-text">Formatting applied across reports, tables, and financial dates.</div>
                                </div>
                            </div>

                            <!-- Section B: Loan Defaults -->
                            <h5 class="fw-bold text-dark mb-3 pb-2 border-bottom d-flex align-items-center gap-2">
                                <i class="fa-solid fa-file-invoice-dollar text-primary"></i> Loan Defaults
                            </h5>
                            <p class="text-muted small mb-3">
                                <i class="fa-solid fa-circle-info text-primary me-1"></i>
                                Important Note: Modifying defaults automatically pre-fills <strong>new loan agreements</strong>. Historical loans remain untouched for audit integrity.
                            </p>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="default_interest_rate" class="form-label fw-semibold">Default Interest Rate (%) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" step="0.01" min="0" max="100" class="form-control" id="default_interest_rate" 
                                               name="default_interest_rate" value="<?php echo htmlspecialchars($settings['default_interest_rate'] ?? '10.00'); ?>" required>
                                        <span class="input-group-text bg-light text-muted">%</span>
                                    </div>
                                    <div class="form-text">Initial percentage suggested on new loan agreements (e.g. 10.00%).</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="default_interest_model" class="form-label fw-semibold">Default Interest Model <span class="text-danger">*</span></label>
                                    <select class="form-select" id="default_interest_model" name="default_interest_model">
                                        <option value="flat" <?php echo (($settings['default_interest_model'] ?? 'flat') === 'flat') ? 'selected' : ''; ?>>Flat Interest (P × R / 100)</option>
                                        <option value="simple" <?php echo (($settings['default_interest_model'] ?? '') === 'simple') ? 'selected' : ''; ?>>Simple Interest (P × R × T / 100)</option>
                                    </select>
                                    <div class="form-text">Default calculation formula selected during loan creation.</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="default_duration_value" class="form-label fw-semibold">Default Loan Duration <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" min="1" max="120" class="form-control" id="default_duration_value" 
                                               name="default_duration_value" value="<?php echo (int)($settings['default_duration_value'] ?? 6); ?>" required>
                                        <select class="form-select" name="default_duration_unit" style="max-width: 140px;">
                                            <option value="months" <?php echo (($settings['default_duration_unit'] ?? 'months') === 'months') ? 'selected' : ''; ?>>Months</option>
                                            <option value="years" <?php echo (($settings['default_duration_unit'] ?? '') === 'years') ? 'selected' : ''; ?>>Years</option>
                                        </select>
                                    </div>
                                    <div class="form-text">Default tenure assigned when drafting a new agreement.</div>
                                </div>
                            </div>

                            <!-- Section C: Repayment Defaults -->
                            <h5 class="fw-bold text-dark mb-3 pb-2 border-bottom d-flex align-items-center gap-2">
                                <i class="fa-solid fa-money-bill-wave text-success"></i> Repayment Defaults
                            </h5>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="default_payment_method" class="form-label fw-semibold">Default Payment Method <span class="text-danger">*</span></label>
                                    <select class="form-select" id="default_payment_method" name="default_payment_method">
                                        <option value="Cash" <?php echo (($settings['default_payment_method'] ?? 'Cash') === 'Cash') ? 'selected' : ''; ?>>Cash</option>
                                        <option value="UPI" <?php echo (($settings['default_payment_method'] ?? '') === 'UPI') ? 'selected' : ''; ?>>UPI</option>
                                        <option value="Bank Transfer" <?php echo (($settings['default_payment_method'] ?? '') === 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                                        <option value="Cheque" <?php echo (($settings['default_payment_method'] ?? '') === 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                                    </select>
                                    <div class="form-text">Initial payment channel highlighted when logging installments.</div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Submit Buttons -->
                            <div class="d-flex justify-content-end gap-2">
                                <button type="reset" class="btn btn-outline-secondary px-3">
                                    <i class="fa-solid fa-rotate-left me-1"></i> Reset
                                </button>
                                <button type="submit" class="btn btn-primary px-4 fw-semibold">
                                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right Column: Information & Summary -->
            <div class="col-lg-4">
                <div class="card content-card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-bottom py-3">
                        <h6 class="fw-bold text-dark mb-0">Active Configuration Summary</h6>
                    </div>
                    <div class="card-body p-3">
                        <ul class="list-group list-group-flush small">
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                <span class="text-muted">App Name:</span>
                                <span class="fw-semibold text-dark"><?php echo htmlspecialchars($settings['app_name'] ?? 'MoneyLend'); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                <span class="text-muted">Currency:</span>
                                <span class="fw-semibold text-dark"><?php echo htmlspecialchars($settings['currency_symbol'] ?? '₹'); ?> (<?php echo htmlspecialchars($settings['currency_name'] ?? 'INR'); ?>)</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                <span class="text-muted">Date Format:</span>
                                <span class="badge bg-light text-secondary border"><?php echo htmlspecialchars($settings['date_format'] ?? 'd/m/Y'); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                <span class="text-muted">Default Rate:</span>
                                <span class="fw-semibold text-primary"><?php echo htmlspecialchars($settings['default_interest_rate'] ?? '10.00'); ?>%</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                <span class="text-muted">Interest Model:</span>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?php echo ucfirst($settings['default_interest_model'] ?? 'flat'); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                <span class="text-muted">Default Tenure:</span>
                                <span class="fw-semibold text-dark"><?php echo (int)($settings['default_duration_value'] ?? 6); ?> <?php echo htmlspecialchars(ucfirst($settings['default_duration_unit'] ?? 'months')); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                <span class="text-muted">Default Method:</span>
                                <span class="badge bg-success-subtle text-success border border-success-subtle"><?php echo htmlspecialchars($settings['default_payment_method'] ?? 'Cash'); ?></span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="card content-card border-0 shadow-sm bg-light">
                    <div class="card-body p-3 small text-muted">
                        <div class="d-flex align-items-center gap-2 fw-semibold text-dark mb-2">
                            <i class="fa-solid fa-shield-check text-success"></i> Audit & Financial Safety
                        </div>
                        <p class="mb-0">
                            Updating settings writes persistently to the MySQL database. Financial calculations for existing historical agreements remain protected against retroactive modifications.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    <!-- Tab 2: Admin Profile -->
    <?php elseif ($activeTab === 'profile'): ?>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card content-card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <div class="d-flex align-items-center gap-2">
                            <span class="brand-icon-box" style="width: 32px; height: 32px; font-size: 0.85rem;">
                                <i class="fa-solid fa-user-gear"></i>
                            </span>
                            <div>
                                <h2 class="content-card-title mb-0">Administrator Profile</h2>
                                <span class="text-muted small">Update your administrative name and primary system email address.</span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="<?php echo BASE_URL; ?>settings/index.php?tab=profile" class="needs-validation" novalidate>
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="form_action" value="update_profile">

                            <div class="mb-3">
                                <label for="admin_name" class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-user"></i></span>
                                    <input type="text" class="form-control" id="admin_name" name="admin_name" 
                                           value="<?php echo htmlspecialchars($dbUser['name'] ?? ''); ?>" required maxlength="100">
                                </div>
                                <div class="form-text">Displayed on recent repayments ledger, audit logs, and navigation bar.</div>
                            </div>

                            <div class="mb-3">
                                <label for="admin_email" class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-envelope"></i></span>
                                    <input type="email" class="form-control" id="admin_email" name="admin_email" 
                                           value="<?php echo htmlspecialchars($dbUser['email'] ?? ''); ?>" required maxlength="150">
                                </div>
                                <div class="form-text">Used to log in to the administrative portal.</div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold">Account Role</label>
                                <div>
                                    <span class="badge bg-primary px-3 py-2 fs-7">
                                        <i class="fa-solid fa-user-shield me-1"></i> <?php echo ucfirst(htmlspecialchars($dbUser['role'] ?? 'admin')); ?>
                                    </span>
                                </div>
                                <div class="form-text">Full system administrative access to loans, borrowers, repayments, and reports.</div>
                            </div>

                            <hr class="my-4">

                            <div class="d-flex justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary px-4 fw-semibold">
                                    <i class="fa-solid fa-check me-1"></i> Update Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Profile Overview Card -->
            <div class="col-lg-5">
                <div class="card content-card border-0 shadow-sm text-center p-4">
                    <div class="d-flex justify-content-center mb-3">
                        <div class="profile-large-avatar shadow-sm" style="width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #1e40af, #3b82f6); color: white; display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 700;">
                            <?php 
                                $initial = strtoupper(substr($dbUser['name'] ?? 'A', 0, 1));
                                echo htmlspecialchars($initial);
                            ?>
                        </div>
                    </div>
                    <h4 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($dbUser['name'] ?? 'Admin User'); ?></h4>
                    <p class="text-muted small mb-2"><?php echo htmlspecialchars($dbUser['email'] ?? ''); ?></p>
                    <div>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1">
                            <i class="fa-solid fa-shield-halved me-1"></i> System Administrator
                        </span>
                    </div>

                    <div class="border-top mt-4 pt-3 text-start small text-muted">
                        <div class="d-flex justify-content-between py-1">
                            <span>User ID:</span>
                            <span class="fw-semibold text-dark">#<?php echo (int)$dbUser['id']; ?></span>
                        </div>
                        <div class="d-flex justify-content-between py-1">
                            <span>Account Created:</span>
                            <span class="fw-semibold text-dark"><?php echo date('M d, Y', strtotime($dbUser['created_at'])); ?></span>
                        </div>
                        <div class="d-flex justify-content-between py-1">
                            <span>Session Status:</span>
                            <span class="badge bg-success-subtle text-success">Active & Protected</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- Tab 3: Security & Password -->
    <?php elseif ($activeTab === 'security'): 
        $currentMinPwd = (int)($settings['min_password_length'] ?? 8);
    ?>
        <div class="row g-4">
            <div class="col-lg-7">
                <!-- Change Password Card -->
                <div class="card content-card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <div class="d-flex align-items-center gap-2">
                            <span class="brand-icon-box" style="width: 32px; height: 32px; font-size: 0.85rem;">
                                <i class="fa-solid fa-key"></i>
                            </span>
                            <div>
                                <h2 class="content-card-title mb-0">Change Password</h2>
                                <span class="text-muted small">Update your account password (minimum <?php echo $currentMinPwd; ?> characters).</span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="<?php echo BASE_URL; ?>settings/index.php?tab=security" class="needs-validation" novalidate>
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="form_action" value="change_password">

                            <div class="mb-3">
                                <label for="current_password" class="form-label fw-semibold">Current Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-lock"></i></span>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required placeholder="Enter current password">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('current_password', this)">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text">Required to verify account ownership.</div>
                            </div>

                            <div class="mb-3">
                                <label for="new_password" class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-key"></i></span>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required minlength="<?php echo $currentMinPwd; ?>" placeholder="At least <?php echo $currentMinPwd; ?> characters">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('new_password', this)">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text">Must be at least <?php echo $currentMinPwd; ?> characters long. Passwords are encrypted with bcrypt.</div>
                            </div>

                            <div class="mb-4">
                                <label for="confirm_password" class="form-label fw-semibold">Confirm New Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-shield"></i></span>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="<?php echo $currentMinPwd; ?>" placeholder="Re-type new password">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('confirm_password', this)">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text">Must match the new password entered above.</div>
                            </div>

                            <hr class="my-4">

                            <div class="d-flex justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary px-4 fw-semibold">
                                    <i class="fa-solid fa-lock me-1"></i> Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Admin Security Configuration Card -->
                <?php if (is_admin()): ?>
                    <div class="card content-card border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom py-3 px-4">
                            <div class="d-flex align-items-center gap-2">
                                <span class="brand-icon-box" style="width: 32px; height: 32px; font-size: 0.85rem;">
                                    <i class="fa-solid fa-shield-halved"></i>
                                </span>
                                <div>
                                    <h2 class="content-card-title mb-0">System Security Policies</h2>
                                    <span class="text-muted small">Configure session limits, brute-force throttling, and password rules.</span>
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" action="<?php echo BASE_URL; ?>settings/index.php?tab=security" class="needs-validation" novalidate>
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="form_action" value="update_security_settings">

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label for="session_timeout_minutes" class="form-label fw-semibold small text-secondary">Session Inactivity Timeout (Minutes)</label>
                                        <input type="number" class="form-control" id="session_timeout_minutes" name="session_timeout_minutes" min="5" max="1440" value="<?php echo htmlspecialchars($settings['session_timeout_minutes'] ?? '30'); ?>" required>
                                        <div class="form-text">Auto-logs out inactive sessions (5 - 1440 mins).</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="min_password_length" class="form-label fw-semibold small text-secondary">Minimum Password Length (Chars)</label>
                                        <input type="number" class="form-control" id="min_password_length" name="min_password_length" min="6" max="64" value="<?php echo htmlspecialchars($settings['min_password_length'] ?? '8'); ?>" required>
                                        <div class="form-text">Enforced on password changes and user creation.</div>
                                    </div>
                                </div>

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label for="login_max_attempts" class="form-label fw-semibold small text-secondary">Max Failed Login Attempts</label>
                                        <input type="number" class="form-control" id="login_max_attempts" name="login_max_attempts" min="3" max="20" value="<?php echo htmlspecialchars($settings['login_max_attempts'] ?? '5'); ?>" required>
                                        <div class="form-text">Failed attempts before IP/account is throttled.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="login_lockout_minutes" class="form-label fw-semibold small text-secondary">Lockout Duration (Minutes)</label>
                                        <input type="number" class="form-control" id="login_lockout_minutes" name="login_lockout_minutes" min="1" max="1440" value="<?php echo htmlspecialchars($settings['login_lockout_minutes'] ?? '15'); ?>" required>
                                        <div class="form-text">Duration for temporary login rate limiting.</div>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label for="audit_retention_days" class="form-label fw-semibold small text-secondary">Audit Log Retention Policy (Days)</label>
                                    <input type="number" class="form-control" id="audit_retention_days" name="audit_retention_days" min="7" max="3650" value="<?php echo htmlspecialchars($settings['audit_retention_days'] ?? '90'); ?>" required>
                                    <div class="form-text">Target retention period for system audit trail logs.</div>
                                </div>

                                <hr class="my-4">

                                <div class="d-flex justify-content-end">
                                    <button type="submit" class="btn btn-secondary px-4 fw-semibold">
                                        <i class="fa-solid fa-shield me-1"></i> Save Security Policies
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Security Policy Card -->
            <div class="col-lg-5">
                <div class="card content-card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-bottom py-3">
                        <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-lock text-primary me-2"></i>Security Specifications &amp; Posture</h6>
                    </div>
                    <div class="card-body p-3 small text-muted">
                        <div class="d-flex align-items-start gap-2 mb-2">
                            <i class="fa-solid fa-check text-success mt-1"></i>
                            <div><strong>Bcrypt Password Hashing:</strong> Passwords are never stored as plain text. Stored with salted irreversible cryptographic hashes.</div>
                        </div>
                        <div class="d-flex align-items-start gap-2 mb-2">
                            <i class="fa-solid fa-check text-success mt-1"></i>
                            <div><strong>Session Protection:</strong> Strict session mode enabled with <code>HttpOnly</code> and <code>SameSite=Lax</code> to prevent cookie hijacking.</div>
                        </div>
                        <div class="d-flex align-items-start gap-2 mb-2">
                            <i class="fa-solid fa-check text-success mt-1"></i>
                            <div><strong>Brute-Force Rate Limiting:</strong> Enforces progressive throttling after <?php echo htmlspecialchars($settings['login_max_attempts'] ?? '5'); ?> failed attempts within <?php echo htmlspecialchars($settings['login_lockout_minutes'] ?? '15'); ?> minutes.</div>
                        </div>
                        <div class="d-flex align-items-start gap-2 mb-2">
                            <i class="fa-solid fa-check text-success mt-1"></i>
                            <div><strong>Session Inactivity Timeout:</strong> Automatic logout guard active at <?php echo htmlspecialchars($settings['session_timeout_minutes'] ?? '30'); ?> minutes of idle time.</div>
                        </div>
                        <div class="d-flex align-items-start gap-2 mb-2">
                            <i class="fa-solid fa-check text-success mt-1"></i>
                            <div><strong>CSRF Tokens:</strong> Every state-altering action validates a unique 256-bit cryptographic CSRF token.</div>
                        </div>
                        <div class="d-flex align-items-start gap-2 mb-2">
                            <i class="fa-solid fa-check text-success mt-1"></i>
                            <div><strong>Prepared Statements:</strong> All database queries use parameterized PDO bindings to mitigate SQL injection.</div>
                        </div>
                        <div class="d-flex align-items-start gap-2">
                            <i class="fa-solid fa-check text-success mt-1"></i>
                            <div><strong>Role-Based Access Control:</strong> Strict separation of privileges between administrators and operational staff.</div>
                        </div>

                        <?php if (is_admin()): ?>
                            <hr class="my-3">
                            <a href="<?php echo BASE_URL; ?>settings/users.php" class="btn btn-outline-primary btn-sm w-100">
                                <i class="fa-solid fa-user-shield me-1"></i> Manage System Users &amp; Roles
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <!-- Tab 4: Notifications & Automation -->
    <?php elseif ($activeTab === 'notifications'): ?>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card content-card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <div class="d-flex align-items-center gap-2">
                            <span class="brand-icon-box" style="width: 32px; height: 32px; font-size: 0.85rem;">
                                <i class="fa-solid fa-bell"></i>
                            </span>
                            <div>
                                <h2 class="content-card-title mb-0">Notification Preferences & Reminders</h2>
                                <span class="text-muted small">Configure automated alerts for maturities, overdue installments, and receipts.</span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="<?php echo BASE_URL; ?>settings/index.php?tab=notifications" class="needs-validation" novalidate>
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="form_action" value="update_notification_settings">

                            <h5 class="fw-bold text-dark mb-3 pb-2 border-bottom d-flex align-items-center gap-2">
                                <i class="fa-solid fa-clock-rotate-left text-primary"></i> Due-Date & Overdue Triggers
                            </h5>

                            <!-- Due Soon Alert -->
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="notify_due_soon" name="notify_due_soon" value="1" <?php echo (($settings['notify_due_soon'] ?? '1') === '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold text-dark" for="notify_due_soon">
                                    Enable "Due Soon" Reminders
                                </label>
                                <div class="text-muted small">Generate upcoming reminder when a loan approaches its maturity date.</div>
                            </div>

                            <div class="row g-2 align-items-center mb-4 ps-4">
                                <div class="col-auto">
                                    <label for="reminder_due_days" class="col-form-label small fw-semibold text-secondary">Advance notice threshold:</label>
                                </div>
                                <div class="col-auto" style="width: 100px;">
                                    <input type="number" class="form-control form-control-sm" id="reminder_due_days" name="reminder_due_days" min="1" max="30" value="<?php echo htmlspecialchars($settings['reminder_due_days'] ?? '3'); ?>">
                                </div>
                                <div class="col-auto">
                                    <span class="text-muted small">day(s) before due date</span>
                                </div>
                            </div>

                            <!-- Due Today Alert -->
                            <div class="form-check form-switch mb-4">
                                <input class="form-check-input" type="checkbox" role="switch" id="notify_due_today" name="notify_due_today" value="1" <?php echo (($settings['notify_due_today'] ?? '1') === '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold text-dark" for="notify_due_today">
                                    Enable "Due Today" Alert
                                </label>
                                <div class="text-muted small">Generate high-priority notification on the exact calendar due date of an active loan.</div>
                            </div>

                            <!-- Overdue Alert -->
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="notify_overdue" name="notify_overdue" value="1" <?php echo (($settings['notify_overdue'] ?? '1') === '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold text-dark" for="notify_overdue">
                                    Enable "Overdue Loan" Notifications
                                </label>
                                <div class="text-muted small">Generate warning notifications when loan agreements pass maturity with outstanding balance.</div>
                            </div>

                            <div class="row g-2 align-items-center mb-4 ps-4">
                                <div class="col-auto">
                                    <label for="reminder_overdue_interval" class="col-form-label small fw-semibold text-secondary">Recurring reminder interval:</label>
                                </div>
                                <div class="col-auto" style="width: 100px;">
                                    <input type="number" class="form-control form-control-sm" id="reminder_overdue_interval" name="reminder_overdue_interval" min="1" max="60" value="<?php echo htmlspecialchars($settings['reminder_overdue_interval'] ?? '7'); ?>">
                                </div>
                                <div class="col-auto">
                                    <span class="text-muted small">day(s) between consecutive notices (prevents spam)</span>
                                </div>
                            </div>

                            <h5 class="fw-bold text-dark mb-3 pb-2 border-bottom d-flex align-items-center gap-2">
                                <i class="fa-solid fa-receipt text-success"></i> Transaction Confirmation Alerts
                            </h5>

                            <!-- Repayment Logged Alert -->
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="notify_repayments" name="notify_repayments" value="1" <?php echo (($settings['notify_repayments'] ?? '1') === '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold text-dark" for="notify_repayments">
                                    Notify when Repayment is Recorded
                                </label>
                                <div class="text-muted small">Create an internal confirmation notice when an installment is successfully committed.</div>
                            </div>

                            <!-- Loan Paid Alert -->
                            <div class="form-check form-switch mb-4">
                                <input class="form-check-input" type="checkbox" role="switch" id="notify_loan_paid" name="notify_loan_paid" value="1" <?php echo (($settings['notify_loan_paid'] ?? '1') === '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold text-dark" for="notify_loan_paid">
                                    Notify when Loan is Fully Settled
                                </label>
                                <div class="text-muted small">Generate milestone alert when a loan reaches zero remaining balance and status becomes Paid.</div>
                            </div>

                            <hr class="my-4">

                            <div class="d-flex justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary px-4 fw-semibold">
                                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Notification Preferences
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right Column: Status & Architecture Details -->
            <div class="col-lg-5">
                <!-- Automation Engine Info -->
                <div class="card content-card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-bottom py-3">
                        <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                            <i class="fa-solid fa-robot text-primary"></i> Scheduled Automation
                        </h6>
                    </div>
                    <div class="card-body p-3 small text-muted">
                        <p class="mb-2">Automated checks can be run on-demand or scheduled via OS cron / Task Scheduler using the CLI runner:</p>
                        <div class="p-2 bg-light border rounded text-dark font-monospace mb-3" style="font-size: 0.75rem;">
                            php scripts/generate_notifications.php
                        </div>
                        <div class="d-flex align-items-start gap-2 mb-2">
                            <i class="fa-solid fa-check text-success mt-1"></i>
                            <div><strong>Idempotent:</strong> Running the CLI script repeatedly on the same day never produces duplicate alerts.</div>
                        </div>
                        <div class="d-flex align-items-start gap-2 mb-2">
                            <i class="fa-solid fa-check text-success mt-1"></i>
                            <div><strong>Read-Only Observer:</strong> Automation reads loan balances without modifying financial calculations.</div>
                        </div>
                        <div class="d-flex align-items-start gap-2">
                            <i class="fa-solid fa-check text-success mt-1"></i>
                            <div><strong>CLI Guarded:</strong> Script requires CLI execution or authorized execution to prevent unauthorized browser access.</div>
                        </div>
                    </div>
                </div>

                <!-- Email Architecture Card -->
                <div class="card content-card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-bottom py-3">
                        <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                            <i class="fa-solid fa-envelope text-info"></i> Email Delivery Architecture
                        </h6>
                    </div>
                    <div class="card-body p-3 small text-muted">
                        <?php 
                        $mailMode = strtolower(trim((string)get_env_var('MAIL_MODE', 'log')));
                        ?>
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <span>Delivery Mode (<code>MAIL_MODE</code>):</span>
                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle text-uppercase">
                                <?php echo htmlspecialchars($mailMode); ?>
                            </span>
                        </div>
                        <p class="mb-2">
                            In local XAMPP development, emails are safely logged to <code>logs/mail.log</code> without sending live messages or leaking credentials.
                        </p>
                        <div class="text-center pt-2">
                            <a href="<?php echo BASE_URL; ?>notifications/" class="btn btn-outline-primary btn-sm w-100">
                                <i class="fa-solid fa-bell me-1"></i> Open Notification Center
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<script>
function syncCurrencyName(selectElem) {
    const selectedOption = selectElem.options[selectElem.selectedIndex];
    const name = selectedOption.getAttribute('data-name') || selectElem.value;
    const nameField = document.getElementById('currency_name');
    if (nameField) {
        nameField.value = name;
    }
}

function togglePasswordVisibility(fieldId, btnElem) {
    const input = document.getElementById(fieldId);
    if (!input) return;
    const icon = btnElem.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        if (icon) {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        }
    } else {
        input.type = 'password';
        if (icon) {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
}
</script>

<style>
.custom-settings-tabs .nav-link {
    color: #4b5563;
    font-weight: 500;
    border-radius: 8px;
    padding: 0.6rem 1.2rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    transition: all 0.2s ease;
}
.custom-settings-tabs .nav-link:hover {
    background: #f1f5f9;
    color: #1e40af;
}
.custom-settings-tabs .nav-link.active {
    background: #1e40af;
    color: #ffffff;
    border-color: #1e40af;
}
</style>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
