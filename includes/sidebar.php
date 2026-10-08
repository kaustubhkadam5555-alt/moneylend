<?php
/**
 * MoneyLend - Reusable Left Sidebar
 * 
 * Provides navigation across all primary modules:
 * Dashboard, Borrowers, Loans, Repayments, Reports, Settings.
 */

// Automated reliable active page detection based on URL script path
$currentScript = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
if (str_contains($currentScript, '/borrowers/')) {
    $activePage = 'borrowers';
} elseif (str_contains($currentScript, '/loans/')) {
    $activePage = 'loans';
} elseif (str_contains($currentScript, '/repayments/')) {
    $activePage = 'repayments';
} elseif (str_contains($currentScript, '/reports/')) {
    $activePage = 'reports';
} elseif (str_contains($currentScript, '/settings/')) {
    $activePage = 'settings';
} elseif (str_contains($currentScript, '/activity/')) {
    $activePage = 'activity';
} elseif (str_contains($currentScript, 'dashboard.php')) {
    $activePage = 'dashboard';
} else {
    $activePage = $activePage ?? 'dashboard';
}
?>
<!-- Mobile Overlay Backdrop -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- Sidebar Component -->
<aside class="app-sidebar" id="appSidebar">
    <!-- Mobile Sidebar Header -->
    <div class="d-flex d-lg-none align-items-center justify-content-between p-3 border-bottom">
        <div class="d-flex align-items-center gap-2">
            <span class="brand-icon-box" style="width: 30px; height: 30px; font-size: 0.9rem;">
                <i class="fa-solid fa-hand-holding-dollar"></i>
            </span>
            <span class="fw-bold text-dark">MoneyLend</span>
        </div>
        <button type="button" class="btn-close" id="sidebarClose" aria-label="Close sidebar"></button>
    </div>

    <!-- Navigation List -->
    <div class="sidebar-nav flex-grow-1">
        <div class="sidebar-section-title">Navigation</div>
        
        <a href="<?php echo BASE_URL; ?>dashboard.php" class="sidebar-link <?php echo ($activePage === 'dashboard') ? 'active' : ''; ?>" <?php echo ($activePage === 'dashboard') ? 'aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-gauge-high"></i>
            <span>Dashboard</span>
        </a>

        <a href="<?php echo BASE_URL; ?>borrowers/" class="sidebar-link <?php echo ($activePage === 'borrowers') ? 'active' : ''; ?>" <?php echo ($activePage === 'borrowers') ? 'aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-users"></i>
            <span>Borrowers</span>
        </a>

        <a href="<?php echo BASE_URL; ?>loans/" class="sidebar-link <?php echo ($activePage === 'loans') ? 'active' : ''; ?>" <?php echo ($activePage === 'loans') ? 'aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-file-invoice-dollar"></i>
            <span>Loans</span>
        </a>

        <a href="<?php echo BASE_URL; ?>repayments/" class="sidebar-link <?php echo ($activePage === 'repayments') ? 'active' : ''; ?>" <?php echo ($activePage === 'repayments') ? 'aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-money-bill-wave"></i>
            <span>Repayments</span>
        </a>

        <div class="sidebar-section-title mt-3">Insights & System</div>

        <a href="<?php echo BASE_URL; ?>reports/" class="sidebar-link <?php echo ($activePage === 'reports') ? 'active' : ''; ?>" <?php echo ($activePage === 'reports') ? 'aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-chart-line"></i>
            <span>Reports</span>
        </a>

        <a href="<?php echo BASE_URL; ?>activity/" class="sidebar-link <?php echo ($activePage === 'activity') ? 'active' : ''; ?>" <?php echo ($activePage === 'activity') ? 'aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span>Activity Log</span>
        </a>

        <a href="<?php echo BASE_URL; ?>settings/" class="sidebar-link <?php echo ($activePage === 'settings') ? 'active' : ''; ?>" <?php echo ($activePage === 'settings') ? 'aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-gear"></i>
            <span>Settings</span>
        </a>
    </div>

    <!-- Sidebar Footer / System Badge -->
    <div class="p-3 border-top bg-light text-center small text-muted">
        <div class="fw-semibold text-secondary" style="font-size: 0.78rem;">
            MoneyLend <span class="badge bg-secondary-subtle text-secondary border">v<?php echo defined('APP_VERSION') ? APP_VERSION : '1.0.0'; ?></span>
        </div>
        <div class="text-truncate text-muted mt-1" style="font-size: 0.72rem;">
            <?php echo defined('APP_TAGLINE') ? APP_TAGLINE : 'Simple Lending. Smarter Tracking.'; ?>
        </div>
    </div>
</aside>
