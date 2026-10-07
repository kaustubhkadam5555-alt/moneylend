<?php
/**
 * MoneyLend - Edit Loan Agreement
 * 
 * Updates loan agreement parameters, recalculates interest & balance,
 * safeguards borrower_id if repayments exist, and updates status.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helper.php';

// Protect page
require_login();

$pageTitle = "Edit Loan Agreement";
$activePage = "loans";
$errors = [];
$pdo = getDBConnection();

$loanId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);

if ($loanId <= 0) {
    set_flash('danger', 'Invalid loan ID specified.');
    header("Location: " . BASE_URL . "loans/index.php");
    exit;
}

// Fetch existing loan
try {
    $stmt = $pdo->prepare("SELECT * FROM loans WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $loanId]);
    $loan = $stmt->fetch();

    if (!$loan) {
        set_flash('danger', "Loan agreement #{$loanId} was not found.");
        header("Location: " . BASE_URL . "loans/index.php");
        exit;
    }

    // Check if repayments exist
    $repCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM repayments WHERE loan_id = :id");
    $repCheckStmt->execute([':id' => $loanId]);
    $hasRepayments = ((int)$repCheckStmt->fetchColumn() > 0) || ((float)$loan['amount_repaid'] > 0);

    // Fetch all borrowers for dropdown
    $bStmt = $pdo->query("SELECT id, full_name, phone FROM borrowers ORDER BY full_name ASC");
    $borrowers = $bStmt->fetchAll();

} catch (PDOException $e) {
    error_log("Edit loan lookup error: " . $e->getMessage());
    set_flash('danger', 'A database error occurred.');
    header("Location: " . BASE_URL . "loans/index.php");
    exit;
}

$formData = [
    'borrower_id'      => (int)$loan['borrower_id'],
    'principal_amount' => (string)$loan['principal_amount'],
    'interest_rate'    => (string)$loan['interest_rate'],
    'interest_type'    => $loan['interest_type'],
    'duration_value'   => (int)$loan['duration_value'],
    'duration_unit'    => $loan['duration_unit'],
    'start_date'       => $loan['start_date'],
    'due_date'         => $loan['due_date'],
    'status'           => $loan['status'],
    'notes'            => $loan['notes'] ?? ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    // Verify CSRF
    if (!verify_csrf_token($csrfToken)) {
        $errors[] = "Security token mismatch. Please reload the form and try again.";
    }

    // If repayments exist, borrower_id CANNOT be changed
    if (!$hasRepayments) {
        $formData['borrower_id'] = (int)($_POST['borrower_id'] ?? 0);
    }

    $formData['principal_amount'] = trim($_POST['principal_amount'] ?? '');
    $formData['interest_rate']    = trim($_POST['interest_rate'] ?? '');
    $formData['interest_type']    = in_array($_POST['interest_type'] ?? '', ['flat', 'simple']) ? $_POST['interest_type'] : 'flat';
    $formData['duration_value']   = (int)($_POST['duration_value'] ?? 1);
    $formData['duration_unit']    = in_array($_POST['duration_unit'] ?? '', ['months', 'years']) ? $_POST['duration_unit'] : 'months';
    $formData['start_date']       = trim($_POST['start_date'] ?? '');
    $formData['due_date']         = trim($_POST['due_date'] ?? '');
    $formData['status']           = in_array($_POST['status'] ?? '', ['active', 'partially_paid', 'paid', 'overdue', 'cancelled']) ? $_POST['status'] : $loan['status'];
    $formData['notes']            = trim($_POST['notes'] ?? '');

    // Validation
    if ($formData['borrower_id'] <= 0) {
        $errors[] = "Please select a valid borrower.";
    }

    if ($formData['principal_amount'] === '' || !is_numeric($formData['principal_amount'])) {
        $errors[] = "Principal amount is required and must be a valid number.";
    } elseif ((float)$formData['principal_amount'] <= 0) {
        $errors[] = "Principal amount must be greater than zero.";
    }

    if ($formData['interest_rate'] === '' || !is_numeric($formData['interest_rate'])) {
        $errors[] = "Interest rate is required and must be numeric.";
    } elseif ((float)$formData['interest_rate'] < 0) {
        $errors[] = "Interest rate cannot be negative.";
    }

    if ($formData['duration_value'] <= 0) {
        $errors[] = "Duration value must be at least 1.";
    }

    if (empty($formData['start_date']) || !strtotime($formData['start_date'])) {
        $errors[] = "Please provide a valid start date.";
    }
    if (empty($formData['due_date']) || !strtotime($formData['due_date'])) {
        $formData['due_date'] = calculate_due_date($formData['start_date'], $formData['duration_value'], $formData['duration_unit']);
    } elseif ($formData['due_date'] <= $formData['start_date']) {
        $errors[] = "Due date must be after the start date.";
    }

    // Recalculate Financial Values
    if (empty($errors)) {
        try {
            $principal = (float)$formData['principal_amount'];
            $rate = (float)$formData['interest_rate'];

            $totals = calculate_loan_totals(
                $principal,
                $rate,
                $formData['interest_type'],
                $formData['duration_value'],
                $formData['duration_unit']
            );

            $totalInterest = $totals['total_interest'];
            $totalPayable = $totals['total_payable'];
            $amountRepaid = (float)$loan['amount_repaid'];

            if ($totalPayable < $amountRepaid) {
                $errors[] = "Total payable (" . CURRENCY_SYMBOL . "{$totalPayable}) cannot be less than the amount already repaid (" . CURRENCY_SYMBOL . "{$amountRepaid}).";
            } else {
                $remainingBalance = round($totalPayable - $amountRepaid, 2);

                // Automated status calculation unless explicitly cancelled
                $updatedStatus = $formData['status'];
                if ($updatedStatus !== 'cancelled') {
                    $updatedStatus = determine_loan_status($updatedStatus, $remainingBalance, $amountRepaid, $formData['due_date']);
                }

                $updateStmt = $pdo->prepare("
                    UPDATE loans SET
                        borrower_id = :borrower_id,
                        principal_amount = :principal_amount,
                        interest_rate = :interest_rate,
                        interest_type = :interest_type,
                        duration_value = :duration_value,
                        duration_unit = :duration_unit,
                        start_date = :start_date,
                        due_date = :due_date,
                        total_interest = :total_interest,
                        total_payable = :total_payable,
                        remaining_balance = :remaining_balance,
                        status = :status,
                        notes = :notes,
                        updated_at = NOW()
                    WHERE id = :id
                ");

                $updateStmt->execute([
                    ':borrower_id'      => $formData['borrower_id'],
                    ':principal_amount' => $principal,
                    ':interest_rate'    => $rate,
                    ':interest_type'    => $formData['interest_type'],
                    ':duration_value'   => $formData['duration_value'],
                    ':duration_unit'    => $formData['duration_unit'],
                    ':start_date'       => $formData['start_date'],
                    ':due_date'         => $formData['due_date'],
                    ':total_interest'   => $totalInterest,
                    ':total_payable'    => $totalPayable,
                    ':remaining_balance'=> $remainingBalance,
                    ':status'           => $updatedStatus,
                    ':notes'            => !empty($formData['notes']) ? $formData['notes'] : null,
                    ':id'               => $loanId
                ]);

                set_flash('success', "Loan updated successfully.");
                header("Location: " . BASE_URL . "loans/view.php?id=" . $loanId);
                exit;
            }
        } catch (PDOException $e) {
            error_log("Update loan error: " . $e->getMessage());
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
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>loans/index.php" class="text-decoration-none">Loans</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo $loanId; ?>" class="text-decoration-none">Loan #<?php echo $loanId; ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit</li>
                </ol>
            </nav>

            <!-- Page Title -->
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h1 class="page-title">Edit Loan Agreement #<?php echo $loanId; ?></h1>
                    <p class="page-subtitle">Modify parameters, interest rate, duration, or agreement notes</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo $loanId; ?>" class="btn btn-outline-primary btn-sm">
                        <i class="fa-solid fa-eye me-1"></i> View Details
                    </a>
                    <a href="<?php echo BASE_URL; ?>loans/index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fa-solid fa-arrow-left me-1"></i> Back to Loans
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

            <!-- Form Row -->
            <div class="row g-4">
                <div class="col-12 col-lg-8">
                    <div class="content-card">
                        <div class="content-card-header">
                            <h2 class="content-card-title">
                                <i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Agreement Parameters
                            </h2>
                            <span class="badge bg-light text-secondary border">Agreement #<?php echo $loanId; ?></span>
                        </div>

                        <div class="p-4">
                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $loanId; ?>" id="loanForm" novalidate>
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo $loanId; ?>">

                                <!-- Borrower Field -->
                                <div class="mb-3">
                                    <label for="borrower_id" class="form-label small fw-semibold text-secondary">
                                        Borrower Client <span class="text-danger">*</span>
                                    </label>
                                    <?php if ($hasRepayments): ?>
                                        <input type="hidden" name="borrower_id" value="<?php echo (int)$formData['borrower_id']; ?>">
                                        <select class="form-select bg-light" disabled>
                                            <?php foreach ($borrowers as $b): ?>
                                                <?php if ((int)$formData['borrower_id'] === (int)$b['id']): ?>
                                                    <option selected><?php echo htmlspecialchars($b['full_name']); ?> (Phone: <?php echo htmlspecialchars($b['phone']); ?>)</option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text small text-warning">
                                            <i class="fa-solid fa-lock me-1"></i> Borrower locked because repayments exist for this agreement.
                                        </div>
                                    <?php else: ?>
                                        <select class="form-select" id="borrower_id" name="borrower_id" required>
                                            <?php foreach ($borrowers as $b): ?>
                                                <option 
                                                    value="<?php echo (int)$b['id']; ?>" 
                                                    <?php echo ((int)$formData['borrower_id'] === (int)$b['id']) ? 'selected' : ''; ?>
                                                >
                                                    <?php echo htmlspecialchars($b['full_name']); ?> (Phone: <?php echo htmlspecialchars($b['phone']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>

                                <!-- Principal & Interest Rate Row -->
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-6">
                                        <label for="principal_amount" class="form-label small fw-semibold text-secondary">
                                            Principal Amount (<?php echo CURRENCY_SYMBOL; ?>) <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light text-muted fw-bold">
                                                <?php echo CURRENCY_SYMBOL; ?>
                                            </span>
                                            <input 
                                                type="number" 
                                                step="0.01" 
                                                min="1" 
                                                class="form-control" 
                                                id="principal_amount" 
                                                name="principal_amount" 
                                                value="<?php echo htmlspecialchars($formData['principal_amount']); ?>" 
                                                required
                                            >
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label for="interest_rate" class="form-label small fw-semibold text-secondary">
                                            Interest Rate (%) <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <input 
                                                type="number" 
                                                step="0.01" 
                                                min="0" 
                                                class="form-control" 
                                                id="interest_rate" 
                                                name="interest_rate" 
                                                value="<?php echo htmlspecialchars($formData['interest_rate']); ?>" 
                                                required
                                            >
                                            <span class="input-group-text bg-light text-muted">
                                                <i class="fa-solid fa-percent"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Interest Model & Duration -->
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-4">
                                        <label for="interest_type" class="form-label small fw-semibold text-secondary">
                                            Interest Model <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" id="interest_type" name="interest_type">
                                            <option value="flat" <?php echo ($formData['interest_type'] === 'flat') ? 'selected' : ''; ?>>Flat Interest</option>
                                            <option value="simple" <?php echo ($formData['interest_type'] === 'simple') ? 'selected' : ''; ?>>Simple Interest (Annual)</option>
                                        </select>
                                    </div>

                                    <div class="col-6 col-md-4">
                                        <label for="duration_value" class="form-label small fw-semibold text-secondary">
                                            Duration <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light text-muted">
                                                <i class="fa-regular fa-clock"></i>
                                            </span>
                                            <input 
                                                type="number" 
                                                min="1" 
                                                max="120" 
                                                class="form-control" 
                                                id="duration_value" 
                                                name="duration_value" 
                                                value="<?php echo htmlspecialchars($formData['duration_value']); ?>" 
                                                required
                                            >
                                        </div>
                                    </div>

                                    <div class="col-6 col-md-4">
                                        <label for="duration_unit" class="form-label small fw-semibold text-secondary">
                                            Unit <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" id="duration_unit" name="duration_unit">
                                            <option value="months" <?php echo ($formData['duration_unit'] === 'months') ? 'selected' : ''; ?>>Months</option>
                                            <option value="years" <?php echo ($formData['duration_unit'] === 'years') ? 'selected' : ''; ?>>Years</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Start & Due Dates -->
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-6">
                                        <label for="start_date" class="form-label small fw-semibold text-secondary">
                                            Start Date <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light text-muted">
                                                <i class="fa-regular fa-calendar-days"></i>
                                            </span>
                                            <input 
                                                type="date" 
                                                class="form-control" 
                                                id="start_date" 
                                                name="start_date" 
                                                value="<?php echo htmlspecialchars($formData['start_date']); ?>" 
                                                required
                                            >
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label for="due_date" class="form-label small fw-semibold text-secondary">
                                            Due Date <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light text-muted">
                                                <i class="fa-solid fa-calendar-check"></i>
                                            </span>
                                            <input 
                                                type="date" 
                                                class="form-control" 
                                                id="due_date" 
                                                name="due_date" 
                                                value="<?php echo htmlspecialchars($formData['due_date']); ?>" 
                                                required
                                            >
                                        </div>
                                    </div>
                                </div>

                                <!-- Status Selection -->
                                <div class="mb-3">
                                    <label for="status" class="form-label small fw-semibold text-secondary">
                                        Agreement Status <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="active" <?php echo ($formData['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="partially_paid" <?php echo ($formData['status'] === 'partially_paid') ? 'selected' : ''; ?>>Partially Paid</option>
                                        <option value="paid" <?php echo ($formData['status'] === 'paid') ? 'selected' : ''; ?>>Paid</option>
                                        <option value="overdue" <?php echo ($formData['status'] === 'overdue') ? 'selected' : ''; ?>>Overdue</option>
                                        <option value="cancelled" <?php echo ($formData['status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                    <div class="form-text small">Status will automatically synchronize with repayment balances unless set to Cancelled.</div>
                                </div>

                                <!-- Notes -->
                                <div class="mb-4">
                                    <label for="notes" class="form-label small fw-semibold text-secondary">
                                        Agreement Notes <span class="text-muted fw-normal">(Optional)</span>
                                    </label>
                                    <textarea 
                                        class="form-control" 
                                        id="notes" 
                                        name="notes" 
                                        rows="3"
                                    ><?php echo htmlspecialchars($formData['notes']); ?></textarea>
                                </div>

                                <hr class="my-4">

                                <!-- Buttons -->
                                <div class="d-flex align-items-center justify-content-end gap-2">
                                    <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo $loanId; ?>" class="btn btn-light border px-3">
                                        Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary px-4 fw-semibold d-flex align-items-center gap-2">
                                        <i class="fa-solid fa-floppy-disk"></i>
                                        <span>Update Agreement</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right: Financial Recalculation Card -->
                <div class="col-12 col-lg-4">
                    <div class="content-card sticky-top" style="top: 80px;">
                        <div class="content-card-header bg-light">
                            <h3 class="content-card-title text-secondary">
                                <i class="fa-solid fa-calculator me-2 text-primary"></i>Updated Financials
                            </h3>
                        </div>

                        <div class="p-3">
                            <ul class="list-group list-group-flush small mb-3">
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Updated Principal:</span>
                                    <span class="fw-bold text-dark" id="livePrincipal"><?php echo CURRENCY_SYMBOL . number_format((float)$loan['principal_amount'], 2); ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Updated Interest:</span>
                                    <span class="fw-bold text-primary" id="liveInterest"><?php echo CURRENCY_SYMBOL . number_format((float)$loan['total_interest'], 2); ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Total Payable:</span>
                                    <span class="fw-bold text-dark fs-6" id="liveTotalPayable"><?php echo CURRENCY_SYMBOL . number_format((float)$loan['total_payable'], 2); ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2 border-top">
                                    <span class="text-muted">Already Repaid:</span>
                                    <span class="fw-semibold text-success"><?php echo CURRENCY_SYMBOL . number_format((float)$loan['amount_repaid'], 2); ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Remaining Balance:</span>
                                    <span class="fw-bold text-danger" id="liveRemaining"><?php echo CURRENCY_SYMBOL . number_format((float)$loan['remaining_balance'], 2); ?></span>
                                </li>
                            </ul>

                            <div class="alert alert-secondary small mb-0 p-2 text-muted">
                                <i class="fa-solid fa-circle-info me-1"></i> Values are dynamically recalculated using selected parameters.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div> <!-- End .content-container -->

        <!-- Client-side Calculation Script -->
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const currencySymbol = '<?php echo CURRENCY_SYMBOL; ?>';
            const principalInput = document.getElementById('principal_amount');
            const rateInput = document.getElementById('interest_rate');
            const typeInput = document.getElementById('interest_type');
            const durationValueInput = document.getElementById('duration_value');
            const durationUnitInput = document.getElementById('duration_unit');
            const amountRepaid = <?php echo (float)$loan['amount_repaid']; ?>;

            const livePrincipal = document.getElementById('livePrincipal');
            const liveInterest = document.getElementById('liveInterest');
            const liveTotalPayable = document.getElementById('liveTotalPayable');
            const liveRemaining = document.getElementById('liveRemaining');

            function recalculate() {
                const principal = parseFloat(principalInput.value) || 0;
                const rate = parseFloat(rateInput.value) || 0;
                const type = typeInput.value;
                const durVal = parseInt(durationValueInput.value, 10) || 1;
                const durUnit = durationUnitInput.value;

                let interest = 0;
                if (type === 'simple') {
                    const timeInYears = (durUnit === 'years') ? durVal : (durVal / 12.0);
                    interest = (principal * rate * timeInYears) / 100.0;
                } else {
                    interest = (principal * rate) / 100.0;
                }

                interest = Math.round(interest * 100) / 100;
                const totalPayable = Math.round((principal + interest) * 100) / 100;
                const remaining = Math.max(0, Math.round((totalPayable - amountRepaid) * 100) / 100);

                livePrincipal.textContent = currencySymbol + principal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                liveInterest.textContent = currencySymbol + interest.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                liveTotalPayable.textContent = currencySymbol + totalPayable.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                liveRemaining.textContent = currencySymbol + remaining.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            [principalInput, rateInput, typeInput, durationValueInput, durationUnitInput].forEach(function (el) {
                el.addEventListener('input', recalculate);
                el.addEventListener('change', recalculate);
            });
        });
        </script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
