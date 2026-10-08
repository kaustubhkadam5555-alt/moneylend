<?php
/**
 * MoneyLend - Reusable Top Navbar
 * 
 * Contains application logo, quick navigation, current user profile badge,
 * and logout action.
 */
?>
<nav class="navbar navbar-expand app-navbar px-3 px-lg-4">
    <div class="container-fluid p-0">
        <!-- Left: Mobile Sidebar Toggle & Brand -->
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light d-lg-none p-1 px-2 border" type="button" id="sidebarToggle" aria-label="Toggle navigation">
                <i class="fa-solid fa-bars text-secondary"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center gap-2 m-0 p-0" href="<?php echo BASE_URL; ?>dashboard.php">
                <span class="brand-icon-box">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </span>
                <span class="brand-text">Money<span>Lend</span></span>
            </a>

<?php
// Resolve current section metadata for navbar breadcrumb indicator
$currentScript = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
$navSection = [
    'title' => 'Dashboard',
    'url'   => BASE_URL . 'dashboard.php',
    'icon'  => 'fa-gauge-high'
];
if (str_contains($currentScript, '/borrowers/')) {
    $navSection = ['title' => 'Borrowers', 'url' => BASE_URL . 'borrowers/', 'icon' => 'fa-users'];
} elseif (str_contains($currentScript, '/loans/')) {
    $navSection = ['title' => 'Loans', 'url' => BASE_URL . 'loans/', 'icon' => 'fa-file-invoice-dollar'];
} elseif (str_contains($currentScript, '/repayments/')) {
    $navSection = ['title' => 'Repayments', 'url' => BASE_URL . 'repayments/', 'icon' => 'fa-money-bill-wave'];
} elseif (str_contains($currentScript, '/reports/')) {
    $navSection = ['title' => 'Reports & Analytics', 'url' => BASE_URL . 'reports/', 'icon' => 'fa-chart-line'];
} elseif (str_contains($currentScript, '/settings/users.php')) {
    $navSection = ['title' => 'User Management', 'url' => BASE_URL . 'settings/users.php', 'icon' => 'fa-user-shield'];
} elseif (str_contains($currentScript, '/settings/')) {
    $navSection = ['title' => 'Settings', 'url' => BASE_URL . 'settings/', 'icon' => 'fa-gear'];
} elseif (str_contains($currentScript, '/activity/')) {
    $navSection = ['title' => 'Activity Log', 'url' => BASE_URL . 'activity/', 'icon' => 'fa-clock-rotate-left'];
} elseif (str_contains($currentScript, '/notifications/')) {
    $navSection = ['title' => 'Notifications', 'url' => BASE_URL . 'notifications/', 'icon' => 'fa-bell'];
}

