<?php
/**
 * MoneyLend - Loans Directory
 * 
 * Lists all loan contracts with summary metrics, search, status filters,
 * and repayment status indicators.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helper.php';

// Protect page
require_login();

$pageTitle = "Loans";
$activePage = "loans";
$flash = get_flash();
$pdo = getDBConnection();

// Filters & Search
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$query = "SELECT l.*, b.full_name AS borrower_name, b.phone AS borrower_phone, b.status AS borrower_status
          FROM loans l 
          JOIN borrowers b ON l.borrower_id = b.id 
          WHERE 1=1";
$params = [];

if ($search !== '') {
    if (is_numeric($search)) {
        $query .= " AND (l.id = :search_id OR b.full_name LIKE :search_name OR b.phone LIKE :search_phone)";
        $params[':search_id'] = (int)$search;
        $params[':search_name'] = "%{$search}%";
        $params[':search_phone'] = "%{$search}%";
    } else {
        $query .= " AND (b.full_name LIKE :search_name OR b.phone LIKE :search_phone)";
        $params[':search_name'] = "%{$search}%";
        $params[':search_phone'] = "%{$search}%";
    }
}

if (!empty($statusFilter)) {
    $allowedStatuses = ['active', 'partially_paid', 'paid', 'overdue', 'cancelled'];
    if (in_array($statusFilter, $allowedStatuses)) {
        $query .= " AND l.status = :status";
        $params[':status'] = $statusFilter;
    }
}

$query .= " ORDER BY l.id DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $loans = $stmt->fetchAll();

    // Re-evaluate automated status on the fly if needed
    foreach ($loans as &$loan) {
        $calculatedStatus = determine_loan_status(
            $loan['status'],
            (float)$loan['remaining_balance'],
            (float)$loan['amount_repaid'],
            $loan['due_date']
        );
        if ($calculatedStatus !== $loan['status']) {
            $upd = $pdo->prepare("UPDATE loans SET status = :st WHERE id = :id");
            $upd->execute([':st' => $calculatedStatus, ':id' => $loan['id']]);
            $loan['status'] = $calculatedStatus;
        }
    }
    unset($loan);

    // Summary Metrics
    $statStmt = $pdo->query("SELECT 
        COUNT(*) AS total_loans,
        COUNT(CASE WHEN status IN ('active', 'partially_paid') THEN 1 END) AS active_loans,
        COALESCE(SUM(principal_amount), 0) AS total_principal,
        COALESCE(SUM(remaining_balance), 0) AS total_outstanding
        FROM loans");
    $stats = $statStmt->fetch();

} catch (PDOException $e) {
    error_log("Loans list error: " . $e->getMessage());
    $loans = [];
    $stats = ['total_loans' => 0, 'active_loans' => 0, 'total_principal' => 0, 'total_outstanding' => 0];
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
                    <h1 class="page-title">Loans</h1>
                    <p class="page-subtitle">Manage lending portfolio, active agreements, and repayments</p>
                </div>
                <div>
                    <a href="<?php echo BASE_URL; ?>loans/add.php" class="btn btn-primary quick-action-btn">
                        <i class="fa-solid fa-plus-circle"></i>
                        <span>Create Loan</span>
                    </a>
                </div>
            </div>

            <!-- Summary Cards Grid -->
            <div class="row g-3 g-xl-4 mb-4">
                <!-- Total Loans -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Total Loans</span>
                            <div class="stat-icon-wrapper stat-icon-emerald">
                                <i class="fa-solid fa-folder-open"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo (int)($stats['total_loans'] ?? 0); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-file-contract me-1"></i> Issued contracts
                        </div>
                    </div>
                </div>

                <!-- Active Loans -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Active Loans</span>
                            <div class="stat-icon-wrapper stat-icon-blue">
                                <i class="fa-solid fa-file-invoice-dollar"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo (int)($stats['active_loans'] ?? 0); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-clock me-1"></i> In-progress loans
                        </div>
                    </div>
                </div>

                <!-- Total Principal -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Total Principal</span>
                            <div class="stat-icon-wrapper stat-icon-teal">
                                <i class="fa-solid fa-hand-holding-dollar"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo CURRENCY_SYMBOL . number_format((float)($stats['total_principal'] ?? 0), 2); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-coins me-1"></i> Capital disbursed
                        </div>
                    </div>
                </div>

                <!-- Outstanding Balance -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Outstanding Balance</span>
                            <div class="stat-icon-wrapper stat-icon-amber">
                                <i class="fa-solid fa-scale-unbalanced"></i>
                            </div>
                        </div>
                        <div class="stat-value text-danger-emphasis"><?php echo CURRENCY_SYMBOL . number_format((float)($stats['total_outstanding'] ?? 0), 2); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-arrow-down-long me-1"></i> Pending recovery
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search & Filters Card -->
            <div class="content-card mb-4">
                <div class="p-3">
                    <form method="GET" action="<?php echo BASE_URL; ?>loans/index.php" class="row g-2 align-items-center">
                        <div class="col-12 col-md-6 col-lg-7">
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted border-end-0">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                </span>
                                <input 
                                    type="text" 
                                    name="search" 
                                    class="form-control border-start-0 ps-0" 
                                    placeholder="Search by borrower name, phone, or loan ID..." 
                                    value="<?php echo htmlspecialchars($search); ?>"
                                >
                            </div>
                        </div>
                        <div class="col-12 col-md-3 col-lg-3">
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="partially_paid" <?php echo ($statusFilter === 'partially_paid') ? 'selected' : ''; ?>>Partially Paid</option>
                                <option value="paid" <?php echo ($statusFilter === 'paid') ? 'selected' : ''; ?>>Paid</option>
                                <option value="overdue" <?php echo ($statusFilter === 'overdue') ? 'selected' : ''; ?>>Overdue</option>
                                <option value="cancelled" <?php echo ($statusFilter === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3 col-lg-2 d-flex gap-2">
                            <button type="submit" class="btn btn-secondary flex-grow-1">
                                Filter
                            </button>
                            <?php if ($search !== '' || $statusFilter !== ''): ?>
                                <a href="<?php echo BASE_URL; ?>loans/index.php" class="btn btn-outline-secondary" title="Clear Filters">
                                    <i class="fa-solid fa-rotate-left"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Loans Table Card -->
            <div class="content-card">
                <div class="content-card-header">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-file-invoice-dollar text-primary"></i>
                        <h2 class="content-card-title">Loan Agreements</h2>
                    </div>
                    <span class="badge bg-light text-secondary border">
                        Showing <?php echo count($loans); ?> Agreement<?php echo count($loans) === 1 ? '' : 's'; ?>
                    </span>
                </div>

                <div class="card-body p-0">
                    <?php if (empty($loans)): ?>
                        <!-- Empty State -->
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fa-solid fa-file-circle-xmark"></i>
                            </div>
                            <?php if ($search !== '' || $statusFilter !== ''): ?>
                                <h3 class="empty-state-title">No loans match your search</h3>
                                <p class="empty-state-text mb-3">Try adjusting your search criteria or reset the filters to view all records.</p>
                                <a href="<?php echo BASE_URL; ?>loans/index.php" class="btn btn-outline-secondary btn-sm">Reset Filters</a>
                            <?php else: ?>
                                <h3 class="empty-state-title">No loan records available yet</h3>
                                <p class="empty-state-text mb-3">Create your first loan agreement to start tracking disbursements and installments.</p>
                                <a href="<?php echo BASE_URL; ?>loans/add.php" class="btn btn-primary btn-sm">
                                    <i class="fa-solid fa-plus-circle me-1"></i> Create First Loan
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light text-muted small text-uppercase">
                                    <tr>
                                        <th scope="col" class="ps-3" style="width: 70px;">ID</th>
                                        <th scope="col">Borrower</th>
                                        <th scope="col">Principal</th>
                                        <th scope="col">Interest</th>
                                        <th scope="col">Total Payable</th>
                                        <th scope="col">Repaid</th>
                                        <th scope="col">Remaining</th>
                                        <th scope="col">Start Date</th>
                                        <th scope="col">Due Date</th>
                                        <th scope="col">Status</th>
                                        <th scope="col" class="text-end pe-3" style="width: 140px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($loans as $l): ?>
                                        <tr>
                                            <td class="ps-3 text-muted small fw-semibold">
                                                #<?php echo (int)$l['id']; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="navbar-user-avatar" style="width: 32px; height: 32px; font-size: 0.8rem; background-color: #ecfdf5; color: #059669;">
                                                        <?php echo htmlspecialchars(strtoupper(substr($l['borrower_name'], 0, 1))); ?>
                                                    </div>
                                                    <div>
                                                        <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$l['id']; ?>" class="fw-semibold text-dark text-decoration-none">
                                                            <?php echo htmlspecialchars($l['borrower_name']); ?>
                                                        </a>
                                                        <div class="text-muted" style="font-size: 0.75rem;">
                                                            <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($l['borrower_phone']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="fw-semibold text-dark">
                                                <?php echo CURRENCY_SYMBOL . number_format((float)$l['principal_amount'], 2); ?>
                                            </td>
                                            <td>
                                                <span class="small text-secondary">
                                                    <?php echo number_format((float)$l['interest_rate'], 1); ?>%
                                                    <span class="badge bg-light text-muted border text-uppercase" style="font-size: 0.65rem;">
                                                        <?php echo htmlspecialchars($l['interest_type']); ?>
                                                    </span>
                                                </span>
                                            </td>
                                            <td class="fw-semibold text-secondary">
                                                <?php echo CURRENCY_SYMBOL . number_format((float)$l['total_payable'], 2); ?>
                                            </td>
                                            <td class="text-success small fw-semibold">
                                                <?php echo CURRENCY_SYMBOL . number_format((float)$l['amount_repaid'], 2); ?>
                                            </td>
                                            <td class="small fw-bold <?php echo ((float)$l['remaining_balance'] > 0) ? 'text-danger' : 'text-muted'; ?>">
                                                <?php echo CURRENCY_SYMBOL . number_format((float)$l['remaining_balance'], 2); ?>
                                            </td>
                                            <td class="text-muted small">
                                                <?php echo date('M d, Y', strtotime($l['start_date'])); ?>
                                            </td>
                                            <td class="small <?php echo (date('Y-m-d') > $l['due_date'] && (float)$l['remaining_balance'] > 0) ? 'text-danger fw-semibold' : 'text-muted'; ?>">
                                                <?php echo date('M d, Y', strtotime($l['due_date'])); ?>
                                            </td>
                                            <td>
                                                <?php echo get_loan_status_badge($l['status']); ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <div class="btn-group btn-group-sm" role="group" aria-label="Loan Actions">
                                                    <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$l['id']; ?>" class="btn btn-outline-secondary" title="View Loan Details" aria-label="View Loan #<?php echo (int)$l['id']; ?>">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </a>
                                                    <a href="<?php echo BASE_URL; ?>loans/edit.php?id=<?php echo (int)$l['id']; ?>" class="btn btn-outline-primary" title="Edit Loan Agreement" aria-label="Edit Loan #<?php echo (int)$l['id']; ?>">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </a>
                                                    <button 
                                                        type="button" 
                                                        class="btn btn-outline-danger" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#deleteLoanModal" 
                                                        data-loan-id="<?php echo (int)$l['id']; ?>" 
                                                        data-borrower-name="<?php echo htmlspecialchars($l['borrower_name']); ?>"
                                                        data-principal="<?php echo CURRENCY_SYMBOL . number_format((float)$l['principal_amount'], 2); ?>"
                                                        data-repaid="<?php echo (float)$l['amount_repaid']; ?>"
                                                        data-status="<?php echo htmlspecialchars($l['status']); ?>"
                                                        title="Delete or Cancel Loan"
                                                        aria-label="Delete or Cancel Loan #<?php echo (int)$l['id']; ?>"
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

        <!-- Delete / Cancel Loan Modal -->
        <div class="modal fade" id="deleteLoanModal" tabindex="-1" aria-labelledby="deleteLoanModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="POST" action="<?php echo BASE_URL; ?>loans/delete.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="loan_id" id="modalLoanId" value="">
                        <input type="hidden" name="action_type" id="modalActionType" value="delete">

                        <div class="modal-header border-bottom">
                            <h5 class="modal-title fw-bold text-danger" id="deleteLoanModalLabel">
                                <i class="fa-solid fa-triangle-exclamation me-2"></i><span id="modalTitleText">Confirm Deletion</span>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body py-4">
                            <p class="mb-2">Loan Agreement: <strong id="modalLoanText" class="text-dark"></strong></p>

                            <!-- Notice when repayments exist -->
                            <div id="modalRepaymentsNotice" class="alert alert-warning small border-0 mb-0 d-none">
                                <i class="fa-solid fa-shield-halved me-1"></i>
                                <strong>Financial Audit Safeguard:</strong> This loan has recorded repayments (<span id="modalRepaidAmount"></span>). To protect financial records from deletion, this loan cannot be permanently destroyed. You may mark this loan as <strong>Cancelled</strong> instead.
                            </div>

                            <!-- Notice when no repayments exist -->
                            <div id="modalSafeDeleteNotice" class="alert alert-danger-subtle small border-0 mb-0 d-none">
                                <i class="fa-solid fa-circle-exclamation me-1"></i>
                                This loan has no repayments logged. Confirming will permanently remove this agreement from the system.
                            </div>
                        </div>
                        <div class="modal-footer border-top bg-light">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-danger btn-sm" id="modalSubmitBtn">
                                <i class="fa-solid fa-trash-can me-1"></i> <span id="modalBtnText">Delete Loan</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const deleteModal = document.getElementById('deleteLoanModal');
            if (deleteModal) {
                deleteModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const loanId = button.getAttribute('data-loan-id');
                    const borrowerName = button.getAttribute('data-borrower-name');
                    const principal = button.getAttribute('data-principal');
                    const repaid = parseFloat(button.getAttribute('data-repaid') || '0');
                    const status = button.getAttribute('data-status');

                    document.getElementById('modalLoanId').value = loanId;
                    document.getElementById('modalLoanText').textContent = '#' + loanId + ' — ' + borrowerName + ' (' + principal + ')';

                    const repaymentsNotice = document.getElementById('modalRepaymentsNotice');
                    const safeDeleteNotice = document.getElementById('modalSafeDeleteNotice');
                    const submitBtn = document.getElementById('modalSubmitBtn');
                    const btnText = document.getElementById('modalBtnText');
                    const titleText = document.getElementById('modalTitleText');
                    const actionType = document.getElementById('modalActionType');
                    const repaidSpan = document.getElementById('modalRepaidAmount');

                    if (repaid > 0 || status === 'paid') {
                        repaymentsNotice.classList.remove('d-none');
                        safeDeleteNotice.classList.add('d-none');
                        repaidSpan.textContent = '₹' + repaid.toFixed(2);
                        actionType.value = 'cancel';
                        titleText.textContent = 'Cancel Loan Agreement';
                        btnText.textContent = 'Cancel Loan';
                        submitBtn.className = 'btn btn-warning btn-sm';
                    } else {
                        repaymentsNotice.classList.add('d-none');
                        safeDeleteNotice.classList.remove('d-none');
                        actionType.value = 'delete';
                        titleText.textContent = 'Delete Loan Agreement';
                        btnText.textContent = 'Delete Loan';
                        submitBtn.className = 'btn btn-danger btn-sm';
                    }
                });
            }
        });
        </script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
