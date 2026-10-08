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
} elseif (str_contains($currentScript, '/settings/')) {
    $navSection = ['title' => 'Settings', 'url' => BASE_URL . 'settings/', 'icon' => 'fa-gear'];
} elseif (str_contains($currentScript, '/activity/')) {
    $navSection = ['title' => 'Activity Log', 'url' => BASE_URL . 'activity/', 'icon' => 'fa-clock-rotate-left'];
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
