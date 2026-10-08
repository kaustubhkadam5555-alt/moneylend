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

    // 8. Phase 10 Intelligence Metrics
    $totalPayable = (float)$pdo->query("SELECT COALESCE(SUM(total_payable), 0) FROM loans WHERE status != 'cancelled'")->fetchColumn();
    $collectionRate = ($totalPayable > 0) ? min(100.0, round(($stats['total_amount_repaid'] / $totalPayable) * 100, 1)) : 0.0;

    $overdueStmt = $pdo->query("SELECT COUNT(*) AS count, COALESCE(SUM(remaining_balance), 0) AS amount FROM loans WHERE status = 'overdue' OR (due_date < CURDATE() AND remaining_balance > 0 AND status != 'cancelled')");
    $overdueData = $overdueStmt->fetch();
    $overdueCount = (int)($overdueData['count'] ?? 0);
    $overdueAmount = (float)($overdueData['amount'] ?? 0);

    $dueSoonCount = (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status != 'cancelled' AND remaining_balance > 0 AND due_date >= CURDATE() AND due_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)")->fetchColumn();

    // 9. Phase 11 Notification & Schedule Queries
    $dashboardUserId = (int)($_SESSION['user_id'] ?? 1);
    $recentAlerts = function_exists('get_user_notifications') ? get_user_notifications($pdo, $dashboardUserId, [], 4, 0) : [];

    // Upcoming Due Loans (Detailed list for widget)
    $upcomingDueStmt = $pdo->query("
        SELECT 
            l.id, l.borrower_id, l.principal_amount, l.remaining_balance, l.due_date,
            DATEDIFF(l.due_date, CURDATE()) AS days_left,
            b.full_name AS borrower_name, b.phone AS borrower_phone
        FROM loans l
        JOIN borrowers b ON l.borrower_id = b.id
        WHERE l.status NOT IN ('cancelled', 'paid')
          AND l.remaining_balance > 0.001
          AND l.due_date >= CURDATE()
          AND l.due_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
        ORDER BY l.due_date ASC
        LIMIT 4
    ");
    $upcomingDueLoans = $upcomingDueStmt->fetchAll();

    // Overdue Loans (Detailed list for widget)
    $overdueLoansStmt = $pdo->query("
        SELECT 
            l.id, l.borrower_id, l.principal_amount, l.remaining_balance, l.due_date,
            DATEDIFF(CURDATE(), l.due_date) AS days_overdue,
            b.full_name AS borrower_name, b.phone AS borrower_phone
        FROM loans l
        JOIN borrowers b ON l.borrower_id = b.id
        WHERE (l.status = 'overdue' OR l.due_date < CURDATE())
          AND l.status != 'cancelled'
          AND l.remaining_balance > 0.001
        ORDER BY l.due_date ASC
        LIMIT 4
    ");
    $overdueLoans = $overdueLoansStmt->fetchAll();

    // 10. Phase 12 Administration & Security Metrics (for Admin users)
    $securityMetrics = [
        'total_users'     => 1,
        'active_users'    => 1,
        'failed_attempts' => 0,
        'recent_events'   => 0
    ];
    if (function_exists('is_admin') && is_admin()) {
        try {
            $userStatsStmt = $pdo->query("SELECT COUNT(*) AS total, COUNT(CASE WHEN status = 'active' THEN 1 END) AS active FROM users");
            $uStats = $userStatsStmt->fetch();
            $securityMetrics['total_users'] = (int)($uStats['total'] ?? 1);
            $securityMetrics['active_users'] = (int)($uStats['active'] ?? 1);

            $failedStmt = $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE is_successful = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $securityMetrics['failed_attempts'] = (int)$failedStmt->fetchColumn();

            $secEventsStmt = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action IN ('login_failed', 'password_change', 'user_create', 'user_activate', 'user_deactivate', 'user_role_change', 'user_password_reset', 'security_settings_update') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $securityMetrics['recent_events'] = (int)$secEventsStmt->fetchColumn();
        } catch (Throwable $e) {
            error_log("Dashboard security metrics error: " . $e->getMessage());
        }
    }

} catch (PDOException $e) {
    error_log("Dashboard query error: " . $e->getMessage());
    // Safe fallbacks already initialized
    $totalPayable = 0.0;
    $collectionRate = 0.0;
    $overdueCount = 0;
    $overdueAmount = 0.0;
    $dueSoonCount = 0;
    $recentAlerts = [];
    $upcomingDueLoans = [];
    $overdueLoans = [];
    $securityMetrics = ['total_users' => 1, 'active_users' => 1, 'failed_attempts' => 0, 'recent_events' => 0];
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
                    <a href="<?php echo BASE_URL; ?>reports/" class="btn btn-outline-secondary quick-action-btn">
                        <i class="fa-solid fa-chart-line"></i>
                        <span>View Reports</span>
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
                            <div class="stat-icon-wrapper stat-icon-blue">
                                <i class="fa-solid fa-users"></i>
                            </div>
                        </div>
                        <div class="stat-value tabular-nums"><?php echo $stats['total_borrowers']; ?></div>
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
                        <div class="stat-value tabular-nums"><?php echo CURRENCY_SYMBOL . number_format($stats['total_amount_lent'], 2); ?></div>
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
                        <div class="stat-value tabular-nums text-success"><?php echo CURRENCY_SYMBOL . number_format($stats['total_amount_repaid'], 2); ?></div>
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
                        <div class="stat-value tabular-nums text-danger"><?php echo CURRENCY_SYMBOL . number_format($stats['total_outstanding'], 2); ?></div>
                        <div class="stat-footer-text">
                            <a href="<?php echo BASE_URL; ?>loans/index.php?status=active" class="text-decoration-none text-muted">
                                <i class="fa-solid fa-clock me-1"></i> Active receivables <i class="fa-solid fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Phase 10 Dashboard Intelligence Widgets -->
            <div class="row g-3 g-xl-4 mb-4">
                <!-- Intelligence Card 1: Collection Rate Progress -->
                <div class="col-12 col-md-4">
                    <div class="content-card h-100 p-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted small text-uppercase fw-semibold">Portfolio Recovery Rate</span>
                            <span class="badge bg-<?php echo ($collectionRate >= 80) ? 'success' : (($collectionRate >= 40) ? 'primary' : 'warning'); ?>-subtle text-<?php echo ($collectionRate >= 80) ? 'success' : (($collectionRate >= 40) ? 'primary' : 'warning'); ?> border">
                                <?php echo $collectionRate; ?>%
                            </span>
                        </div>
                        <div class="progress mb-2" style="height: 8px;">
                            <div class="progress-bar <?php echo ($collectionRate >= 80) ? 'bg-success' : 'bg-primary'; ?>" 
                                 style="width: <?php echo $collectionRate; ?>%;" 
                                 role="progressbar" 
                                 aria-valuenow="<?php echo $collectionRate; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                            </div>
                        </div>
                        <div class="d-flex justify-content-between small text-muted">
                            <span>Repaid: <?php echo CURRENCY_SYMBOL . number_format($stats['total_amount_repaid'], 2); ?></span>
                            <span>Payable: <?php echo CURRENCY_SYMBOL . number_format($totalPayable, 2); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Intelligence Card 2: Risk & Overdue Monitor -->
                <div class="col-12 col-md-4">
                    <div class="content-card h-100 p-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted small text-uppercase fw-semibold">Overdue Receivables</span>
                            <span class="badge <?php echo ($overdueCount > 0) ? 'bg-danger-subtle text-danger border border-danger-subtle' : 'bg-success-subtle text-success border'; ?>">
                                <?php echo $overdueCount; ?> Loan<?php echo $overdueCount === 1 ? '' : 's'; ?>
                            </span>
                        </div>
                        <div class="h5 fw-bold <?php echo ($overdueCount > 0) ? 'text-danger' : 'text-success'; ?> mb-1">
                            <?php echo CURRENCY_SYMBOL . number_format($overdueAmount, 2); ?>
                        </div>
                        <div class="small text-muted">
                            <?php if ($overdueCount > 0): ?>
                                <a href="<?php echo BASE_URL; ?>loans/index.php?status=overdue" class="text-danger fw-semibold text-decoration-none">
                                    <i class="fa-solid fa-triangle-exclamation me-1"></i> Review overdue agreements &rarr;
                                </a>
                            <?php else: ?>
                                <span class="text-success"><i class="fa-solid fa-circle-check me-1"></i> No overdue loans currently</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Intelligence Card 3: Due Soon & Audit Log Shortcut -->
                <div class="col-12 col-md-4">
                    <div class="content-card h-100 p-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted small text-uppercase fw-semibold">Upcoming Maturities (14d)</span>
                            <span class="badge bg-light text-secondary border">
                                <?php echo $dueSoonCount; ?> Due Soon
                            </span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="small text-muted">
                                <?php echo $dueSoonCount; ?> loan contract<?php echo $dueSoonCount === 1 ? '' : 's'; ?> due within 2 weeks.
                            </div>
                            <a href="<?php echo BASE_URL; ?>activity/" class="btn btn-outline-secondary btn-sm ms-2 text-nowrap" title="View Audit Trail">
                                <i class="fa-solid fa-list-check me-1"></i> Audit
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Phase 11 Notification & Maturity Monitoring Grid -->
            <?php if (!empty($upcomingDueLoans) || !empty($overdueLoans) || !empty($recentAlerts)): ?>
            <div class="row g-3 g-xl-4 mb-4">
                <!-- Widget 1: Upcoming Due / Overdue Watchlist -->
                <div class="col-12 col-lg-6">
                    <div class="content-card h-100">
                        <div class="content-card-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-clock-rotate-left text-primary"></i>
                                <h2 class="content-card-title mb-0">Upcoming Due Dates</h2>
                            </div>
                            <?php if (!empty($upcomingDueLoans)): ?>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?php echo count($upcomingDueLoans); ?> Soon</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($upcomingDueLoans)): ?>
                                <div class="text-center py-4 text-muted small">
                                    <i class="fa-solid fa-circle-check text-success fs-4 mb-2 d-block"></i>
                                    No loans approaching due date within 14 days.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0 small">
                                        <thead class="table-light text-muted">
                                            <tr>
                                                <th class="ps-3">Borrower &amp; Loan</th>
                                                <th>Due Date</th>
                                                <th>Outstanding</th>
                                                <th class="text-end pe-3">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($upcomingDueLoans as $udl): ?>
                                                <tr>
                                                    <td class="ps-3">
                                                        <div class="fw-semibold text-dark">
                                                            <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo (int)$udl['borrower_id']; ?>" class="text-dark text-decoration-none">
                                                                <?php echo htmlspecialchars($udl['borrower_name']); ?>
                                                            </a>
                                                        </div>
                                                        <span class="text-muted" style="font-size: 0.72rem;">Loan #LN-<?php echo (int)$udl['id']; ?></span>
                                                    </td>
                                                    <td>
                                                        <div><?php echo date('M d, Y', strtotime($udl['due_date'])); ?></div>
                                                        <span class="badge <?php echo ((int)$udl['days_left'] <= 3) ? 'bg-warning-subtle text-warning-emphasis' : 'bg-light text-secondary border'; ?>" style="font-size: 0.68rem;">
                                                            <?php echo ((int)$udl['days_left'] === 0) ? 'Due Today' : 'In ' . (int)$udl['days_left'] . ' day(s)'; ?>
                                                        </span>
                                                    </td>
                                                    <td class="fw-semibold text-danger tabular-nums">
                                                        <?php echo CURRENCY_SYMBOL . number_format((float)$udl['remaining_balance'], 2); ?>
                                                    </td>
                                                    <td class="text-end pe-3">
                                                        <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$udl['id']; ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" title="View Loan Agreement">
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

                <!-- Widget 2: Recent Alerts -->
                <div class="col-12 col-lg-6">
                    <div class="content-card h-100">
                        <div class="content-card-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-bell text-info"></i>
                                <h2 class="content-card-title mb-0">Recent Alerts</h2>
                            </div>
                            <a href="<?php echo BASE_URL; ?>notifications/" class="small text-decoration-none">View All &rarr;</a>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($recentAlerts)): ?>
                                <div class="text-center py-4 text-muted small">
                                    <i class="fa-regular fa-bell-slash text-secondary fs-4 mb-2 d-block"></i>
                                    No alerts logged yet. System is clear.
                                </div>
                            <?php else: ?>
                                <ul class="list-group list-group-flush mb-0 small">
                                    <?php foreach ($recentAlerts as $ra): 
                                        $bInfo = get_notification_badge_info($ra['type'], $ra['severity']);
                                    ?>
                                        <li class="list-group-item px-3 py-2 d-flex align-items-start gap-2 <?php echo empty($ra['is_read']) ? 'bg-light bg-opacity-75' : ''; ?>">
                                            <span class="badge <?php echo $bInfo['badge']; ?> p-1 px-2 mt-1">
                                                <i class="fa-solid <?php echo $bInfo['icon']; ?>"></i>
                                            </span>
                                            <div class="flex-grow-1 overflow-hidden">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <a href="<?php echo BASE_URL; ?>notifications/?id=<?php echo (int)$ra['id']; ?>" class="text-decoration-none fw-semibold text-truncate <?php echo empty($ra['is_read']) ? 'text-primary' : 'text-dark'; ?>">
                                                        <?php echo htmlspecialchars($ra['title']); ?>
                                                    </a>
                                                    <span class="text-muted ms-2" style="font-size: 0.68rem;">
                                                        <?php echo date('M d, H:i', strtotime($ra['created_at'])); ?>
                                                    </span>
                                                </div>
                                                <div class="text-muted text-truncate" style="font-size: 0.75rem;">
                                                    <?php echo htmlspecialchars($ra['message']); ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

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

            <!-- Phase 12: Security & Administration Status (Admins Only) -->
            <?php if (function_exists('is_admin') && is_admin()): ?>
                <div class="row g-4 mt-1">
                    <div class="col-12">
                        <div class="content-card border-0 shadow-sm">
                            <div class="content-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="brand-icon-box" style="width: 32px; height: 32px; font-size: 0.85rem;">
                                        <i class="fa-solid fa-shield-halved"></i>
                                    </span>
                                    <div>
                                        <h3 class="content-card-title mb-0">Security &amp; Administrative Posture</h3>
                                        <p class="content-card-subtitle mb-0">System integrity indicators, authentication safeguards, and operational access</p>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <a href="<?php echo BASE_URL; ?>settings/users.php" class="btn btn-sm btn-outline-primary d-flex align-items-center gap-1">
                                        <i class="fa-solid fa-user-shield"></i>
                                        <span>User Management</span>
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>settings/index.php?tab=security" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1">
                                        <i class="fa-solid fa-gear"></i>
                                        <span>Security Config</span>
                                    </a>
                                </div>
                            </div>

                            <div class="p-3 p-md-4">
                                <div class="row g-3">
                                    <div class="col-6 col-md-4 col-lg-2">
                                        <div class="p-2 border rounded bg-light text-center">
                                            <div class="text-muted small" style="font-size: 0.72rem;">Authentication</div>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle mt-1">
                                                <i class="fa-solid fa-check me-1"></i> Protected
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4 col-lg-2">
                                        <div class="p-2 border rounded bg-light text-center">
                                            <div class="text-muted small" style="font-size: 0.72rem;">CSRF Defense</div>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle mt-1">
                                                <i class="fa-solid fa-check me-1"></i> Enabled
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4 col-lg-2">
                                        <div class="p-2 border rounded bg-light text-center">
                                            <div class="text-muted small" style="font-size: 0.72rem;">Session Security</div>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle mt-1">
                                                <i class="fa-solid fa-check me-1"></i> Enabled
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4 col-lg-2">
                                        <div class="p-2 border rounded bg-light text-center">
                                            <div class="text-muted small" style="font-size: 0.72rem;">Prepared Queries</div>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle mt-1">
                                                <i class="fa-solid fa-check me-1"></i> Enabled
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4 col-lg-2">
                                        <div class="p-2 border rounded bg-light text-center">
                                            <div class="text-muted small" style="font-size: 0.72rem;">Audit Logging</div>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle mt-1">
                                                <i class="fa-solid fa-check me-1"></i> Enabled
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4 col-lg-2">
                                        <div class="p-2 border rounded bg-light text-center">
                                            <div class="text-muted small" style="font-size: 0.72rem;">Notifications</div>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle mt-1">
                                                <i class="fa-solid fa-check me-1"></i> Enabled
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-3 mt-2 pt-2 border-top">
                                    <div class="col-12 col-md-4 d-flex align-items-center gap-2">
                                        <i class="fa-solid fa-users text-primary"></i>
                                        <div class="small">
                                            <span class="text-muted">Accounts:</span>
                                            <strong class="text-dark tabular-nums"><?php echo $securityMetrics['active_users']; ?> Active</strong>
                                            <span class="text-muted">of <?php echo $securityMetrics['total_users']; ?></span>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-4 d-flex align-items-center gap-2">
                                        <i class="fa-solid fa-user-lock text-warning"></i>
                                        <div class="small">
                                            <span class="text-muted">Failed Logins (24h):</span>
                                            <strong class="text-dark tabular-nums"><?php echo $securityMetrics['failed_attempts']; ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-4 d-flex align-items-center gap-2">
                                        <i class="fa-solid fa-clock-rotate-left text-info"></i>
                                        <div class="small">
                                            <span class="text-muted">Security Events (7d):</span>
                                            <strong class="text-dark tabular-nums"><?php echo $securityMetrics['recent_events']; ?></strong>
                                            <a href="<?php echo BASE_URL; ?>activity/" class="ms-1 text-decoration-none">View Log &rarr;</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div> <!-- End .content-container -->


<?php
// Include reusable page footer
require_once __DIR__ . '/includes/footer.php';
?>
