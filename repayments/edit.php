<?php
/**
 * MoneyLend - Edit Repayment
 * 
 * Allows administrator to modify an existing repayment installment.
 * Excludes the current payment amount to validate against the loan's maximum
 * allowable balance, then atomically updates the repayment and recalculates
 * the entire loan financial state.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helper.php';

require_login();

$pageTitle = "Edit Repayment";
$activePage = "repayments";

$pdo = getDBConnection();
$errors = [];
$repaymentId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);

if ($repaymentId <= 0) {
    set_flash("danger", "Invalid repayment ID specified.");
    redirect(BASE_URL . "repayments/index.php");
}

$allowedMethods = ['Cash', 'UPI', 'Bank Transfer', 'Cheque', 'Other'];

try {
    // Fetch repayment along with loan and borrower details
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            l.principal_amount,
            l.interest_rate,
            l.interest_type,
            l.total_payable,
            l.amount_repaid AS loan_total_repaid,
            l.remaining_balance AS loan_current_balance,
            l.status AS loan_status,
            l.start_date,
            l.due_date,
            b.id AS borrower_id,
            b.full_name AS borrower_name,
            b.phone AS borrower_phone
        FROM repayments r
        JOIN loans l ON r.loan_id = l.id
        JOIN borrowers b ON l.borrower_id = b.id
        WHERE r.id = :id
    ");
    $stmt->execute([':id' => $repaymentId]);
    $repayment = $stmt->fetch();

    if (!$repayment) {
        set_flash("danger", "Repayment #{$repaymentId} was not found.");
        redirect(BASE_URL . "repayments/index.php");
    }

    // Calculate sum of all OTHER repayments for this loan (excluding the current one)
    $otherStmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM repayments
        WHERE loan_id = :loan_id AND id != :id
    ");
    $otherStmt->execute([
        ':loan_id' => $repayment['loan_id'],
        ':id'      => $repaymentId
    ]);
    $otherRepaymentsSum = round((float)$otherStmt->fetchColumn(), 2);

    $totalPayable = round((float)$repayment['total_payable'], 2);
    // Max allowable for this single repayment
    $maxAllowableAmount = round(max(0.0, $totalPayable - $otherRepaymentsSum), 2);

} catch (PDOException $e) {
    error_log("Error loading repayment for editing: " . $e->getMessage());
    set_flash("danger", "Database error occurred while retrieving repayment details.");
    redirect(BASE_URL . "repayments/index.php");
}

// Initialize form values
$formData = [
    'amount'         => (string)$repayment['amount'],
    'payment_date'   => $repayment['payment_date'],
    'payment_method' => $repayment['payment_method'],
    'notes'          => $repayment['notes'] ?? ''
];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['amount']         = isset($_POST['amount']) ? trim($_POST['amount']) : '';
    $formData['payment_date']   = isset($_POST['payment_date']) ? trim($_POST['payment_date']) : '';
    $formData['payment_method'] = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'Cash';
    $formData['notes']          = isset($_POST['notes']) ? trim($_POST['notes']) : '';

    $csrfToken = $_POST['csrf_token'] ?? '';

    // 1. Verify CSRF Token
    if (!verify_csrf_token($csrfToken)) {
        $errors[] = "Security validation failed. Please refresh the page and try again.";
    }

    // 2. Validate Amount
    $newAmount = (float)$formData['amount'];
    if (!is_numeric($formData['amount']) || $newAmount <= 0) {
        $errors[] = "Repayment amount must be a positive number greater than zero.";
    } elseif (round($newAmount, 2) > round($maxAllowableAmount, 2)) {
        $errors[] = "Repayment amount cannot exceed the available balance of " . CURRENCY_SYMBOL . number_format($maxAllowableAmount, 2) . " (Total Payable: " . CURRENCY_SYMBOL . number_format($totalPayable, 2) . ", Other Payments: " . CURRENCY_SYMBOL . number_format($otherRepaymentsSum, 2) . ").";
    }

    // 3. Validate Date
    if (empty($formData['payment_date']) || !strtotime($formData['payment_date'])) {
        $errors[] = "Please provide a valid payment date.";
    }

    // 4. Validate Method
    if (!in_array($formData['payment_method'], $allowedMethods, true)) {
        $errors[] = "Please select a valid payment method.";
    }

    // 5. Atomic Update and Recalculation
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare("
                UPDATE repayments
                SET amount = :amount,
                    payment_date = :payment_date,
                    payment_method = :payment_method,
                    notes = :notes
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':amount'         => round($newAmount, 2),
                ':payment_date'   => $formData['payment_date'],
                ':payment_method' => $formData['payment_method'],
                ':notes'          => !empty($formData['notes']) ? $formData['notes'] : null,
                ':id'             => $repaymentId
            ]);

            // Atomically synchronize the entire loan financial state from the repayments table
            $syncResult = sync_loan_balances($pdo, (int)$repayment['loan_id']);

            $pdo->commit();

            log_activity($pdo, 'repayment_update', 'repayment', $repaymentId, 'Updated repayment installment #' . $repaymentId . ' (Amount: ' . CURRENCY_SYMBOL . number_format($newAmount, 2) . ')');

            set_flash("success", "Repayment updated successfully.");
            redirect(BASE_URL . "repayments/view.php?id={$repaymentId}");

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Repayment update failed: " . $e->getMessage());
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
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>repayments/index.php" class="text-decoration-none">Repayments</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>repayments/view.php?id=<?php echo $repaymentId; ?>" class="text-decoration-none">Receipt #<?php echo $repaymentId; ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit</li>
                </ol>
            </nav>

            <!-- Page Title -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="page-title mb-0">Edit Repayment #<?php echo $repaymentId; ?></h1>
                    <p class="page-subtitle">Modify payment details with automatic recalculation of loan balances</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="<?php echo BASE_URL; ?>repayments/view.php?id=<?php echo $repaymentId; ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Cancel &amp; View Receipt</span>
                    </a>
                </div>
            </div>

            <!-- Validation Errors Alert -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 mb-4" role="alert">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fa-solid fa-circle-exclamation mt-1"></i>
                        <div>
                            <strong class="d-block mb-1">Please fix the following issues:</strong>
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

            <div class="row g-4">
                <!-- Left: Edit Form -->
                <div class="col-12 col-lg-7">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-pen-to-square text-primary"></i>
                                <h2 class="content-card-title">Update Installment Data</h2>
                            </div>
                            <span class="badge bg-light text-secondary border">Receipt #<?php echo $repaymentId; ?></span>
                        </div>

                        <div class="card-body p-4">
                            <form method="POST" action="<?php echo BASE_URL; ?>repayments/edit.php?id=<?php echo $repaymentId; ?>" id="editRepaymentForm" novalidate>
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">

                                <!-- Loan Association Display (Locked) -->
                                <div class="mb-3">
                                    <label class="form-label fw-semibold text-dark">Associated Loan Agreement</label>
                                    <div class="p-3 bg-light rounded border">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="fw-bold text-dark">
                                                    Loan #<?php echo (int)$repayment['loan_id']; ?> — <?php echo htmlspecialchars($repayment['borrower_name']); ?>
                                                </div>
                                                <div class="text-muted small">
                                                    Phone: <?php echo htmlspecialchars($repayment['borrower_phone']); ?> | Disbursed: <?php echo date('M d, Y', strtotime($repayment['start_date'])); ?>
                                                </div>
                                            </div>
                                            <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$repayment['loan_id']; ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                                                <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Loan Details
                                            </a>
                                        </div>
                                    </div>
                                    <div class="form-text small">Repayment records cannot be shifted to a different loan agreement.</div>
                                </div>

                                <!-- Repayment Amount -->
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label for="amount" class="form-label fw-semibold text-dark mb-0">
                                            Repayment Amount (<?php echo CURRENCY_SYMBOL; ?>) <span class="text-danger">*</span>
                                        </label>
                                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none small text-primary" id="btnSetMax">
                                            <i class="fa-solid fa-calculator me-1"></i> Max Allowable (<?php echo CURRENCY_SYMBOL . number_format($maxAllowableAmount, 2); ?>)
                                        </button>
                                    </div>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light text-dark fw-bold"><?php echo CURRENCY_SYMBOL; ?></span>
                                        <input 
                                            type="number" 
                                            step="0.01" 
                                            min="0.01" 
                                            max="<?php echo $maxAllowableAmount; ?>"
                                            name="amount" 
                                            id="amount" 
                                            class="form-control form-control-lg fw-bold text-dark" 
                                            value="<?php echo htmlspecialchars($formData['amount']); ?>" 
                                            required
                                        >
                                    </div>
                                    <div id="amountFeedback" class="small mt-1 text-muted">
                                        Max allowable for this installment is <?php echo CURRENCY_SYMBOL . number_format($maxAllowableAmount, 2); ?>.
                                    </div>
                                </div>

                                <!-- Payment Date & Method -->
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

                                <!-- Notes -->
                                <div class="mb-4">
                                    <label for="notes" class="form-label fw-semibold text-dark">
                                        Notes / Reference <span class="text-muted small fw-normal">(Optional)</span>
                                    </label>
                                    <textarea 
                                        name="notes" 
                                        id="notes" 
                                        rows="3" 
                                        class="form-control" 
                                        placeholder="Add or update payment reference or audit comments..."
                                    ><?php echo htmlspecialchars($formData['notes']); ?></textarea>
                                </div>

                                <!-- Action Buttons -->
                                <div class="d-flex align-items-center gap-2 pt-2 border-top">
                                    <button type="submit" id="btnSaveRepayment" class="btn btn-primary d-flex align-items-center gap-2 px-4 py-2">
                                        <i class="fa-solid fa-save"></i>
                                        <span>Update &amp; Recalculate</span>
                                    </button>
                                    <a href="<?php echo BASE_URL; ?>repayments/view.php?id=<?php echo $repaymentId; ?>" class="btn btn-outline-secondary px-3 py-2">
                                        Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right: Recalculation Impact Preview -->
                <div class="col-12 col-lg-5">
                    <div class="content-card mb-4">
                        <div class="content-card-header bg-light">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-scale-balanced text-primary"></i>
                                <h2 class="content-card-title">Balance Recalculation Model</h2>
                            </div>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                Live Preview
                            </span>
                        </div>

                        <div class="card-body p-4">
                            <ul class="list-group list-group-flush small mb-3">
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Total Loan Payable:</span>
                                    <span class="fw-semibold text-dark"><?php echo CURRENCY_SYMBOL . number_format($totalPayable, 2); ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Other Repayments Sum:</span>
                                    <span class="text-secondary"><?php echo CURRENCY_SYMBOL . number_format($otherRepaymentsSum, 2); ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Max Allowable for this Receipt:</span>
                                    <span class="fw-bold text-success"><?php echo CURRENCY_SYMBOL . number_format($maxAllowableAmount, 2); ?></span>
                                </li>
                            </ul>

                            <div class="p-3 bg-light rounded border mb-3">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span>New Repayment Amount:</span>
                                    <span id="previewNewAmount" class="fw-semibold text-dark"><?php echo CURRENCY_SYMBOL . number_format((float)$formData['amount'], 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span>Recalculated Total Repaid:</span>
                                    <span id="previewTotalRepaid" class="fw-semibold text-success"><?php echo CURRENCY_SYMBOL; ?>0.00</span>
                                </div>
                                <div class="border-top pt-2 d-flex justify-content-between align-items-center">
                                    <span class="fw-bold text-dark">Recalculated Outstanding:</span>
                                    <span class="h5 fw-bold mb-0 text-primary" id="previewRemainingBalance">
                                        <?php echo CURRENCY_SYMBOL; ?>0.00
                                    </span>
                                </div>
                            </div>

                            <div id="editNoticeBox" class="alert alert-info py-2 px-3 small mb-0 border-0">
                                <i class="fa-solid fa-circle-info me-1"></i> Saving will adjust the loan's balance and status automatically.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
(function() {
    'use strict';

    const totalPayable = <?php echo json_encode($totalPayable); ?>;
    const otherRepaymentsSum = <?php echo json_encode($otherRepaymentsSum); ?>;
    const maxAllowable = <?php echo json_encode($maxAllowableAmount); ?>;
    const currencySymbol = <?php echo json_encode(CURRENCY_SYMBOL); ?>;

    const amountInput = document.getElementById('amount');
    const btnSetMax = document.getElementById('btnSetMax');
    const btnSave = document.getElementById('btnSaveRepayment');
    const amountFeedback = document.getElementById('amountFeedback');

    const previewNewAmount = document.getElementById('previewNewAmount');
    const previewTotalRepaid = document.getElementById('previewTotalRepaid');
    const previewRemainingBalance = document.getElementById('previewRemainingBalance');
    const editNoticeBox = document.getElementById('editNoticeBox');

    function formatMoney(num) {
        return currencySymbol + Number(num || 0).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function calculatePreview() {
        const entered = parseFloat(amountInput.value) || 0;
        previewNewAmount.textContent = formatMoney(entered);

        const newTotalRepaid = otherRepaymentsSum + entered;
        const newRemaining = Math.max(0, totalPayable - newTotalRepaid);

        previewTotalRepaid.textContent = formatMoney(newTotalRepaid);
        previewRemainingBalance.textContent = formatMoney(newRemaining);

        if (amountInput.value === '') {
            editNoticeBox.className = 'alert alert-info py-2 px-3 small mb-0 border-0';
            editNoticeBox.innerHTML = '<i class="fa-solid fa-circle-info me-1"></i> Enter an amount to preview the recalculated balance.';
            btnSave.disabled = false;
        } else if (entered <= 0) {
            editNoticeBox.className = 'alert alert-warning py-2 px-3 small mb-0 border-0';
            editNoticeBox.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> Amount must be greater than zero.';
            amountFeedback.className = 'small mt-1 text-danger';
            amountFeedback.textContent = 'Amount must be greater than ₹0.00.';
            btnSave.disabled = true;
        } else if (entered > (maxAllowable + 0.001)) {
            const diff = (entered - maxAllowable).toFixed(2);
            editNoticeBox.className = 'alert alert-danger py-2 px-3 small mb-0 border-0';
            editNoticeBox.innerHTML = '<i class="fa-solid fa-circle-xmark me-1"></i> <strong>Exceeds balance!</strong> Amount exceeds max allowable by ₹' + diff + '.';
            amountFeedback.className = 'small mt-1 text-danger fw-semibold';
            amountFeedback.textContent = 'Cannot exceed maximum allowable balance of ' + formatMoney(maxAllowable) + '.';
            btnSave.disabled = true;
        } else if (Math.abs(newRemaining) < 0.009) {
            editNoticeBox.className = 'alert alert-success py-2 px-3 small mb-0 border-0';
            editNoticeBox.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> Loan will be <strong>Fully Settled (Paid)</strong> upon saving.';
            amountFeedback.className = 'small mt-1 text-success fw-semibold';
            amountFeedback.textContent = 'Exact payoff amount reached.';
            btnSave.disabled = false;
        } else {
            editNoticeBox.className = 'alert alert-primary py-2 px-3 small mb-0 border-0';
            editNoticeBox.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> Valid amount. Loan status will remain <strong>Partially Paid / Active</strong>.';
            amountFeedback.className = 'small mt-1 text-muted';
            amountFeedback.textContent = 'Valid amount within allowed limits.';
            btnSave.disabled = false;
        }
    }

    amountInput.addEventListener('input', calculatePreview);

    btnSetMax.addEventListener('click', function(e) {
        e.preventDefault();
        amountInput.value = maxAllowable.toFixed(2);
        calculatePreview();
    });

    calculatePreview();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
