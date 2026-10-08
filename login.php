<?php
/**
 * MoneyLend - Login Page
 * 
 * Provides administrative login interface.
 * Phase 1: UI Shell structured for Phase 2 authentication integration.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

require_once __DIR__ . '/config/database.php';

// If already logged in, redirect straight to dashboard
if (is_logged_in()) {
    header("Location: " . BASE_URL . "dashboard.php");
    exit;
}

$errorMessage = '';
$loggedOutMessage = '';
$inputEmail = '';

// Check if user was redirected from logout
if (isset($_GET['logged_out']) && $_GET['logged_out'] == '1') {
    $loggedOutMessage = "You have been logged out successfully.";
}

// Process login attempt with MySQL database and password_verify
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputEmail = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? '';

    // Verify CSRF token
    if (!verify_csrf_token($csrfToken)) {
        $errorMessage = "Security validation failed. Please refresh the page and try again.";
    } elseif (empty($inputEmail) || empty($password)) {
        $errorMessage = "Please enter both your email address and password.";
    } elseif (!filter_var($inputEmail, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = "Please enter a valid email address format.";
    } else {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT id, name, email, password, role FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $inputEmail]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Successful authentication
                login_user([
                    'id'    => (int)$user['id'],
                    'name'  => $user['name'],
                    'email' => $user['email'],
                    'role'  => $user['role']
                ]);

                log_activity($pdo, 'login', 'user', (int)$user['id'], 'User signed in: ' . $user['name'], (int)$user['id']);

                set_flash('success', 'Welcome back, ' . htmlspecialchars($user['name']) . '! You have logged in successfully.');
                header("Location: " . BASE_URL . "dashboard.php");
                exit;
            } else {
                // Generic error to prevent user enumeration
                $errorMessage = "Invalid email or password. Please verify your credentials and try again.";
            }
        } catch (PDOException $e) {
            error_log("Login database error: " . $e->getMessage());
            $errorMessage = "A system error occurred while verifying credentials. Please try again later.";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MoneyLend - Sign In. Manage borrowers, loans, and repayments.">
    <title>Login — <?php echo APP_NAME; ?></title>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <!-- Font Awesome 6 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- MoneyLend Custom Stylesheet -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
</head>
<body class="auth-page">

    <div class="auth-card text-center">
        <!-- Logo & Branding -->
        <div class="mb-3">
            <span class="auth-brand-logo">
                <i class="fa-solid fa-hand-holding-dollar"></i>
            </span>
            <h1 class="brand-text m-0">Money<span>Lend</span></h1>
            <p class="text-muted small mb-0 mt-1"><?php echo APP_TAGLINE; ?></p>
        </div>

        <hr class="my-3 opacity-25">

        <!-- Header -->
        <div class="text-start mb-3">
            <h2 class="h5 fw-bold text-dark mb-1">Welcome Back</h2>
            <p class="text-muted small mb-0">Sign in to manage loans, repayments, and borrowers.</p>
        </div>

        <!-- Logout / Flash notice -->
        <?php if (!empty($loggedOutMessage)): ?>
            <div class="alert alert-info alert-dismissible fade show text-start py-2 px-3 small border-0 shadow-sm" role="alert">
                <i class="fa-solid fa-circle-info me-1"></i> <?php echo htmlspecialchars($loggedOutMessage); ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Error Message -->
        <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger alert-dismissible fade show text-start py-2 px-3 small border-0 shadow-sm" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo htmlspecialchars($errorMessage); ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="text-start">
            <?php echo csrf_field(); ?>

            <!-- Email Field -->
            <div class="mb-3">
                <label for="email" class="form-label small fw-semibold text-secondary">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text bg-light text-muted border-end-0">
                        <i class="fa-regular fa-envelope"></i>
                    </span>
                    <input 
                        type="email" 
                        class="form-control border-start-0 ps-0" 
                        id="email" 
                        name="email" 
                        placeholder="name@example.com" 
                        value="<?php echo htmlspecialchars($inputEmail !== '' ? $inputEmail : 'admin@moneylend.local'); ?>" 
                        required 
                        autocomplete="email"
                    >
                </div>
            </div>

            <!-- Password Field -->
            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between">
                    <label for="password" class="form-label small fw-semibold text-secondary mb-1">Password</label>
                    <a href="javascript:void(0)" class="text-muted small text-decoration-none" title="Password reset available in Phase 2">Forgot?</a>
                </div>
                <div class="input-group">
                    <span class="input-group-text bg-light text-muted border-end-0">
                        <i class="fa-solid fa-lock"></i>
                    </span>
                    <input 
                        type="password" 
                        class="form-control border-start-0 ps-0" 
                        id="password" 
                        name="password" 
                        placeholder="Enter your password" 
                        value="" 
                        required 
                        autocomplete="current-password"
                    >
                </div>
            </div>

            <!-- Remember Me & Help -->
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="rememberMe" name="remember_me">
                <label class="form-check-label small text-muted" for="rememberMe">Remember me on this device</label>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold d-flex align-items-center justify-content-center gap-2 shadow-sm">
                <span>Sign In to Dashboard</span>
                <i class="fa-solid fa-arrow-right small"></i>
            </button>
        </form>

        <!-- Small Professional Footer -->
        <div class="mt-4 pt-2 border-top text-center text-muted" style="font-size: 0.78rem;">
            &copy; <?php echo date('Y'); ?> MoneyLend &bull; Secure Lending Portal
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/script.js"></script>
</body>
</html>
