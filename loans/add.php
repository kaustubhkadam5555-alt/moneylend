<?php
/**
 * MoneyLend - Create Loan Agreement
 * 
 * Creates a new loan record with real-time financial calculations,
 * borrower dropdown, duration units, and CSRF protection.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helper.php';

// Protect page
require_login();

$pageTitle = "Create Loan";
$activePage = "loans";
$errors = [];
$pdo = getDBConnection();

// Fetch active borrowers for dropdown
try {
    $borrowersStmt = $pdo->query("SELECT id, full_name, phone, status FROM borrowers ORDER BY full_name ASC");
    $borrowers = $borrowersStmt->fetchAll();
} catch (PDOException $e) {
    error_log("Borrowers dropdown error: " . $e->getMessage());
    $borrowers = [];
}

// Pre-fill borrower from query param if available (e.g. coming from borrower profile)
$preselectedBorrowerId = (int)($_GET['borrower_id'] ?? 0);

$defaultInterestRate  = get_setting('default_interest_rate', '10.0');
$defaultInterestModel = get_setting('default_interest_model', 'flat');
$defaultDurationVal   = (int)get_setting('default_duration_value', '6');
$defaultDurationUnit  = get_setting('default_duration_unit', 'months');

$formData = [
    'borrower_id'      => $preselectedBorrowerId > 0 ? $preselectedBorrowerId : '',
    'principal_amount' => '',
    'interest_rate'    => $defaultInterestRate,
    'interest_type'    => $defaultInterestModel,
    'duration_value'   => (string)$defaultDurationVal,
    'duration_unit'    => $defaultDurationUnit,
    'start_date'       => date('Y-m-d'),
    'due_date'         => calculate_due_date(date('Y-m-d'), $defaultDurationVal, $defaultDurationUnit),
    'status'           => 'active',
    'notes'            => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    // Verify CSRF
    if (!verify_csrf_token($csrfToken)) {
        $errors[] = "Security token mismatch. Please reload the form and try again.";
    }

    $formData['borrower_id']      = (int)($_POST['borrower_id'] ?? 0);
    $formData['principal_amount'] = trim($_POST['principal_amount'] ?? '');
    $formData['interest_rate']    = trim($_POST['interest_rate'] ?? '');
    $formData['interest_type']    = in_array($_POST['interest_type'] ?? '', ['flat', 'simple']) ? $_POST['interest_type'] : 'flat';
    $formData['duration_value']   = (int)($_POST['duration_value'] ?? 1);
    $formData['duration_unit']    = in_array($_POST['duration_unit'] ?? '', ['months', 'years']) ? $_POST['duration_unit'] : 'months';
    $formData['start_date']       = trim($_POST['start_date'] ?? date('Y-m-d'));
    $formData['due_date']         = trim($_POST['due_date'] ?? '');
    $formData['notes']            = trim($_POST['notes'] ?? '');

    // Server-side validation
    // 1. Borrower check
    if ($formData['borrower_id'] <= 0) {
        $errors[] = "Please select a valid borrower.";
    } else {
        $bCheck = $pdo->prepare("SELECT id, full_name, status FROM borrowers WHERE id = :id LIMIT 1");
        $bCheck->execute([':id' => $formData['borrower_id']]);
        $borrowerRecord = $bCheck->fetch();
        if (!$borrowerRecord) {
            $errors[] = "Selected borrower does not exist in the database.";
        }
    }

    // 2. Principal check
    if ($formData['principal_amount'] === '' || !is_numeric($formData['principal_amount'])) {
        $errors[] = "Principal amount is required and must be a valid number.";
    } elseif ((float)$formData['principal_amount'] <= 0) {
        $errors[] = "Principal amount must be greater than zero.";
    }

    // 3. Interest rate check
    if ($formData['interest_rate'] === '' || !is_numeric($formData['interest_rate'])) {
        $errors[] = "Interest rate is required and must be a valid percentage.";
    } elseif ((float)$formData['interest_rate'] < 0) {
        $errors[] = "Interest rate cannot be negative.";
    }

    // 4. Duration check
    if ($formData['duration_value'] <= 0) {
        $errors[] = "Duration value must be at least 1.";
    }

    // 5. Date validation
    if (empty($formData['start_date']) || !strtotime($formData['start_date'])) {
        $errors[] = "Please provide a valid start date.";
    }
    if (empty($formData['due_date']) || !strtotime($formData['due_date'])) {
        // Auto-calculate if empty
        $formData['due_date'] = calculate_due_date($formData['start_date'], $formData['duration_value'], $formData['duration_unit']);
    } elseif ($formData['due_date'] <= $formData['start_date']) {
        $errors[] = "Due date must be after the start date.";
    }

    // If valid, calculate financial totals and insert
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
            $amountRepaid = 0.00;
            $remainingBalance = $totalPayable;
            $status = 'active';

            $insertStmt = $pdo->prepare("
                INSERT INTO loans (
                    borrower_id, principal_amount, interest_rate, interest_type,
                    duration_value, duration_unit, start_date, due_date,
                    total_interest, total_payable, amount_repaid, remaining_balance,
                    status, notes, created_at
                ) VALUES (
                    :borrower_id, :principal_amount, :interest_rate, :interest_type,
                    :duration_value, :duration_unit, :start_date, :due_date,
                    :total_interest, :total_payable, :amount_repaid, :remaining_balance,
                    :status, :notes, NOW()
                )
            ");

            $insertStmt->execute([
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
                ':amount_repaid'    => $amountRepaid,
                ':remaining_balance'=> $remainingBalance,
                ':status'           => $status,
                ':notes'            => !empty($formData['notes']) ? $formData['notes'] : null,
            ]);

            $newLoanId = (int)$pdo->lastInsertId();
            set_flash('success', "Loan created successfully.");
            header("Location: " . BASE_URL . "loans/view.php?id=" . $newLoanId);
            exit;

        } catch (PDOException $e) {
            error_log("Create loan error: " . $e->getMessage());
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
                    <li class="breadcrumb-item active" aria-current="page">Create Loan</li>
                </ol>
            </nav>

            <!-- Page Title -->
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h1 class="page-title">Create Loan Agreement</h1>
                    <p class="page-subtitle">Configure principal amount, interest structure, and repayment terms</p>
                </div>
                <div>
                    <a href="<?php echo BASE_URL; ?>loans/index.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Back to Loans</span>
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

            <?php if (empty($borrowers)): ?>
                <div class="alert alert-warning shadow-sm border-0 mb-4 p-4">
                    <div class="d-flex align-items-center gap-3">
                        <span class="brand-icon-box bg-warning text-dark" style="width: 44px; height: 44px; font-size: 1.25rem;">
                            <i class="fa-solid fa-user-plus"></i>
                        </span>
                        <div>
                            <h5 class="fw-bold mb-1">No Borrowers Available</h5>
                            <p class="mb-0 small text-secondary">
                                You must register at least one borrower client before creating a loan agreement.
                            </p>
                        </div>
                        <div class="ms-auto">
                            <a href="<?php echo BASE_URL; ?>borrowers/add.php" class="btn btn-primary btn-sm">
                                Register Borrower
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Form & Live Calculator Row -->
            <div class="row g-4">
                <!-- Left: Loan Creation Form -->
                <div class="col-12 col-lg-8">
                    <div class="content-card">
                        <div class="content-card-header">
                            <h2 class="content-card-title">
                                <i class="fa-solid fa-file-contract me-2 text-primary"></i>Loan Terms &amp; Parameters
                            </h2>
                            <span class="text-muted small">* Required fields</span>
                        </div>

                        <div class="p-4">
                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="loanForm" novalidate>
                                <?php echo csrf_field(); ?>

                                <!-- Borrower Selection -->
                                <div class="mb-3">
                                    <label for="borrower_id" class="form-label small fw-semibold text-secondary">
                                        Borrower Client <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light text-muted">
                                            <i class="fa-solid fa-user"></i>
                                        </span>
                                        <select class="form-select" id="borrower_id" name="borrower_id" required>
                                            <option value="">-- Select Borrower Client --</option>
                                            <?php foreach ($borrowers as $b): ?>
                                                <option 
                                                    value="<?php echo (int)$b['id']; ?>" 
                                                    <?php echo ((int)$formData['borrower_id'] === (int)$b['id']) ? 'selected' : ''; ?>
                                                >
                                                    <?php echo htmlspecialchars($b['full_name']); ?> (Phone: <?php echo htmlspecialchars($b['phone']); ?>)
                                                    <?php if ($b['status'] === 'inactive'): ?> [Inactive]<?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-text small">
                                        Client not listed? <a href="<?php echo BASE_URL; ?>borrowers/add.php" target="_blank" class="text-decoration-none">Add new borrower</a>
                                    </div>
                                </div>

                                <!-- Principal & Interest Rate Row -->
                                <div class="row g-3 mb-3">
                                    <!-- Principal Amount -->
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
                                                placeholder="e.g. 50000.00" 
                                                value="<?php echo htmlspecialchars($formData['principal_amount']); ?>" 
                                                required
                                            >
                                        </div>
                                    </div>

                                    <!-- Interest Rate -->
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
                                                placeholder="e.g. 10.0" 
                                                value="<?php echo htmlspecialchars($formData['interest_rate']); ?>" 
                                                required
                                            >
                                            <span class="input-group-text bg-light text-muted">
                                                <i class="fa-solid fa-percent"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Interest Type & Duration Row -->
                                <div class="row g-3 mb-3">
                                    <!-- Interest Type -->
                                    <div class="col-12 col-md-4">
                                        <label for="interest_type" class="form-label small fw-semibold text-secondary">
                                            Interest Model <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" id="interest_type" name="interest_type">
                                            <option value="flat" <?php echo ($formData['interest_type'] === 'flat') ? 'selected' : ''; ?>>Flat Interest</option>
                                            <option value="simple" <?php echo ($formData['interest_type'] === 'simple') ? 'selected' : ''; ?>>Simple Interest (Annual)</option>
                                        </select>
                                    </div>

                                    <!-- Duration Value -->
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

                                    <!-- Duration Unit -->
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

                                <!-- Start Date & Due Date Row -->
                                <div class="row g-3 mb-3">
                                    <!-- Start Date -->
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

                                    <!-- Due Date -->
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
                                        <div class="form-text small">Automatically computed; can be manually customized.</div>
                                    </div>
                                </div>

                                <!-- Notes -->
                                <div class="mb-4">
                                    <label for="notes" class="form-label small fw-semibold text-secondary">
                                        Agreement Notes &amp; Purpose <span class="text-muted fw-normal">(Optional)</span>
                                    </label>
                                    <textarea 
                                        class="form-control" 
                                        id="notes" 
                                        name="notes" 
                                        rows="3" 
                                        placeholder="e.g. Business expansion loan, collateral details, or repayment mode..."
                                    ><?php echo htmlspecialchars($formData['notes']); ?></textarea>
                                </div>

                                <hr class="my-4">

                                <!-- Buttons -->
                                <div class="d-flex align-items-center justify-content-end gap-2">
                                    <a href="<?php echo BASE_URL; ?>loans/index.php" class="btn btn-light border px-3">
                                        Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary px-4 fw-semibold d-flex align-items-center gap-2">
                                        <i class="fa-solid fa-check"></i>
                                        <span>Create Loan Agreement</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right: Live Financial Summary Calculator Card -->
                <div class="col-12 col-lg-4">
                    <div class="content-card sticky-top" style="top: 80px;">
                        <div class="content-card-header bg-light">
                            <h3 class="content-card-title text-secondary">
                                <i class="fa-solid fa-calculator me-2 text-primary"></i>Live Financial Summary
                            </h3>
                        </div>

                        <div class="p-3">
                            <div class="p-3 bg-light rounded-3 border mb-3">
                                <span class="d-block small text-muted text-uppercase fw-semibold mb-1">Principal Disbursed</span>
                                <div class="h3 fw-bold text-dark mb-0" id="livePrincipal">
                                    <?php echo CURRENCY_SYMBOL; ?>0.00
                                </div>
                            </div>

                            <ul class="list-group list-group-flush small mb-3">
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Interest Calculation:</span>
                                    <span class="fw-semibold text-secondary" id="liveInterestType">Flat</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Interest Rate:</span>
                                    <span class="fw-semibold text-secondary" id="liveRate">10.0%</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Duration:</span>
                                    <span class="fw-semibold text-secondary" id="liveDuration">6 Months</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Total Interest Accrued:</span>
                                    <span class="fw-bold text-primary" id="liveInterest">
                                        <?php echo CURRENCY_SYMBOL; ?>0.00
                                    </span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2 border-top">
                                    <span class="fw-semibold text-dark">Total Repayable:</span>
                                    <span class="fw-bold text-dark fs-6" id="liveTotalPayable">
                                        <?php echo CURRENCY_SYMBOL; ?>0.00
                                    </span>
                                </li>
                            </ul>

                            <div class="alert alert-info-subtle border border-info-subtle small mb-0 p-2 text-muted">
                                <i class="fa-solid fa-circle-info me-1 text-primary"></i>
                                <span id="formulaExplanation">
                                    Flat interest: Principal &times; Rate%
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div> <!-- End .content-container -->

        <!-- Client-side Live Calculation Script -->
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const currencySymbol = '<?php echo CURRENCY_SYMBOL; ?>';
            const principalInput = document.getElementById('principal_amount');
            const rateInput = document.getElementById('interest_rate');
            const typeInput = document.getElementById('interest_type');
            const durationValueInput = document.getElementById('duration_value');
            const durationUnitInput = document.getElementById('duration_unit');
            const startDateInput = document.getElementById('start_date');
            const dueDateInput = document.getElementById('due_date');

            const livePrincipal = document.getElementById('livePrincipal');
            const liveInterestType = document.getElementById('liveInterestType');
            const liveRate = document.getElementById('liveRate');
            const liveDuration = document.getElementById('liveDuration');
            const liveInterest = document.getElementById('liveInterest');
            const liveTotalPayable = document.getElementById('liveTotalPayable');
            const formulaExplanation = document.getElementById('formulaExplanation');

            let userManuallySetDueDate = false;

            dueDateInput.addEventListener('input', function () {
                userManuallySetDueDate = true;
            });

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
                    formulaExplanation.innerHTML = 'Simple interest: (Principal &times; Rate &times; ' + timeInYears.toFixed(2) + ' yrs) / 100';
                    liveInterestType.textContent = 'Simple (Annual)';
                } else {
                    interest = (principal * rate) / 100.0;
                    formulaExplanation.innerHTML = 'Flat interest: Principal &times; ' + rate + '%';
                    liveInterestType.textContent = 'Flat Interest';
                }

                interest = Math.round(interest * 100) / 100;
                const totalPayable = Math.round((principal + interest) * 100) / 100;

                livePrincipal.textContent = currencySymbol + principal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                liveRate.textContent = rate.toFixed(1) + '%';
                liveDuration.textContent = durVal + ' ' + (durUnit.charAt(0).toUpperCase() + durUnit.slice(1));
                liveInterest.textContent = currencySymbol + interest.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                liveTotalPayable.textContent = currencySymbol + totalPayable.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                // Auto-update due date if user hasn't overridden
                if (!userManuallySetDueDate && startDateInput.value) {
                    const start = new Date(startDateInput.value);
                    if (!isNaN(start.getTime())) {
                        if (durUnit === 'years') {
                            start.setFullYear(start.getFullYear() + durVal);
                        } else {
                            start.setMonth(start.getMonth() + durVal);
                        }
                        const y = start.getFullYear();
                        const m = String(start.getMonth() + 1).padStart(2, '0');
                        const d = String(start.getDate()).padStart(2, '0');
                        dueDateInput.value = `${y}-${m}-${d}`;
                    }
                }
            }

            [principalInput, rateInput, typeInput, durationValueInput, durationUnitInput, startDateInput].forEach(function (el) {
                el.addEventListener('input', recalculate);
                el.addEventListener('change', recalculate);
            });

            // Initial calculation
            recalculate();
        });
        </script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
