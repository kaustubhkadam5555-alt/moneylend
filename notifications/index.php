<?php
/**
 * MoneyLend - Internal Notification Center (Phase 11)
 *
 * Central hub for reviewing system reminders, upcoming maturity alerts,
 * overdue loan notifications, and repayment transaction notices.
 * Fully secured with CSRF protection, output escaping, and strict user isolation.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helper.php';

require_login();

$pageTitle = "Notification Center";
$activePage = "notifications";
$flash = get_flash();

$pdo = getDBConnection();
$currentUserId = (int)($_SESSION['user_id'] ?? 1);

// Handle POST actions: mark_read, mark_all_read, delete, run_scan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrfToken)) {
        set_flash('danger', 'Security validation failed. Please refresh the page and try again.');
        redirect(BASE_URL . 'notifications/');
    }

    if ($action === 'mark_read') {
        $notificationId = (int)($_POST['notification_id'] ?? 0);
        if ($notificationId > 0 && mark_notification_read($pdo, $notificationId, $currentUserId)) {
            set_flash('success', 'Notification marked as read.');
        } else {
            set_flash('warning', 'Unable to update notification status.');
        }
    } elseif ($action === 'mark_all_read') {
        $count = mark_all_notifications_read($pdo, $currentUserId);
        set_flash('success', "Marked {$count} notification(s) as read.");
    } elseif ($action === 'delete') {
        $notificationId = (int)($_POST['notification_id'] ?? 0);
        if ($notificationId > 0 && delete_notification($pdo, $notificationId, $currentUserId)) {
            set_flash('success', 'Notification dismissed.');
        } else {
            set_flash('warning', 'Unable to remove notification.');
        }
    } elseif ($action === 'run_scan') {
        $scanResults = generate_loan_notifications($pdo, $currentUserId);
        $msg = sprintf(
            "Scan complete: %d new reminder(s) generated (%d due soon, %d due today, %d overdue). %d duplicate(s) safely skipped.",
            $scanResults['total_created'],
            $scanResults['due_soon_created'],
            $scanResults['due_today_created'],
            $scanResults['overdue_created'],
            $scanResults['duplicates_skipped']
        );
        set_flash('info', $msg);
    }

    redirect(BASE_URL . 'notifications/' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

// Filter handling
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$typeFilter = isset($_GET['type']) ? trim($_GET['type']) : '';
$highlightId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Automatically mark highlighted notification as read if directly linked
if ($highlightId > 0) {
    mark_notification_read($pdo, $highlightId, $currentUserId);
}

$filters = [];
if ($statusFilter === 'unread') {
    $filters['is_read'] = 0;
} elseif ($statusFilter === 'read') {
    $filters['is_read'] = 1;
}

if (!empty($typeFilter)) {
    $filters['type'] = $typeFilter;
}

// Pagination setup
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$totalNotifications = get_user_notifications_count($pdo, $currentUserId, $filters);
$totalPages = ceil(max(1, $totalNotifications) / $perPage);
$notifications = get_user_notifications($pdo, $currentUserId, $filters, $perPage, $offset);

// Summary counts for filter tabs
$totalUnreadCount = get_unread_notification_count($pdo, $currentUserId);
$allCount = get_user_notifications_count($pdo, $currentUserId, []);
$dueSoonCount = get_user_notifications_count($pdo, $currentUserId, ['type' => 'loan_due_soon']);
$overdueCount = get_user_notifications_count($pdo, $currentUserId, ['type' => 'loan_overdue']);

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
                    <i class="fa-solid <?php echo ($flash['type'] === 'success') ? 'fa-circle-check' : (($flash['type'] === 'danger') ? 'fa-triangle-exclamation' : 'fa-circle-info'); ?> me-2"></i>
                    <?php echo htmlspecialchars($flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="page-title mb-1">
                        <i class="fa-solid fa-bell text-primary me-2"></i>Notification Center
                    </h1>
                    <p class="page-subtitle mb-0">System alerts, upcoming due dates, and repayment notices</p>
                </div>

                <!-- Top Actions -->
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <form method="POST" action="<?php echo BASE_URL; ?>notifications/" class="d-inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="run_scan">
                        <button type="submit" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-2">
                            <i class="fa-solid fa-arrows-rotate"></i>
                            <span>Check Due Dates</span>
                        </button>
                    </form>

                    <?php if ($totalUnreadCount > 0): ?>
                        <form method="POST" action="<?php echo BASE_URL; ?>notifications/" class="d-inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="btn btn-primary btn-sm d-flex align-items-center gap-2">
                                <i class="fa-solid fa-envelope-open"></i>
                                <span>Mark All as Read (<?php echo $totalUnreadCount; ?>)</span>
                            </button>
                        </form>
                    <?php endif; ?>

                    <a href="<?php echo BASE_URL; ?>settings/#notifications" class="btn btn-outline-secondary btn-sm" title="Configure Notification Preferences">
                        <i class="fa-solid fa-gear me-1"></i> Preferences
                    </a>
                </div>
            </div>

            <!-- Filter Navigation Tabs -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-2 p-md-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <ul class="nav nav-pills gap-1">
                            <li class="nav-item">
                                <a class="nav-link py-1 px-3 small <?php echo ($statusFilter === 'all' && empty($typeFilter)) ? 'active' : ''; ?>" 
                                   href="<?php echo BASE_URL; ?>notifications/?status=all">
                                    All <span class="badge bg-secondary-subtle text-secondary ms-1"><?php echo $allCount; ?></span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link py-1 px-3 small <?php echo ($statusFilter === 'unread') ? 'active' : ''; ?>" 
                                   href="<?php echo BASE_URL; ?>notifications/?status=unread">
                                    Unread 
                                    <?php if ($totalUnreadCount > 0): ?>
                                        <span class="badge bg-danger ms-1"><?php echo $totalUnreadCount; ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary ms-1">0</span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link py-1 px-3 small <?php echo ($typeFilter === 'loan_due_soon') ? 'active' : ''; ?>" 
                                   href="<?php echo BASE_URL; ?>notifications/?type=loan_due_soon">
                                    Due Soon <span class="badge bg-info-subtle text-info ms-1"><?php echo $dueSoonCount; ?></span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link py-1 px-3 small <?php echo ($typeFilter === 'loan_overdue') ? 'active' : ''; ?>" 
                                   href="<?php echo BASE_URL; ?>notifications/?type=loan_overdue">
                                    Overdue <span class="badge bg-danger-subtle text-danger ms-1"><?php echo $overdueCount; ?></span>
                                </a>
                            </li>
                        </ul>

                        <div class="text-muted small">
                            Showing <?php echo count($notifications); ?> of <?php echo $totalNotifications; ?> notification<?php echo $totalNotifications === 1 ? '' : 's'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notification Items List -->
            <div class="content-card">
                <div class="card-body p-0">
                    <?php if (empty($notifications)): ?>
                        <!-- Empty State -->
                        <div class="empty-state py-5">
                            <div class="empty-state-icon text-muted mb-3" style="font-size: 2.5rem;">
                                <i class="fa-regular fa-bell-slash"></i>
                            </div>
                            <h3 class="empty-state-title">No notifications found</h3>
                            <p class="empty-state-text text-muted mb-4">
                                <?php if ($statusFilter === 'unread'): ?>
                                    You're all caught up! There are no unread notifications right now.
                                <?php else: ?>
                                    No reminders or alerts have been generated yet. You can click "Check Due Dates" above to scan current agreements.
                                <?php endif; ?>
                            </p>
                            <form method="POST" action="<?php echo BASE_URL; ?>notifications/" class="d-inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="run_scan">
                                <button type="submit" class="btn btn-outline-primary btn-sm">
                                    <i class="fa-solid fa-arrows-rotate me-1"></i> Scan Due Dates Now
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($notifications as $item): 
                                $badgeInfo = get_notification_badge_info($item['type'], $item['severity']);
                                $isUnread = empty($item['is_read']);
                                $isHighlighted = ($item['id'] == $highlightId);
                            ?>
                                <div class="list-group-item p-3 p-md-4 border-bottom <?php echo $isUnread ? 'bg-light bg-opacity-75' : ''; ?> <?php echo $isHighlighted ? 'border-start border-4 border-primary' : ''; ?>">
                                    <div class="d-flex flex-column flex-sm-row align-items-start gap-3">
                                        <!-- Severity Icon -->
                                        <div class="rounded-circle d-flex align-items-center justify-content-center p-2 mt-1 flex-shrink-0" 
                                             style="width: 44px; height: 44px; background-color: <?php 
                                                echo ($item['severity'] === 'danger') ? '#fee2e2' : 
                                                    (($item['severity'] === 'warning') ? '#fef3c7' : 
                                                    (($item['severity'] === 'success') ? '#dcfce7' : '#e0f2fe')); 
                                             ?>; color: <?php 
                                                echo ($item['severity'] === 'danger') ? '#dc2626' : 
                                                    (($item['severity'] === 'warning') ? '#d97706' : 
                                                    (($item['severity'] === 'success') ? '#16a34a' : '#0284c7')); 
                                             ?>;">
                                            <i class="fa-solid <?php echo $badgeInfo['icon']; ?> fs-5"></i>
                                        </div>

                                        <!-- Message Body & Metadata -->
                                        <div class="flex-grow-1">
                                            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-1 mb-1">
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <span class="fw-semibold text-dark fs-6">
                                                        <?php echo htmlspecialchars($item['title']); ?>
                                                    </span>
                                                    <span class="badge <?php echo $badgeInfo['badge']; ?> py-1 px-2" style="font-size: 0.72rem;">
                                                        <?php echo $badgeInfo['label']; ?>
                                                    </span>
                                                    <?php if ($isUnread): ?>
                                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle py-1 px-2" style="font-size: 0.7rem;">
                                                            Unread
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-muted small text-nowrap">
                                                    <i class="fa-regular fa-clock me-1"></i>
                                                    <?php echo date('M d, Y \a\t H:i', strtotime($item['created_at'])); ?>
                                                </div>
                                            </div>

                                            <p class="text-secondary small mb-2 lh-base">
                                                <?php echo htmlspecialchars($item['message']); ?>
                                            </p>

                                            <!-- Context Links: Borrower, Loan, Repayment -->
                                            <div class="d-flex flex-wrap align-items-center gap-2 pt-1">
                                                <?php if (!empty($item['borrower_id'])): ?>
                                                    <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo (int)$item['borrower_id']; ?>" 
                                                       class="badge bg-white text-secondary border text-decoration-none py-1 px-2" 
                                                       title="View Borrower Details">
                                                        <i class="fa-solid fa-user me-1 text-primary"></i>
                                                        <?php echo htmlspecialchars($item['borrower_name'] ?? 'Borrower'); ?>
                                                    </a>
                                                <?php endif; ?>

                                                <?php if (!empty($item['loan_id'])): ?>
                                                    <a href="<?php echo BASE_URL; ?>loans/view.php?id=<?php echo (int)$item['loan_id']; ?>" 
                                                       class="badge bg-white text-secondary border text-decoration-none py-1 px-2"
                                                       title="View Loan Agreement">
                                                        <i class="fa-solid fa-file-invoice-dollar me-1 text-primary"></i>
                                                        Agreement #<?php echo (int)$item['loan_id']; ?>
                                                    </a>
                                                <?php endif; ?>

                                                <?php if (!empty($item['repayment_id'])): ?>
                                                    <a href="<?php echo BASE_URL; ?>repayments/view.php?id=<?php echo (int)$item['repayment_id']; ?>" 
                                                       class="badge bg-white text-secondary border text-decoration-none py-1 px-2"
                                                       title="View Payment Receipt">
                                                        <i class="fa-solid fa-receipt me-1 text-success"></i>
                                                        Receipt #<?php echo (int)$item['repayment_id']; ?>
                                                    </a>
                                                <?php endif; ?>

                                                <!-- Action Buttons: Mark Read / Dismiss -->
                                                <div class="ms-auto d-flex align-items-center gap-1">
                                                    <?php if ($isUnread): ?>
                                                        <form method="POST" action="<?php echo BASE_URL; ?>notifications/" class="d-inline">
                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="action" value="mark_read">
                                                            <input type="hidden" name="notification_id" value="<?php echo (int)$item['id']; ?>">
                                                            <button type="submit" class="btn btn-link btn-sm text-decoration-none p-1 text-muted" title="Mark as Read" aria-label="Mark notification <?php echo (int)$item['id']; ?> as read">
                                                                <i class="fa-solid fa-check me-1"></i> Mark Read
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>

                                                    <form method="POST" action="<?php echo BASE_URL; ?>notifications/" class="d-inline">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="notification_id" value="<?php echo (int)$item['id']; ?>">
                                                        <button type="submit" class="btn btn-link btn-sm text-decoration-none p-1 text-danger" title="Dismiss" aria-label="Dismiss notification <?php echo (int)$item['id']; ?>">
                                                            <i class="fa-regular fa-trash-can"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pagination Controls -->
                        <?php if ($totalPages > 1): ?>
                            <div class="p-3 border-top d-flex justify-content-center">
                                <nav aria-label="Notifications pagination">
                                    <ul class="pagination pagination-sm mb-0">
                                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo BASE_URL; ?>notifications/?status=<?php echo urlencode($statusFilter); ?>&type=<?php echo urlencode($typeFilter); ?>&page=<?php echo $page - 1; ?>" aria-label="Previous">
                                                &laquo;
                                            </a>
                                        </li>
                                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                            <li class="page-item <?php echo ($p === $page) ? 'active' : ''; ?>">
                                                <a class="page-link" href="<?php echo BASE_URL; ?>notifications/?status=<?php echo urlencode($statusFilter); ?>&type=<?php echo urlencode($typeFilter); ?>&page=<?php echo $p; ?>">
                                                    <?php echo $p; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo BASE_URL; ?>notifications/?status=<?php echo urlencode($statusFilter); ?>&type=<?php echo urlencode($typeFilter); ?>&page=<?php echo $page + 1; ?>" aria-label="Next">
                                                &raquo;
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>

                    <?php endif; ?>
                </div>
            </div>

        </div> <!-- End .content-container -->
    </main>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