// Resolve unread notifications for current user
$navUserId = (int)($_SESSION['user_id'] ?? 1);
$navUnreadCount = 0;
$navRecentNotifications = [];
if (function_exists('getDBConnection') && function_exists('get_unread_notification_count')) {
    try {
        $navPdo = getDBConnection();
        $navUnreadCount = get_unread_notification_count($navPdo, $navUserId);
        $navRecentNotifications = get_user_notifications($navPdo, $navUserId, [], 5, 0);
    } catch (Throwable $e) {
        $navUnreadCount = 0;
        $navRecentNotifications = [];
    }
}
?>
            <!-- Quick breadcrumb / indicator on desktop -->
            <div class="d-none d-md-flex align-items-center ms-3 ps-3 border-start">
                <a href="<?php echo htmlspecialchars($navSection['url']); ?>" class="text-decoration-none text-secondary fw-semibold small">
                    <i class="fa-solid <?php echo htmlspecialchars($navSection['icon']); ?> me-1 text-primary"></i> <?php echo htmlspecialchars($navSection['title']); ?>
                </a>
            </div>
        </div>

        <!-- Right: User Area & Logout -->
        <div class="d-flex align-items-center gap-3 ms-auto">
            <!-- Notification Bell Dropdown -->
            <div class="dropdown">
                <button class="btn btn-light position-relative p-2 border rounded-circle d-flex align-items-center justify-content-center" 
                        type="button" 
                        id="notificationsDropdown" 
                        data-bs-toggle="dropdown" 
                        aria-expanded="false" 
                        aria-label="Notifications, <?php echo $navUnreadCount; ?> unread"
                        style="width: 38px; height: 38px;">
                    <i class="fa-solid fa-bell text-secondary"></i>
                    <?php if ($navUnreadCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-white" style="font-size: 0.65rem;">
                            <?php echo $navUnreadCount > 99 ? '99+' : $navUnreadCount; ?>
                            <span class="visually-hidden">unread notifications</span>
                        </span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-sm border p-0 mt-2" aria-labelledby="notificationsDropdown" style="width: 320px; max-width: 90vw;">
                    <div class="d-flex align-items-center justify-content-between p-3 border-bottom bg-light rounded-top">
                        <div class="fw-semibold small text-dark d-flex align-items-center gap-2">
                            <i class="fa-solid fa-bell text-primary"></i> Notifications
                            <?php if ($navUnreadCount > 0): ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?php echo $navUnreadCount; ?> new</span>
                            <?php endif; ?>
                        </div>
                        <a href="<?php echo BASE_URL; ?>notifications/" class="small text-decoration-none">View All</a>
                    </div>
                    <div class="p-0 overflow-auto" style="max-height: 280px;">
                        <?php if (empty($navRecentNotifications)): ?>
                            <div class="text-center py-4 text-muted small">
                                <i class="fa-regular fa-bell-slash mb-2 fs-4 text-secondary d-block"></i>
                                No notifications yet
                            </div>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($navRecentNotifications as $notif): 
                                    $badgeInfo = get_notification_badge_info($notif['type'], $notif['severity']);
                                ?>
                                    <li class="border-bottom px-3 py-2 <?php echo empty($notif['is_read']) ? 'bg-light bg-opacity-75' : ''; ?>">
                                        <a href="<?php echo BASE_URL; ?>notifications/?id=<?php echo (int)$notif['id']; ?>" class="text-decoration-none text-dark d-block">
                                            <div class="d-flex align-items-start gap-2">
                                                <span class="badge <?php echo $badgeInfo['badge']; ?> p-1 px-2 mt-1">
                                                    <i class="fa-solid <?php echo $badgeInfo['icon']; ?>"></i>
                                                </span>
                                                <div class="flex-grow-1 overflow-hidden">
                                                    <div class="small fw-semibold text-truncate <?php echo empty($notif['is_read']) ? 'text-primary' : 'text-dark'; ?>">
                                                        <?php echo htmlspecialchars($notif['title']); ?>
                                                    </div>
                                                    <div class="text-muted" style="font-size: 0.75rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                                        <?php echo htmlspecialchars($notif['message']); ?>
                                                    </div>
                                                    <div class="text-muted mt-1" style="font-size: 0.68rem;">
                                                        <?php echo date('M d, H:i', strtotime($notif['created_at'])); ?>
                                                        <?php if (empty($notif['is_read'])): ?>
                                                            <span class="badge bg-danger-subtle text-danger ms-1" style="font-size: 0.6rem;">Unread</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                    <div class="p-2 border-top bg-light text-center rounded-bottom">
                        <a href="<?php echo BASE_URL; ?>notifications/" class="btn btn-sm btn-outline-primary w-100 py-1">
                            Open Notification Center
                        </a>
                    </div>
                </div>
            </div>
            <!-- User Profile Display -->
            <a href="<?php echo BASE_URL; ?>settings/" class="d-flex align-items-center gap-2 text-decoration-none" title="Manage Profile & Settings" aria-label="Manage Profile & Settings">
                <div class="navbar-user-avatar" title="<?php echo htmlspecialchars($currentUser['email'] ?? ''); ?>">
                    <?php 
                        $initials = strtoupper(substr($currentUser['name'] ?? 'A', 0, 1));
                        echo htmlspecialchars($initials);
                    ?>
                </div>
                <div class="d-none d-sm-block text-start lh-sm">
                    <div class="fw-semibold text-dark small mb-0">
                        <?php echo htmlspecialchars($currentUser['name'] ?? 'Admin User'); ?>
                    </div>
                    <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.68rem;">
                        <?php echo htmlspecialchars(ucfirst($currentUser['role'] ?? 'admin')); ?>
                    </span>
                </div>
            </a>

            <!-- Divider -->
            <div class="vr mx-1 text-muted d-none d-sm-block" style="height: 24px;"></div>

            <!-- Logout Link -->
            <a href="<?php echo BASE_URL; ?>logout.php" class="btn btn-outline-danger btn-sm d-flex align-items-center gap-2 px-2 px-sm-3 py-1" title="Log out from MoneyLend" aria-label="Log out from MoneyLend">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span class="d-none d-sm-inline">Logout</span>
            </a>
        </div>
    </div>
</nav>
