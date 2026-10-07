<?php
/**
 * MoneyLend - Repayments Directory
 * 
 * Lists all recorded repayment transactions with search, date filters,
 * payment method filters, live summary cards, and reversal/deletion options.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Protect page
require_login();

$pageTitle = "Repayments";
$activePage = "repayments";
$flash = get_flash();
$pdo = getDBConnection();

// Filters & Search
$search = trim($_GET['search'] ?? '');
$methodFilter = trim($_GET['method'] ?? '');
$fromDate = trim($_GET['from_date'] ?? '');
$toDate = trim($_GET['to_date'] ?? '');

$query = "SELECT r.*, 
                 l.principal_amount, 
                 l.total_payable, 
                 l.remaining_balance, 
                 l.status AS loan_status,
                 b.id AS borrower_id,
                 b.full_name AS borrower_name, 
                 b.phone AS borrower_phone,
                 COALESCE(u.name, 'Admin User') AS recorded_by_name
          FROM repayments r
          JOIN loans l ON r.loan_id = l.id
          JOIN borrowers b ON l.borrower_id = b.id
          LEFT JOIN users u ON r.user_id = u.id
          WHERE 1=1";
$params = [];

// Search by borrower name, phone, loan ID, or repayment ID
if ($search !== '') {
    if (is_numeric($search)) {
        $query .= " AND (r.id = :search_id OR r.loan_id = :search_loan_id OR b.full_name LIKE :search_name OR b.phone LIKE :search_phone)";
        $params[':search_id'] = (int)$search;
        $params[':search_loan_id'] = (int)$search;
        $params[':search_name'] = "%{$search}%";
        $params[':search_phone'] = "%{$search}%";
    } else {
        $query .= " AND (b.full_name LIKE :search_name OR b.phone LIKE :search_phone)";
        $params[':search_name'] = "%{$search}%";
        $params[':search_phone'] = "%{$search}%";
    }
}

// Payment method filter
if (!empty($methodFilter)) {
    $allowedMethods = ['Cash', 'UPI', 'Bank Transfer', 'Cheque', 'Other'];
    if (in_array($methodFilter, $allowedMethods)) {
        $query .= " AND r.payment_method = :method";
        $params[':method'] = $methodFilter;
    }
}

// Date filters
if (!empty($fromDate) && strtotime($fromDate)) {
    $query .= " AND r.payment_date >= :from_date";
    $params[':from_date'] = $fromDate;
}
if (!empty($toDate) && strtotime($toDate)) {
    $query .= " AND r.payment_date <= :to_date";
    $params[':to_date'] = $toDate;
}

$query .= " ORDER BY r.payment_date DESC, r.id DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $repayments = $stmt->fetchAll();

    // Summary Metric Calculations
    // 1. Total Repayments count
    $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM repayments")->fetchColumn();

    // 2. Total Amount Collected
    $totalCollected = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM repayments")->fetchColumn();

    // 3. Today's Collections
    $todayStmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM repayments WHERE payment_date = CURDATE()");
    $todayCollected = (float)$todayStmt->fetchColumn();

    // 4. Pending Loan Balance (from non-cancelled loans)
    $pendingStmt = $pdo->query("SELECT COALESCE(SUM(remaining_balance), 0) FROM loans WHERE status != 'cancelled'");
    $pendingBalance = (float)$pendingStmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Repayments directory error: " . $e->getMessage());
    $repayments = [];
    $totalCount = 0;
    $totalCollected = 0.0;
    $todayCollected = 0.0;
    $pendingBalance = 0.0;
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

            <!-- Page Header & Action -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="page-title">Repayments</h1>
                    <p class="page-subtitle">Receive and track repayment transactions</p>
                </div>
                <div>
                    <a href="<?php echo BASE_URL; ?>repayments/add.php" class="btn btn-success quick-action-btn">
                        <i class="fa-solid fa-money-bill-transfer"></i>
                        <span>Record Repayment</span>
                    </a>
                </div>
            </div>

            <!-- Summary Cards Grid -->
            <div class="row g-3 g-xl-4 mb-4">
                <!-- Card 1: Total Repayments -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Total Repayments</span>
                            <div class="stat-icon-wrapper stat-icon-emerald">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo $totalCount; ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-circle-check me-1"></i> Recorded transactions
                        </div>
                    </div>
                </div>

                <!-- Card 2: Total Amount Collected -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Total Collected</span>
                            <div class="stat-icon-wrapper stat-icon-blue">
                                <i class="fa-solid fa-hand-holding-dollar"></i>
                            </div>
                        </div>
                        <div class="stat-value text-success"><?php echo CURRENCY_SYMBOL . number_format($totalCollected, 2); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-arrow-trend-up me-1"></i> Lifetime recoveries
                        </div>
                    </div>
                </div>

                <!-- Card 3: Today's Collections -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Today's Collections</span>
                            <div class="stat-icon-wrapper stat-icon-teal">
                                <i class="fa-solid fa-calendar-day"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo CURRENCY_SYMBOL . number_format($todayCollected, 2); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-clock me-1"></i> Logged for <?php echo date('M d'); ?>
                        </div>
                    </div>
                </div>

                <!-- Card 4: Pending Loan Balance -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Pending Balance</span>
                            <div class="stat-icon-wrapper stat-icon-amber">
                                <i class="fa-solid fa-scale-unbalanced"></i>
                            </div>
                        </div>
                        <div class="stat-value text-danger-emphasis"><?php echo CURRENCY_SYMBOL . number_format($pendingBalance, 2); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-arrow-down-long me-1"></i> Total remaining to recover
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search & Filters Card -->
            <div class="content-card mb-4">
                <div class="p-3">
                    <form method="GET" action="<?php echo BASE_URL; ?>repayments/index.php" class="row g-2 align-items-center">
                        <!-- Search Keyword -->
                        <div class="col-12 col-md-4 col-lg-4">
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted border-end-0">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                </span>
                                <input 
                                    type="text" 
                                    name="search" 
                                    class="form-control border-start-0 ps-0" 
                                    placeholder="Search by client, phone, loan #, or receipt #..." 
                                    value="<?php echo htmlspecialchars($search); ?>"
                                >
                            </div>
                        </div>

                        <!-- Payment Method Filter -->
                        <div class="col-6 col-md-3 col-lg-2">
                            <select name="method" class="form-select">
                                <option value="">All Methods</option>
                                <option value="Cash" <?php echo ($methodFilter === 'Cash') ? 'selected' : ''; ?>>Cash</option>
                                <option value="UPI" <?php echo ($methodFilter === 'UPI') ? 'selected' : ''; ?>>UPI</option>
                                <option value="Bank Transfer" <?php echo ($methodFilter === 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="Cheque" <?php echo ($methodFilter === 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                                <option value="Other" <?php echo ($methodFilter === 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <!-- Date Range: From -->
                        <div class="col-6 col-md-2 col-lg-2">
                            <input 
                                type="date" 
                                name="from_date" 
                                class="form-control" 
                                title="From Date"
                                value="<?php echo htmlspecialchars($fromDate); ?>"
                            >
                        </div>

                        <!-- Date Range: To -->
                        <div class="col-6 col-md-2 col-lg-2">
                            <input 
                                type="date" 
                                name="to_date" 
                                class="form-control" 
                                title="To Date"
                                value="<?php echo htmlspecialchars($toDate); ?>"
                            >
                        </div>

                        <!-- Submit & Clear -->
                        <div class="col-6 col-md-1 col-lg-2 d-flex gap-2">
                            <button type="submit" class="btn btn-secondary flex-grow-1">
                                Filter
                            </button>
                            <?php if ($search !== '' || $methodFilter !== '' || $fromDate !== '' || $toDate !== ''): ?>
                                <a href="<?php echo BASE_URL; ?>repayments/index.php" class="btn btn-outline-secondary" title="Clear Filters">
                                    <i class="fa-solid fa-rotate-left"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Repayments Table Card -->
            <div class="content-card">
                <div class="content-card-header">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-money-bill-wave text-success"></i>
                        <h2 class="content-card-title">Repayment Transactions</h2>
                    </div>
                    <span class="badge bg-light text-secondary border">
                        Showing <?php echo count($repayments); ?> Transaction<?php echo count($repayments) === 1 ? '' : 's'; ?>
                    </span>
                </div>

                <div class="card-body p-0">
                    <?php if (empty($repayments)): ?>
                        <!-- Empty State -->
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                            <?php if ($search !== '' || $methodFilter !== '' || $fromDate !== '' || $toDate !== ''): ?>
                                <h3 class="empty-state-title">No repayments match your search criteria</h3>
                                <p class="empty-state-text mb-3">Try adjusting your filters or search keywords to view all transaction records.</p>
                                <a href="<?php echo BASE_URL; ?>repayments/index.php" class="btn btn-outline-secondary btn-sm">Reset Filters</a>
                            <?php else: ?>
                                <h3 class="empty-state-title">No repayment records available yet</h3>
                                <p class="empty-state-text mb-3">When borrowers make installments against active loans, log them here to update balances.</p>
                                <a href="<?php echo BASE_URL; ?>repayments/add.php" class="btn btn-success btn-sm">
                                    <i class="fa-solid fa-plus me-1"></i> Record First Repayment
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light text-muted small text-uppercase">
                                    <tr>
                                        <th scope="col" class="ps-3" style="width: 80px;">Receipt #</th>
                                        <th scope="col" style="width: 90px;">Loan #</th>
                                        <th scope="col">Borrower</th>
                                        <th scope="col">Amount Paid</th>
                                        <th scope="col">Payment Date</th>
                                        <th scope="col">Method</th>
                                        <th scope="col">Recorded By</th>
                                        <th scope="col" class="text-end pe-3" style="width: 140px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($repayments as $r): ?>
                                        <tr>
                                            <td class="ps-3 fw-semibold text-secondary small">
                                                #<?php echo (int)$r['id']; ?>
                                            </td>
                                            <td>
                                                <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$r['loan_id']; ?>" class="badge bg-light text-primary border text-decoration-none" title="View Loan Agreement">
                                                    Loan #<?php echo (int)$r['loan_id']; ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="navbar-user-avatar" style="width: 32px; height: 32px; font-size: 0.8rem; background-color: #ecfdf5; color: #059669;">
                                                        <?php echo htmlspecialchars(strtoupper(substr($r['borrower_name'], 0, 1))); ?>
                                                    </div>
                                                    <div>
                                                        <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo (int)$r['borrower_id']; ?>" class="fw-semibold text-dark text-decoration-none">
                                                            <?php echo htmlspecialchars($r['borrower_name']); ?>
                                                        </a>
                                                        <div class="text-muted" style="font-size: 0.75rem;">
                                                            <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($r['borrower_phone']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-success fw-bold">
                                                +<?php echo CURRENCY_SYMBOL . number_format((float)$r['amount'], 2); ?>
                                            </td>
                                            <td class="text-muted small">
                                                <?php echo date('M d, Y', strtotime($r['payment_date'])); ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    $methodBadges = [
                                                        'Cash'          => 'bg-success-subtle text-success border border-success-subtle',
                                                        'UPI'           => 'bg-primary-subtle text-primary border border-primary-subtle',
                                                        'Bank Transfer' => 'bg-info-subtle text-info-emphasis border border-info-subtle',
                                                        'Cheque'        => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                                                        'Other'         => 'bg-secondary-subtle text-secondary border'
                                                    ];
                                                    $badgeClass = $methodBadges[$r['payment_method']] ?? 'bg-light text-secondary border';
                                                ?>
                                                <span class="badge <?php echo $badgeClass; ?> px-2 py-1">
                                                    <?php echo htmlspecialchars($r['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td class="text-muted small">
                                                <i class="fa-solid fa-user-tie me-1 text-muted" style="font-size: 0.7rem;"></i>
                                                <?php echo htmlspecialchars($r['recorded_by_name']); ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <div class="btn-group btn-group-sm" role="group" aria-label="Repayment Actions">
                                                    <a href="<?php echo BASE_URL; ?>repayments/view.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-outline-secondary" title="View Receipt Voucher" aria-label="View Receipt #<?php echo (int)$r['id']; ?>">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </a>
                                                    <a href="<?php echo BASE_URL; ?>repayments/edit.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-outline-primary" title="Edit Repayment" aria-label="Edit Repayment #<?php echo (int)$r['id']; ?>">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </a>
                                                    <button 
                                                        type="button" 
                                                        class="btn btn-outline-danger" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#deleteRepaymentModal" 
                                                        data-repayment-id="<?php echo (int)$r['id']; ?>" 
                                                        data-loan-id="<?php echo (int)$r['loan_id']; ?>"
                                                        data-amount="<?php echo CURRENCY_SYMBOL . number_format((float)$r['amount'], 2); ?>"
                                                        data-borrower-name="<?php echo htmlspecialchars($r['borrower_name']); ?>"
                                                        title="Delete Repayment"
                                                        aria-label="Delete Repayment #<?php echo (int)$r['id']; ?>"
                                                    >
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </button>
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

        </div> <!-- End .content-container -->

        <!-- Delete Repayment Confirmation Modal -->
        <div class="modal fade" id="deleteRepaymentModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="POST" action="<?php echo BASE_URL; ?>repayments/delete.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="repayment_id" id="modalRepaymentId" value="">

                        <div class="modal-header border-bottom">
                            <h5 class="modal-title fw-bold text-danger" id="deleteModalLabel">
                                <i class="fa-solid fa-triangle-exclamation me-2"></i>Delete Repayment
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body py-4">
                            <p class="mb-3">
                                Are you sure you want to delete repayment <strong id="modalRepaymentIdText" class="text-dark"></strong> of <strong id="modalAmountText" class="text-success"></strong> for borrower <strong id="modalBorrowerText" class="text-dark"></strong>?
                            </p>
                            
                            <div class="alert alert-warning small border-0 mb-0">
                                <i class="fa-solid fa-rotate-left me-1"></i>
                                <strong>Automatic Balance Reversal:</strong> Deleting this installment will automatically add the amount back to the outstanding balance of Loan <strong id="modalLoanIdText"></strong>. If the loan was previously marked as <strong>Paid</strong>, it will automatically transition back to <strong>Active</strong>.
                            </div>
                        </div>
                        <div class="modal-footer border-top bg-light">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="fa-solid fa-trash-can me-1"></i> Delete &amp; Reverse Balance
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const deleteModal = document.getElementById('deleteRepaymentModal');
            if (deleteModal) {
                deleteModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const repaymentId = button.getAttribute('data-repayment-id');
                    const loanId = button.getAttribute('data-loan-id');
                    const amount = button.getAttribute('data-amount');
                    const borrowerName = button.getAttribute('data-borrower-name');

                    document.getElementById('modalRepaymentId').value = repaymentId;
                    document.getElementById('modalRepaymentIdText').textContent = '#' + repaymentId;
                    document.getElementById('modalAmountText').textContent = amount;
                    document.getElementById('modalBorrowerText').textContent = borrowerName;
                    document.getElementById('modalLoanIdText').textContent = '#' + loanId;
                });
            }
        });
        </script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
