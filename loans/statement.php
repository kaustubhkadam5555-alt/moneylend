<?php
/**
 * MoneyLend - Loan Agreement Statement (Phase 10)
 *
 * Official printable loan statement and installment ledger for an individual loan.
 * Shows complete agreement terms, disbursed capital, interest breakdown,
 * chronological repayment ledger with running balance, and signature vouchers.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helper.php';

require_login();

$pageTitle = "Loan Agreement Statement";
$activePage = "loans";
$pdo = getDBConnection();

$loanId = (int)($_GET['id'] ?? 0);

if ($loanId <= 0) {
    set_flash('danger', 'Invalid loan agreement ID specified.');
    header("Location: " . BASE_URL . "loans/index.php");
    exit;
}

try {
    // 1. Fetch Loan with Borrower details
    $stmt = $pdo->prepare("
        SELECT l.*, 
               b.full_name AS borrower_name, 
               b.phone AS borrower_phone, 
               b.email AS borrower_email, 
               b.address AS borrower_address, 
               b.status AS borrower_status,
               b.created_at AS borrower_created_at
        FROM loans l
        JOIN borrowers b ON l.borrower_id = b.id
        WHERE l.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $loanId]);
    $loan = $stmt->fetch();

    if (!$loan) {
        set_flash('danger', "Loan agreement #{$loanId} was not found.");
        header("Location: " . BASE_URL . "loans/index.php");
        exit;
    }

    // 2. Fetch all repayments for this loan ordered chronologically
    $repStmt = $pdo->prepare("
        SELECT r.*, u.name AS recorded_by_name
        FROM repayments r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.loan_id = :loan_id
        ORDER BY r.payment_date ASC, r.id ASC
    ");
    $repStmt->execute([':loan_id' => $loanId]);
    $repayments = $repStmt->fetchAll();

    // 3. Financial calculations
    $principal = (float)$loan['principal_amount'];
    $totalInterest = (float)$loan['total_interest'];
    $totalPayable = (float)$loan['total_payable'];
    $amountRepaid = (float)$loan['amount_repaid'];
    $remainingBalance = (float)$loan['remaining_balance'];
    $progressPercent = ($totalPayable > 0) ? min(100.0, round(($amountRepaid / $totalPayable) * 100, 1)) : 0.0;

    // 4. Calculate chronological running balance ledger
    $runningBalance = $totalPayable;
    $ledgerEntries = [];
    foreach ($repayments as $idx => $r) {
        $installmentAmt = (float)$r['amount'];
        $prevBal = $runningBalance;
        $runningBalance = max(0.0, $runningBalance - $installmentAmt);
        $ledgerEntries[] = [
            'number'           => $idx + 1,
            'id'               => (int)$r['id'],
            'date'             => $r['payment_date'],
            'amount'           => $installmentAmt,
            'method'           => $r['payment_method'],
            'notes'            => $r['notes'] ?? '',
            'recorded_by'      => $r['recorded_by_name'] ?? 'System Admin',
            'previous_balance' => $prevBal,
            'running_balance'  => $runningBalance
        ];
    }

} catch (PDOException $e) {
    error_log("Loan statement error: " . $e->getMessage());
    set_flash('danger', 'Unable to generate statement due to a database error.');
    header("Location: " . BASE_URL . "loans/view.php?id=" . $loanId);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="content-container">

            <!-- Breadcrumb Navigation (Screen Only) -->
            <nav aria-label="breadcrumb" class="mb-3 d-print-none">
                <ol class="breadcrumb small">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard.php" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>loans/index.php" class="text-decoration-none">Loans</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo $loanId; ?>" class="text-decoration-none">Loan #<?php echo $loanId; ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Statement</li>
                </ol>
            </nav>

            <!-- Screen Action Bar -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4 d-print-none">
                <div>
                    <h1 class="page-title mb-1">Loan Agreement Statement</h1>
                    <p class="page-subtitle mb-0">Official installment statement and ledger balance for Agreement #<?php echo $loanId; ?></p>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="button" onclick="window.print()" class="btn btn-primary btn-sm d-flex align-items-center gap-2 shadow-sm">
                        <i class="fa-solid fa-print"></i>
                        <span>Print Statement</span>
                    </button>
                    <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo $loanId; ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Back to Loan</span>
                    </a>
                </div>
            </div>

            <!-- PRINTABLE STATEMENT SHEET -->
            <div class="statement-sheet card border-0 shadow-sm p-4 p-md-5 mb-5 bg-white">
                
                <!-- Statement Header -->
                <div class="row align-items-center pb-4 mb-4 border-bottom g-3">
                    <div class="col-12 col-md-7">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="fs-3 fw-bold text-primary tracking-tight">
                                <i class="fa-solid fa-hand-holding-dollar text-primary me-2"></i>MoneyLend
                            </span>
                        </div>
                        <p class="text-muted small mb-0">
                            Micro-Finance &amp; Lending Management Services<br>
                            Official Lending Statement &bull; Reference #LN-<?php echo str_pad($loanId, 6, '0', STR_PAD_LEFT); ?>
                        </p>
                    </div>
                    <div class="col-12 col-md-5 text-md-end">
                        <span class="badge bg-light text-dark border px-3 py-2 fs-6 fw-semibold mb-2">
                            LOAN STATEMENT
                        </span>
                        <div class="text-muted small">
                            <strong>Date of Statement:</strong> <?php echo date('F d, Y'); ?><br>
                            <strong>Generated At:</strong> <?php echo date('H:i:s T'); ?>
                        </div>
                    </div>
                </div>

                <!-- Borrower and Agreement Overview -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-md-6">
                        <div class="p-3 bg-light rounded border h-100">
                            <h2 class="text-uppercase small fw-bold text-muted mb-2 letter-spacing">Borrower (Obligor) Details</h2>
                            <div class="fs-5 fw-bold text-dark mb-1"><?php echo htmlspecialchars($loan['borrower_name']); ?></div>
                            <div class="small text-muted mb-2">Client ID: #<?php echo (int)$loan['borrower_id']; ?></div>
                            <ul class="list-unstyled small mb-0">
                                <li class="mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars($loan['borrower_phone']); ?></li>
                                <li class="mb-1"><strong>Email:</strong> <?php echo !empty($loan['borrower_email']) ? htmlspecialchars($loan['borrower_email']) : 'N/A'; ?></li>
                                <li><strong>Address:</strong> <?php echo !empty($loan['borrower_address']) ? htmlspecialchars($loan['borrower_address']) : 'N/A'; ?></li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="p-3 bg-light rounded border h-100">
                            <h2 class="text-uppercase small fw-bold text-muted mb-2 letter-spacing">Agreement Parameters</h2>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold fs-6">Agreement ID: #<?php echo $loanId; ?></span>
                                <div><?php echo get_loan_status_badge($loan['status']); ?></div>
                            </div>
                            <ul class="list-unstyled small mb-0">
                                <li class="mb-1 d-flex justify-content-between">
                                    <span class="text-muted">Disbursement Date:</span>
                                    <span class="fw-semibold"><?php echo date('M d, Y', strtotime($loan['start_date'])); ?></span>
                                </li>
                                <li class="mb-1 d-flex justify-content-between">
                                    <span class="text-muted">Maturity / Due Date:</span>
                                    <span class="fw-semibold"><?php echo date('M d, Y', strtotime($loan['due_date'])); ?></span>
                                </li>
                                <li class="mb-1 d-flex justify-content-between">
                                    <span class="text-muted">Interest Scheme:</span>
                                    <span class="fw-semibold text-capitalize"><?php echo number_format((float)$loan['interest_rate'], 1); ?>% (<?php echo htmlspecialchars($loan['interest_type']); ?>)</span>
                                </li>
                                <li class="d-flex justify-content-between">
                                    <span class="text-muted">Duration:</span>
                                    <span class="fw-semibold"><?php echo (int)$loan['duration_value'] . ' ' . ucfirst($loan['duration_unit']); ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Financial Terms Summary Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-3 text-center bg-white shadow-2xs">
                            <span class="text-muted small text-uppercase fw-semibold d-block">Principal Disbursed</span>
                            <span class="fs-5 fw-bold text-dark"><?php echo CURRENCY_SYMBOL . number_format($principal, 2); ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-3 text-center bg-white shadow-2xs">
                            <span class="text-muted small text-uppercase fw-semibold d-block">Total Interest</span>
                            <span class="fs-5 fw-bold text-primary">+<?php echo CURRENCY_SYMBOL . number_format($totalInterest, 2); ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-3 text-center bg-white shadow-2xs">
                            <span class="text-muted small text-uppercase fw-semibold d-block">Total Repaid</span>
                            <span class="fs-5 fw-bold text-success"><?php echo CURRENCY_SYMBOL . number_format($amountRepaid, 2); ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-3 text-center bg-white shadow-2xs">
                            <span class="text-muted small text-uppercase fw-semibold d-block">Net Balance Due</span>
                            <span class="fs-5 fw-bold <?php echo ($remainingBalance > 0.001) ? 'text-danger' : 'text-success'; ?>">
                                <?php echo CURRENCY_SYMBOL . number_format($remainingBalance, 2); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Progress Bar Summary -->
                <div class="mb-4 p-3 bg-light rounded border">
                    <div class="d-flex justify-content-between small fw-semibold text-muted mb-1">
                        <span>Repayment Progress</span>
                        <span><?php echo $progressPercent; ?>% Recovered (<?php echo CURRENCY_SYMBOL . number_format($amountRepaid, 2); ?> of <?php echo CURRENCY_SYMBOL . number_format($totalPayable, 2); ?>)</span>
                    </div>
                    <div class="progress" style="height: 10px;">
                        <div class="progress-bar <?php echo ($progressPercent >= 100) ? 'bg-success' : 'bg-primary'; ?>" 
                             style="width: <?php echo $progressPercent; ?>%;" 
                             role="progressbar" 
                             aria-valuenow="<?php echo $progressPercent; ?>" 
                             aria-valuemin="0" 
                             aria-valuemax="100">
                        </div>
                    </div>
                </div>

                <!-- Repayment Ledger Table -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="fs-5 fw-bold text-dark mb-0">
                            <i class="fa-solid fa-list-check text-primary me-2"></i>Repayment Installment Ledger
                        </h2>
                        <span class="text-muted small"><?php echo count($repayments); ?> Installment(s) Recorded</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;" class="text-center">#</th>
                                    <th>Payment Date</th>
                                    <th>Receipt #</th>
                                    <th>Method</th>
                                    <th class="text-end">Amount Paid</th>
                                    <th class="text-end">Remaining Balance</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($ledgerEntries)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">
                                            <em>No repayment installments have been recorded for this agreement yet.</em>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($ledgerEntries as $entry): ?>
                                        <tr>
                                            <td class="text-center fw-semibold text-muted"><?php echo $entry['number']; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($entry['date'])); ?></td>
                                            <td class="fw-semibold">
                                                <a href="<?php echo BASE_URL; ?>repayments/view.php?id=<?php echo $entry['id']; ?>" class="text-decoration-none text-dark">
                                                    #<?php echo $entry['id']; ?>
                                                </a>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-secondary border">
                                                    <?php echo htmlspecialchars($entry['method']); ?>
                                                </span>
                                            </td>
                                            <td class="text-end fw-bold text-success">+<?php echo CURRENCY_SYMBOL . number_format($entry['amount'], 2); ?></td>
                                            <td class="text-end fw-bold <?php echo ($entry['running_balance'] > 0.001) ? 'text-dark' : 'text-success'; ?>">
                                                <?php echo CURRENCY_SYMBOL . number_format($entry['running_balance'], 2); ?>
                                            </td>
                                            <td class="text-muted">
                                                <?php echo !empty($entry['notes']) ? htmlspecialchars($entry['notes']) : '—'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="4" class="text-end">Total Capital Payable:</td>
                                    <td colspan="3" class="text-dark"><?php echo CURRENCY_SYMBOL . number_format($totalPayable, 2); ?></td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="text-end text-success">Total Recovered:</td>
                                    <td colspan="3" class="text-success"><?php echo CURRENCY_SYMBOL . number_format($amountRepaid, 2); ?></td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="text-end <?php echo ($remainingBalance > 0.001) ? 'text-danger' : 'text-success'; ?>">
                                        Outstanding Balance Due:
                                    </td>
                                    <td colspan="3" class="<?php echo ($remainingBalance > 0.001) ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo CURRENCY_SYMBOL . number_format($remainingBalance, 2); ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Terms & Signatures (Print Ready) -->
                <div class="row pt-4 mt-4 border-top g-4">
                    <div class="col-6">
                        <div class="text-muted small mb-5">
                            <strong>Borrower Declaration:</strong><br>
                            I acknowledge the loan terms and confirm the accuracy of all recorded transactions above.
                        </div>
                        <div class="border-top pt-2 text-center" style="max-width: 240px;">
                            <span class="small text-muted fw-semibold">Borrower Signature</span>
                        </div>
                    </div>
                    <div class="col-6 text-end">
                        <div class="text-muted small mb-5">
                            <strong>Authorized Lending Officer:</strong><br>
                            Certified statement generated from MoneyLend portfolio database.
                        </div>
                        <div class="border-top pt-2 text-center ms-auto" style="max-width: 240px;">
                            <span class="small text-muted fw-semibold">Authorized Signature &amp; Seal</span>
                        </div>
                    </div>
                </div>

                <!-- Statement Footer Note -->
                <div class="text-center text-muted small mt-5 pt-3 border-top">
                    <p class="mb-0">
                        MoneyLend Automated Portfolio Statement &bull; This is a system-generated document.
                    </p>
                </div>

            </div> <!-- End .statement-sheet -->

        </div> <!-- End .content-container -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<style>
@media print {
    /* Hide navigational and action controls */
    .app-sidebar,
    .app-navbar,
    .app-footer,
    .d-print-none,
    .btn,
    .breadcrumb,
    .alert {
        display: none !important;
    }

    body, html {
        background-color: #ffffff !important;
        color: #000000 !important;
        font-size: 11pt !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .app-wrapper,
    .app-main,
    .content-container {
        margin: 0 !important;
        padding: 0 !important;
        max-width: 100% !important;
        width: 100% !important;
    }

    .statement-sheet {
        box-shadow: none !important;
        border: none !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    .table {
        border-color: #dee2e6 !important;
    }

    .badge {
        border: 1px solid #999 !important;
        color: #000 !important;
        background: transparent !important;
    }

    a {
        text-decoration: none !important;
        color: inherit !important;
    }
}
</style>
