<?php
/**
 * MoneyLend - View Repayment Details & Receipt
 * 
 * Displays comprehensive receipt details for an installment payment,
 * including borrower details, loan summary, ledger breakdown (previous,
 * current, and remaining balances), and printable voucher layout.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helper.php';

require_login();

$pageTitle = "Repayment Receipt";
$activePage = "repayments";

$pdo = getDBConnection();
$repaymentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($repaymentId <= 0) {
    set_flash("danger", "Invalid repayment receipt ID specified.");
    redirect(BASE_URL . "repayments/index.php");
}

try {
    // Fetch repayment record joined with loan, borrower, and recording user
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            l.principal_amount,
            l.interest_rate,
            l.interest_type,
            l.total_payable,
            l.amount_repaid AS loan_total_repaid,
            l.remaining_balance AS loan_current_balance,
            l.start_date,
            l.due_date,
            l.status AS loan_status,
            b.id AS borrower_id,
            b.full_name AS borrower_name,
            b.phone AS borrower_phone,
            b.email AS borrower_email,
            b.address AS borrower_address,
            u.name AS recorded_by_name,
            u.email AS recorded_by_email
        FROM repayments r
        JOIN loans l ON r.loan_id = l.id
        JOIN borrowers b ON l.borrower_id = b.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.id = :id
    ");
    $stmt->execute([':id' => $repaymentId]);
    $repayment = $stmt->fetch();

    if (!$repayment) {
        set_flash("danger", "Repayment receipt #{$repaymentId} was not found.");
        redirect(BASE_URL . "repayments/index.php");
    }

    // Trace historical balance ledger for this loan to determine Previous & Remaining Outstanding
    $histStmt = $pdo->prepare("
        SELECT id, amount, payment_date
        FROM repayments
        WHERE loan_id = :loan_id
        ORDER BY payment_date ASC, id ASC
    ");
    $histStmt->execute([':loan_id' => $repayment['loan_id']]);
    $allPayments = $histStmt->fetchAll();

    $totalPayable = (float)$repayment['total_payable'];
    $runningRepaid = 0.0;
    $prevOutstanding = $totalPayable;
    $paymentAmount = (float)$repayment['amount'];
    $postOutstanding = $totalPayable - $paymentAmount;

    foreach ($allPayments as $p) {
        if ((int)$p['id'] === $repaymentId) {
            $prevOutstanding = round(max(0, $totalPayable - $runningRepaid), 2);
            $runningRepaid += (float)$p['amount'];
            $postOutstanding = round(max(0, $totalPayable - $runningRepaid), 2);
            break;
        }
        $runningRepaid += (float)$p['amount'];
    }

} catch (PDOException $e) {
    error_log("Error retrieving repayment details: " . $e->getMessage());
    set_flash("danger", "An error occurred while loading repayment details.");
    redirect(BASE_URL . "repayments/index.php");
}

$flash = get_flash();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="content-container">

            <!-- Flash Notification -->
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show shadow-sm border-0 mb-4" role="alert">
                    <i class="fa-solid <?php echo ($flash['type'] === 'success') ? 'fa-circle-check' : 'fa-circle-info'; ?> me-2"></i>
                    <?php echo htmlspecialchars($flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Breadcrumb Navigation -->
            <nav aria-label="breadcrumb" class="mb-3 d-print-none">
                <ol class="breadcrumb small">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard.php" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>repayments/index.php" class="text-decoration-none">Repayments</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Receipt #<?php echo $repaymentId; ?></li>
                </ol>
            </nav>

            <!-- Page Title & Top Actions -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4 d-print-none">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h1 class="page-title mb-0">Repayment Receipt #<?php echo $repaymentId; ?></h1>
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                            <i class="fa-solid fa-circle-check me-1"></i> Recorded
                        </span>
                    </div>
                    <p class="page-subtitle">
                        Payment of <strong class="text-success"><?php echo CURRENCY_SYMBOL . number_format($paymentAmount, 2); ?></strong> received on <?php echo date('M d, Y', strtotime($repayment['payment_date'])); ?>
                    </p>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="button" onclick="window.print()" class="btn btn-outline-dark btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-print"></i>
                        <span>Print Receipt</span>
                    </button>
                    <a href="<?php echo BASE_URL; ?>repayments/edit.php?id=<?php echo $repaymentId; ?>" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-pen-to-square"></i>
                        <span>Edit</span>
                    </a>
                    <button type="button" class="btn btn-outline-danger btn-sm d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#deleteRepaymentModal">
                        <i class="fa-solid fa-trash-can"></i>
                        <span>Delete</span>
                    </button>
                    <a href="<?php echo BASE_URL; ?>repayments/index.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Back to Repayments</span>
                    </a>
                </div>
            </div>

            <!-- Receipt Container Card -->
            <div class="content-card receipt-card mb-4" id="printableReceipt">
                <div class="p-4 p-md-5">

                    <!-- Receipt Header Banner -->
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 pb-4 mb-4 border-bottom">
                        <div class="d-flex align-items-center gap-3">
                            <div class="brand-icon-box bg-success text-white" style="width: 52px; height: 52px; font-size: 1.5rem; border-radius: 12px;">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                            <div>
                                <h2 class="h4 fw-bold text-dark mb-0"><?php echo APP_NAME; ?></h2>
                                <span class="text-muted small">Official Repayment Voucher &amp; Acknowledgment</span>
                            </div>
                        </div>
                        <div class="text-md-end">
                            <div class="badge bg-light text-dark border px-3 py-2 fs-6 fw-mono mb-1">
                                RECEIPT #REP-<?php echo str_pad((string)$repaymentId, 5, '0', STR_PAD_LEFT); ?>
                            </div>
                            <div class="text-muted small">
                                Date: <strong class="text-dark"><?php echo date('M d, Y', strtotime($repayment['payment_date'])); ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- 3-Column Info Overview: Borrower, Loan, Payment Method -->
                    <div class="row g-4 mb-4">
                        <!-- 1. Borrower Information -->
                        <div class="col-12 col-md-4">
                            <div class="p-3 bg-light rounded border h-100">
                                <span class="text-muted small text-uppercase fw-semibold d-block mb-2">
                                    <i class="fa-solid fa-user me-1 text-primary"></i> Borrower Details
                                </span>
                                <div class="fw-bold text-dark fs-6 mb-1">
                                    <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo (int)$repayment['borrower_id']; ?>" class="text-dark text-decoration-none">
                                        <?php echo htmlspecialchars($repayment['borrower_name']); ?>
                                    </a>
                                </div>
                                <div class="small text-secondary mb-1">
                                    <i class="fa-solid fa-phone me-1 text-muted"></i><?php echo htmlspecialchars($repayment['borrower_phone']); ?>
                                </div>
                                <?php if (!empty($repayment['borrower_email'])): ?>
                                    <div class="small text-secondary mb-1">
                                        <i class="fa-solid fa-envelope me-1 text-muted"></i><?php echo htmlspecialchars($repayment['borrower_email']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($repayment['borrower_address'])): ?>
                                    <div class="small text-muted mt-2">
                                        <i class="fa-solid fa-location-dot me-1"></i><?php echo htmlspecialchars($repayment['borrower_address']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 2. Loan Agreement Information -->
                        <div class="col-12 col-md-4">
                            <div class="p-3 bg-light rounded border h-100">
                                <span class="text-muted small text-uppercase fw-semibold d-block mb-2">
                                    <i class="fa-solid fa-file-invoice-dollar me-1 text-primary"></i> Loan Agreement
                                </span>
                                <div class="fw-bold text-dark fs-6 mb-1">
                                    <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$repayment['loan_id']; ?>" class="text-primary text-decoration-none">
                                        Loan Agreement #<?php echo (int)$repayment['loan_id']; ?>
                                    </a>
                                </div>
                                <div class="small text-secondary mb-1">
                                    Principal: <strong><?php echo CURRENCY_SYMBOL . number_format((float)$repayment['principal_amount'], 2); ?></strong>
                                </div>
                                <div class="small text-secondary mb-1">
                                    Total Payable: <strong><?php echo CURRENCY_SYMBOL . number_format($totalPayable, 2); ?></strong>
                                </div>
                                <div class="small text-secondary">
                                    Due Date: <?php echo date('M d, Y', strtotime($repayment['due_date'])); ?>
                                </div>
                                <div class="mt-2">
                                    <?php echo get_loan_status_badge($repayment['loan_status']); ?>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Transaction Meta & Recorded By -->
                        <div class="col-12 col-md-4">
                            <div class="p-3 bg-light rounded border h-100">
                                <span class="text-muted small text-uppercase fw-semibold d-block mb-2">
                                    <i class="fa-solid fa-circle-info me-1 text-primary"></i> Payment Method &amp; Audit
                                </span>
                                <div class="mb-2">
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis border px-2 py-1">
                                        <i class="fa-solid fa-wallet me-1"></i> <?php echo htmlspecialchars($repayment['payment_method']); ?>
                                    </span>
                                </div>
                                <div class="small text-secondary mb-1">
                                    Recorded By: <strong class="text-dark"><?php echo htmlspecialchars($repayment['recorded_by_name'] ?? 'Admin User'); ?></strong>
                                </div>
                                <div class="small text-muted mb-1">
                                    Logged at: <?php echo date('M d, Y h:i A', strtotime($repayment['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Ledger Summary Table (Requirement 8) -->
                    <div class="card border mb-4">
                        <div class="card-header bg-light py-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <h3 class="h6 fw-bold text-dark mb-0">
                                    <i class="fa-solid fa-calculator text-success me-2"></i>Financial Settlement Ledger
                                </h3>
                                <span class="badge bg-light text-secondary border">Single Installment Impact</span>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered mb-0 align-middle">
                                <thead class="table-light text-muted small text-uppercase">
                                    <tr>
                                        <th class="ps-4">Ledger Item</th>
                                        <th class="text-center" style="width: 250px;">Calculation</th>
                                        <th class="text-end pe-4" style="width: 220px;">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="ps-4">
                                            <span class="fw-semibold text-dark">Previous Outstanding Balance</span>
                                            <div class="text-muted small">Total balance remaining prior to this installment</div>
                                        </td>
                                        <td class="text-center text-muted small">
                                            Payable − Prior Repayments
                                        </td>
                                        <td class="text-end pe-4 fw-bold text-dark fs-6">
                                            <?php echo CURRENCY_SYMBOL . number_format($prevOutstanding, 2); ?>
                                        </td>
                                    </tr>
                                    <tr class="table-success-subtle" style="background-color: #f0fdf4;">
                                        <td class="ps-4">
                                            <span class="fw-bold text-success">
                                                <i class="fa-solid fa-plus me-1"></i> Payment Received
                                            </span>
                                            <div class="text-success small opacity-75">Installment recorded on this voucher via <?php echo htmlspecialchars($repayment['payment_method']); ?></div>
                                        </td>
                                        <td class="text-center text-success small fw-semibold">
                                            Direct Credit
                                        </td>
                                        <td class="text-end pe-4 fw-bold text-success fs-5">
                                            - <?php echo CURRENCY_SYMBOL . number_format($paymentAmount, 2); ?>
                                        </td>
                                    </tr>
                                    <tr class="table-light">
                                        <td class="ps-4">
                                            <span class="fw-bold text-dark">Remaining Outstanding Balance</span>
                                            <div class="text-muted small">Balance left immediately after this payment was applied</div>
                                        </td>
                                        <td class="text-center text-muted small">
                                            Previous − Payment Amount
                                        </td>
                                        <td class="text-end pe-4 fw-bold fs-6 <?php echo ($postOutstanding <= 0.001) ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo CURRENCY_SYMBOL . number_format($postOutstanding, 2); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="ps-4">
                                            <span class="fw-semibold text-secondary">Current Loan Portfolio Balance Today</span>
                                            <div class="text-muted small">Reflects all subsequent payments recorded up to today</div>
                                        </td>
                                        <td class="text-center text-muted small">
                                            Live Loan State
                                        </td>
                                        <td class="text-end pe-4 fw-bold <?php echo ((float)$repayment['loan_current_balance'] <= 0.001) ? 'text-success' : 'text-secondary'; ?>">
                                            <?php echo CURRENCY_SYMBOL . number_format((float)$repayment['loan_current_balance'], 2); ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Notes Section -->
                    <div class="p-3 bg-light rounded border mb-4">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1">
                            <i class="fa-solid fa-note-sticky me-1"></i> Transaction Notes / Reference
                        </span>
                        <div class="text-dark small">
                            <?php if (!empty($repayment['notes'])): ?>
                                <?php echo nl2br(htmlspecialchars($repayment['notes'])); ?>
                            <?php else: ?>
                                <span class="text-muted fst-italic">No additional notes or reference specified for this transaction.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Signatures / Footer for Printable Voucher -->
                    <div class="row pt-4 mt-4 border-top">
                        <div class="col-6">
                            <div class="text-muted small mb-4">Borrower Signature</div>
                            <div class="border-top pt-1 text-muted small" style="width: 180px;">
                                <?php echo htmlspecialchars($repayment['borrower_name']); ?>
                            </div>
                        </div>
                        <div class="col-6 text-end">
                            <div class="text-muted small mb-4">Authorized Signature / Seal</div>
                            <div class="border-top pt-1 text-muted small ms-auto" style="width: 180px;">
                                <?php echo htmlspecialchars($repayment['recorded_by_name'] ?? APP_NAME); ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Delete Confirmation Modal (Requirement 10) -->
            <div class="modal fade" id="deleteRepaymentModal" tabindex="-1" aria-labelledby="deleteRepaymentModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 shadow">
                        <form method="POST" action="<?php echo BASE_URL; ?>repayments/delete.php">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <input type="hidden" name="id" value="<?php echo $repaymentId; ?>">

                            <div class="modal-header border-bottom">
                                <h5 class="modal-title fw-bold text-danger" id="deleteRepaymentModalLabel">
                                    <i class="fa-solid fa-triangle-exclamation me-2"></i>Delete Repayment #<?php echo $repaymentId; ?>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body py-4">
                                <p class="text-dark mb-2">
                                    Are you sure you want to delete repayment <strong>#<?php echo $repaymentId; ?></strong> of <strong><?php echo CURRENCY_SYMBOL . number_format($paymentAmount, 2); ?></strong>?
                                </p>
                                <div class="alert alert-warning small mb-0">
                                    <i class="fa-solid fa-circle-exclamation me-1"></i>
                                    Deleting this repayment will automatically revert the loan balance and recalculate the loan status back to Active/Partially Paid if it was previously marked as Paid.
                                </div>
                            </div>
                            <div class="modal-footer border-top bg-light">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-danger btn-sm d-flex align-items-center gap-1">
                                    <i class="fa-solid fa-trash-can"></i>
                                    <span>Confirm Delete</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<style>
@media print {
    .d-print-none, .app-sidebar, .app-navbar, .app-footer, .breadcrumb {
        display: none !important;
    }
    .app-main {
        margin: 0 !important;
        padding: 0 !important;
    }
    .content-container {
        padding: 0 !important;
        max-width: 100% !important;
    }
    .content-card {
        border: none !important;
        box-shadow: none !important;
    }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
