<?php
/**
 * MoneyLend - Loan Details View
 * 
 * Comprehensive view of an individual loan agreement, borrower information,
 * financial totals, repayment progress bar, and installment ledger.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helper.php';

// Protect page
require_login();

$pageTitle = "Loan Agreement Details";
$activePage = "loans";
$flash = get_flash();
$pdo = getDBConnection();

$loanId = (int)($_GET['id'] ?? 0);

if ($loanId <= 0) {
    set_flash('danger', 'Invalid loan ID specified.');
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

    // 2. Fetch associated repayments
    $repStmt = $pdo->prepare("SELECT * FROM repayments WHERE loan_id = :id ORDER BY payment_date DESC, id DESC");
    $repStmt->execute([':id' => $loanId]);
    $repayments = $repStmt->fetchAll();

    // 3. Dynamic balance check & automated status update
    $calculatedStatus = determine_loan_status(
        $loan['status'],
        (float)$loan['remaining_balance'],
        (float)$loan['amount_repaid'],
        $loan['due_date']
    );
    if ($calculatedStatus !== $loan['status']) {
        $upd = $pdo->prepare("UPDATE loans SET status = :st WHERE id = :id");
        $upd->execute([':st' => $calculatedStatus, ':id' => $loanId]);
        $loan['status'] = $calculatedStatus;
    }

    // 4. Financial Calculations for UI
    $totalPayable = (float)$loan['total_payable'];
    $amountRepaid = (float)$loan['amount_repaid'];
    $remainingBalance = (float)$loan['remaining_balance'];
    $repaymentPercentage = ($totalPayable > 0) ? min(100.0, round(($amountRepaid / $totalPayable) * 100, 1)) : 0.0;

} catch (PDOException $e) {
    error_log("Loan view error: " . $e->getMessage());
    set_flash('danger', 'A database error occurred while fetching the loan agreement.');
    header("Location: " . BASE_URL . "loans/index.php");
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="content-container">

            <!-- Flash Alert -->
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show shadow-sm border-0 mb-4" role="alert">
                    <i class="fa-solid <?php echo ($flash['type'] === 'success') ? 'fa-circle-check' : 'fa-circle-info'; ?> me-2"></i>
                    <?php echo htmlspecialchars($flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Breadcrumb Navigation -->
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb small">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard.php" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>loans/index.php" class="text-decoration-none">Loans</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Loan #<?php echo $loanId; ?></li>
                </ol>
            </nav>

            <!-- Page Title & Top Actions -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h1 class="page-title mb-0">Loan Agreement #<?php echo $loanId; ?></h1>
                        <?php echo get_loan_status_badge($loan['status']); ?>
                    </div>
                    <p class="page-subtitle">
                        Issued to <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo (int)$loan['borrower_id']; ?>" class="fw-semibold text-dark text-decoration-none"><?php echo htmlspecialchars($loan['borrower_name']); ?></a> on <?php echo date('M d, Y', strtotime($loan['start_date'])); ?>
                    </p>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="<?php echo BASE_URL; ?>loans/edit.php?id=<?php echo $loanId; ?>" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-pen-to-square"></i>
                        <span>Edit Loan</span>
                    </a>
                    <?php if ($loan['status'] !== 'paid' && (float)$loan['remaining_balance'] > 0.001): ?>
                        <a href="<?php echo BASE_URL; ?>repayments/add.php?loan_id=<?php echo $loanId; ?>" class="btn btn-success btn-sm d-flex align-items-center gap-1">
                            <i class="fa-solid fa-money-bill-transfer"></i>
                            <span>Record Repayment</span>
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo BASE_URL; ?>loans/index.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Back to Loans</span>
                    </a>
                </div>
            </div>

            <!-- Repayment Progress Card -->
            <div class="content-card mb-4">
                <div class="p-4">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-2">
                        <div>
                            <span class="text-muted small text-uppercase fw-semibold">Portfolio Recovery Status</span>
                            <div class="h4 fw-bold text-dark mb-0">
                                <?php echo CURRENCY_SYMBOL . number_format($amountRepaid, 2); ?> 
                                <span class="text-muted fs-6 fw-normal">recovered of <?php echo CURRENCY_SYMBOL . number_format($totalPayable, 2); ?></span>
                            </div>
                        </div>
                        <div class="text-md-end">
                            <span class="badge bg-<?php echo ($repaymentPercentage >= 100) ? 'success' : (($repaymentPercentage > 0) ? 'primary' : 'secondary'); ?> fs-6 px-3 py-1">
                                <?php echo $repaymentPercentage; ?>% Completed
                            </span>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <div class="progress" style="height: 12px;" role="progressbar" aria-valuenow="<?php echo $repaymentPercentage; ?>" aria-valuemin="0" aria-valuemax="100">
                        <div 
                            class="progress-bar <?php echo ($repaymentPercentage >= 100) ? 'bg-success' : 'bg-primary'; ?>" 
                            style="width: <?php echo $repaymentPercentage; ?>%; transition: width 0.6s ease;"
                        ></div>
                    </div>

                    <div class="d-flex justify-content-between text-muted small mt-2">
                        <span>Disbursed: <?php echo CURRENCY_SYMBOL . number_format((float)$loan['principal_amount'], 2); ?></span>
                        <span class="fw-semibold <?php echo ($remainingBalance > 0) ? 'text-danger' : 'text-success'; ?>">
                            Remaining: <?php echo CURRENCY_SYMBOL . number_format($remainingBalance, 2); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Main Details Row -->
            <div class="row g-4 mb-4">
                <!-- Section 1: Loan Parameters -->
                <div class="col-12 col-lg-7">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <h2 class="content-card-title">
                                <i class="fa-solid fa-file-contract me-2 text-primary"></i>Loan Terms &amp; Financial Structure
                            </h2>
                            <span class="text-muted small">Agreement #<?php echo $loanId; ?></span>
                        </div>

                        <div class="p-3">
                            <div class="row g-2 mb-3">
                                <div class="col-6 col-sm-3">
                                    <div class="p-2 bg-light rounded text-center border">
                                        <div class="text-muted small">Principal</div>
                                        <div class="fw-bold text-dark"><?php echo CURRENCY_SYMBOL . number_format((float)$loan['principal_amount'], 2); ?></div>
                                    </div>
                                </div>
                                <div class="col-6 col-sm-3">
                                    <div class="p-2 bg-light rounded text-center border">
                                        <div class="text-muted small">Rate</div>
                                        <div class="fw-bold text-dark"><?php echo number_format((float)$loan['interest_rate'], 1); ?>%</div>
                                    </div>
                                </div>
                                <div class="col-6 col-sm-3">
                                    <div class="p-2 bg-light rounded text-center border">
                                        <div class="text-muted small">Interest Model</div>
                                        <div class="fw-bold text-dark text-capitalize"><?php echo htmlspecialchars($loan['interest_type']); ?></div>
                                    </div>
                                </div>
                                <div class="col-6 col-sm-3">
                                    <div class="p-2 bg-light rounded text-center border">
                                        <div class="text-muted small">Duration</div>
                                        <div class="fw-bold text-dark"><?php echo (int)$loan['duration_value'] . ' ' . ucfirst($loan['duration_unit']); ?></div>
                                    </div>
                                </div>
                            </div>

                            <ul class="list-group list-group-flush small">
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Start (Disbursement) Date:</span>
                                    <span class="fw-semibold text-dark"><?php echo date('F d, Y', strtotime($loan['start_date'])); ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Maturity / Due Date:</span>
                                    <span class="fw-semibold <?php echo (date('Y-m-d') > $loan['due_date'] && $remainingBalance > 0) ? 'text-danger' : 'text-dark'; ?>">
                                        <?php echo date('F d, Y', strtotime($loan['due_date'])); ?>
                                        <?php if (date('Y-m-d') > $loan['due_date'] && $remainingBalance > 0): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1">Overdue</span>
                                        <?php endif; ?>
                                    </span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Calculated Total Interest:</span>
                                    <span class="fw-bold text-primary">+<?php echo CURRENCY_SYMBOL . number_format((float)$loan['total_interest'], 2); ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Total Repayable Capital:</span>
                                    <span class="fw-bold text-dark fs-6"><?php echo CURRENCY_SYMBOL . number_format($totalPayable, 2); ?></span>
                                </li>
                                <li class="list-group-item px-0 py-2">
                                    <span class="text-muted d-block mb-1">Contract Notes:</span>
                                    <span class="text-dark">
                                        <?php echo !empty($loan['notes']) ? nl2br(htmlspecialchars($loan['notes'])) : '<em class="text-muted">No notes provided for this agreement.</em>'; ?>
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Borrower Summary -->
                <div class="col-12 col-lg-5">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <h2 class="content-card-title">
                                <i class="fa-solid fa-user me-2 text-primary"></i>Borrower Client
                            </h2>
                            <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo (int)$loan['borrower_id']; ?>" class="btn btn-outline-secondary btn-sm" style="font-size: 0.78rem;">
                                Full Profile &rarr;
                            </a>
                        </div>

                        <div class="p-3">
                            <div class="d-flex align-items-center gap-3 mb-3 p-2 bg-light rounded border">
                                <div class="navbar-user-avatar" style="width: 44px; height: 44px; font-size: 1.1rem; background-color: #ecfdf5; color: #059669;">
                                    <?php echo htmlspecialchars(strtoupper(substr($loan['borrower_name'], 0, 1))); ?>
                                </div>
                                <div>
                                    <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($loan['borrower_name']); ?></div>
                                    <div class="text-muted small">Client ID #<?php echo (int)$loan['borrower_id']; ?></div>
                                </div>
                                <div class="ms-auto">
                                    <?php if ($loan['borrower_status'] === 'active'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <ul class="list-group list-group-flush small">
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Mobile Phone:</span>
                                    <span class="fw-semibold text-dark">
                                        <i class="fa-solid fa-phone me-1 text-muted"></i>
                                        <?php echo htmlspecialchars($loan['borrower_phone']); ?>
                                    </span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Email Address:</span>
                                    <span class="text-dark">
                                        <?php echo !empty($loan['borrower_email']) ? htmlspecialchars($loan['borrower_email']) : '<em class="text-muted">Not specified</em>'; ?>
                                    </span>
                                </li>
                                <li class="list-group-item px-0 py-2">
                                    <span class="text-muted d-block mb-1">Residential Address:</span>
                                    <span class="text-dark">
                                        <?php echo !empty($loan['borrower_address']) ? nl2br(htmlspecialchars($loan['borrower_address'])) : '<em class="text-muted">Not specified</em>'; ?>
                                    </span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Member Since:</span>
                                    <span class="text-secondary"><?php echo date('M d, Y', strtotime($loan['borrower_created_at'])); ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Associated Repayments Ledger -->
            <div class="content-card">
                <div class="content-card-header">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-receipt text-success"></i>
                        <h2 class="content-card-title">Repayment Installment History</h2>
                    </div>
                    <span class="badge bg-light text-secondary border"><?php echo count($repayments); ?> Records</span>
                </div>

                <div class="card-body p-0">
                    <?php if (empty($repayments)): ?>
                        <div class="empty-state py-4">
                            <div class="empty-state-icon" style="width: 44px; height: 44px; font-size: 1.2rem;">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                            <h3 class="empty-state-title fs-6">No repayment installments recorded yet</h3>
                            <p class="empty-state-text small mb-3">Installments logged against this agreement will appear here automatically.</p>
                            <?php if ($loan['status'] !== 'paid' && (float)$loan['remaining_balance'] > 0.001): ?>
                                <a href="<?php echo BASE_URL; ?>repayments/add.php?loan_id=<?php echo $loanId; ?>" class="btn btn-outline-success btn-sm">
                                    <i class="fa-solid fa-plus me-1"></i> Record First Payment
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead class="table-light text-muted">
                                    <tr>
                                        <th class="ps-3">Receipt ID</th>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Payment Method</th>
                                        <th>Notes</th>
                                        <th class="text-end pe-3">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($repayments as $r): ?>
                                        <tr>
                                            <td class="ps-3 fw-semibold">
                                                <a href="<?php echo BASE_URL; ?>repayments/view.php?id=<?php echo (int)$r['id']; ?>" class="text-dark text-decoration-none">
                                                    #<?php echo (int)$r['id']; ?>
                                                </a>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($r['payment_date'])); ?></td>
                                            <td class="text-success fw-bold">+<?php echo CURRENCY_SYMBOL . number_format((float)$r['amount'], 2); ?></td>
                                            <td>
                                                <span class="badge bg-light text-secondary border">
                                                    <?php echo htmlspecialchars($r['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td class="text-muted">
                                                <?php echo !empty($r['notes']) ? htmlspecialchars($r['notes']) : '—'; ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <a href="<?php echo BASE_URL; ?>repayments/view.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-outline-primary btn-sm py-0 px-2" title="View Receipt Voucher">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div> <!-- End .content-container -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
