<?php
/**
 * MoneyLend - Record Repayment
 * 
 * Allows administrator to record an installment payment against an active loan.
 * Includes live real-time financial calculations, strict balance validations,
 * and atomic database transactions.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helper.php';

require_login();

$pageTitle = "Record Repayment";
$activePage = "repayments";

$pdo = getDBConnection();
$errors = [];
$selectedLoanId = isset($_GET['loan_id']) ? (int)$_GET['loan_id'] : 0;

// Default Form Data
$defaultPaymentMethod = get_setting('default_payment_method', 'Cash');

$formData = [
    'loan_id'        => $selectedLoanId,
    'amount'         => '',
    'payment_date'   => date('Y-m-d'),
    'payment_method' => $defaultPaymentMethod,
    'notes'          => ''
];

$allowedMethods = ['Cash', 'UPI', 'Bank Transfer', 'Cheque', 'Other'];

// Fetch all eligible loans (outstanding balance > 0 and status not cancelled/paid)
$eligibleLoans = [];
$loansLookup = [];

try {
    $loansStmt = $pdo->query("
        SELECT 
            l.id,
            l.borrower_id,
            l.principal_amount,
            l.interest_rate,
            l.interest_type,
            l.duration_value,
            l.duration_unit,
            l.start_date,
            l.due_date,
            l.total_interest,
            l.total_payable,
            l.amount_repaid,
            l.remaining_balance,
            l.status,
            b.full_name AS borrower_name,
            b.phone AS borrower_phone
        FROM loans l
        JOIN borrowers b ON l.borrower_id = b.id
        WHERE l.status NOT IN ('cancelled', 'paid')
          AND l.remaining_balance > 0.001
        ORDER BY l.id DESC
    ");
    $eligibleLoans = $loansStmt->fetchAll();

    foreach ($eligibleLoans as $el) {
        $loansLookup[(int)$el['id']] = [
            'id'                  => (int)$el['id'],
            'borrower_id'         => (int)$el['borrower_id'],
            'borrower_name'       => $el['borrower_name'],
            'borrower_phone'      => $el['borrower_phone'],
            'principal_amount'    => (float)$el['principal_amount'],
            'interest_rate'       => (float)$el['interest_rate'],
            'interest_type'       => $el['interest_type'],
            'total_payable'       => (float)$el['total_payable'],
            'amount_repaid'       => (float)$el['amount_repaid'],
            'remaining_balance'   => (float)$el['remaining_balance'],
            'start_date'          => $el['start_date'],
            'start_date_formatted'=> date('M d, Y', strtotime($el['start_date'])),
            'due_date'            => $el['due_date'],
            'due_date_formatted'  => date('M d, Y', strtotime($el['due_date'])),
            'status'              => $el['status']
        ];
    }
} catch (PDOException $e) {
    error_log("Error fetching eligible loans: " . $e->getMessage());
    $errors[] = "Failed to load eligible loans. Please try again.";
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['loan_id']        = isset($_POST['loan_id']) ? (int)$_POST['loan_id'] : 0;
    $formData['amount']         = isset($_POST['amount']) ? trim($_POST['amount']) : '';
    $formData['payment_date']   = isset($_POST['payment_date']) ? trim($_POST['payment_date']) : '';
    $formData['payment_method'] = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'Cash';
    $formData['notes']          = isset($_POST['notes']) ? trim($_POST['notes']) : '';

    $csrfToken = $_POST['csrf_token'] ?? '';

    // 1. Verify CSRF Token
    if (!verify_csrf_token($csrfToken)) {
        $errors[] = "Security validation failed. Please refresh the page and submit again.";
    }

    // 2. Validate Selected Loan
    $selectedLoan = null;
    if ($formData['loan_id'] <= 0 || !isset($loansLookup[$formData['loan_id']])) {
        // Double check against database directly in case it was just modified
        try {
            $chkStmt = $pdo->prepare("
                SELECT l.*, b.full_name AS borrower_name, b.phone AS borrower_phone
                FROM loans l
                JOIN borrowers b ON l.borrower_id = b.id
                WHERE l.id = :id
            ");
            $chkStmt->execute([':id' => $formData['loan_id']]);
            $chkLoan = $chkStmt->fetch();

            if (!$chkLoan) {
                $errors[] = "Please select a valid loan from the dropdown.";
            } elseif ($chkLoan['status'] === 'paid' || (float)$chkLoan['remaining_balance'] <= 0.001) {
                $errors[] = "Loan #{$formData['loan_id']} is already fully paid and cannot accept new repayments.";
            } elseif ($chkLoan['status'] === 'cancelled') {
                $errors[] = "Loan #{$formData['loan_id']} is cancelled and cannot receive repayments.";
            } else {
                $selectedLoan = $chkLoan;
            }
        } catch (PDOException $e) {
            $errors[] = "Database error while validating loan agreement.";
        }
    } else {
        $selectedLoan = $loansLookup[$formData['loan_id']];
    }

    // 3. Validate Repayment Amount
    $paymentAmount = (float)$formData['amount'];
    if (!is_numeric($formData['amount']) || $paymentAmount <= 0) {
        $errors[] = "Repayment amount must be a positive number greater than zero.";
    } elseif ($selectedLoan !== null) {
        $currentOutstanding = (float)$selectedLoan['remaining_balance'];
        // Compare with round precision of 2 decimals to prevent floating point inaccuracy
        if (round($paymentAmount, 2) > round($currentOutstanding, 2)) {
            $errors[] = "Repayment amount cannot exceed the outstanding balance of " . CURRENCY_SYMBOL . number_format($currentOutstanding, 2) . ".";
        }
    }

    // 4. Validate Payment Date
    if (empty($formData['payment_date']) || !strtotime($formData['payment_date'])) {
        $errors[] = "Please provide a valid payment date.";
    }

    // 5. Validate Payment Method
    if (!in_array($formData['payment_method'], $allowedMethods, true)) {
        $errors[] = "Please select a valid payment method from the list.";
    }

    // Process Repayment within Atomic Transaction
    if (empty($errors) && $selectedLoan !== null) {
        try {
            $pdo->beginTransaction();

            $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

            // Insert Repayment Record
            $insertStmt = $pdo->prepare("
                INSERT INTO repayments (loan_id, amount, payment_date, payment_method, notes, user_id, created_at)
                VALUES (:loan_id, :amount, :payment_date, :payment_method, :notes, :user_id, NOW())
            ");
            $insertStmt->execute([
                ':loan_id'        => $formData['loan_id'],
                ':amount'         => round($paymentAmount, 2),
                ':payment_date'   => $formData['payment_date'],
                ':payment_method' => $formData['payment_method'],
                ':notes'          => !empty($formData['notes']) ? $formData['notes'] : null,
                ':user_id'        => $userId
            ]);

            $repaymentId = (int)$pdo->lastInsertId();

            // Synchronize Loan Balances & Status
            $syncResult = sync_loan_balances($pdo, $formData['loan_id']);

            $pdo->commit();

            // Success Flash Notification
            set_flash("success", "Repayment recorded successfully.");

            redirect(BASE_URL . "repayments/view.php?id={$repaymentId}");

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Repayment insertion failure: " . $e->getMessage());
            $errors[] = "Unable to save this record. Please verify the information and try again.";
        }
    }
}

// Include Layout Components
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="content-container">

            <!-- Breadcrumbs -->
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb small">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard.php" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>repayments/index.php" class="text-decoration-none">Repayments</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Record Repayment</li>
                </ol>
            </nav>

            <!-- Page Title -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="page-title mb-0">Record Repayment</h1>
                    <p class="page-subtitle">Receive and log installment payments against active loan agreements</p>
                </div>
                <div>
                    <a href="<?php echo BASE_URL; ?>repayments/index.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Back to Repayments</span>
                    </a>
                </div>
            </div>

            <!-- Validation Errors Alert -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 mb-4" role="alert">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fa-solid fa-circle-exclamation mt-1"></i>
                        <div>
                            <strong class="d-block mb-1">Please correct the following errors:</strong>
                            <ul class="mb-0 ps-3 small">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (empty($eligibleLoans)): ?>
                <!-- Empty State: No active loans -->
                <div class="content-card">
                    <div class="p-5 text-center">
                        <div class="brand-icon-box bg-light text-muted mx-auto mb-3" style="width: 56px; height: 56px; font-size: 1.6rem;">
                            <i class="fa-solid fa-circle-check text-success"></i>
                        </div>
                        <h3 class="h5 fw-bold text-dark mb-2">No Active Loans Eligible for Repayment</h3>
                        <p class="text-muted small mx-auto mb-4" style="max-width: 480px;">
                            All registered loans are either fully settled or cancelled. To record new repayments, disburse an active loan agreement first.
                        </p>
                        <div class="d-flex justify-content-center gap-2">
                            <a href="<?php echo BASE_URL; ?>loans/add.php" class="btn btn-primary btn-sm">
                                <i class="fa-solid fa-plus-circle me-1"></i> Create New Loan
                            </a>
                            <a href="<?php echo BASE_URL; ?>loans/index.php" class="btn btn-outline-secondary btn-sm">
                                View Loans Directory
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>

                <!-- Repayment Form & Live Financial Calculator Row -->
                <div class="row g-4">
                    <!-- Left Column: Repayment Form -->
                    <div class="col-12 col-lg-7">
                        <div class="content-card h-100">
                            <div class="content-card-header">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-money-bill-transfer text-success"></i>
                                    <h2 class="content-card-title">Payment Transaction Details</h2>
                                </div>
                                <span class="badge bg-light text-secondary border">Step 1 of 1</span>
                            </div>

                            <div class="card-body p-4">
                                <form method="POST" action="<?php echo BASE_URL; ?>repayments/add.php" id="repaymentForm" novalidate>
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">

                                    <!-- 1. Select Loan -->
                                    <div class="mb-3">
                                        <label for="loan_id" class="form-label fw-semibold text-dark">
                                            Select Active Loan <span class="text-danger">*</span>
                                        </label>
                                        <select name="loan_id" id="loan_id" class="form-select form-select-lg" required>
                                            <option value="">-- Choose an active loan agreement --</option>
                                            <?php foreach ($eligibleLoans as $l): ?>
                                                <?php $isSel = ($formData['loan_id'] === (int)$l['id']); ?>
                                                <option 
                                                    value="<?php echo (int)$l['id']; ?>" 
                                                    <?php echo $isSel ? 'selected' : ''; ?>
                                                    data-balance="<?php echo (float)$l['remaining_balance']; ?>"
                                                >
                                                    #<?php echo (int)$l['id']; ?> — <?php echo htmlspecialchars($l['borrower_name']); ?> 
                                                    (Due: <?php echo CURRENCY_SYMBOL . number_format((float)$l['remaining_balance'], 2); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text small">
                                            Only active, partially paid, or overdue loans with an outstanding balance are listed.
                                        </div>
                                    </div>

                                    <!-- 2. Repayment Amount -->
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <label for="amount" class="form-label fw-semibold text-dark mb-0">
                                                Repayment Amount (<?php echo CURRENCY_SYMBOL; ?>) <span class="text-danger">*</span>
                                            </label>
                                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none small text-primary" id="btnPayFull" style="display: none;">
                                                <i class="fa-solid fa-calculator me-1"></i> Pay Full Balance
                                            </button>
                                        </div>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light text-dark fw-bold"><?php echo CURRENCY_SYMBOL; ?></span>
                                            <input 
                                                type="number" 
                                                step="0.01" 
                                                min="0.01" 
                                                name="amount" 
                                                id="amount" 
                                                class="form-control form-control-lg fw-bold text-dark" 
                                                placeholder="0.00"
                                                value="<?php echo htmlspecialchars($formData['amount']); ?>" 
                                                required
                                            >
                                        </div>
                                        <div id="amountFeedback" class="small mt-1 text-muted">
                                            Enter the payment amount collected from the borrower.
                                        </div>
                                    </div>

                                    <!-- 3. Payment Date & Method Row -->
                                    <div class="row g-3 mb-3">
                                        <div class="col-12 col-md-6">
                                            <label for="payment_date" class="form-label fw-semibold text-dark">
                                                Payment Date <span class="text-danger">*</span>
                                            </label>
                                            <input 
                                                type="date" 
                                                name="payment_date" 
                                                id="payment_date" 
                                                class="form-control" 
                                                value="<?php echo htmlspecialchars($formData['payment_date']); ?>" 
                                                max="<?php echo date('Y-m-d'); ?>"
                                                required
                                            >
                                            <div class="form-text small">Default is today's date.</div>
                                        </div>

                                        <div class="col-12 col-md-6">
                                            <label for="payment_method" class="form-label fw-semibold text-dark">
                                                Payment Method <span class="text-danger">*</span>
                                            </label>
                                            <select name="payment_method" id="payment_method" class="form-select" required>
                                                <?php foreach ($allowedMethods as $m): ?>
                                                    <option value="<?php echo htmlspecialchars($m); ?>" <?php echo ($formData['payment_method'] === $m) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($m); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- 4. Notes / Transaction Reference -->
                                    <div class="mb-4">
                                        <label for="notes" class="form-label fw-semibold text-dark">
                                            Notes / Transaction Reference <span class="text-muted small fw-normal">(Optional)</span>
                                        </label>
                                        <textarea 
                                            name="notes" 
                                            id="notes" 
                                            rows="3" 
                                            class="form-control" 
                                            placeholder="e.g. Bank UTR / UPI Ref ID, receipt acknowledgment, or cash handed over by borrower's brother..."
                                        ><?php echo htmlspecialchars($formData['notes']); ?></textarea>
                                    </div>

                                    <!-- Form Action Buttons -->
                                    <div class="d-flex align-items-center gap-2 pt-2 border-top">
                                        <button type="submit" id="btnSubmitRepayment" class="btn btn-success d-flex align-items-center gap-2 px-4 py-2">
                                            <i class="fa-solid fa-check"></i>
                                            <span>Record Repayment</span>
                                        </button>
                                        <a href="<?php echo BASE_URL; ?>repayments/index.php" class="btn btn-outline-secondary px-3 py-2">
                                            Cancel
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Live Financial Summary & Loan Meta -->
                    <div class="col-12 col-lg-5">
                        <!-- Loan Overview Card -->
                        <div class="content-card mb-4" id="loanOverviewCard" style="display: none;">
                            <div class="content-card-header bg-light">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-circle-info text-primary"></i>
                                    <h2 class="content-card-title">Loan Agreement Info</h2>
                                </div>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle" id="lblLoanStatus">
                                    Active
                                </span>
                            </div>

                            <div class="card-body p-4">
                                <!-- Borrower Info Block -->
                                <div class="d-flex align-items-center gap-3 p-3 bg-light rounded mb-3 border">
                                    <div class="navbar-user-avatar" style="width: 42px; height: 42px; font-size: 1.1rem; background-color: #ecfdf5; color: #059669;">
                                        <i class="fa-solid fa-user"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark" id="lblBorrowerName">—</div>
                                        <div class="text-muted small">
                                            <i class="fa-solid fa-phone me-1"></i><span id="lblBorrowerPhone">—</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Key Dates & Parameters -->
                                <ul class="list-group list-group-flush small mb-3">
                                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                        <span class="text-muted">Agreement ID:</span>
                                        <span class="fw-semibold text-dark" id="lblLoanId">#—</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                        <span class="text-muted">Disbursement Date:</span>
                                        <span class="text-secondary" id="lblStartDate">—</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                        <span class="text-muted">Maturity / Due Date:</span>
                                        <span class="text-secondary" id="lblDueDate">—</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                        <span class="text-muted">Original Principal:</span>
                                        <span class="fw-semibold text-dark" id="lblPrincipal"><?php echo CURRENCY_SYMBOL; ?>0.00</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                        <span class="text-muted">Interest Rate:</span>
                                        <span class="text-secondary" id="lblInterestRate">0.0%</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                        <span class="text-muted">Total Payable:</span>
                                        <span class="fw-semibold text-dark" id="lblTotalPayable"><?php echo CURRENCY_SYMBOL; ?>0.00</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                        <span class="text-muted">Already Repaid:</span>
                                        <span class="text-success fw-bold" id="lblAlreadyRepaid"><?php echo CURRENCY_SYMBOL; ?>0.00</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                        <span class="text-muted">Current Outstanding:</span>
                                        <span class="text-danger fw-bold fs-6" id="lblOutstanding"><?php echo CURRENCY_SYMBOL; ?>0.00</span>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Live Impact Calculator Card -->
                        <div class="content-card" id="calculationCard" style="display: none;">
                            <div class="content-card-header bg-light">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-chart-line text-emerald"></i>
                                    <h2 class="content-card-title">Live Settlement Impact</h2>
                                </div>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                    Real-time
                                </span>
                            </div>

                            <div class="card-body p-4">
                                <div class="p-3 bg-light rounded border mb-3">
                                    <div class="d-flex justify-content-between small text-muted mb-1">
                                        <span>Current Outstanding</span>
                                        <span id="calcCurrentOutstanding"><?php echo CURRENCY_SYMBOL; ?>0.00</span>
                                    </div>
                                    <div class="d-flex justify-content-between small text-success fw-semibold mb-2">
                                        <span>Less: Entered Payment</span>
                                        <span id="calcPaymentAmount">- <?php echo CURRENCY_SYMBOL; ?>0.00</span>
                                    </div>
                                    <div class="border-top pt-2 d-flex justify-content-between align-items-center">
                                        <span class="fw-bold text-dark">New Balance After Payment:</span>
                                        <span class="h5 fw-bold mb-0 text-primary" id="calcNewBalance">
                                            <?php echo CURRENCY_SYMBOL; ?>0.00
                                        </span>
                                    </div>
                                </div>

                                <!-- Dynamic Feedback Notice -->
                                <div id="calcStatusNotice" class="alert alert-info py-2 px-3 small mb-0 border-0">
                                    <i class="fa-solid fa-circle-info me-1"></i> Enter an installment amount to compute remaining balance.
                                </div>
                            </div>
                        </div>

                        <!-- Placeholder when no loan is chosen -->
                        <div class="content-card text-center p-5" id="loanPromptPlaceholder">
                            <div class="brand-icon-box bg-light text-muted mx-auto mb-3" style="width: 48px; height: 48px; font-size: 1.4rem;">
                                <i class="fa-solid fa-hand-pointer"></i>
                            </div>
                            <h4 class="h6 fw-bold text-dark mb-1">No Loan Selected</h4>
                            <p class="text-muted small mb-0">
                                Select an active loan agreement from the dropdown to load the borrower's details and live settlement preview.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Client-side Javascript for Live Calculation -->
                <script>
                (function() {
                    'use strict';

                    const loansData = <?php echo json_encode($loansLookup, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    const currencySymbol = <?php echo json_encode(CURRENCY_SYMBOL); ?>;

                    const loanSelect = document.getElementById('loan_id');
                    const amountInput = document.getElementById('amount');
                    const btnPayFull = document.getElementById('btnPayFull');
                    const btnSubmit = document.getElementById('btnSubmitRepayment');
                    const amountFeedback = document.getElementById('amountFeedback');

                    const loanOverviewCard = document.getElementById('loanOverviewCard');
                    const calculationCard = document.getElementById('calculationCard');
                    const loanPromptPlaceholder = document.getElementById('loanPromptPlaceholder');

                    // Detail labels
                    const lblBorrowerName = document.getElementById('lblBorrowerName');
                    const lblBorrowerPhone = document.getElementById('lblBorrowerPhone');
                    const lblLoanId = document.getElementById('lblLoanId');
                    const lblStartDate = document.getElementById('lblStartDate');
                    const lblDueDate = document.getElementById('lblDueDate');
                    const lblPrincipal = document.getElementById('lblPrincipal');
                    const lblInterestRate = document.getElementById('lblInterestRate');
                    const lblTotalPayable = document.getElementById('lblTotalPayable');
                    const lblAlreadyRepaid = document.getElementById('lblAlreadyRepaid');
                    const lblOutstanding = document.getElementById('lblOutstanding');
                    const lblLoanStatus = document.getElementById('lblLoanStatus');

                    // Calc labels
                    const calcCurrentOutstanding = document.getElementById('calcCurrentOutstanding');
                    const calcPaymentAmount = document.getElementById('calcPaymentAmount');
                    const calcNewBalance = document.getElementById('calcNewBalance');
                    const calcStatusNotice = document.getElementById('calcStatusNotice');

                    function formatMoney(num) {
                        return currencySymbol + Number(num || 0).toLocaleString('en-IN', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });
                    }

                    function updateUI() {
                        const selectedId = parseInt(loanSelect.value, 10);
                        const loan = loansData[selectedId];

                        if (!loan) {
                            loanOverviewCard.style.display = 'none';
                            calculationCard.style.display = 'none';
                            loanPromptPlaceholder.style.display = 'block';
                            btnPayFull.style.display = 'none';
                            amountFeedback.className = 'small mt-1 text-muted';
                            amountFeedback.textContent = 'Enter the payment amount collected from the borrower.';
                            return;
                        }

                        // Display Loan Meta
                        loanPromptPlaceholder.style.display = 'none';
                        loanOverviewCard.style.display = 'block';
                        calculationCard.style.display = 'block';
                        btnPayFull.style.display = 'inline-block';

                        lblBorrowerName.textContent = loan.borrower_name;
                        lblBorrowerPhone.textContent = loan.borrower_phone;
                        lblLoanId.textContent = '#' + loan.id;
                        lblStartDate.textContent = loan.start_date_formatted;
                        lblDueDate.textContent = loan.due_date_formatted;
                        lblPrincipal.textContent = formatMoney(loan.principal_amount);
                        lblInterestRate.textContent = loan.interest_rate + '% (' + loan.interest_type + ')';
                        lblTotalPayable.textContent = formatMoney(loan.total_payable);
                        lblAlreadyRepaid.textContent = formatMoney(loan.amount_repaid);
                        lblOutstanding.textContent = formatMoney(loan.remaining_balance);
                        lblLoanStatus.textContent = loan.status.replace('_', ' ').toUpperCase();

                        // Perform Live Balance Calculation
                        const outstanding = Number(loan.remaining_balance) || 0;
                        const enteredAmount = parseFloat(amountInput.value) || 0;

                        calcCurrentOutstanding.textContent = formatMoney(outstanding);
                        calcPaymentAmount.textContent = '- ' + formatMoney(enteredAmount);

                        const newBalance = Math.max(0, outstanding - enteredAmount);
                        calcNewBalance.textContent = formatMoney(newBalance);

                        // Validation checks
                        if (amountInput.value === '') {
                            calcStatusNotice.className = 'alert alert-info py-2 px-3 small mb-0 border-0';
                            calcStatusNotice.innerHTML = '<i class="fa-solid fa-circle-info me-1"></i> Enter an installment amount to compute remaining balance.';
                            amountFeedback.className = 'small mt-1 text-muted';
                            amountFeedback.textContent = 'Enter the payment amount collected from the borrower.';
                            btnSubmit.disabled = false;
                        } else if (enteredAmount <= 0) {
                            calcStatusNotice.className = 'alert alert-warning py-2 px-3 small mb-0 border-0';
                            calcStatusNotice.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> Repayment amount must be greater than zero.';
                            amountFeedback.className = 'small mt-1 text-danger';
                            amountFeedback.textContent = 'Amount must be greater than ₹0.00.';
                            btnSubmit.disabled = true;
                        } else if (enteredAmount > (outstanding + 0.001)) {
                            const excess = (enteredAmount - outstanding).toFixed(2);
                            calcStatusNotice.className = 'alert alert-danger py-2 px-3 small mb-0 border-0';
                            calcStatusNotice.innerHTML = '<i class="fa-solid fa-circle-xmark me-1"></i> <strong>Exceeds balance!</strong> Payment is ₹' + excess + ' higher than outstanding.';
                            amountFeedback.className = 'small mt-1 text-danger fw-semibold';
                            amountFeedback.textContent = 'Repayment amount cannot exceed the outstanding balance of ' + formatMoney(outstanding) + '.';
                            btnSubmit.disabled = true;
                        } else if (Math.abs(enteredAmount - outstanding) < 0.009) {
                            calcStatusNotice.className = 'alert alert-success py-2 px-3 small mb-0 border-0';
                            calcStatusNotice.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> <strong>Full Settlement:</strong> This payment will mark Loan #' + loan.id + ' as <strong>PAID</strong>.';
                            amountFeedback.className = 'small mt-1 text-success fw-semibold';
                            amountFeedback.textContent = 'Exact outstanding balance reached. Loan will be fully settled.';
                            btnSubmit.disabled = false;
                        } else {
                            calcStatusNotice.className = 'alert alert-primary py-2 px-3 small mb-0 border-0';
                            calcStatusNotice.innerHTML = '<i class="fa-solid fa-calculator me-1"></i> Partial repayment: ' + formatMoney(newBalance) + ' will remain unpaid.';
                            amountFeedback.className = 'small mt-1 text-muted';
                            amountFeedback.textContent = 'Remaining balance after payment: ' + formatMoney(newBalance);
                            btnSubmit.disabled = false;
                        }
                    }

                    loanSelect.addEventListener('change', function() {
                        const selectedId = parseInt(this.value, 10);
                        const loan = loansData[selectedId];
                        if (loan && (!amountInput.value || parseFloat(amountInput.value) <= 0)) {
                            // Leave amount empty for user to type, or keep current
                        }
                        updateUI();
                    });

                    amountInput.addEventListener('input', updateUI);

                    btnPayFull.addEventListener('click', function(e) {
                        e.preventDefault();
                        const selectedId = parseInt(loanSelect.value, 10);
                        const loan = loansData[selectedId];
                        if (loan) {
                            amountInput.value = parseFloat(loan.remaining_balance).toFixed(2);
                            updateUI();
                        }
                    });

                    // Initial trigger
                    updateUI();
                })();
                </script>
            <?php endif; ?>

        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
