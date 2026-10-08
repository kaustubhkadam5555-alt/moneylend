<?php
/**
 * MoneyLend - Edit Borrower
 * 
 * Form to update an existing borrower profile with validation and CSRF protection.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Protect page
require_login();

$pageTitle = "Edit Borrower";
$activePage = "borrowers";
$errors = [];
$pdo = getDBConnection();

// Get borrower ID from GET or POST
$borrowerId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);

if ($borrowerId <= 0) {
    set_flash('danger', 'Invalid borrower ID specified.');
    header("Location: " . BASE_URL . "borrowers/index.php");
    exit;
}

// Fetch existing borrower record
try {
    $stmt = $pdo->prepare("SELECT * FROM borrowers WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $borrowerId]);
    $borrower = $stmt->fetch();

    if (!$borrower) {
        set_flash('danger', 'The requested borrower was not found.');
        header("Location: " . BASE_URL . "borrowers/index.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Edit borrower lookup error: " . $e->getMessage());
    set_flash('danger', 'A database error occurred while fetching borrower details.');
    header("Location: " . BASE_URL . "borrowers/index.php");
    exit;
}

// Handle form submission
$formData = [
    'full_name' => $borrower['full_name'],
    'phone'     => $borrower['phone'],
    'email'     => $borrower['email'] ?? '',
    'address'   => $borrower['address'] ?? '',
    'status'    => $borrower['status'] ?? 'active'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    // Verify CSRF
    if (!verify_csrf_token($csrfToken)) {
        $errors[] = "Security token mismatch. Please reload the page and try again.";
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

    // Check for duplicates excluding current borrower
    if (empty($errors)) {
        try {
            // Duplicate phone check
            $stmt = $pdo->prepare("SELECT id FROM borrowers WHERE phone = :phone AND id != :id LIMIT 1");
            $stmt->execute([':phone' => $formData['phone'], ':id' => $borrowerId]);
            if ($stmt->fetch()) {
                $errors[] = "Another borrower already has the phone number '{$formData['phone']}'.";
            }

            // Duplicate email check
            if (empty($errors) && !empty($formData['email'])) {
                $stmt = $pdo->prepare("SELECT id FROM borrowers WHERE email = :email AND id != :id LIMIT 1");
                $stmt->execute([':email' => $formData['email'], ':id' => $borrowerId]);
                if ($stmt->fetch()) {
                    $errors[] = "Another borrower already has the email address '{$formData['email']}'.";
                }
            }

            // Update record
            if (empty($errors)) {
                $updateStmt = $pdo->prepare("UPDATE borrowers 
                    SET full_name = :full_name, phone = :phone, email = :email, address = :address, status = :status, updated_at = NOW() 
                    WHERE id = :id");
                
                $updateStmt->execute([
                    ':full_name' => $formData['full_name'],
                    ':phone'     => $formData['phone'],
                    ':email'     => !empty($formData['email']) ? $formData['email'] : null,
                    ':address'   => !empty($formData['address']) ? $formData['address'] : null,
                    ':status'    => $formData['status'],
                    ':id'        => $borrowerId
                ]);

                log_activity($pdo, 'borrower_update', 'borrower', $borrowerId, 'Updated borrower profile: ' . $formData['full_name']);

                set_flash('success', "Borrower updated successfully.");
                header("Location: " . BASE_URL . "borrowers/view.php?id=" . $borrowerId);
                exit;
            }
        } catch (PDOException $e) {
            error_log("Update borrower error: " . $e->getMessage());
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
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo $borrowerId; ?>" class="text-decoration-none"><?php echo htmlspecialchars($borrower['full_name']); ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit</li>
                </ol>
            </nav>

            <!-- Page Title -->
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h1 class="page-title">Edit Borrower</h1>
                    <p class="page-subtitle">Update contact details and status for <?php echo htmlspecialchars($borrower['full_name']); ?></p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo $borrowerId; ?>" class="btn btn-outline-primary btn-sm">
                        <i class="fa-solid fa-eye me-1"></i> View Profile
                    </a>
                    <a href="<?php echo BASE_URL; ?>borrowers/index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fa-solid fa-arrow-left me-1"></i> Back to Directory
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
                                <i class="fa-solid fa-user-pen me-2 text-primary"></i>Edit Client Details
                            </h2>
                            <span class="badge bg-light text-secondary border">Borrower ID #<?php echo $borrowerId; ?></span>
                        </div>

                        <div class="p-4">
                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $borrowerId; ?>" novalidate>
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo $borrowerId; ?>">

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
                                            value="<?php echo htmlspecialchars($formData['full_name']); ?>" 
                                            required 
                                            maxlength="150"
                                        >
                                    </div>
                                </div>

                                <!-- Contact Row -->
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
                                                value="<?php echo htmlspecialchars($formData['phone']); ?>" 
                                                required 
                                                maxlength="20"
                                            >
                                        </div>
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
                                                value="<?php echo htmlspecialchars($formData['email']); ?>" 
                                                maxlength="150"
                                            >
                                        </div>
                                    </div>
                                </div>

                                <!-- Address -->
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

                                <!-- Actions -->
                                <div class="d-flex align-items-center justify-content-end gap-2">
                                    <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo $borrowerId; ?>" class="btn btn-light border px-3">
                                        Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary px-4 fw-semibold d-flex align-items-center gap-2">
                                        <i class="fa-solid fa-floppy-disk"></i>
                                        <span>Update Borrower</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Info Side Column -->
                <div class="col-12 col-lg-4 mt-4 mt-lg-0">
                    <div class="content-card">
                        <div class="content-card-header">
                            <h3 class="content-card-title text-secondary">
                                <i class="fa-solid fa-clock-rotate-left me-1 text-primary"></i> Record History
                            </h3>
                        </div>
                        <div class="p-3 small text-muted">
                            <div class="mb-2">
                                <span class="d-block fw-semibold text-secondary">Registered On:</span>
                                <span><?php echo date('F d, Y — h:i A', strtotime($borrower['created_at'])); ?></span>
                            </div>
                            <div>
                                <span class="d-block fw-semibold text-secondary">Last Updated:</span>
                                <span><?php echo date('F d, Y — h:i A', strtotime($borrower['updated_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div> <!-- End .content-container -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
