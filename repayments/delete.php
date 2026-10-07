<?php
/**
 * MoneyLend - Delete Repayment Handler
 * 
 * Safely handles repayment deletion requests via POST with CSRF protection.
 * If accessed via GET, displays a full standalone confirmation screen.
 * Upon deletion within an atomic transaction, recalculates total repaid,
 * remaining balance, and updates loan status (e.g., reverting Paid loans to Active).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helper.php';

require_login();

$pdo = getDBConnection();

// Process POST deletion request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken   = $_POST['csrf_token'] ?? '';
    $repaymentId = (int)($_POST['id'] ?? $_POST['repayment_id'] ?? 0);

    // Validate CSRF token
    if (!verify_csrf_token($csrfToken)) {
        set_flash('danger', 'Security validation failed. Repayment deletion was cancelled.');
        redirect(BASE_URL . "repayments/index.php");
    }

    if ($repaymentId <= 0) {
        set_flash('danger', 'Invalid repayment ID specified.');
        redirect(BASE_URL . "repayments/index.php");
    }

    try {
        $pdo->beginTransaction();

        // 1. Fetch repayment and lock record
        $stmt = $pdo->prepare("SELECT id, loan_id, amount FROM repayments WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $repaymentId]);
        $repayment = $stmt->fetch();

        if (!$repayment) {
            $pdo->rollBack();
            set_flash('warning', "Repayment #{$repaymentId} was not found or already deleted.");
            redirect(BASE_URL . "repayments/index.php");
        }

        $loanId = (int)$repayment['loan_id'];
        $deletedAmount = (float)$repayment['amount'];

        // 2. Delete the repayment
        $delStmt = $pdo->prepare("DELETE FROM repayments WHERE id = :id");
        $delStmt->execute([':id' => $repaymentId]);

        // 3. Atomically recalculate loan totals and revert status if necessary
        $syncResult = sync_loan_balances($pdo, $loanId);

        $pdo->commit();

        $formattedStatus = ucfirst(str_replace('_', ' ', $syncResult['status']));
        set_flash(
            'success', 
            "Repayment #{$repaymentId} of " . CURRENCY_SYMBOL . number_format($deletedAmount, 2) . " has been deleted. " .
            "Loan #{$loanId} balance is now " . CURRENCY_SYMBOL . number_format($syncResult['remaining_balance'], 2) . 
            " (Status: {$formattedStatus})."
        );
        redirect(BASE_URL . "repayments/index.php");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Repayment deletion error: " . $e->getMessage());
        set_flash('danger', 'A database error occurred while deleting the repayment.');
        redirect(BASE_URL . "repayments/index.php");
    }
}

// If accessed via GET, render full confirmation page
$repaymentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($repaymentId <= 0) {
    set_flash('danger', 'Invalid repayment ID specified.');
    redirect(BASE_URL . "repayments/index.php");
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            l.principal_amount,
            l.total_payable,
            l.amount_repaid AS loan_repaid,
            l.remaining_balance AS loan_balance,
            l.status AS loan_status,
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
        set_flash('danger', 'Repayment receipt was not found.');
        redirect(BASE_URL . "repayments/index.php");
    }

    $projectedRepaid = max(0.0, (float)$repayment['loan_repaid'] - (float)$repayment['amount']);
    $projectedBalance = min((float)$repayment['total_payable'], (float)$repayment['loan_balance'] + (float)$repayment['amount']);

} catch (PDOException $e) {
    error_log("Repayment fetch error: " . $e->getMessage());
    set_flash('danger', 'Error loading repayment details.');
    redirect(BASE_URL . "repayments/index.php");
}

$pageTitle = "Confirm Delete Repayment";
$activePage = "repayments";

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="content-container">

            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb small">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard.php" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>repayments/index.php" class="text-decoration-none">Repayments</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Confirm Delete</li>
                </ol>
            </nav>

            <div class="row justify-content-center">
                <div class="col-12 col-md-8 col-lg-6">
                    <div class="content-card border-danger shadow-sm">
                        <div class="content-card-header bg-danger-subtle text-danger border-bottom">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <h2 class="content-card-title text-danger mb-0">Confirm Repayment Deletion</h2>
                            </div>
                            <span class="badge bg-danger text-white">Receipt #<?php echo $repaymentId; ?></span>
                        </div>

                        <div class="card-body p-4">
                            <div class="text-center mb-4">
                                <div class="brand-icon-box bg-danger-subtle text-danger mx-auto mb-3" style="width: 54px; height: 54px; font-size: 1.6rem; border-radius: 50%;">
                                    <i class="fa-solid fa-trash-can"></i>
                                </div>
                                <h3 class="h5 fw-bold text-dark mb-1">Delete Repayment Voucher?</h3>
                                <p class="text-muted small">
                                    You are about to delete an installment payment of <strong class="text-danger"><?php echo CURRENCY_SYMBOL . number_format((float)$repayment['amount'], 2); ?></strong> collected from <strong><?php echo htmlspecialchars($repayment['borrower_name']); ?></strong>.
                                </p>
                            </div>

                            <!-- Transaction Details Summary -->
                            <div class="p-3 bg-light rounded border mb-4">
                                <div class="row g-2 small">
                                    <div class="col-6 text-muted">Receipt ID:</div>
                                    <div class="col-6 text-end fw-semibold">#<?php echo $repaymentId; ?></div>

                                    <div class="col-6 text-muted">Loan Agreement:</div>
                                    <div class="col-6 text-end fw-semibold">Loan #<?php echo (int)$repayment['loan_id']; ?></div>

                                    <div class="col-6 text-muted">Payment Date:</div>
                                    <div class="col-6 text-end text-dark"><?php echo date('M d, Y', strtotime($repayment['payment_date'])); ?></div>

                                    <div class="col-6 text-muted">Payment Method:</div>
                                    <div class="col-6 text-end"><?php echo htmlspecialchars($repayment['payment_method']); ?></div>

                                    <div class="col-6 text-muted">Payment Amount:</div>
                                    <div class="col-6 text-end text-success fw-bold"><?php echo CURRENCY_SYMBOL . number_format((float)$repayment['amount'], 2); ?></div>
                                </div>
                            </div>

                            <!-- Projected Balance Adjustment -->
                            <div class="alert alert-warning py-3 px-3 small mb-4">
                                <div class="fw-semibold mb-1">
                                    <i class="fa-solid fa-calculator me-1"></i> Automatic Financial Balance Reversion:
                                </div>
                                <div>• Current Loan Outstanding: <strong><?php echo CURRENCY_SYMBOL . number_format((float)$repayment['loan_balance'], 2); ?></strong></div>
                                <div>• Reverted Loan Outstanding: <strong class="text-danger"><?php echo CURRENCY_SYMBOL . number_format($projectedBalance, 2); ?></strong></div>
                                <div>• If this loan was previously marked as <em>Paid</em>, it will immediately reopen as <strong>Active / Partially Paid</strong>.</div>
                            </div>

                            <!-- Deletion Form -->
                            <form method="POST" action="<?php echo BASE_URL; ?>repayments/delete.php">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                <input type="hidden" name="id" value="<?php echo $repaymentId; ?>">

                                <div class="d-flex justify-content-between gap-2">
                                    <a href="<?php echo BASE_URL; ?>repayments/view.php?id=<?php echo $repaymentId; ?>" class="btn btn-outline-secondary flex-grow-1">
                                        Cancel &amp; Keep Record
                                    </a>
                                    <button type="submit" class="btn btn-danger flex-grow-1 d-flex align-items-center justify-content-center gap-1">
                                        <i class="fa-solid fa-trash-can"></i>
                                        <span>Confirm Delete</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
