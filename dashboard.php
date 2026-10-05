<?php
/**
 * MoneyLend - Dashboard
 * 
 * Main administrative dashboard displaying high-level financial metrics,
 * quick actions, recent loans, and recent repayments.
 * Phase 1: Foundation & UI Shell.
 */

// Application configuration and session handling
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

// In Phase 1, ensure user session exists or initialize a clean demo session for viewing
if (!is_logged_in()) {
    // If not authenticated, redirect to login page as required by auth structure
    header("Location: " . BASE_URL . "login.php");
    exit;
}

$pageTitle = "Dashboard";
$activePage = "dashboard";
$flash = get_flash();

// Placeholder Metrics for Phase 1
$stats = [
    'total_borrowers'     => 0,
    'active_loans'        => 0,
    'total_amount_lent'   => CURRENCY_SYMBOL . '0',
    'total_amount_repaid' => CURRENCY_SYMBOL . '0',
];

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
                    <i class="fa-solid fa-circle-check me-2"></i>
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
                    <button type="button" class="btn btn-primary quick-action-btn" data-bs-toggle="modal" data-bs-target="#quickActionNoticeModal" data-action="Add Borrower">
                        <i class="fa-solid fa-user-plus"></i>
                        <span>Add Borrower</span>
                    </button>
                    <button type="button" class="btn btn-outline-primary quick-action-btn" data-bs-toggle="modal" data-bs-target="#quickActionNoticeModal" data-action="Create Loan">
                        <i class="fa-solid fa-plus-circle"></i>
                        <span>Create Loan</span>
                    </button>
                    <button type="button" class="btn btn-outline-success quick-action-btn" data-bs-toggle="modal" data-bs-target="#quickActionNoticeModal" data-action="Record Repayment">
                        <i class="fa-solid fa-money-bill-transfer"></i>
                        <span>Record Repayment</span>
                    </button>
                </div>
            </div>

            <!-- Statistic Cards Grid -->
            <div class="row g-3 g-xl-4 mb-4">
                <!-- Stat 1: Total Borrowers -->
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
                            <i class="fa-solid fa-circle-info me-1"></i> Registered clients
                        </div>
                    </div>
                </div>

                <!-- Stat 2: Active Loans -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Active Loans</span>
                            <div class="stat-icon-wrapper stat-icon-blue">
                                <i class="fa-solid fa-file-invoice-dollar"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo $stats['active_loans']; ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-chart-pie me-1"></i> Ongoing loan agreements
                        </div>
                    </div>
                </div>

                <!-- Stat 3: Total Amount Lent -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Total Amount Lent</span>
                            <div class="stat-icon-wrapper stat-icon-teal">
                                <i class="fa-solid fa-hand-holding-dollar"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_amount_lent']; ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-arrow-trend-up me-1"></i> Cumulative principal
                        </div>
                    </div>
                </div>

                <!-- Stat 4: Total Amount Repaid -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Total Amount Repaid</span>
                            <div class="stat-icon-wrapper stat-icon-amber">
                                <i class="fa-solid fa-piggy-bank"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_amount_repaid']; ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-shield-halved me-1"></i> Recovered funds
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
                        </div>
                    </div>
                </div>

                <!-- Recent Repayments -->
                <div class="col-12 col-lg-6">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <h2 class="content-card-title">
                                <i class="fa-solid fa-receipt me-2 text-success"></i>Recent Repayments
                            </h2>
                            <span class="badge bg-light text-secondary border">Latest 5</span>
                        </div>
                        <div class="card-body p-0">
                            <!-- Clean Empty State -->
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fa-solid fa-receipt"></i>
                                </div>
                                <h3 class="empty-state-title">No repayment records available yet.</h3>
                                <p class="empty-state-text">
                                    Payments recorded from borrowers will be logged here with date, amount, and payment method.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div> <!-- End .content-container -->

        <!-- Quick Action Placeholder Modal -->
        <div class="modal fade" id="quickActionNoticeModal" tabindex="-1" aria-labelledby="quickActionModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header border-bottom">
                        <h5 class="modal-title fw-bold text-dark" id="quickActionModalLabel">
                            <i class="fa-solid fa-circle-info text-primary me-2"></i>Quick Action
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body py-4 text-center">
                        <div class="mb-3">
                            <span class="brand-icon-box" style="width: 48px; height: 48px; font-size: 1.4rem;">
                                <i class="fa-solid fa-layer-group"></i>
                            </span>
                        </div>
                        <h5 class="fw-semibold text-dark mb-2">Phase 1 — UI Shell Active</h5>
                        <p class="text-muted small mb-0 px-3">
                            The action handler for this feature will be connected in <strong>Phase 2</strong> with complete database operations.
                        </p>
                    </div>
                    <div class="modal-footer border-top bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

<?php
// Include reusable page footer
require_once __DIR__ . '/includes/footer.php';
?>
