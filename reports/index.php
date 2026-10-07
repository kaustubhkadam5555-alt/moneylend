<?php
/**
 * MoneyLend - Reports & Analytics Module
 * 
 * Provides comprehensive portfolio analytics, repayment collection trends,
 * loan status distribution, payment method breakdowns, top borrowers,
 * overdue loan alerts, borrower-specific ledger audits, printable vouchers,
 * and real-time CSV exports.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helper.php';

require_login();

$pageTitle = "Reports & Analytics";
$activePage = "reports";

$pdo = getDBConnection();

// -------------------------------------------------------------
// 1. FILTER PARAMETERS PROCESSING
// -------------------------------------------------------------
$dateRange      = $_GET['date_range'] ?? 'all';
$fromDate       = $_GET['from_date'] ?? '';
$toDate         = $_GET['to_date'] ?? '';
$statusFilter   = $_GET['status'] ?? '';
$methodFilter   = $_GET['method'] ?? '';
$borrowerFilter = isset($_GET['borrower_id']) ? (int)$_GET['borrower_id'] : 0;
$searchTerm     = trim($_GET['search'] ?? '');

$today = date('Y-m-d');
$startDate = null;
$endDate = null;
$rangeLabel = "All Time";

switch ($dateRange) {
    case 'today':
        $startDate = $today;
        $endDate = $today;
        $rangeLabel = "Today (" . date('M d, Y') . ")";
        break;
    case 'yesterday':
        $startDate = date('Y-m-d', strtotime('-1 day'));
        $endDate = $startDate;
        $rangeLabel = "Yesterday (" . date('M d, Y', strtotime('-1 day')) . ")";
        break;
    case 'this_week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate = date('Y-m-d', strtotime('sunday this week'));
        $rangeLabel = "This Week (" . date('M d', strtotime($startDate)) . " – " . date('M d, Y', strtotime($endDate)) . ")";
        break;
    case 'this_month':
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
        $rangeLabel = "This Month (" . date('F Y') . ")";
        break;
    case 'last_month':
        $startDate = date('Y-m-01', strtotime('first day of last month'));
        $endDate = date('Y-m-t', strtotime('last day of last month'));
        $rangeLabel = "Last Month (" . date('F Y', strtotime('first day of last month')) . ")";
        break;
    case 'this_year':
        $startDate = date('Y-01-01');
        $endDate = date('Y-12-31');
        $rangeLabel = "This Year (" . date('Y') . ")";
        break;
    case 'custom':
        if (!empty($fromDate) && strtotime($fromDate)) {
            $startDate = $fromDate;
        }
        if (!empty($toDate) && strtotime($toDate)) {
            $endDate = $toDate;
        }
        $rangeLabel = "Custom Range (" . ($startDate ? date('M d, Y', strtotime($startDate)) : 'Start') . " – " . ($endDate ? date('M d, Y', strtotime($endDate)) : 'Today') . ")";
        break;
    case 'all':
    default:
        $startDate = null;
        $endDate = null;
        $dateRange = 'all';
        $rangeLabel = "All Historical Data";
        break;
}

// -------------------------------------------------------------
// 2. FETCH ALL BORROWERS FOR SELECT DROPDOWN
// -------------------------------------------------------------
$allBorrowers = [];
try {
    $allBorrowers = $pdo->query("SELECT id, full_name, phone FROM borrowers ORDER BY full_name ASC")->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching borrowers list for reports: " . $e->getMessage());
}

// -------------------------------------------------------------
// 3. SECTION 2: FINANCIAL SUMMARY METRICS
// -------------------------------------------------------------
$financialSummary = [
    'total_principal'   => 0.0,
    'total_repayments'  => 0.0,
    'total_outstanding' => 0.0,
    'total_interest'    => 0.0
];

try {
    // 3a. Total Principal Lent & Total Interest
    $loanMetricSql = "SELECT COALESCE(SUM(principal_amount), 0) AS total_principal, COALESCE(SUM(total_interest), 0) AS total_interest FROM loans WHERE status != 'cancelled'";
    $loanMetricParams = [];
    if ($startDate !== null) {
        $loanMetricSql .= " AND start_date >= :start_date";
        $loanMetricParams[':start_date'] = $startDate;
    }
    if ($endDate !== null) {
        $loanMetricSql .= " AND start_date <= :end_date";
        $loanMetricParams[':end_date'] = $endDate;
    }
    if ($borrowerFilter > 0) {
        $loanMetricSql .= " AND borrower_id = :b_id";
        $loanMetricParams[':b_id'] = $borrowerFilter;
    }
    $loanMetricStmt = $pdo->prepare($loanMetricSql);
    $loanMetricStmt->execute($loanMetricParams);
    $lData = $loanMetricStmt->fetch();
    $financialSummary['total_principal'] = (float)$lData['total_principal'];
    $financialSummary['total_interest']  = (float)$lData['total_interest'];

    // 3b. Total Repayments Collected
    $repMetricSql = "
        SELECT COALESCE(SUM(r.amount), 0) AS total_repaid 
        FROM repayments r
        JOIN loans l ON r.loan_id = l.id
        WHERE 1=1
    ";
    $repMetricParams = [];
    if ($startDate !== null) {
        $repMetricSql .= " AND r.payment_date >= :start_date";
        $repMetricParams[':start_date'] = $startDate;
    }
    if ($endDate !== null) {
        $repMetricSql .= " AND r.payment_date <= :end_date";
        $repMetricParams[':end_date'] = $endDate;
    }
    if (!empty($methodFilter)) {
        $repMetricSql .= " AND r.payment_method = :method";
        $repMetricParams[':method'] = $methodFilter;
    }
    if ($borrowerFilter > 0) {
        $repMetricSql .= " AND l.borrower_id = :b_id";
        $repMetricParams[':b_id'] = $borrowerFilter;
    }
    $repMetricStmt = $pdo->prepare($repMetricSql);
    $repMetricStmt->execute($repMetricParams);
    $financialSummary['total_repayments'] = (float)$repMetricStmt->fetchColumn();

    // 3c. Outstanding Balance
    $outMetricSql = "SELECT COALESCE(SUM(remaining_balance), 0) FROM loans WHERE status != 'cancelled'";
    $outMetricParams = [];
    if ($borrowerFilter > 0) {
        $outMetricSql .= " AND borrower_id = :b_id";
        $outMetricParams[':b_id'] = $borrowerFilter;
    }
    if (!empty($statusFilter)) {
        $outMetricSql .= " AND status = :st";
        $outMetricParams[':st'] = $statusFilter;
    }
    $outMetricStmt = $pdo->prepare($outMetricSql);
    $outMetricStmt->execute($outMetricParams);
    $financialSummary['total_outstanding'] = (float)$outMetricStmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Financial summary calculation error: " . $e->getMessage());
}

// -------------------------------------------------------------
// 4. SECTION 3: PORTFOLIO OVERVIEW COUNTS
// -------------------------------------------------------------
$portfolioCounts = [
    'active'         => 0,
    'partially_paid' => 0,
    'paid'           => 0,
    'overdue'        => 0
];

try {
    $pQuery = "
        SELECT 
            status,
            due_date,
            remaining_balance
        FROM loans 
        WHERE status != 'cancelled'
    ";
    $pParams = [];
    if ($borrowerFilter > 0) {
        $pQuery .= " AND borrower_id = :b_id";
        $pParams[':b_id'] = $borrowerFilter;
    }
    $pStmt = $pdo->prepare($pQuery);
    $pStmt->execute($pParams);
    $allLoanRows = $pStmt->fetchAll();

    foreach ($allLoanRows as $row) {
        $rem = (float)$row['remaining_balance'];
        if ($rem <= 0.001) {
            $portfolioCounts['paid']++;
        } elseif ($row['due_date'] < $today && $rem > 0) {
            $portfolioCounts['overdue']++;
        } elseif ($row['status'] === 'partially_paid') {
            $portfolioCounts['partially_paid']++;
        } else {
            $portfolioCounts['active']++;
        }
    }
} catch (PDOException $e) {
    error_log("Portfolio overview calculation error: " . $e->getMessage());
}

// -------------------------------------------------------------
// 5. SECTION 4: COLLECTION TREND CHART DATA (DAILY)
// -------------------------------------------------------------
$trendLabels = [];
$trendData = [];

try {
    $trendSql = "
        SELECT 
            r.payment_date,
            SUM(r.amount) AS daily_total
        FROM repayments r
        JOIN loans l ON r.loan_id = l.id
        WHERE 1=1
    ";
    $trendParams = [];
    if ($startDate !== null) {
        $trendSql .= " AND r.payment_date >= :start_date";
        $trendParams[':start_date'] = $startDate;
    }
    if ($endDate !== null) {
        $trendSql .= " AND r.payment_date <= :end_date";
        $trendParams[':end_date'] = $endDate;
    }
    if (!empty($methodFilter)) {
        $trendSql .= " AND r.payment_method = :method";
        $trendParams[':method'] = $methodFilter;
    }
    if ($borrowerFilter > 0) {
        $trendSql .= " AND l.borrower_id = :b_id";
        $trendParams[':b_id'] = $borrowerFilter;
    }
    $trendSql .= " GROUP BY r.payment_date ORDER BY r.payment_date ASC LIMIT 30";
    $trendStmt = $pdo->prepare($trendSql);
    $trendStmt->execute($trendParams);
    $trendRows = $trendStmt->fetchAll();

    foreach ($trendRows as $tr) {
        $trendLabels[] = date('M d', strtotime($tr['payment_date']));
        $trendData[]   = (float)$tr['daily_total'];
    }
} catch (PDOException $e) {
    error_log("Trend chart query error: " . $e->getMessage());
}

// -------------------------------------------------------------
// 6. SECTION 6: PAYMENT METHOD BREAKDOWN
// -------------------------------------------------------------
$methodBreakdown = [];
$totalMethodAmount = 0.0;

try {
    $mSql = "
        SELECT 
            r.payment_method,
            COUNT(r.id) AS tx_count,
            COALESCE(SUM(r.amount), 0) AS total_amount
        FROM repayments r
        JOIN loans l ON r.loan_id = l.id
        WHERE 1=1
    ";
    $mParams = [];
    if ($startDate !== null) {
        $mSql .= " AND r.payment_date >= :start_date";
        $mParams[':start_date'] = $startDate;
    }
    if ($endDate !== null) {
        $mSql .= " AND r.payment_date <= :end_date";
        $mParams[':end_date'] = $endDate;
    }
    if ($borrowerFilter > 0) {
        $mSql .= " AND l.borrower_id = :b_id";
        $mParams[':b_id'] = $borrowerFilter;
    }
    $mSql .= " GROUP BY r.payment_method ORDER BY total_amount DESC";
    $mStmt = $pdo->prepare($mSql);
    $mStmt->execute($mParams);
    $methodBreakdown = $mStmt->fetchAll();

    foreach ($methodBreakdown as $mb) {
        $totalMethodAmount += (float)$mb['total_amount'];
    }
} catch (PDOException $e) {
    error_log("Payment method breakdown error: " . $e->getMessage());
}

// -------------------------------------------------------------
// 7. SECTION 7: TOP BORROWERS RANKING
// -------------------------------------------------------------
$topBorrowers = [];
try {
    $topStmt = $pdo->query("
        SELECT 
            b.id,
            b.full_name,
            b.phone,
            COUNT(l.id) AS total_loans,
            COALESCE(SUM(l.principal_amount), 0) AS total_borrowed,
            COALESCE(SUM(l.amount_repaid), 0) AS total_repaid,
            COALESCE(SUM(l.remaining_balance), 0) AS total_outstanding
        FROM borrowers b
        JOIN loans l ON b.id = l.borrower_id
        WHERE l.status != 'cancelled'
        GROUP BY b.id, b.full_name, b.phone
        ORDER BY total_borrowed DESC
        LIMIT 5
    ");
    $topBorrowers = $topStmt->fetchAll();
} catch (PDOException $e) {
    error_log("Top borrowers query error: " . $e->getMessage());
}

// -------------------------------------------------------------
// 8. SECTION 8: DETAILED LOAN PORTFOLIO REPORT TABLE
// -------------------------------------------------------------
$loanReports = [];
try {
    $lRepSql = "
        SELECT 
            l.*,
            b.full_name AS borrower_name,
            b.phone AS borrower_phone
        FROM loans l
        JOIN borrowers b ON l.borrower_id = b.id
        WHERE l.status != 'cancelled'
    ";
    $lRepParams = [];
    if ($startDate !== null) {
        $lRepSql .= " AND l.start_date >= :start_date";
        $lRepParams[':start_date'] = $startDate;
    }
    if ($endDate !== null) {
        $lRepSql .= " AND l.start_date <= :end_date";
        $lRepParams[':end_date'] = $endDate;
    }
    if (!empty($statusFilter)) {
        $lRepSql .= " AND l.status = :status";
        $lRepParams[':status'] = $statusFilter;
    }
    if ($borrowerFilter > 0) {
        $lRepSql .= " AND l.borrower_id = :b_id";
        $lRepParams[':b_id'] = $borrowerFilter;
    }
    if ($searchTerm !== '') {
        $lRepSql .= " AND (b.full_name LIKE :s_name OR b.phone LIKE :s_phone OR l.id = :s_id)";
        $lRepParams[':s_name']  = "%{$searchTerm}%";
        $lRepParams[':s_phone'] = "%{$searchTerm}%";
        $lRepParams[':s_id']    = is_numeric($searchTerm) ? (int)$searchTerm : 0;
    }
    $lRepSql .= " ORDER BY l.id DESC LIMIT 50";
    $lRepStmt = $pdo->prepare($lRepSql);
    $lRepStmt->execute($lRepParams);
    $loanReports = $lRepStmt->fetchAll();
} catch (PDOException $e) {
    error_log("Loan reports table error: " . $e->getMessage());
}

// -------------------------------------------------------------
// 9. SECTION 9: DETAILED REPAYMENT COLLECTION REPORT TABLE
// -------------------------------------------------------------
$repaymentReports = [];
try {
    $rRepSql = "
        SELECT 
            r.*,
            b.id AS borrower_id,
            b.full_name AS borrower_name,
            b.phone AS borrower_phone,
            COALESCE(u.name, 'Admin User') AS recorded_by_name
        FROM repayments r
        JOIN loans l ON r.loan_id = l.id
        JOIN borrowers b ON l.borrower_id = b.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE 1=1
    ";
    $rRepParams = [];
    if ($startDate !== null) {
        $rRepSql .= " AND r.payment_date >= :start_date";
        $rRepParams[':start_date'] = $startDate;
    }
    if ($endDate !== null) {
        $rRepSql .= " AND r.payment_date <= :end_date";
        $rRepParams[':end_date'] = $endDate;
    }
    if (!empty($methodFilter)) {
        $rRepSql .= " AND r.payment_method = :method";
        $rRepParams[':method'] = $methodFilter;
    }
    if ($borrowerFilter > 0) {
        $rRepSql .= " AND l.borrower_id = :b_id";
        $rRepParams[':b_id'] = $borrowerFilter;
    }
    if ($searchTerm !== '') {
        $rRepSql .= " AND (b.full_name LIKE :s_name OR b.phone LIKE :s_phone OR r.id = :s_id OR r.loan_id = :s_lid)";
        $rRepParams[':s_name']  = "%{$searchTerm}%";
        $rRepParams[':s_phone'] = "%{$searchTerm}%";
        $rRepParams[':s_id']    = is_numeric($searchTerm) ? (int)$searchTerm : 0;
        $rRepParams[':s_lid']   = is_numeric($searchTerm) ? (int)$searchTerm : 0;
    }
    $rRepSql .= " ORDER BY r.payment_date DESC, r.id DESC LIMIT 50";
    $rRepStmt = $pdo->prepare($rRepSql);
    $rRepStmt->execute($rRepParams);
    $repaymentReports = $rRepStmt->fetchAll();
} catch (PDOException $e) {
    error_log("Repayment reports table error: " . $e->getMessage());
}

// -------------------------------------------------------------
// 10. SECTION 10: OVERDUE LOANS
// -------------------------------------------------------------
$overdueLoans = [];
try {
    $overdueStmt = $pdo->query("
        SELECT 
            l.*,
            b.id AS borrower_id,
            b.full_name AS borrower_name,
            b.phone AS borrower_phone,
            DATEDIFF(CURDATE(), l.due_date) AS days_overdue
        FROM loans l
        JOIN borrowers b ON l.borrower_id = b.id
        WHERE l.remaining_balance > 0.001
          AND l.due_date < CURDATE()
          AND l.status != 'cancelled'
        ORDER BY days_overdue DESC
    ");
    $overdueLoans = $overdueStmt->fetchAll();
} catch (PDOException $e) {
    error_log("Overdue loans query error: " . $e->getMessage());
}

// -------------------------------------------------------------
// 11. SECTION 11: BORROWER-SPECIFIC DEEP DIVE REPORT
// -------------------------------------------------------------
$selectedBorrowerData = null;
$borrowerLoansList = [];
$borrowerRepaymentsList = [];

if ($borrowerFilter > 0) {
    try {
        $bProfileStmt = $pdo->prepare("SELECT * FROM borrowers WHERE id = :id");
        $bProfileStmt->execute([':id' => $borrowerFilter]);
        $bProfile = $bProfileStmt->fetch();

        if ($bProfile) {
            // Aggregated financial summary for this borrower
            $bSummaryStmt = $pdo->prepare("
                SELECT 
                    COUNT(id) AS total_loans,
                    COALESCE(SUM(principal_amount), 0) AS total_borrowed,
                    COALESCE(SUM(total_interest), 0) AS total_interest,
                    COALESCE(SUM(total_payable), 0) AS total_payable,
                    COALESCE(SUM(amount_repaid), 0) AS total_repaid,
                    COALESCE(SUM(remaining_balance), 0) AS total_outstanding,
                    COUNT(CASE WHEN status = 'active' OR status = 'partially_paid' OR (due_date < CURDATE() AND remaining_balance > 0) THEN 1 END) AS active_loans,
                    COUNT(CASE WHEN remaining_balance <= 0.001 THEN 1 END) AS completed_loans
                FROM loans 
                WHERE borrower_id = :id AND status != 'cancelled'
            ");
            $bSummaryStmt->execute([':id' => $borrowerFilter]);
            $bSummary = $bSummaryStmt->fetch();

            $selectedBorrowerData = array_merge($bProfile, $bSummary);

            // Fetch loan history
            $blStmt = $pdo->prepare("SELECT * FROM loans WHERE borrower_id = :id AND status != 'cancelled' ORDER BY id DESC");
            $blStmt->execute([':id' => $borrowerFilter]);
            $borrowerLoansList = $blStmt->fetchAll();

            // Fetch repayment history
            $brStmt = $pdo->prepare("
                SELECT r.* 
                FROM repayments r
                JOIN loans l ON r.loan_id = l.id
                WHERE l.borrower_id = :id
                ORDER BY r.payment_date DESC, r.id DESC
            ");
            $brStmt->execute([':id' => $borrowerFilter]);
            $borrowerRepaymentsList = $brStmt->fetchAll();
        }
    } catch (PDOException $e) {
        error_log("Borrower-specific report error: " . $e->getMessage());
    }
}

// Build query string for export URLs preserving active filters
$exportParams = http_build_query([
    'date_range'  => $dateRange,
    'from_date'   => $fromDate,
    'to_date'     => $toDate,
    'status'      => $statusFilter,
    'method'      => $methodFilter,
    'borrower_id' => $borrowerFilter
]);

// Include Header & Top Navbar
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<!-- App Wrapper containing Sidebar and Main Area -->
<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="content-container">

            <!-- Printable Official Header Banner (Visible only when printed) -->
            <div class="d-none d-print-block mb-4 pb-3 border-bottom">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="h3 fw-bold text-dark mb-0"><?php echo APP_NAME; ?></h2>
                        <div class="text-secondary small">Lending &amp; Financial Activity Report</div>
                    </div>
                    <div class="text-end small text-muted">
                        <div>Generated: <strong class="text-dark"><?php echo date('M d, Y h:i A'); ?></strong></div>
                        <div>Reporting Period: <strong class="text-dark"><?php echo htmlspecialchars($rangeLabel); ?></strong></div>
                    </div>
                </div>
            </div>

            <!-- Breadcrumb Navigation -->
            <nav aria-label="breadcrumb" class="mb-3 d-print-none">
                <ol class="breadcrumb small">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard.php" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Reports &amp; Analytics</li>
                </ol>
            </nav>

            <!-- Page Title & Top Actions -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4 d-print-none">
                <div>
                    <h1 class="page-title mb-0">Reports &amp; Analytics</h1>
                    <p class="page-subtitle">Analyze lending performance and portfolio health</p>
                </div>

                <!-- Export & Print Actions -->
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="button" onclick="window.print()" class="btn btn-outline-dark btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-print"></i>
                        <span>Print Report</span>
                    </button>

                    <div class="dropdown">
                        <button class="btn btn-primary btn-sm dropdown-toggle d-flex align-items-center gap-1" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa-solid fa-file-csv"></i>
                            <span>Export CSV</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 small">
                            <li>
                                <a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>reports/export.php?type=loans&<?php echo $exportParams; ?>">
                                    <i class="fa-solid fa-file-invoice-dollar me-2 text-primary"></i>Export Loans Portfolio CSV
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>reports/export.php?type=repayments&<?php echo $exportParams; ?>">
                                    <i class="fa-solid fa-receipt me-2 text-success"></i>Export Repayments Collection CSV
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- SECTION 1: REPORT FILTERS CARD -->
            <div class="content-card filter-card mb-4 d-print-none">
                <div class="p-3 p-md-4">
                    <form method="GET" action="<?php echo BASE_URL; ?>reports/index.php" id="reportFilterForm">
                        <div class="row g-3 align-items-end">
                            <!-- 1. Date Range Dropdown -->
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label for="date_range" class="form-label small fw-semibold text-secondary mb-1">
                                    <i class="fa-regular fa-calendar me-1"></i>Date Range
                                </label>
                                <select name="date_range" id="date_range" class="form-select form-select-sm">
                                    <option value="all" <?php echo ($dateRange === 'all') ? 'selected' : ''; ?>>All Historical Data</option>
                                    <option value="today" <?php echo ($dateRange === 'today') ? 'selected' : ''; ?>>Today</option>
                                    <option value="yesterday" <?php echo ($dateRange === 'yesterday') ? 'selected' : ''; ?>>Yesterday</option>
                                    <option value="this_week" <?php echo ($dateRange === 'this_week') ? 'selected' : ''; ?>>This Week</option>
                                    <option value="this_month" <?php echo ($dateRange === 'this_month') ? 'selected' : ''; ?>>This Month</option>
                                    <option value="last_month" <?php echo ($dateRange === 'last_month') ? 'selected' : ''; ?>>Last Month</option>
                                    <option value="this_year" <?php echo ($dateRange === 'this_year') ? 'selected' : ''; ?>>This Year</option>
                                    <option value="custom" <?php echo ($dateRange === 'custom') ? 'selected' : ''; ?>>Custom Range...</option>
                                </select>
                            </div>

                            <!-- Custom Date Range: From & To -->
                            <div class="col-6 col-sm-3 col-lg-2" id="customFromCol" style="display: <?php echo ($dateRange === 'custom') ? 'block' : 'none'; ?>;">
                                <label for="from_date" class="form-label small fw-semibold text-secondary mb-1">From Date</label>
                                <input type="date" name="from_date" id="from_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($fromDate); ?>">
                            </div>

                            <div class="col-6 col-sm-3 col-lg-2" id="customToCol" style="display: <?php echo ($dateRange === 'custom') ? 'block' : 'none'; ?>;">
                                <label for="to_date" class="form-label small fw-semibold text-secondary mb-1">To Date</label>
                                <input type="date" name="to_date" id="to_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($toDate); ?>">
                            </div>

                            <!-- 2. Loan Status Filter -->
                            <div class="col-12 col-sm-6 col-lg-2">
                                <label for="status" class="form-label small fw-semibold text-secondary mb-1">
                                    <i class="fa-solid fa-signal me-1"></i>Loan Status
                                </label>
                                <select name="status" id="status" class="form-select form-select-sm">
                                    <option value="">All Statuses</option>
                                    <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="partially_paid" <?php echo ($statusFilter === 'partially_paid') ? 'selected' : ''; ?>>Partially Paid</option>
                                    <option value="paid" <?php echo ($statusFilter === 'paid') ? 'selected' : ''; ?>>Fully Paid</option>
                                    <option value="overdue" <?php echo ($statusFilter === 'overdue') ? 'selected' : ''; ?>>Overdue</option>
                                </select>
                            </div>

                            <!-- 3. Payment Method Filter -->
                            <div class="col-12 col-sm-6 col-lg-2">
                                <label for="method" class="form-label small fw-semibold text-secondary mb-1">
                                    <i class="fa-solid fa-wallet me-1"></i>Payment Method
                                </label>
                                <select name="method" id="method" class="form-select form-select-sm">
                                    <option value="">All Methods</option>
                                    <option value="Cash" <?php echo ($methodFilter === 'Cash') ? 'selected' : ''; ?>>Cash</option>
                                    <option value="UPI" <?php echo ($methodFilter === 'UPI') ? 'selected' : ''; ?>>UPI</option>
                                    <option value="Bank Transfer" <?php echo ($methodFilter === 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                                    <option value="Cheque" <?php echo ($methodFilter === 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                                </select>
                            </div>

                            <!-- 4. Borrower Selector -->
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label for="borrower_id" class="form-label small fw-semibold text-secondary mb-1">
                                    <i class="fa-solid fa-user me-1"></i>Borrower
                                </label>
                                <select name="borrower_id" id="borrower_id" class="form-select form-select-sm">
                                    <option value="">All Borrowers</option>
                                    <?php foreach ($allBorrowers as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>" <?php echo ($borrowerFilter === (int)$b['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($b['full_name']); ?> (<?php echo htmlspecialchars($b['phone']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Action Buttons: Apply & Reset -->
                            <div class="col-12 col-lg-2 d-flex gap-2">
                                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                                    <i class="fa-solid fa-filter me-1"></i> Apply
                                </button>
                                <a href="<?php echo BASE_URL; ?>reports/index.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
                                    <i class="fa-solid fa-rotate-left"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Active Filter Badge Information -->
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex flex-wrap align-items-center gap-2 small">
                    <span class="text-muted fw-semibold">Reporting Scope:</span>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                        <i class="fa-regular fa-calendar-check me-1"></i><?php echo htmlspecialchars($rangeLabel); ?>
                    </span>
                    <?php if (!empty($statusFilter)): ?>
                        <span class="badge bg-secondary-subtle text-secondary border px-2 py-1">
                            Status: <?php echo ucfirst(str_replace('_', ' ', $statusFilter)); ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($methodFilter)): ?>
                        <span class="badge bg-secondary-subtle text-secondary border px-2 py-1">
                            Method: <?php echo htmlspecialchars($methodFilter); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($borrowerFilter > 0 && $selectedBorrowerData): ?>
                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-2 py-1">
                            Borrower: <?php echo htmlspecialchars($selectedBorrowerData['full_name']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="text-muted small d-print-none">
                    Showing real-time calculations
                </div>
            </div>

            <!-- SECTION 2: 4 FINANCIAL SUMMARY CARDS -->
            <div class="row g-3 g-xl-4 mb-4">
                <!-- Card 1: Total Principal Lent -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Total Principal Lent</span>
                            <div class="stat-icon-wrapper stat-icon-teal">
                                <i class="fa-solid fa-hand-holding-dollar"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo CURRENCY_SYMBOL . number_format($financialSummary['total_principal'], 2); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-arrow-trend-up me-1 text-primary"></i> Cumulative capital disbursed
                        </div>
                    </div>
                </div>

                <!-- Card 2: Total Repayments Collected -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Total Repayments</span>
                            <div class="stat-icon-wrapper stat-icon-emerald">
                                <i class="fa-solid fa-piggy-bank"></i>
                            </div>
                        </div>
                        <div class="stat-value text-success"><?php echo CURRENCY_SYMBOL . number_format($financialSummary['total_repayments'], 2); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-circle-check me-1 text-success"></i> Recovered installments
                        </div>
                    </div>
                </div>

                <!-- Card 3: Outstanding Balance -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Outstanding Balance</span>
                            <div class="stat-icon-wrapper stat-icon-amber">
                                <i class="fa-solid fa-file-invoice-dollar"></i>
                            </div>
                        </div>
                        <div class="stat-value text-danger"><?php echo CURRENCY_SYMBOL . number_format($financialSummary['total_outstanding'], 2); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-clock me-1 text-danger"></i> Pending receivable capital
                        </div>
                    </div>
                </div>

                <!-- Card 4: Interest Earned -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Interest Earned</span>
                            <div class="stat-icon-wrapper stat-icon-blue">
                                <i class="fa-solid fa-chart-line"></i>
                            </div>
                        </div>
                        <div class="stat-value text-primary"><?php echo CURRENCY_SYMBOL . number_format($financialSummary['total_interest'], 2); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-scale-balanced me-1 text-primary"></i> Contracted yield
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 3: PORTFOLIO OVERVIEW STATUS PILLS -->
            <div class="content-card mb-4">
                <div class="content-card-header">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-layer-group text-primary"></i>
                        <h2 class="content-card-title">Portfolio Status Overview</h2>
                    </div>
                    <span class="badge bg-light text-secondary border">Lifecycle Distribution</span>
                </div>

                <div class="card-body p-3 p-md-4">
                    <div class="row g-3 text-center">
                        <!-- Active Loans -->
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-light rounded border h-100">
                                <div class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 mb-2">
                                    <i class="fa-solid fa-circle-dot me-1"></i> Active
                                </div>
                                <div class="h3 fw-bold text-dark mb-1"><?php echo $portfolioCounts['active']; ?></div>
                                <div class="text-muted small">Standard active loans within schedule</div>
                            </div>
                        </div>

                        <!-- Partially Paid -->
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-light rounded border h-100">
                                <div class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-2 py-1 mb-2">
                                    <i class="fa-solid fa-circle-half-stroke me-1"></i> Partially Paid
                                </div>
                                <div class="h3 fw-bold text-dark mb-1"><?php echo $portfolioCounts['partially_paid']; ?></div>
                                <div class="text-muted small">Installments actively contributing</div>
                            </div>
                        </div>

                        <!-- Fully Paid -->
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-light rounded border h-100">
                                <div class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 mb-2">
                                    <i class="fa-solid fa-circle-check me-1"></i> Fully Paid
                                </div>
                                <div class="h3 fw-bold text-success mb-1"><?php echo $portfolioCounts['paid']; ?></div>
                                <div class="text-muted small">Fully closed &amp; settled agreements</div>
                            </div>
                        </div>

                        <!-- Overdue -->
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-light rounded border h-100">
                                <div class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 mb-2">
                                    <i class="fa-solid fa-triangle-exclamation me-1"></i> Overdue
                                </div>
                                <div class="h3 fw-bold text-danger mb-1"><?php echo $portfolioCounts['overdue']; ?></div>
                                <div class="text-muted small">Maturity date passed with balance</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 4 & 5: VISUAL CHARTS ROW -->
            <div class="row g-4 mb-4">
                <!-- Section 4: Collection Trend Chart -->
                <div class="col-12 col-lg-8">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-chart-area text-success"></i>
                                <h2 class="content-card-title">Repayment Collection Trend</h2>
                            </div>
                            <span class="badge bg-light text-secondary border">Timeline Activity</span>
                        </div>
                        <div class="card-body p-4">
                            <?php if (empty($trendData)): ?>
                                <div class="empty-state py-4">
                                    <div class="empty-state-icon" style="width: 44px; height: 44px; font-size: 1.2rem;">
                                        <i class="fa-solid fa-chart-line"></i>
                                    </div>
                                    <h4 class="empty-state-title fs-6">No repayment collections in selected period</h4>
                                    <p class="empty-state-text small mb-0">Collections logged across this timeframe will appear as an interactive trend chart.</p>
                                </div>
                            <?php else: ?>
                                <div style="position: relative; height: 260px; width: 100%;">
                                    <canvas id="collectionTrendChart"></canvas>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Section 5: Loan Status Analytics (Donut) -->
                <div class="col-12 col-lg-4">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-chart-pie text-primary"></i>
                                <h2 class="content-card-title">Loan Distribution</h2>
                            </div>
                            <span class="badge bg-light text-secondary border">Ratio</span>
                        </div>
                        <div class="card-body p-4 text-center">
                            <?php 
                            $totalLoanDist = array_sum($portfolioCounts);
                            if ($totalLoanDist === 0): ?>
                                <div class="empty-state py-4">
                                    <div class="empty-state-icon" style="width: 44px; height: 44px; font-size: 1.2rem;">
                                        <i class="fa-solid fa-chart-pie"></i>
                                    </div>
                                    <h4 class="empty-state-title fs-6">No loan portfolio records found</h4>
                                    <p class="empty-state-text small mb-0">Loan agreements will populate this status ratio.</p>
                                </div>
                            <?php else: ?>
                                <div style="position: relative; height: 210px; width: 100%;" class="mb-3">
                                    <canvas id="loanStatusChart"></canvas>
                                </div>
                                <div class="row g-1 text-muted small justify-content-center">
                                    <div class="col-6"><span class="badge bg-primary me-1">&nbsp;</span> Active: <strong><?php echo $portfolioCounts['active']; ?></strong></div>
                                    <div class="col-6"><span class="badge bg-info me-1">&nbsp;</span> Partially: <strong><?php echo $portfolioCounts['partially_paid']; ?></strong></div>
                                    <div class="col-6"><span class="badge bg-success me-1">&nbsp;</span> Paid: <strong><?php echo $portfolioCounts['paid']; ?></strong></div>
                                    <div class="col-6"><span class="badge bg-danger me-1">&nbsp;</span> Overdue: <strong><?php echo $portfolioCounts['overdue']; ?></strong></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 6 & 7: PAYMENT METHOD BREAKDOWN & TOP BORROWERS ROW -->
            <div class="row g-4 mb-4">
                <!-- Section 6: Payment Method Breakdown Table -->
                <div class="col-12 col-lg-6">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-wallet text-teal"></i>
                                <h2 class="content-card-title">Payment Method Breakdown</h2>
                            </div>
                            <span class="badge bg-light text-secondary border">Volume &amp; Share</span>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($methodBreakdown)): ?>
                                <div class="empty-state py-4">
                                    <div class="empty-state-icon" style="width: 44px; height: 44px; font-size: 1.2rem;">
                                        <i class="fa-solid fa-wallet"></i>
                                    </div>
                                    <h4 class="empty-state-title fs-6">No payment method records</h4>
                                    <p class="empty-state-text small mb-0">Payment breakdowns will appear here after installments are received.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0 small">
                                        <thead class="table-light text-muted">
                                            <tr>
                                                <th class="ps-3">Payment Method</th>
                                                <th class="text-center">Transactions</th>
                                                <th class="text-end">Total Collected</th>
                                                <th class="text-end pe-3" style="width: 110px;">Share</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($methodBreakdown as $mb): ?>
                                                <?php 
                                                    $sharePct = ($totalMethodAmount > 0) ? round(((float)$mb['total_amount'] / $totalMethodAmount) * 100, 1) : 0;
                                                ?>
                                                <tr>
                                                    <td class="ps-3 fw-semibold">
                                                        <span class="badge bg-light text-dark border px-2 py-1">
                                                            <i class="fa-solid fa-circle-check me-1 text-success" style="font-size: 0.65rem;"></i>
                                                            <?php echo htmlspecialchars($mb['payment_method']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center text-secondary"><?php echo (int)$mb['tx_count']; ?></td>
                                                    <td class="text-end fw-bold text-success">
                                                        <?php echo CURRENCY_SYMBOL . number_format((float)$mb['total_amount'], 2); ?>
                                                    </td>
                                                    <td class="text-end pe-3">
                                                        <div class="d-flex align-items-center justify-content-end gap-2">
                                                            <div class="progress flex-grow-1" style="height: 6px;">
                                                                <div class="progress-bar bg-success" style="width: <?php echo $sharePct; ?>%;"></div>
                                                            </div>
                                                            <span class="text-muted small" style="width: 38px;"><?php echo $sharePct; ?>%</span>
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

                <!-- Section 7: Top Borrowers by Volume -->
                <div class="col-12 col-lg-6">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-trophy text-amber"></i>
                                <h2 class="content-card-title">Top Borrowers (by Capital Borrowed)</h2>
                            </div>
                            <span class="badge bg-light text-secondary border">Top 5 Clients</span>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($topBorrowers)): ?>
                                <div class="empty-state py-4">
                                    <div class="empty-state-icon" style="width: 44px; height: 44px; font-size: 1.2rem;">
                                        <i class="fa-solid fa-users"></i>
                                    </div>
                                    <h4 class="empty-state-title fs-6">No borrowers registered yet</h4>
                                    <p class="empty-state-text small mb-0">Active borrower portfolios will be listed here.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0 small">
                                        <thead class="table-light text-muted">
                                            <tr>
                                                <th class="ps-3" style="width: 50px;">#</th>
                                                <th>Borrower</th>
                                                <th class="text-center">Loans</th>
                                                <th class="text-end">Borrowed</th>
                                                <th class="text-end pe-3">Outstanding</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($topBorrowers as $idx => $tb): ?>
                                                <tr>
                                                    <td class="ps-3 fw-bold text-muted"><?php echo $idx + 1; ?></td>
                                                    <td>
                                                        <div class="fw-semibold d-flex align-items-center gap-1">
                                                            <a href="<?php echo BASE_URL; ?>reports/index.php?borrower_id=<?php echo (int)$tb['id']; ?>" class="text-dark text-decoration-none" title="Filter report for this borrower">
                                                                <?php echo htmlspecialchars($tb['full_name']); ?>
                                                            </a>
                                                            <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo (int)$tb['id']; ?>" class="text-muted ms-1" title="View Client Profile" aria-label="View Profile for <?php echo htmlspecialchars($tb['full_name']); ?>">
                                                                <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.7rem;"></i>
                                                            </a>
                                                        </div>
                                                        <div class="text-muted" style="font-size: 0.75rem;">
                                                            <?php echo htmlspecialchars($tb['phone']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-light text-secondary border"><?php echo (int)$tb['total_loans']; ?></span>
                                                    </td>
                                                    <td class="text-end fw-semibold text-dark">
                                                        <?php echo CURRENCY_SYMBOL . number_format((float)$tb['total_borrowed'], 2); ?>
                                                    </td>
                                                    <td class="text-end pe-3 fw-bold <?php echo ((float)$tb['total_outstanding'] > 0) ? 'text-danger' : 'text-success'; ?>">
                                                        <?php echo CURRENCY_SYMBOL . number_format((float)$tb['total_outstanding'], 2); ?>
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

            <!-- SECTION 10: DEDICATED OVERDUE LOANS ALERT SECTION -->
            <div class="content-card mb-4 border-danger-subtle">
                <div class="content-card-header bg-danger-subtle text-danger border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <h2 class="content-card-title text-danger mb-0">Overdue Loans Alert</h2>
                    </div>
                    <span class="badge bg-danger text-white"><?php echo count($overdueLoans); ?> Default Risk<?php echo count($overdueLoans) === 1 ? '' : 's'; ?></span>
                </div>

                <div class="card-body p-0">
                    <?php if (empty($overdueLoans)): ?>
                        <div class="empty-state py-4 text-center">
                            <div class="brand-icon-box bg-success-subtle text-success mx-auto mb-2" style="width: 44px; height: 44px; font-size: 1.2rem; border-radius: 50%;">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                            <h3 class="empty-state-title fs-6 text-dark mb-1">No overdue loans</h3>
                            <p class="empty-state-text small text-muted mb-0">All active loans are currently within their payment terms. No delayed accounts.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead class="table-light text-muted">
                                    <tr>
                                        <th class="ps-3">Loan ID</th>
                                        <th>Borrower</th>
                                        <th>Due Date</th>
                                        <th>Total Payable</th>
                                        <th>Repaid</th>
                                        <th>Outstanding</th>
                                        <th class="text-end pe-3">Days Overdue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($overdueLoans as $ol): ?>
                                        <tr>
                                            <td class="ps-3 fw-bold">
                                                <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$ol['id']; ?>" class="badge bg-light text-primary border text-decoration-none">
                                                    Loan #<?php echo (int)$ol['id']; ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="fw-semibold">
                                                    <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo (int)$ol['borrower_id']; ?>" class="text-dark text-decoration-none">
                                                        <?php echo htmlspecialchars($ol['borrower_name']); ?>
                                                    </a>
                                                </div>
                                                <div class="text-muted" style="font-size: 0.75rem;">
                                                    <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($ol['borrower_phone']); ?>
                                                </div>
                                            </td>
                                            <td class="text-danger fw-semibold">
                                                <?php echo date('M d, Y', strtotime($ol['due_date'])); ?>
                                            </td>
                                            <td class="text-secondary"><?php echo CURRENCY_SYMBOL . number_format((float)$ol['total_payable'], 2); ?></td>
                                            <td class="text-success"><?php echo CURRENCY_SYMBOL . number_format((float)$ol['amount_repaid'], 2); ?></td>
                                            <td class="text-danger fw-bold fs-6">
                                                <?php echo CURRENCY_SYMBOL . number_format((float)$ol['remaining_balance'], 2); ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <span class="badge bg-danger text-white px-2 py-1">
                                                    <i class="fa-solid fa-clock me-1"></i><?php echo max(1, (int)$ol['days_overdue']); ?> Days Overdue
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SECTION 11: BORROWER-SPECIFIC DETAILED REPORT (WHEN SELECTED) -->
            <?php if ($selectedBorrowerData): ?>
                <div class="content-card mb-4 border-primary shadow-sm" id="borrowerDeepDiveSection">
                    <div class="content-card-header bg-primary-subtle text-primary border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa-solid fa-user-check"></i>
                            <h2 class="content-card-title text-primary mb-0">
                                Client Financial Profile: <?php echo htmlspecialchars($selectedBorrowerData['full_name']); ?>
                            </h2>
                        </div>
                        <a href="<?php echo BASE_URL; ?>reports/index.php" class="btn btn-outline-primary btn-sm d-print-none">
                            <i class="fa-solid fa-xmark me-1"></i> Clear Borrower Scope
                        </a>
                    </div>

                    <div class="card-body p-4">
                        <!-- Borrower Summary Cards Grid -->
                        <div class="row g-3 mb-4 text-center">
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-light rounded border">
                                    <div class="text-muted small mb-1">Total Borrowed</div>
                                    <div class="h5 fw-bold text-dark mb-0"><?php echo CURRENCY_SYMBOL . number_format((float)$selectedBorrowerData['total_borrowed'], 2); ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-light rounded border">
                                    <div class="text-muted small mb-1">Total Repaid</div>
                                    <div class="h5 fw-bold text-success mb-0"><?php echo CURRENCY_SYMBOL . number_format((float)$selectedBorrowerData['total_repaid'], 2); ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-light rounded border">
                                    <div class="text-muted small mb-1">Outstanding Balance</div>
                                    <div class="h5 fw-bold text-danger mb-0"><?php echo CURRENCY_SYMBOL . number_format((float)$selectedBorrowerData['total_outstanding'], 2); ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-light rounded border">
                                    <div class="text-muted small mb-1">Total Interest Yield</div>
                                    <div class="h5 fw-bold text-primary mb-0"><?php echo CURRENCY_SYMBOL . number_format((float)$selectedBorrowerData['total_interest'], 2); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- 2 Tabs or Subsections: Loan History and Repayment History -->
                        <div class="row g-4">
                            <!-- Loan History -->
                            <div class="col-12 col-lg-6">
                                <div class="border rounded p-3 bg-white h-100">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <h3 class="h6 fw-bold text-dark mb-0">
                                            <i class="fa-solid fa-file-invoice-dollar me-1 text-primary"></i>Loan History (<?php echo count($borrowerLoansList); ?>)
                                        </h3>
                                        <a href="<?php echo BASE_URL; ?>loans/add.php?borrower_id=<?php echo (int)$selectedBorrowerData['id']; ?>" class="btn btn-outline-primary btn-sm py-0 px-2 d-print-none">
                                            + New Loan
                                        </a>
                                    </div>
                                    <?php if (empty($borrowerLoansList)): ?>
                                        <div class="text-muted small py-3 text-center">No loan records on file for this borrower.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover mb-0 small">
                                                <thead class="table-light text-muted">
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Principal</th>
                                                        <th>Repaid</th>
                                                        <th>Remaining</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($borrowerLoansList as $bl): ?>
                                                        <tr>
                                                            <td class="fw-semibold">
                                                                <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$bl['id']; ?>" class="text-primary text-decoration-none">
                                                                    #<?php echo (int)$bl['id']; ?>
                                                                </a>
                                                            </td>
                                                            <td><?php echo CURRENCY_SYMBOL . number_format((float)$bl['principal_amount'], 2); ?></td>
                                                            <td class="text-success"><?php echo CURRENCY_SYMBOL . number_format((float)$bl['amount_repaid'], 2); ?></td>
                                                            <td class="text-danger fw-semibold"><?php echo CURRENCY_SYMBOL . number_format((float)$bl['remaining_balance'], 2); ?></td>
                                                            <td><?php echo get_loan_status_badge($bl['status']); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Repayment History -->
                            <div class="col-12 col-lg-6">
                                <div class="border rounded p-3 bg-white h-100">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <h3 class="h6 fw-bold text-dark mb-0">
                                            <i class="fa-solid fa-receipt me-1 text-success"></i>Repayment History (<?php echo count($borrowerRepaymentsList); ?>)
                                        </h3>
                                        <a href="<?php echo BASE_URL; ?>repayments/add.php" class="btn btn-outline-success btn-sm py-0 px-2 d-print-none">
                                            + Record Payment
                                        </a>
                                    </div>
                                    <?php if (empty($borrowerRepaymentsList)): ?>
                                        <div class="text-muted small py-3 text-center">No payment installments recorded for this borrower.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover mb-0 small">
                                                <thead class="table-light text-muted">
                                                    <tr>
                                                        <th>Receipt</th>
                                                        <th>Loan</th>
                                                        <th>Amount</th>
                                                        <th>Date</th>
                                                        <th>Method</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($borrowerRepaymentsList as $br): ?>
                                                        <tr>
                                                            <td class="fw-semibold">
                                                                <a href="<?php echo BASE_URL; ?>repayments/view.php?id=<?php echo (int)$br['id']; ?>" class="text-dark text-decoration-none">
                                                                    #<?php echo (int)$br['id']; ?>
                                                                </a>
                                                            </td>
                                                            <td>#<?php echo (int)$br['loan_id']; ?></td>
                                                            <td class="text-success fw-bold">+<?php echo CURRENCY_SYMBOL . number_format((float)$br['amount'], 2); ?></td>
                                                            <td class="text-muted"><?php echo date('M d, Y', strtotime($br['payment_date'])); ?></td>
                                                            <td>
                                                                <span class="badge bg-light text-secondary border"><?php echo htmlspecialchars($br['payment_method']); ?></span>
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
                </div>
            <?php endif; ?>

            <!-- SECTION 8: LOAN PORTFOLIO REPORT TABLE -->
            <div class="content-card mb-4">
                <div class="content-card-header">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-file-invoice-dollar text-primary"></i>
                        <h2 class="content-card-title">Loan Portfolio Report</h2>
                    </div>
                    <span class="badge bg-light text-secondary border"><?php echo count($loanReports); ?> Records</span>
                </div>

                <div class="card-body p-0">
                    <?php if (empty($loanReports)): ?>
                        <div class="empty-state py-4 text-center">
                            <div class="empty-state-icon" style="width: 44px; height: 44px; font-size: 1.2rem;">
                                <i class="fa-solid fa-file-circle-xmark"></i>
                            </div>
                            <h3 class="empty-state-title fs-6">No loan records found</h3>
                            <p class="empty-state-text small mb-0">No loan records match the selected date scope or status criteria.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead class="table-light text-muted">
                                    <tr>
                                        <th class="ps-3" style="width: 70px;">ID</th>
                                        <th>Borrower</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>Total Payable</th>
                                        <th>Repaid</th>
                                        <th>Outstanding</th>
                                        <th>Start Date</th>
                                        <th>Due Date</th>
                                        <th class="text-end pe-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($loanReports as $lr): ?>
                                        <tr>
                                            <td class="ps-3 fw-semibold">
                                                <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$lr['id']; ?>" class="text-primary text-decoration-none">
                                                    #<?php echo (int)$lr['id']; ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-dark">
                                                    <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo (int)$lr['borrower_id']; ?>" class="text-dark text-decoration-none">
                                                        <?php echo htmlspecialchars($lr['borrower_name']); ?>
                                                    </a>
                                                </div>
                                                <div class="text-muted" style="font-size: 0.75rem;">
                                                    <?php echo htmlspecialchars($lr['borrower_phone']); ?>
                                                </div>
                                            </td>
                                            <td class="fw-semibold"><?php echo CURRENCY_SYMBOL . number_format((float)$lr['principal_amount'], 2); ?></td>
                                            <td>
                                                <?php echo number_format((float)$lr['interest_rate'], 1); ?>% 
                                                <span class="badge bg-light text-muted border text-uppercase" style="font-size: 0.65rem;"><?php echo htmlspecialchars($lr['interest_type']); ?></span>
                                            </td>
                                            <td class="fw-semibold text-secondary"><?php echo CURRENCY_SYMBOL . number_format((float)$lr['total_payable'], 2); ?></td>
                                            <td class="text-success fw-semibold"><?php echo CURRENCY_SYMBOL . number_format((float)$lr['amount_repaid'], 2); ?></td>
                                            <td class="fw-bold <?php echo ((float)$lr['remaining_balance'] > 0) ? 'text-danger' : 'text-success'; ?>">
                                                <?php echo CURRENCY_SYMBOL . number_format((float)$lr['remaining_balance'], 2); ?>
                                            </td>
                                            <td class="text-muted"><?php echo date('M d, Y', strtotime($lr['start_date'])); ?></td>
                                            <td class="text-muted"><?php echo date('M d, Y', strtotime($lr['due_date'])); ?></td>
                                            <td class="text-end pe-3">
                                                <?php echo get_loan_status_badge($lr['status']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SECTION 9: REPAYMENT COLLECTION REPORT TABLE -->
            <div class="content-card mb-4">
                <div class="content-card-header">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-receipt text-success"></i>
                        <h2 class="content-card-title">Repayment Collection Report</h2>
                    </div>
                    <span class="badge bg-light text-secondary border"><?php echo count($repaymentReports); ?> Records</span>
                </div>

                <div class="card-body p-0">
                    <?php if (empty($repaymentReports)): ?>
                        <div class="empty-state py-4 text-center">
                            <div class="empty-state-icon" style="width: 44px; height: 44px; font-size: 1.2rem;">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                            <h3 class="empty-state-title fs-6">No repayment records found</h3>
                            <p class="empty-state-text small mb-0">No installments found for the selected time range or payment method.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead class="table-light text-muted">
                                    <tr>
                                        <th class="ps-3" style="width: 80px;">Receipt ID</th>
                                        <th>Borrower</th>
                                        <th>Loan ID</th>
                                        <th>Amount Paid</th>
                                        <th>Payment Method</th>
                                        <th>Payment Date</th>
                                        <th class="text-end pe-3">Recorded By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($repaymentReports as $rr): ?>
                                        <tr>
                                            <td class="ps-3 fw-semibold">
                                                <a href="<?php echo BASE_URL; ?>repayments/view.php?id=<?php echo (int)$rr['id']; ?>" class="text-dark text-decoration-none">
                                                    #<?php echo (int)$rr['id']; ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-dark">
                                                    <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo (int)$rr['borrower_id']; ?>" class="text-dark text-decoration-none">
                                                        <?php echo htmlspecialchars($rr['borrower_name']); ?>
                                                    </a>
                                                </div>
                                                <div class="text-muted" style="font-size: 0.75rem;">
                                                    <?php echo htmlspecialchars($rr['borrower_phone']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$rr['loan_id']; ?>" class="badge bg-light text-primary border text-decoration-none">
                                                    Loan #<?php echo (int)$rr['loan_id']; ?>
                                                </a>
                                            </td>
                                            <td class="text-success fw-bold fs-6">
                                                +<?php echo CURRENCY_SYMBOL . number_format((float)$rr['amount'], 2); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-secondary border px-2 py-1">
                                                    <?php echo htmlspecialchars($rr['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td class="text-muted"><?php echo date('M d, Y', strtotime($rr['payment_date'])); ?></td>
                                            <td class="text-end pe-3 text-muted">
                                                <i class="fa-solid fa-user-tie me-1 text-muted" style="font-size: 0.7rem;"></i>
                                                <?php echo htmlspecialchars($rr['recorded_by_name']); ?>
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
    </main>
</div>

<!-- Chart.js 4.4 CDN for interactive graphs -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    // 1. Toggle custom date inputs dynamically based on dropdown selection
    const dateRangeSelect = document.getElementById('date_range');
    const customFromCol = document.getElementById('customFromCol');
    const customToCol = document.getElementById('customToCol');

    if (dateRangeSelect) {
        dateRangeSelect.addEventListener('change', function() {
            if (this.value === 'custom') {
                customFromCol.style.display = 'block';
                customToCol.style.display = 'block';
            } else {
                customFromCol.style.display = 'none';
                customToCol.style.display = 'none';
            }
        });
    }

    // 2. Render Collection Trend Chart (Chart.js)
    const trendCtx = document.getElementById('collectionTrendChart');
    if (trendCtx) {
        const trendLabels = <?php echo json_encode($trendLabels); ?>;
        const trendData   = <?php echo json_encode($trendData); ?>;
        const currencySymbol = <?php echo json_encode(CURRENCY_SYMBOL); ?>;

        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'Repayment Collections (' + currencySymbol + ')',
                    data: trendData,
                    borderColor: '#0f766e',
                    backgroundColor: 'rgba(15, 118, 110, 0.12)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 2.5,
                    pointBackgroundColor: '#0f766e',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return ' Collections: ' + currencySymbol + Number(context.parsed.y).toLocaleString('en-IN', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(226, 232, 240, 0.8)' },
                        ticks: {
                            callback: function(val) {
                                return currencySymbol + Number(val).toLocaleString('en-IN');
                            },
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
    }

    // 3. Render Loan Status Analytics Donut Chart
    const statusCtx = document.getElementById('loanStatusChart');
    if (statusCtx) {
        const counts = <?php echo json_encode([
            $portfolioCounts['active'],
            $portfolioCounts['partially_paid'],
            $portfolioCounts['paid'],
            $portfolioCounts['overdue']
        ]); ?>;

        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Active', 'Partially Paid', 'Fully Paid', 'Overdue'],
                datasets: [{
                    data: counts,
                    backgroundColor: [
                        '#2563eb', // Active (Royal Blue)
                        '#0284c7', // Partially Paid (Cyan/Info)
                        '#059669', // Fully Paid (Emerald Green)
                        '#dc2626'  // Overdue (Danger Red)
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                cutout: '70%'
            }
        });
    }
});
</script>

<style>
/* Print Styles for A4-Friendly Clean Output */
@media print {
    body {
        background-color: #ffffff !important;
        color: #000000 !important;
    }
    .d-print-none, 
    .app-sidebar, 
    .app-navbar, 
    .app-footer, 
    .filter-card, 
    .btn, 
    .dropdown,
    #sidebarBackdrop {
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
        border: 1px solid #d1d5db !important;
        box-shadow: none !important;
        margin-bottom: 1.5rem !important;
        page-break-inside: avoid;
    }
    .content-card-header {
        background-color: #f3f4f6 !important;
        border-bottom: 1px solid #d1d5db !important;
    }
    .table {
        font-size: 0.78rem !important;
    }
    .table-responsive {
        overflow: visible !important;
    }
    canvas {
        max-height: 200px !important;
    }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
