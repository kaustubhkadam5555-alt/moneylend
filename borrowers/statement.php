<?php
/**
 * MoneyLend - Borrower Financial Statement (Phase 10)
 *
 * Comprehensive printable ledger statement of all loan disbursements,
 * installment repayments, and running balances for a borrower client.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helper.php';

require_login();

$pageTitle = "Borrower Financial Statement";
$activePage = "borrowers";
$pdo = getDBConnection();

$borrowerId = (int)($_GET['id'] ?? 0);

if ($borrowerId <= 0) {
    set_flash('danger', 'Invalid borrower ID specified.');
    header("Location: " . BASE_URL . "borrowers/index.php");
    exit;
}

try {
    // 1. Fetch Borrower Details
    $stmt = $pdo->prepare("SELECT * FROM borrowers WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $borrowerId]);
    $borrower = $stmt->fetch();

    if (!$borrower) {
        set_flash('danger', 'Borrower not found.');
        header("Location: " . BASE_URL . "borrowers/index.php");
        exit;
    }

    // 2. Fetch all loans for this borrower
    $loanStmt = $pdo->prepare("
        SELECT id, principal_amount, total_payable, amount_repaid, remaining_balance,
               interest_rate, interest_type, start_date, due_date, status
        FROM loans
        WHERE borrower_id = :id
        ORDER BY start_date ASC, id ASC
    ");
    $loanStmt->execute([':id' => $borrowerId]);
    $loans = $loanStmt->fetchAll();

    // 3. Fetch all repayments for this borrower
    $repStmt = $pdo->prepare("
        SELECT r.id, r.loan_id, r.amount, r.payment_date, r.payment_method, r.notes
        FROM repayments r
        JOIN loans l ON r.loan_id = l.id
        WHERE l.borrower_id = :id
        ORDER BY r.payment_date ASC, r.id ASC
    ");
    $repStmt->execute([':id' => $borrowerId]);
    $repayments = $repStmt->fetchAll();

    // 4. Calculate Financial Totals
    $totalPrincipal = 0.0;
    $totalPayable = 0.0;
    $totalRepaid = 0.0;
    $totalOutstanding = 0.0;
    $activeLoansCount = 0;

    foreach ($loans as $l) {
        if ($l['status'] !== 'cancelled') {
            $totalPrincipal += (float)$l['principal_amount'];
            $totalPayable += (float)$l['total_payable'];
            $totalRepaid += (float)$l['amount_repaid'];
            $totalOutstanding += (float)$l['remaining_balance'];
            if (in_array($l['status'], ['active', 'partially_paid', 'overdue'])) {
                $activeLoansCount++;
            }
        }
    }

    // 5. Build Unified Chronological Transaction Ledger
    $ledger = [];

    // Add Loans as Debit Entries
    foreach ($loans as $l) {
        if ($l['status'] !== 'cancelled') {
            $ledger[] = [
                'date'        => $l['start_date'],
                'sort_date'   => $l['start_date'] . '_0_' . $l['id'],
                'type'        => 'Loan Disbursement',
                'ref'         => 'Agreement #' . $l['id'],
                'ref_url'     => BASE_URL . 'loans/view.php?id=' . $l['id'],
                'debit'       => (float)$l['total_payable'],
                'credit'      => 0.0,
                'method'      => 'Disbursed',
                'status'      => $l['status'],
                'notes'       => 'Principal: ' . CURRENCY_SYMBOL . number_format((float)$l['principal_amount'], 2) . ' @ ' . (float)$l['interest_rate'] . '% ' . $l['interest_type']
            ];
        }
    }

    // Add Repayments as Credit Entries
    foreach ($repayments as $r) {
        $ledger[] = [
            'date'        => $r['payment_date'],
            'sort_date'   => $r['payment_date'] . '_1_' . $r['id'],
            'type'        => 'Repayment Installment',
            'ref'         => 'Receipt #' . $r['id'] . ' (Loan #' . $r['loan_id'] . ')',
            'ref_url'     => BASE_URL . 'repayments/view.php?id=' . $r['id'],
            'debit'       => 0.0,
            'credit'      => (float)$r['amount'],
            'method'      => $r['payment_method'],
            'status'      => 'completed',
            'notes'       => !empty($r['notes']) ? $r['notes'] : ''
        ];
    }

    // Sort Chronologically by date ASC
    usort($ledger, function($a, $b) {
        return strcmp($a['sort_date'], $b['sort_date']);
    });

    // Calculate Running Balance
    $runningBalance = 0.0;
    for ($i = 0; $i < count($ledger); $i++) {
        $runningBalance += ($ledger[$i]['debit'] - $ledger[$i]['credit']);
        $ledger[$i]['running_balance'] = max(0.0, round($runningBalance, 2));
    }

} catch (PDOException $e) {
    error_log("Borrower statement error: " . $e->getMessage());
    set_flash('danger', 'An error occurred while generating the financial statement.');
    header("Location: " . BASE_URL . "borrowers/view.php?id=" . $borrowerId);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="content-container">

            <!-- Breadcrumbs (Screen Only) -->
            <nav aria-label="breadcrumb" class="mb-3 d-print-none">
                <ol class="breadcrumb small">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard.php" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>borrowers/index.php" class="text-decoration-none">Borrowers</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo $borrowerId; ?>" class="text-decoration-none"><?php echo htmlspecialchars($borrower['full_name']); ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Statement</li>
                </ol>
            </nav>

            <!-- Top Action Bar (Screen Only) -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4 d-print-none">
                <div>
                    <h1 class="page-title mb-0">Financial Statement</h1>
                    <p class="page-subtitle">Client statement for <?php echo htmlspecialchars($borrower['full_name']); ?> (ID #<?php echo $borrowerId; ?>)</p>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="button" onclick="window.print()" class="btn btn-outline-dark btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-print"></i>
                        <span>Print Statement</span>
                    </button>
                    <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo $borrowerId; ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Back to Profile</span>
                    </a>
                </div>
            </div>

            <!-- Statement Container Card -->
            <div class="content-card receipt-voucher-card mb-4" id="printableStatement">
                <div class="p-4 p-md-5">

                    <!-- Statement Header Banner -->
                    <div class="receipt-header-banner d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <span class="brand-icon-box" style="width: 52px; height: 52px; font-size: 1.5rem;">
                                <i class="fa-solid fa-hand-holding-dollar"></i>
                            </span>
                            <div>
                                <h2 class="h4 fw-bold text-dark mb-0"><?php echo APP_NAME; ?></h2>
                                <span class="text-muted small">Official Borrower Account Statement</span>
                            </div>
                        </div>
                        <div class="text-md-end">
                            <div class="badge bg-light text-dark border px-3 py-2 fs-6 tabular-nums mb-1">
                                STATEMENT #STM-B<?php echo str_pad((string)$borrowerId, 4, '0', STR_PAD_LEFT); ?>
                            </div>
                            <div class="text-muted small">
                                Generated: <strong class="text-dark"><?php echo date('M d, Y h:i A'); ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- Borrower Info Overview & Financial Summary -->
                    <div class="row g-4 mb-4">
                        <!-- Left: Borrower Contact Details -->
                        <div class="col-12 col-md-6">
                            <div class="p-3 bg-light rounded border h-100">
                                <span class="text-muted small text-uppercase fw-semibold d-block mb-2">
                                    <i class="fa-solid fa-user me-1 text-primary"></i> Client Details
                                </span>
                                <div class="fw-bold text-dark fs-6 mb-1"><?php echo htmlspecialchars($borrower['full_name']); ?></div>
                                <div class="small text-secondary mb-1">
                                    <i class="fa-solid fa-phone me-1 text-muted"></i><?php echo htmlspecialchars($borrower['phone']); ?>
                                </div>
                                <?php if (!empty($borrower['email'])): ?>
                                    <div class="small text-secondary mb-1">
                                        <i class="fa-solid fa-envelope me-1 text-muted"></i><?php echo htmlspecialchars($borrower['email']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($borrower['address'])): ?>
                                    <div class="small text-muted mt-2">
                                        <i class="fa-solid fa-location-dot me-1"></i><?php echo nl2br(htmlspecialchars($borrower['address'])); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="mt-2">
                                    Account Status: 
                                    <?php if ($borrower['status'] === 'active'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Ledger Summary Card -->
                        <div class="col-12 col-md-6">
                            <div class="p-3 bg-light rounded border h-100">
                                <span class="text-muted small text-uppercase fw-semibold d-block mb-2">
                                    <i class="fa-solid fa-scale-balanced me-1 text-primary"></i> Financial Position Summary
                                </span>
                                <div class="d-flex justify-content-between small py-1 border-bottom">
                                    <span class="text-muted">Total Agreements:</span>
                                    <span class="fw-semibold text-dark"><?php echo count($loans); ?> (<?php echo $activeLoansCount; ?> active)</span>
                                </div>
                                <div class="d-flex justify-content-between small py-1 border-bottom">
                                    <span class="text-muted">Principal Disbursed:</span>
                                    <span class="fw-semibold text-dark tabular-nums"><?php echo CURRENCY_SYMBOL . number_format($totalPrincipal, 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between small py-1 border-bottom">
                                    <span class="text-muted">Total Repayable:</span>
                                    <span class="fw-semibold text-dark tabular-nums"><?php echo CURRENCY_SYMBOL . number_format($totalPayable, 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between small py-1 border-bottom">
                                    <span class="text-muted">Total Repaid to Date:</span>
                                    <span class="fw-bold text-success tabular-nums"><?php echo CURRENCY_SYMBOL . number_format($totalRepaid, 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between small py-2">
                                    <span class="fw-bold text-dark">Current Net Outstanding:</span>
                                    <span class="fw-bold fs-6 tabular-nums <?php echo ($totalOutstanding > 0) ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo CURRENCY_SYMBOL . number_format($totalOutstanding, 2); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Chronological Transaction Ledger -->
                    <div class="card border mb-4">
                        <div class="card-header bg-light py-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <h3 class="h6 fw-bold text-dark mb-0">
                                    <i class="fa-solid fa-list-ol text-primary me-2"></i>Account Transaction Ledger
                                </h3>
                                <span class="badge bg-light text-secondary border"><?php echo count($ledger); ?> Transactions</span>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <?php if (empty($ledger)): ?>
                                <div class="empty-state py-4 text-center">
                                    <div class="empty-state-icon">
                                        <i class="fa-solid fa-file-invoice"></i>
                                    </div>
                                    <h4 class="empty-state-title fs-6">No financial transactions recorded</h4>
                                    <p class="empty-state-text small mb-0">Disbursed loans and repayments will appear in this ledger.</p>
                                </div>
                            <?php else: ?>
                                <table class="table table-bordered mb-0 align-middle small">
                                    <thead class="table-light text-muted text-uppercase">
                                        <tr>
                                            <th class="ps-3" style="width: 120px;">Date</th>
                                            <th>Transaction Details</th>
                                            <th style="width: 130px;">Method</th>
                                            <th class="text-end" style="width: 140px;">Debit (Lent)</th>
                                            <th class="text-end" style="width: 140px;">Credit (Repaid)</th>
                                            <th class="text-end pe-3" style="width: 150px;">Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ledger as $entry): ?>
                                            <tr>
                                                <td class="ps-3 text-secondary text-nowrap">
                                                    <?php echo date('M d, Y', strtotime($entry['date'])); ?>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold text-dark">
                                                        <?php echo htmlspecialchars($entry['type']); ?>
                                                        <span class="badge bg-light text-primary border ms-1" style="font-size: 0.68rem;">
                                                            <?php echo htmlspecialchars($entry['ref']); ?>
                                                        </span>
                                                    </div>
                                                    <?php if (!empty($entry['notes'])): ?>
                                                        <div class="text-muted" style="font-size: 0.75rem;">
                                                            <?php echo htmlspecialchars($entry['notes']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-secondary border">
                                                        <?php echo htmlspecialchars($entry['method']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end tabular-nums">
                                                    <?php if ($entry['debit'] > 0): ?>
                                                        <span class="fw-semibold text-danger">
                                                            +<?php echo CURRENCY_SYMBOL . number_format($entry['debit'], 2); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end tabular-nums">
                                                    <?php if ($entry['credit'] > 0): ?>
                                                        <span class="fw-bold text-success">
                                                            -<?php echo CURRENCY_SYMBOL . number_format($entry['credit'], 2); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-3 fw-bold tabular-nums <?php echo ($entry['running_balance'] > 0) ? 'text-dark' : 'text-success'; ?>">
                                                    <?php echo CURRENCY_SYMBOL . number_format($entry['running_balance'], 2); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light fw-bold">
                                        <tr>
                                            <td colspan="3" class="ps-3 text-uppercase">Totals</td>
                                            <td class="text-end text-danger tabular-nums">
                                                <?php echo CURRENCY_SYMBOL . number_format($totalPayable, 2); ?>
                                            </td>
                                            <td class="text-end text-success tabular-nums">
                                                -<?php echo CURRENCY_SYMBOL . number_format($totalRepaid, 2); ?>
                                            </td>
                                            <td class="text-end pe-3 tabular-nums <?php echo ($totalOutstanding > 0) ? 'text-danger' : 'text-success'; ?>">
                                                <?php echo CURRENCY_SYMBOL . number_format($totalOutstanding, 2); ?>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Signatures / Footer for Printable Statement -->
                    <div class="row pt-4 mt-4 border-top">
                        <div class="col-6">
                            <div class="text-muted small mb-4">Borrower Acknowledgment</div>
                            <div class="border-top pt-1 text-muted small" style="width: 200px;">
                                Signature of <?php echo htmlspecialchars($borrower['full_name']); ?>
                            </div>
                        </div>
                        <div class="col-6 text-end">
                            <div class="text-muted small mb-4">Authorized Verification</div>
                            <div class="border-top pt-1 text-muted small ms-auto" style="width: 200px;">
                                For <?php echo APP_NAME; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        </div> <!-- End .content-container -->
    </main>
</div> <!-- End .app-wrapper -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
