<?php
/**
 * MoneyLend - Dashboard
 * 
 * Main administrative dashboard displaying real-time financial metrics,
 * quick actions, recent loans, and recent repayments.
 * Connected to MySQL database via PDO.
 */

// Application configuration and session handling
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/loan_helper.php';

// Protect dashboard from unauthenticated visitors
require_login();

$pageTitle = "Dashboard";
$activePage = "dashboard";
$flash = get_flash();

$pdo = getDBConnection();

// Initialize Metrics with safe defaults
$stats = [
    'total_borrowers'     => 0,
    'active_loans'        => 0,
    'total_amount_lent'   => 0.00,
    'total_amount_repaid' => 0.00,
    'total_outstanding'   => 0.00,
];
$recentLoans = [];
$recentRepayments = [];

try {
    // 1. Total Borrowers
    $borrowerStmt = $pdo->query("SELECT COUNT(*) FROM borrowers");
    $stats['total_borrowers'] = (int)$borrowerStmt->fetchColumn();

    // 2. Active Loans (active, partially_paid, or overdue)
    $activeLoanStmt = $pdo->query("SELECT COUNT(*) FROM loans WHERE status IN ('active', 'partially_paid', 'overdue')");
    $stats['active_loans'] = (int)$activeLoanStmt->fetchColumn();

    // 3. Total Amount Lent (Principal disbursed)
    $amountLentStmt = $pdo->query("SELECT COALESCE(SUM(principal_amount), 0) FROM loans WHERE status != 'cancelled'");
    $stats['total_amount_lent'] = (float)$amountLentStmt->fetchColumn();

    // 4. Total Amount Repaid (Sum of all completed repayments)
    $repaidStmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM repayments");
    $stats['total_amount_repaid'] = (float)$repaidStmt->fetchColumn();

    // 5. Total Outstanding Balance
    $outstandingStmt = $pdo->query("SELECT COALESCE(SUM(remaining_balance), 0) FROM loans WHERE status != 'cancelled'");
    $stats['total_outstanding'] = (float)$outstandingStmt->fetchColumn();

    // 6. Recent Loans (Latest 5)
    $recentLoansStmt = $pdo->query("
        SELECT l.*, b.full_name AS borrower_name 
        FROM loans l 
        JOIN borrowers b ON l.borrower_id = b.id 
        ORDER BY l.id DESC 
        LIMIT 5
    ");
    $recentLoans = $recentLoansStmt->fetchAll();

    // 7. Recent Repayments (Latest 5)
    $recentRepaymentsStmt = $pdo->query("
        SELECT 
            r.*, 
            b.id AS borrower_id, 
            b.full_name AS borrower_name, 
            b.phone AS borrower_phone, 
            l.id AS loan_number
        FROM repayments r 
        JOIN loans l ON r.loan_id = l.id 
        JOIN borrowers b ON l.borrower_id = b.id 
        ORDER BY r.id DESC 
        LIMIT 5
    ");
    $recentRepayments = $recentRepaymentsStmt->fetchAll();

} catch (PDOException $e) {
    error_log("Dashboard query error: " . $e->getMessage());
    // Safe fallbacks already initialized
}

// Include template components
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<!-- App Wrapper containing Sidebar and Main Area -->
<div class="app-wrapper">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

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

            <!-- Page Header & Quick Actions -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="page-title">Dashboard</h1>
                    <p class="page-subtitle">Overview of your lending activity</p>
                </div>

                <!-- Quick Actions Section -->
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="<?php echo BASE_URL; ?>borrowers/add.php" class="btn btn-primary quick-action-btn">
                        <i class="fa-solid fa-user-plus"></i>
                        <span>Add Borrower</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>loans/add.php" class="btn btn-outline-primary quick-action-btn">
                        <i class="fa-solid fa-plus-circle"></i>
                        <span>Create Loan</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>repayments/add.php" class="btn btn-outline-success quick-action-btn">
                        <i class="fa-solid fa-money-bill-transfer"></i>
                        <span>Record Repayment</span>
                    </a>
                </div>
            </div>

            <!-- Statistic Cards Grid -->
            <div class="row g-3 g-xl-4 mb-4">
                <!-- Stat 1: Total Borrowers & Active Agreements -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Total Borrowers</span>
                            <div class="stat-icon-wrapper stat-icon-emerald">
                                <i class="fa-solid fa-users"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_borrowers']; ?></div>
                        <div class="stat-footer-text">
                            <a href="<?php echo BASE_URL; ?>borrowers/index.php" class="text-decoration-none text-muted">
                                <span class="text-primary fw-semibold"><?php echo $stats['active_loans']; ?></span> active loan<?php echo $stats['active_loans'] === 1 ? '' : 's'; ?> &bull; Directory <i class="fa-solid fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Stat 2: Total Amount Lent -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Total Amount Lent</span>
                            <div class="stat-icon-wrapper stat-icon-teal">
                                <i class="fa-solid fa-hand-holding-dollar"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo CURRENCY_SYMBOL . number_format($stats['total_amount_lent'], 2); ?></div>
                        <div class="stat-footer-text">
                            <a href="<?php echo BASE_URL; ?>loans/index.php" class="text-decoration-none text-muted">
                                <i class="fa-solid fa-arrow-trend-up me-1"></i> View all loans <i class="fa-solid fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Stat 3: Total Amount Repaid -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Total Amount Repaid</span>
                            <div class="stat-icon-wrapper stat-icon-emerald">
                                <i class="fa-solid fa-piggy-bank"></i>
                            </div>
                        </div>
                        <div class="stat-value text-success"><?php echo CURRENCY_SYMBOL . number_format($stats['total_amount_repaid'], 2); ?></div>
                        <div class="stat-footer-text">
                            <a href="<?php echo BASE_URL; ?>repayments/index.php" class="text-decoration-none text-muted">
                                <i class="fa-solid fa-receipt me-1"></i> View collections <i class="fa-solid fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Stat 4: Outstanding Amount -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Outstanding Amount</span>
                            <div class="stat-icon-wrapper stat-icon-amber">
                                <i class="fa-solid fa-file-invoice-dollar"></i>
                            </div>
                        </div>
                        <div class="stat-value text-danger"><?php echo CURRENCY_SYMBOL . number_format($stats['total_outstanding'], 2); ?></div>
                        <div class="stat-footer-text">
                            <a href="<?php echo BASE_URL; ?>loans/index.php?status=active" class="text-decoration-none text-muted">
                                <i class="fa-solid fa-clock me-1"></i> Active receivables <i class="fa-solid fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>


            <!-- Activity Sections: Recent Loans & Recent Repayments -->
            <div class="row g-4">
                <!-- Recent Loans -->
                <div class="col-12 col-lg-6">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <h2 class="content-card-title">
                                <i class="fa-solid fa-clock-rotate-left me-2 text-primary"></i>Recent Loans
                            </h2>
                            <span class="badge bg-light text-secondary border">Latest 5</span>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($recentLoans)): ?>
                                <!-- Clean Empty State -->
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fa-solid fa-file-circle-xmark"></i>
                                    </div>
                                    <h3 class="empty-state-title">No loan records available yet.</h3>
                                    <p class="empty-state-text">
                                        Loans created for borrowers will appear here with principal amounts, interest rates, and status.
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0 small">
                                        <thead class="table-light text-muted">
                                            <tr>
                                                <th class="ps-3">Loan</th>
                                                <th>Borrower</th>
                                                <th>Principal</th>
                                                <th>Date</th>
                                                <th>Status</th>
                                                <th class="text-end pe-3">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentLoans as $loan): ?>
                                                <tr>
                                                    <td class="ps-3 fw-semibold">
                                                        <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$loan['id']; ?>" class="badge bg-light text-primary border text-decoration-none" title="View Loan Agreement">
                                                            #<?php echo (int)$loan['id']; ?>
                                                        </a>
                                                    </td>
                                                    <td>
                                                        <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo (int)$loan['borrower_id']; ?>" class="fw-semibold text-dark text-decoration-none" title="View Borrower Profile">
                                                            <?php echo htmlspecialchars($loan['borrower_name']); ?>
                                                        </a>
                                                    </td>
                                                    <td class="fw-semibold">
                                                        <?php echo CURRENCY_SYMBOL . number_format((float)$loan['principal_amount'], 2); ?>
                                                    </td>
                                                    <td class="text-muted">
                                                        <?php echo date('M d, Y', strtotime($loan['start_date'])); ?>
                                                    </td>
                                                    <td>
                                                        <?php echo get_loan_status_badge($loan['status']); ?>
                                                    </td>
                                                    <td class="text-end pe-3">
                                                        <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$loan['id']; ?>" class="btn btn-outline-secondary btn-sm py-0 px-2" title="View Loan Details" aria-label="View Loan #<?php echo (int)$loan['id']; ?>">
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
                </div>

                <!-- Recent Repayments -->
                <div class="col-12 col-lg-6">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-receipt text-success"></i>
                                <h2 class="content-card-title">Recent Repayments</h2>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-light text-secondary border">Latest 5</span>
                                <a href="<?php echo BASE_URL; ?>repayments/index.php" class="text-decoration-none small">View all</a>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($recentRepayments)): ?>
                                <!-- Clean Empty State -->
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fa-solid fa-receipt"></i>
                                    </div>
                                    <h3 class="empty-state-title">No repayment records available yet.</h3>
                                    <p class="empty-state-text mb-3">
                                        Payments recorded from borrowers will be logged here with date, amount, and payment method.
                                    </p>
                                    <a href="<?php echo BASE_URL; ?>repayments/add.php" class="btn btn-sm btn-outline-success">
                                        <i class="fa-solid fa-plus me-1"></i> Record First Repayment
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0 small">
                                        <thead class="table-light text-muted">
                                            <tr>
                                                <th class="ps-3">Borrower &amp; Loan</th>
                                                <th class="text-end pe-3">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentRepayments as $rep): ?>
                                                <tr>
                                                    <td class="ps-3 py-2 text-start">
                                                        <div class="fw-semibold text-dark">
                                                            <?php if (!empty($rep['borrower_id'])): ?>
                                                                <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo (int)$rep['borrower_id']; ?>" class="text-dark text-decoration-none" title="View Borrower Profile">
                                                                    <?php echo htmlspecialchars($rep['borrower_name']); ?>
                                                                </a>
                                                            <?php else: ?>
                                                                <?php echo htmlspecialchars($rep['borrower_name']); ?>
                                                            <?php endif; ?>
                                                            <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$rep['loan_number']; ?>" class="badge bg-light text-primary border text-decoration-none ms-1" style="font-size: 0.65rem;" title="View Loan #<?php echo (int)$rep['loan_number']; ?>">
                                                                Loan #<?php echo (int)$rep['loan_number']; ?>
                                                            </a>
                                                        </div>
                                                        <?php if (!empty($rep['borrower_phone'])): ?>
                                                            <div class="text-muted" style="font-size: 0.75rem;">
                                                                <?php echo htmlspecialchars($rep['borrower_phone']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end pe-3 py-2">
                                                        <div class="text-success fw-bold">
                                                            <a href="<?php echo BASE_URL; ?>repayments/view.php?id=<?php echo (int)$rep['id']; ?>" class="text-success text-decoration-none" title="View Receipt #<?php echo (int)$rep['id']; ?>" aria-label="View Receipt #<?php echo (int)$rep['id']; ?>">
                                                                +<?php echo CURRENCY_SYMBOL . number_format((float)$rep['amount'], 2); ?>
                                                            </a>
                                                        </div>
                                                        <div class="text-muted" style="font-size: 0.75rem;">
                                                            <?php echo date('M d, Y', strtotime($rep['payment_date'])); ?> &bull; <?php echo htmlspecialchars($rep['payment_method']); ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div> <!-- End .content-container -->


<?php
// Include reusable page footer
require_once __DIR__ . '/includes/footer.php';
?>
