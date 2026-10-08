<?php
/**
 * MoneyLend - Borrower Profile View
 * 
 * Displays complete client information and associated loan activity.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helper.php';

// Protect page
require_login();

$pageTitle = "Borrower Details";
$activePage = "borrowers";
$flash = get_flash();
$pdo = getDBConnection();

$borrowerId = (int)($_GET['id'] ?? 0);

if ($borrowerId <= 0) {
    set_flash('danger', 'Borrower not found.');
    header("Location: " . BASE_URL . "borrowers/index.php");
    exit;
}

try {
    // Fetch borrower details
    $stmt = $pdo->prepare("SELECT * FROM borrowers WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $borrowerId]);
    $borrower = $stmt->fetch();

    if (!$borrower) {
        set_flash('danger', 'Borrower not found.');
        header("Location: " . BASE_URL . "borrowers/index.php");
        exit;
    }

    // Fetch borrower loans
    $loansStmt = $pdo->prepare("SELECT l.*, 
        (SELECT COALESCE(SUM(amount), 0) FROM repayments WHERE loan_id = l.id) AS total_repaid
        FROM loans l 
        WHERE l.borrower_id = :id 
        ORDER BY l.id DESC");
    $loansStmt->execute([':id' => $borrowerId]);
    $loans = $loansStmt->fetchAll();

    // Summary calculations
    $totalPrincipal = 0.0;
    $totalPayable = 0.0;
    $totalRepaid = 0.0;
    $totalOutstanding = 0.0;
    $activeLoansCount = 0;
    $overdueLoansCount = 0;

    foreach ($loans as $l) {
        $totalPrincipal += (float)$l['principal_amount'];
        $totalPayable += (float)$l['total_payable'];
        $totalRepaid += (float)$l['total_repaid'];
        $remBal = (float)$l['remaining_balance'];
        $totalOutstanding += $remBal;
        if (in_array($l['status'], ['active', 'partially_paid', 'overdue'])) {
            $activeLoansCount++;
        }
        if ($l['status'] === 'overdue' || (date('Y-m-d') > $l['due_date'] && $remBal > 0.001)) {
            $overdueLoansCount++;
        }
    }

    $recoveryRate = ($totalPayable > 0) ? min(100.0, round(($totalRepaid / $totalPayable) * 100, 1)) : 0.0;

    // Fetch all repayments for this borrower
    $repStmt = $pdo->prepare("
        SELECT r.*, l.id AS loan_id_ref
        FROM repayments r
        JOIN loans l ON r.loan_id = l.id
        WHERE l.borrower_id = :id
        ORDER BY r.payment_date DESC, r.id DESC
    ");
    $repStmt->execute([':id' => $borrowerId]);
    $repayments = $repStmt->fetchAll();

} catch (PDOException $e) {
    error_log("Borrower view error: " . $e->getMessage());
    set_flash('danger', 'Unable to load borrower details. Please try again.');
    header("Location: " . BASE_URL . "borrowers/index.php");
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
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>borrowers/index.php" class="text-decoration-none">Borrowers</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($borrower['full_name']); ?></li>
                </ol>
            </nav>

            <!-- Page Title -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="page-title"><?php echo htmlspecialchars($borrower['full_name']); ?></h1>
                    <p class="page-subtitle">Client Profile &bull; Borrower ID #<?php echo $borrowerId; ?></p>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="<?php echo BASE_URL; ?>borrowers/statement.php?id=<?php echo $borrowerId; ?>" class="btn btn-outline-dark btn-sm d-flex align-items-center gap-1 shadow-2xs" target="_blank" rel="noopener" title="Print Client Statement" aria-label="Print Client Statement">
                        <i class="fa-solid fa-file-invoice"></i>
                        <span>Statement</span>
                    </a>
                    <?php if ($borrower['status'] === 'active'): ?>
                        <a href="<?php echo BASE_URL; ?>loans/add.php?borrower_id=<?php echo $borrowerId; ?>" class="btn btn-primary btn-sm d-flex align-items-center gap-1" title="Create Loan for this Borrower" aria-label="Create Loan for this Borrower">
                            <i class="fa-solid fa-plus-circle"></i>
                            <span>Create Loan</span>
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo BASE_URL; ?>borrowers/edit.php?id=<?php echo $borrowerId; ?>" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1" title="Edit Client Information" aria-label="Edit Client Information">
                        <i class="fa-solid fa-pen-to-square"></i>
                        <span>Edit Client</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>borrowers/index.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1" title="Back to Borrowers Directory" aria-label="Back to Borrowers Directory">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Directory</span>
                    </a>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <!-- Profile Information Card -->
                <div class="col-12 col-lg-5">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <h2 class="content-card-title">
                                <i class="fa-solid fa-user me-2 text-primary"></i>Client Information
                            </h2>
                            <?php if ($borrower['status'] === 'active'): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary border">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <div class="p-3">
                            <ul class="list-group list-group-flush small">
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Full Legal Name:</span>
                                    <span class="fw-semibold text-dark"><?php echo htmlspecialchars($borrower['full_name']); ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Primary Phone:</span>
                                    <span class="fw-semibold text-dark">
                                        <i class="fa-solid fa-phone me-1 text-muted"></i>
                                        <?php echo htmlspecialchars($borrower['phone']); ?>
                                    </span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Email Address:</span>
                                    <span class="fw-semibold text-dark">
                                        <?php echo !empty($borrower['email']) ? htmlspecialchars($borrower['email']) : '<em class="text-muted">Not specified</em>'; ?>
                                    </span>
                                </li>
                                <li class="list-group-item px-0 py-2">
                                    <span class="text-muted d-block mb-1">Residential Address:</span>
                                    <span class="text-dark">
                                        <?php echo !empty($borrower['address']) ? nl2br(htmlspecialchars($borrower['address'])) : '<em class="text-muted">Not specified</em>'; ?>
                                    </span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Registration Date:</span>
                                    <span class="text-secondary"><?php echo date('M d, Y', strtotime($borrower['created_at'])); ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Last Updated:</span>
                                    <span class="text-secondary"><?php echo date('M d, Y', strtotime($borrower['updated_at'])); ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Financial Portfolio Summary -->
                <div class="col-12 col-lg-7">
                    <div class="row g-3 mb-3">
                        <div class="col-6 col-sm-4">
                            <div class="stat-card py-3">
                                <span class="stat-label">Total Loans</span>
                                <div class="h4 fw-bold text-dark mb-0"><?php echo count($loans); ?></div>
                                <span class="text-muted small"><?php echo $activeLoansCount; ?> active</span>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4">
                            <div class="stat-card py-3">
                                <span class="stat-label">Total Borrowed</span>
                                <div class="h4 fw-bold text-dark mb-0"><?php echo CURRENCY_SYMBOL . number_format($totalPrincipal, 2); ?></div>
                                <span class="text-muted small">Principal capital</span>
                            </div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <div class="stat-card py-3">
                                <span class="stat-label">Total Repaid</span>
                                <div class="h4 fw-bold text-success mb-0"><?php echo CURRENCY_SYMBOL . number_format($totalRepaid, 2); ?></div>
                                <span class="text-muted small">Recovered funds</span>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-6 col-sm-6">
                            <div class="stat-card py-3">
                                <span class="stat-label">Outstanding Balance</span>
                                <div class="h4 fw-bold <?php echo ($totalOutstanding > 0.001) ? 'text-danger' : 'text-success'; ?> mb-0">
                                    <?php echo CURRENCY_SYMBOL . number_format($totalOutstanding, 2); ?>
                                </div>
                                <span class="text-muted small">Payable: <?php echo CURRENCY_SYMBOL . number_format($totalPayable, 2); ?></span>
                            </div>
                        </div>
                        <div class="col-6 col-sm-6">
                            <div class="stat-card py-3">
                                <span class="stat-label">Overdue Portfolio</span>
                                <div class="h4 fw-bold <?php echo ($overdueLoansCount > 0) ? 'text-danger' : 'text-dark'; ?> mb-0">
                                    <?php echo $overdueLoansCount; ?>
                                </div>
                                <span class="small <?php echo ($overdueLoansCount > 0) ? 'text-danger fw-semibold' : 'text-muted'; ?>">
                                    <?php echo ($overdueLoansCount > 0) ? 'Action Required' : 'All loans current'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="p-3 bg-light rounded border">
                                <div class="d-flex justify-content-between small fw-semibold text-muted mb-1">
                                    <span>Client Repayment Health</span>
                                    <span><?php echo $recoveryRate; ?>% Recovered (<?php echo CURRENCY_SYMBOL . number_format($totalRepaid, 2); ?> of <?php echo CURRENCY_SYMBOL . number_format($totalPayable, 2); ?>)</span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar <?php echo ($recoveryRate >= 100) ? 'bg-success' : 'bg-primary'; ?>" 
                                         style="width: <?php echo $recoveryRate; ?>%;" 
                                         role="progressbar" 
                                         aria-valuenow="<?php echo $recoveryRate; ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Associated Loans List -->
                    <div class="content-card">
                        <div class="content-card-header">
                            <h2 class="content-card-title">
                                <i class="fa-solid fa-file-invoice-dollar me-2 text-primary"></i>Loan History
                            </h2>
                            <span class="badge bg-light text-secondary border"><?php echo count($loans); ?> Records</span>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($loans)): ?>
                                <div class="empty-state py-4">
                                    <div class="empty-state-icon" style="width: 44px; height: 44px; font-size: 1.2rem;">
                                        <i class="fa-solid fa-file-circle-xmark"></i>
                                    </div>
                                    <h4 class="empty-state-title fs-6">No loan agreements recorded yet</h4>
                                    <p class="empty-state-text small mb-3">Disburse a new loan agreement to begin tracking repayments for this client.</p>
                                    <?php if ($borrower['status'] === 'active'): ?>
                                        <a href="<?php echo BASE_URL; ?>loans/add.php?borrower_id=<?php echo $borrowerId; ?>" class="btn btn-primary btn-sm">
                                            <i class="fa-solid fa-plus-circle me-1"></i> Create Loan Agreement
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0 small">
                                        <thead class="table-light text-muted">
                                            <tr>
                                                <th class="ps-3">Loan ID</th>
                                                <th>Principal</th>
                                                <th>Interest</th>
                                                <th>Issue Date</th>
                                                <th>Due Date</th>
                                                <th>Status</th>
                                                <th class="text-end pe-3">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($loans as $l): ?>
                                                <tr>
                                                    <td class="ps-3 fw-semibold">
                                                        <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$l['id']; ?>" class="text-dark text-decoration-none" title="View Loan Details">
                                                            #<?php echo (int)$l['id']; ?>
                                                        </a>
                                                    </td>
                                                    <td class="fw-semibold"><?php echo CURRENCY_SYMBOL . number_format((float)$l['principal_amount'], 2); ?></td>
                                                    <td><?php echo number_format((float)$l['interest_rate'], 1); ?>%</td>
                                                    <td><?php echo date('M d, Y', strtotime($l['start_date'])); ?></td>
                                                    <td><?php echo date('M d, Y', strtotime($l['due_date'])); ?></td>
                                                    <td>
                                                        <?php echo get_loan_status_badge($l['status']); ?>
                                                    </td>
                                                    <td class="text-end pe-3">
                                                        <div class="btn-group btn-group-sm" role="group" aria-label="Loan Options">
                                                            <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$l['id']; ?>" class="btn btn-outline-secondary py-0 px-2" title="View Loan Details" aria-label="View Loan #<?php echo (int)$l['id']; ?>">
                                                                <i class="fa-solid fa-eye"></i>
                                                            </a>
                                                            <?php if ($l['status'] !== 'paid' && $l['status'] !== 'cancelled'): ?>
                                                                <a href="<?php echo BASE_URL; ?>repayments/add.php?loan_id=<?php echo (int)$l['id']; ?>" class="btn btn-outline-success py-0 px-2" title="Record Repayment" aria-label="Record Repayment for Loan #<?php echo (int)$l['id']; ?>">
                                                                    <i class="fa-solid fa-money-bill-transfer"></i>
                                                                </a>
                                                            <?php endif; ?>
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

            <!-- Borrower Repayment History Card -->
            <div class="content-card mt-4 mb-4">
                <div class="content-card-header">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-receipt text-success"></i>
                        <h2 class="content-card-title">Borrower Repayment History</h2>
                    </div>
                    <span class="badge bg-light text-secondary border"><?php echo count($repayments); ?> Records</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($repayments)): ?>
                        <div class="empty-state py-4">
                            <div class="empty-state-icon" style="width: 44px; height: 44px; font-size: 1.2rem;">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                            <h3 class="empty-state-title fs-6">No repayment installments recorded</h3>
                            <p class="empty-state-text small mb-0">Payments recorded across all loans for this client will appear here chronologically.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead class="table-light text-muted">
                                    <tr>
                                        <th class="ps-3">Receipt ID</th>
                                        <th>Loan Ref</th>
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
                                            <td>
                                                <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$r['loan_id_ref']; ?>" class="badge bg-light text-dark border text-decoration-none">
                                                    Agreement #<?php echo (int)$r['loan_id_ref']; ?>
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
