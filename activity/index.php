<?php
/**
 * MoneyLend - Activity & Audit Trail (Phase 10)
 *
 * Provides complete visibility into system operations: logins, loans,
 * repayments, borrower changes, and settings updates.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activity_helper.php';

require_login();

$pageTitle = "Activity & Audit Log";
$activePage = "activity";
$pdo = getDBConnection();

// Filters & Pagination parameters
$actionFilter = trim($_GET['action'] ?? '');
$entityFilter = trim($_GET['entity'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Metric counts
$totalLogs = 0;
$todayLogs = 0;
$financialLogs = 0;
$authLogs = 0;

try {
    $totalLogs = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
    $todayLogs = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $financialLogs = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE entity_type IN ('loan', 'repayment')")->fetchColumn();
    $authLogs = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action IN ('login', 'login_failed', 'logout', 'password_change', 'user_create', 'user_update', 'user_activate', 'user_deactivate', 'user_role_change', 'user_password_reset', 'security_settings_update')")->fetchColumn();

    $filteredTotal = get_activity_count($pdo, $actionFilter ?: null, $entityFilter ?: null);
    $totalPages = max(1, (int)ceil($filteredTotal / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $activities = get_recent_activities($pdo, $perPage, $offset, $actionFilter ?: null, $entityFilter ?: null);

} catch (PDOException $e) {
    error_log("Activity page error: " . $e->getMessage());
    $activities = [];
    $filteredTotal = 0;
    $totalPages = 1;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="content-container">

            <!-- Breadcrumb Navigation -->
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb small">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard.php" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Activity Log</li>
                </ol>
            </nav>

            <!-- Page Header -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="page-title">Activity &amp; Audit Trail</h1>
                    <p class="page-subtitle">Chronological record of system actions, updates, and transactions</p>
                </div>
                <div>
                    <a href="<?php echo BASE_URL; ?>dashboard.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Back to Dashboard</span>
                    </a>
                </div>
            </div>

            <!-- Statistic Cards Grid -->
            <div class="row g-3 g-xl-4 mb-4">
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Total Logged Events</span>
                            <div class="stat-icon-wrapper stat-icon-blue">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </div>
                        </div>
                        <div class="stat-value tabular-nums"><?php echo number_format($totalLogs); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-shield-halved me-1"></i> Audit history records
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Events Today</span>
                            <div class="stat-icon-wrapper stat-icon-emerald">
                                <i class="fa-solid fa-calendar-day"></i>
                            </div>
                        </div>
                        <div class="stat-value tabular-nums text-success"><?php echo number_format($todayLogs); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-circle-check me-1"></i> Logged for <?php echo date('M d, Y'); ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Financial Activity</span>
                            <div class="stat-icon-wrapper stat-icon-teal">
                                <i class="fa-solid fa-file-invoice-dollar"></i>
                            </div>
                        </div>
                        <div class="stat-value tabular-nums"><?php echo number_format($financialLogs); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-coins me-1"></i> Loan &amp; repayment operations
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="stat-label">Security &amp; Auth</span>
                            <div class="stat-icon-wrapper stat-icon-amber">
                                <i class="fa-solid fa-user-shield"></i>
                            </div>
                        </div>
                        <div class="stat-value tabular-nums"><?php echo number_format($authLogs); ?></div>
                        <div class="stat-footer-text">
                            <i class="fa-solid fa-key me-1"></i> Sign-ins &amp; credentials
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search & Filters Card -->
            <div class="content-card mb-4">
                <div class="p-3">
                    <form method="GET" action="<?php echo BASE_URL; ?>activity/" class="row g-2 align-items-center">
                        <div class="col-12 col-md-5 col-lg-5">
                            <label for="action" class="visually-hidden">Action Filter</label>
                            <select name="action" id="action" class="form-select">
                                <option value="">All Actions</option>
                                <option value="login" <?php echo ($actionFilter === 'login') ? 'selected' : ''; ?>>Login</option>
                                <option value="login_failed" <?php echo ($actionFilter === 'login_failed') ? 'selected' : ''; ?>>Failed Login</option>
                                <option value="logout" <?php echo ($actionFilter === 'logout') ? 'selected' : ''; ?>>Logout</option>
                                <option value="user_create" <?php echo ($actionFilter === 'user_create') ? 'selected' : ''; ?>>User Created</option>
                                <option value="user_update" <?php echo ($actionFilter === 'user_update') ? 'selected' : ''; ?>>User Updated</option>
                                <option value="user_activate" <?php echo ($actionFilter === 'user_activate') ? 'selected' : ''; ?>>User Activated</option>
                                <option value="user_deactivate" <?php echo ($actionFilter === 'user_deactivate') ? 'selected' : ''; ?>>User Deactivated</option>
                                <option value="user_role_change" <?php echo ($actionFilter === 'user_role_change') ? 'selected' : ''; ?>>Role Changed</option>
                                <option value="user_password_reset" <?php echo ($actionFilter === 'user_password_reset') ? 'selected' : ''; ?>>Password Reset</option>
                                <option value="borrower_create" <?php echo ($actionFilter === 'borrower_create') ? 'selected' : ''; ?>>Borrower Added</option>
                                <option value="borrower_update" <?php echo ($actionFilter === 'borrower_update') ? 'selected' : ''; ?>>Borrower Updated</option>
                                <option value="borrower_delete" <?php echo ($actionFilter === 'borrower_delete') ? 'selected' : ''; ?>>Borrower Deleted</option>
                                <option value="loan_create" <?php echo ($actionFilter === 'loan_create') ? 'selected' : ''; ?>>Loan Created</option>
                                <option value="loan_update" <?php echo ($actionFilter === 'loan_update') ? 'selected' : ''; ?>>Loan Updated</option>
                                <option value="loan_cancel" <?php echo ($actionFilter === 'loan_cancel') ? 'selected' : ''; ?>>Loan Cancelled</option>
                                <option value="repayment_create" <?php echo ($actionFilter === 'repayment_create') ? 'selected' : ''; ?>>Repayment Recorded</option>
                                <option value="repayment_update" <?php echo ($actionFilter === 'repayment_update') ? 'selected' : ''; ?>>Repayment Updated</option>
                                <option value="repayment_delete" <?php echo ($actionFilter === 'repayment_delete') ? 'selected' : ''; ?>>Repayment Deleted</option>
                                <option value="settings_update" <?php echo ($actionFilter === 'settings_update') ? 'selected' : ''; ?>>Settings Updated</option>
                                <option value="security_settings_update" <?php echo ($actionFilter === 'security_settings_update') ? 'selected' : ''; ?>>Security Config Updated</option>
                                <option value="password_change" <?php echo ($actionFilter === 'password_change') ? 'selected' : ''; ?>>Password Changed</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-4 col-lg-4">
                            <label for="entity" class="visually-hidden">Entity Filter</label>
                            <select name="entity" id="entity" class="form-select">
                                <option value="">All Module Entities</option>
                                <option value="borrower" <?php echo ($entityFilter === 'borrower') ? 'selected' : ''; ?>>Borrowers</option>
                                <option value="loan" <?php echo ($entityFilter === 'loan') ? 'selected' : ''; ?>>Loans</option>
                                <option value="repayment" <?php echo ($entityFilter === 'repayment') ? 'selected' : ''; ?>>Repayments</option>
                                <option value="settings" <?php echo ($entityFilter === 'settings') ? 'selected' : ''; ?>>Settings</option>
                                <option value="user" <?php echo ($entityFilter === 'user') ? 'selected' : ''; ?>>User / Auth</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-3 col-lg-3 d-flex gap-2">
                            <button type="submit" class="btn btn-secondary flex-grow-1">
                                <i class="fa-solid fa-filter me-1"></i> Filter
                            </button>
                            <?php if ($actionFilter !== '' || $entityFilter !== ''): ?>
                                <a href="<?php echo BASE_URL; ?>activity/" class="btn btn-outline-secondary" title="Clear Filters" aria-label="Clear Filters">
                                    <i class="fa-solid fa-rotate-left"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Activity Logs Table Card -->
            <div class="content-card mb-4">
                <div class="content-card-header">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-list-check text-primary"></i>
                        <h2 class="content-card-title">Event Log Ledger</h2>
                    </div>
                    <span class="badge bg-light text-secondary border">
                        Showing <?php echo count($activities); ?> of <?php echo $filteredTotal; ?> Events
                    </span>
                </div>

                <div class="card-body p-0">
                    <?php if (empty($activities)): ?>
                        <div class="empty-state py-5 text-center">
                            <div class="empty-state-icon">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </div>
                            <?php if ($actionFilter !== '' || $entityFilter !== ''): ?>
                                <h3 class="empty-state-title">No activity logs match your filter</h3>
                                <p class="empty-state-text mb-3">Try adjusting your filters or reset to view all system events.</p>
                                <a href="<?php echo BASE_URL; ?>activity/" class="btn btn-outline-secondary btn-sm">Reset Filter</a>
                            <?php else: ?>
                                <h3 class="empty-state-title">No activity logged yet</h3>
                                <p class="empty-state-text mb-0">System events such as logins, loan agreements, and repayments will be audited here automatically.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead class="table-light text-muted text-uppercase">
                                    <tr>
                                        <th class="ps-3" style="width: 170px;">Date &amp; Time</th>
                                        <th style="width: 170px;">Action</th>
                                        <th>Entity Reference</th>
                                        <th>Description</th>
                                        <th class="text-end pe-3" style="width: 180px;">User</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activities as $log): 
                                        $badge = get_activity_badge_info($log['action']);
                                    ?>
                                        <tr>
                                            <td class="ps-3 text-secondary text-nowrap">
                                                <i class="fa-regular fa-clock me-1 text-muted"></i>
                                                <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $badge['badge']; ?> px-2 py-1">
                                                    <i class="fa-solid <?php echo $badge['icon']; ?> me-1"></i>
                                                    <?php echo htmlspecialchars($badge['label']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($log['entity_type'])): ?>
                                                    <span class="text-capitalize fw-semibold text-dark">
                                                        <?php echo htmlspecialchars($log['entity_type']); ?>
                                                    </span>
                                                    <?php if (!empty($log['entity_id'])): ?>
                                                        <span class="text-muted">#<?php echo (int)$log['entity_id']; ?></span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="text-dark">
                                                    <?php echo htmlspecialchars($log['description']); ?>
                                                </span>
                                            </td>
                                            <td class="text-end pe-3 text-nowrap">
                                                <span class="fw-semibold text-secondary">
                                                    <i class="fa-solid fa-user-circle me-1 text-muted"></i>
                                                    <?php echo htmlspecialchars($log['user_name'] ?? 'System / Admin'); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination Controls -->
                        <?php if ($totalPages > 1): ?>
                            <div class="p-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="text-muted small">
                                    Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                                </div>
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&action=<?php echo urlencode($actionFilter); ?>&entity=<?php echo urlencode($entityFilter); ?>" aria-label="Previous">
                                            &laquo; Prev
                                        </a>
                                    </li>
                                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                        <li class="page-item <?php echo ($p === $page) ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $p; ?>&action=<?php echo urlencode($actionFilter); ?>&entity=<?php echo urlencode($entityFilter); ?>">
                                                <?php echo $p; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&action=<?php echo urlencode($actionFilter); ?>&entity=<?php echo urlencode($entityFilter); ?>" aria-label="Next">
                                            Next &raquo;
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        <?php endif; ?>

                    <?php endif; ?>
                </div>
            </div>

        </div> <!-- End .content-container -->
    </main>
</div> <!-- End .app-wrapper -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
