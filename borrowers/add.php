<?php
/**
 * MoneyLend - Add New Borrower
 * 
 * Form to register a new client with validation, duplicate checks, and CSRF protection.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Protect page
require_login();

$pageTitle = "Add Borrower";
$activePage = "borrowers";
$errors = [];
$formData = [
    'full_name' => '',
    'phone'     => '',
    'email'     => '',
    'address'   => '',
    'status'    => 'active'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    // Verify CSRF
    if (!verify_csrf_token($csrfToken)) {
        $errors[] = "Security token mismatch. Please reload the form and try again.";
    }

    $formData['full_name'] = trim($_POST['full_name'] ?? '');
    $formData['phone']     = trim($_POST['phone'] ?? '');
    $formData['email']     = trim($_POST['email'] ?? '');
    $formData['address']   = trim($_POST['address'] ?? '');
    $formData['status']    = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

    // Validation
    if (empty($formData['full_name'])) {
        $errors[] = "Full Name is required.";
    } elseif (mb_strlen($formData['full_name']) > 150) {
        $errors[] = "Full Name must not exceed 150 characters.";
    }

    if (empty($formData['phone'])) {
        $errors[] = "Phone number is required.";
    } else {
        $digitsOnly = preg_replace('/\D/', '', $formData['phone']);
        if (str_starts_with($digitsOnly, '91') && strlen($digitsOnly) === 12) {
            $mobile10 = substr($digitsOnly, 2);
        } elseif (str_starts_with($digitsOnly, '0') && strlen($digitsOnly) === 11) {
            $mobile10 = substr($digitsOnly, 1);
        } else {
            $mobile10 = $digitsOnly;
        }

        if (!preg_match('/^[6-9]\d{9}$/', $mobile10)) {
            $errors[] = "Please provide a valid 10-digit Indian mobile number (e.g. 9876543210 or +91 98765 43210).";
        }
    }

    if (!empty($formData['email'])) {
        if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please provide a valid email address format.";
        } elseif (mb_strlen($formData['email']) > 150) {
            $errors[] = "Email must not exceed 150 characters.";
        }
    }

    // Check for duplicates in database
    if (empty($errors)) {
        try {
            $pdo = getDBConnection();

            // Duplicate phone check
            $stmt = $pdo->prepare("SELECT id FROM borrowers WHERE phone = :phone LIMIT 1");
            $stmt->execute([':phone' => $formData['phone']]);
            if ($stmt->fetch()) {
                $errors[] = "A borrower with the phone number '{$formData['phone']}' is already registered.";
            }

            // Duplicate email check if email is provided
            if (empty($errors) && !empty($formData['email'])) {
                $stmt = $pdo->prepare("SELECT id FROM borrowers WHERE email = :email LIMIT 1");
                $stmt->execute([':email' => $formData['email']]);
                if ($stmt->fetch()) {
                    $errors[] = "A borrower with the email '{$formData['email']}' is already registered.";
                }
            }

            // Insert new borrower
            if (empty($errors)) {
                $insertStmt = $pdo->prepare("INSERT INTO borrowers (full_name, phone, email, address, status, created_at) 
                                             VALUES (:full_name, :phone, :email, :address, :status, NOW())");
                $insertStmt->execute([
                    ':full_name' => $formData['full_name'],
                    ':phone'     => $formData['phone'],
                    ':email'     => !empty($formData['email']) ? $formData['email'] : null,
                    ':address'   => !empty($formData['address']) ? $formData['address'] : null,
                    ':status'    => $formData['status']
                ]);

                $newBorrowerId = (int)$pdo->lastInsertId();
                log_activity($pdo, 'borrower_create', 'borrower', $newBorrowerId, 'Registered borrower: ' . $formData['full_name']);

                set_flash('success', "Borrower created successfully.");
                header("Location: " . BASE_URL . "borrowers/index.php");
                exit;
            }
        } catch (PDOException $e) {
            error_log("Add borrower error: " . $e->getMessage());
            $errors[] = "Unable to save this record. Please verify the information and try again.";
        }
    }
}

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
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>borrowers/index.php" class="text-decoration-none">Borrowers</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Add Borrower</li>
                </ol>
            </nav>

            <!-- Page Title -->
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h1 class="page-title">Add New Borrower</h1>
                    <p class="page-subtitle">Register a new client profile into the lending directory</p>
                </div>
                <div>
                    <a href="<?php echo BASE_URL; ?>borrowers/index.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Back to Directory</span>
                    </a>
                </div>
            </div>

            <!-- Validation Errors Alert -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 mb-4" role="alert">
                    <h6 class="alert-heading fw-bold mb-2">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i> Please correct the following errors:
                    </h6>
                    <ul class="mb-0 ps-3 small">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Form Card -->
            <div class="row">
                <div class="col-12 col-lg-8">
                    <div class="content-card">
                        <div class="content-card-header">
                            <h2 class="content-card-title">
                                <i class="fa-solid fa-id-card me-2 text-primary"></i>Client Information
                            </h2>
                            <span class="text-muted small">* Required fields</span>
                        </div>

                        <div class="p-4">
                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" novalidate>
                                <?php echo csrf_field(); ?>

                                <!-- Full Name -->
                                <div class="mb-3">
                                    <label for="full_name" class="form-label small fw-semibold text-secondary">
                                        Full Name <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light text-muted">
                                            <i class="fa-solid fa-user"></i>
                                        </span>
                                        <input 
                                            type="text" 
                                            class="form-control" 
                                            id="full_name" 
                                            name="full_name" 
                                            placeholder="e.g. Ramesh Sharma" 
                                            value="<?php echo htmlspecialchars($formData['full_name']); ?>" 
                                            required 
                                            maxlength="150"
                                        >
                                    </div>
                                    <div class="form-text small">Enter legal full name as shown on official identity documents.</div>
                                </div>

                                <!-- Contact Information Row -->
                                <div class="row g-3 mb-3">
                                    <!-- Phone -->
                                    <div class="col-12 col-md-6">
                                        <label for="phone" class="form-label small fw-semibold text-secondary">
                                            Phone Number <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light text-muted">
                                                <i class="fa-solid fa-phone"></i>
                                            </span>
                                            <input 
                                                type="tel" 
                                                class="form-control" 
                                                id="phone" 
                                                name="phone" 
                                                placeholder="e.g. +91 98765 43210" 
                                                value="<?php echo htmlspecialchars($formData['phone']); ?>" 
                                                required 
                                                maxlength="20"
                                            >
                                        </div>
                                        <div class="form-text small">Primary mobile number for SMS and notifications.</div>
                                    </div>

                                    <!-- Email -->
                                    <div class="col-12 col-md-6">
                                        <label for="email" class="form-label small fw-semibold text-secondary">
                                            Email Address <span class="text-muted fw-normal">(Optional)</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light text-muted">
                                                <i class="fa-regular fa-envelope"></i>
                                            </span>
                                            <input 
                                                type="email" 
                                                class="form-control" 
                                                id="email" 
                                                name="email" 
                                                placeholder="e.g. ramesh@example.com" 
                                                value="<?php echo htmlspecialchars($formData['email']); ?>" 
                                                maxlength="150"
                                            >
                                        </div>
                                        <div class="form-text small">Optional email address for digital receipts.</div>
                                    </div>
                                </div>

                                <!-- Residential Address -->
                                <div class="mb-3">
                                    <label for="address" class="form-label small fw-semibold text-secondary">
                                        Residential / Business Address <span class="text-muted fw-normal">(Optional)</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light text-muted align-items-start pt-2">
                                            <i class="fa-solid fa-location-dot"></i>
                                        </span>
                                        <textarea 
                                            class="form-control" 
                                            id="address" 
                                            name="address" 
                                            rows="3" 
                                            placeholder="Street address, city, state, postal code..."
                                        ><?php echo htmlspecialchars($formData['address']); ?></textarea>
                                    </div>
                                </div>

                                <!-- Status -->
                                <div class="mb-4">
                                    <label class="form-label small fw-semibold text-secondary d-block">
                                        Account Status <span class="text-danger">*</span>
                                    </label>
                                    <div class="form-check form-check-inline">
                                        <input 
                                            class="form-check-input" 
                                            type="radio" 
                                            name="status" 
                                            id="statusActive" 
                                            value="active" 
                                            <?php echo ($formData['status'] === 'active') ? 'checked' : ''; ?>
                                        >
                                        <label class="form-check-label small" for="statusActive">
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Active</span> — Eligible for new loans
                                        </label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input 
                                            class="form-check-input" 
                                            type="radio" 
                                            name="status" 
                                            id="statusInactive" 
                                            value="inactive" 
                                            <?php echo ($formData['status'] === 'inactive') ? 'checked' : ''; ?>
                                        >
                                        <label class="form-check-label small" for="statusInactive">
                                            <span class="badge bg-secondary-subtle text-secondary border px-2 py-1">Inactive</span> — Ineligible / on hold
                                        </label>
                                    </div>
                                </div>

                                <hr class="my-4">

                                <!-- Submit and Cancel Buttons -->
                                <div class="d-flex align-items-center justify-content-end gap-2">
                                    <a href="<?php echo BASE_URL; ?>borrowers/index.php" class="btn btn-light border px-3">
                                        Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary px-4 fw-semibold d-flex align-items-center gap-2">
                                        <i class="fa-solid fa-check"></i>
                                        <span>Save Borrower</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Info Side Column -->
                <div class="col-12 col-lg-4 mt-4 mt-lg-0">
                    <div class="content-card mb-3">
                        <div class="content-card-header">
                            <h3 class="content-card-title text-secondary">
                                <i class="fa-solid fa-circle-question me-1 text-primary"></i> Lending Best Practices
                            </h3>
                        </div>
                        <div class="p-3 small text-muted">
                            <p class="mb-2"><strong>Identity Verification:</strong> Always verify client identity proofs (Aadhaar, PAN, voter card) before disbursing loans.</p>
                            <p class="mb-2"><strong>Accurate Contact Info:</strong> Double check mobile numbers to ensure borrower can be reached for due date reminders.</p>
                            <p class="mb-0"><strong>Status Management:</strong> Inactive borrowers can be re-activated at any time.</p>
                        </div>
                    </div>
                </div>
            </div>

        </div> <!-- End .content-container -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
