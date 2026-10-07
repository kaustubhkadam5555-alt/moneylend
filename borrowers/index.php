<?php
/**
 * MoneyLend - Borrowers Management
 * 
 * Lists all registered borrowers with search, statistics, and action options.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Protect page
require_login();

$pageTitle = "Borrowers";
$activePage = "borrowers";
$flash = get_flash();
$pdo = getDBConnection();

// Handle search query
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$query = "SELECT b.*, 
          (SELECT COUNT(*) FROM loans WHERE borrower_id = b.id) AS total_loans,
          (SELECT COUNT(*) FROM loans WHERE borrower_id = b.id AND status = 'active') AS active_loans
          FROM borrowers b WHERE 1=1";
$params = [];

if ($search !== '') {
    $query .= " AND (b.full_name LIKE :search_name OR b.phone LIKE :search_phone OR b.email LIKE :search_email)";
    $params[':search_name'] = "%{$search}%";
    $params[':search_phone'] = "%{$search}%";
    $params[':search_email'] = "%{$search}%";
}

if ($statusFilter === 'active' || $statusFilter === 'inactive') {
    $query .= " AND b.status = :status";
    $params[':status'] = $statusFilter;
}

$query .= " ORDER BY b.id DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $borrowers = $stmt->fetchAll();

    // Fetch summary statistics
    $statStmt = $pdo->query("SELECT 
        COUNT(*) AS total,
        COUNT(CASE WHEN status = 'active' THEN 1 END) AS active_count,
        COUNT(CASE WHEN status = 'inactive' THEN 1 END) AS inactive_count
        FROM borrowers");
    $counts = $statStmt->fetch();
} catch (PDOException $e) {
    error_log("Borrowers fetch error: " . $e->getMessage());
    $borrowers = [];
    $counts = ['total' => 0, 'active_count' => 0, 'inactive_count' => 0];
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
                    <i class="fa-solid <?php echo ($flash['type'] === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> me-2"></i>
                    <?php echo htmlspecialchars($flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Page Header & Action -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="page-title">Borrowers</h1>
                    <p class="page-subtitle">Manage borrower profiles and lending relationships</p>
                </div>
                <div>
                    <a href="<?php echo BASE_URL; ?>borrowers/add.php" class="btn btn-primary quick-action-btn">
                        <i class="fa-solid fa-user-plus"></i>
                        <span>Add Borrower</span>
                    </a>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-sm-4">
                    <div class="stat-card py-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="stat-label">Total Borrowers</span>
                                <div class="h4 fw-bold text-dark mb-0"><?php echo (int)($counts['total'] ?? 0); ?></div>
                            </div>
                            <div class="stat-icon-wrapper stat-icon-emerald" style="width: 40px; height: 40px; font-size: 1.1rem;">
                                <i class="fa-solid fa-users"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-4">
                    <div class="stat-card py-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="stat-label">Active Clients</span>
                                <div class="h4 fw-bold text-dark mb-0"><?php echo (int)($counts['active_count'] ?? 0); ?></div>
                            </div>
                            <div class="stat-icon-wrapper stat-icon-blue" style="width: 40px; height: 40px; font-size: 1.1rem;">
                                <i class="fa-solid fa-user-check"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-4">
                    <div class="stat-card py-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="stat-label">Inactive Clients</span>
                                <div class="h4 fw-bold text-dark mb-0"><?php echo (int)($counts['inactive_count'] ?? 0); ?></div>
                            </div>
                            <div class="stat-icon-wrapper stat-icon-amber" style="width: 40px; height: 40px; font-size: 1.1rem;">
                                <i class="fa-solid fa-user-xmark"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search & Filters Card -->
            <div class="content-card mb-4">
                <div class="p-3">
                    <form method="GET" action="<?php echo BASE_URL; ?>borrowers/index.php" class="row g-2 align-items-center">
                        <div class="col-12 col-md-6 col-lg-7">
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted border-end-0">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                </span>
                                <input 
                                    type="text" 
                                    name="search" 
                                    class="form-control border-start-0 ps-0" 
                                    placeholder="Search by name, phone, or email..." 
                                    value="<?php echo htmlspecialchars($search); ?>"
                                >
                            </div>
                        </div>
                        <div class="col-12 col-md-3 col-lg-3">
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>Active Only</option>
                                <option value="inactive" <?php echo ($statusFilter === 'inactive') ? 'selected' : ''; ?>>Inactive Only</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3 col-lg-2 d-flex gap-2">
                            <button type="submit" class="btn btn-secondary flex-grow-1">
                                Filter
                            </button>
                            <?php if ($search !== '' || $statusFilter !== ''): ?>
                                <a href="<?php echo BASE_URL; ?>borrowers/index.php" class="btn btn-outline-secondary" title="Clear Filters">
                                    <i class="fa-solid fa-rotate-left"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Borrowers Table Card -->
            <div class="content-card">
                <div class="content-card-header">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-address-book text-primary"></i>
                        <h2 class="content-card-title">Borrower Directory</h2>
                    </div>
                    <span class="badge bg-light text-secondary border">
                        Showing <?php echo count($borrowers); ?> Record<?php echo count($borrowers) === 1 ? '' : 's'; ?>
                    </span>
                </div>

                <div class="card-body p-0">
                    <?php if (empty($borrowers)): ?>
                        <!-- Empty State -->
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fa-solid fa-user-slash"></i>
                            </div>
                            <?php if ($search !== '' || $statusFilter !== ''): ?>
                                <h3 class="empty-state-title">No borrowers match your search</h3>
                                <p class="empty-state-text mb-3">Try adjusting your search query or reset the filter to view all records.</p>
                                <a href="<?php echo BASE_URL; ?>borrowers/index.php" class="btn btn-outline-secondary btn-sm">Reset Filter</a>
                            <?php else: ?>
                                <h3 class="empty-state-title">No borrowers registered yet</h3>
                                <p class="empty-state-text mb-3">Start by adding your first borrower client to track their loan portfolio.</p>
                                <a href="<?php echo BASE_URL; ?>borrowers/add.php" class="btn btn-primary btn-sm">
                                    <i class="fa-solid fa-user-plus me-1"></i> Add First Borrower
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light text-muted small text-uppercase">
                                    <tr>
                                        <th scope="col" class="ps-3" style="width: 70px;">ID</th>
                                        <th scope="col">Full Name</th>
                                        <th scope="col">Phone</th>
                                        <th scope="col">Email</th>
                                        <th scope="col">Address</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Registered</th>
                                        <th scope="col" class="text-end pe-3" style="width: 140px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($borrowers as $b): ?>
                                        <tr>
                                            <td class="ps-3 text-muted small">
                                                #<?php echo (int)$b['id']; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="navbar-user-avatar" style="width: 32px; height: 32px; font-size: 0.8rem; background-color: #e0f2fe; color: #0284c7;">
                                                        <?php echo htmlspecialchars(strtoupper(substr($b['full_name'], 0, 1))); ?>
                                                    </div>
                                                    <div>
                                                        <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo (int)$b['id']; ?>" class="fw-semibold text-dark text-decoration-none">
                                                            <?php echo htmlspecialchars($b['full_name']); ?>
                                                        </a>
                                                        <?php if ((int)$b['active_loans'] > 0): ?>
                                                            <span class="badge bg-success-subtle text-success border border-success-subtle ms-1" style="font-size: 0.65rem;">
                                                                <?php echo (int)$b['active_loans']; ?> Active Loan<?php echo (int)$b['active_loans'] > 1 ? 's' : ''; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="text-secondary small">
                                                    <i class="fa-solid fa-phone me-1 text-muted" style="font-size: 0.75rem;"></i>
                                                    <?php echo htmlspecialchars($b['phone']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($b['email'])): ?>
                                                    <span class="text-secondary small">
                                                        <?php echo htmlspecialchars($b['email']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted small fst-italic">Not provided</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($b['address'])): ?>
                                                    <span class="text-secondary small text-truncate d-inline-block" style="max-width: 180px;" title="<?php echo htmlspecialchars($b['address']); ?>">
                                                        <?php echo htmlspecialchars($b['address']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted small fst-italic">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($b['status'] === 'active'): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                        <i class="fa-solid fa-circle-dot me-1" style="font-size: 0.55rem;"></i> Active
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border px-2 py-1">
                                                        <i class="fa-solid fa-circle-pause me-1" style="font-size: 0.55rem;"></i> Inactive
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-muted small">
                                                <?php echo date('M d, Y', strtotime($b['created_at'])); ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <div class="btn-group btn-group-sm" role="group" aria-label="Borrower Actions">
                                                    <a href="<?php echo BASE_URL; ?>borrowers/view.php?id=<?php echo (int)$b['id']; ?>" class="btn btn-outline-secondary" title="View Details" aria-label="View Details for <?php echo htmlspecialchars($b['full_name']); ?>">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </a>
                                                    <a href="<?php echo BASE_URL; ?>borrowers/edit.php?id=<?php echo (int)$b['id']; ?>" class="btn btn-outline-primary" title="Edit Borrower" aria-label="Edit Borrower <?php echo htmlspecialchars($b['full_name']); ?>">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </a>
                                                    <button 
                                                        type="button" 
                                                        class="btn btn-outline-danger" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#deleteBorrowerModal" 
                                                        data-borrower-id="<?php echo (int)$b['id']; ?>" 
                                                        data-borrower-name="<?php echo htmlspecialchars($b['full_name']); ?>"
                                                        data-total-loans="<?php echo (int)$b['total_loans']; ?>"
                                                        title="Delete Borrower"
                                                        aria-label="Delete Borrower <?php echo htmlspecialchars($b['full_name']); ?>"
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

        <!-- Delete Confirmation Modal -->
        <div class="modal fade" id="deleteBorrowerModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="POST" action="<?php echo BASE_URL; ?>borrowers/delete.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="borrower_id" id="modalBorrowerId" value="">

                        <div class="modal-header border-bottom">
                            <h5 class="modal-title fw-bold text-danger" id="deleteModalLabel">
                                <i class="fa-solid fa-triangle-exclamation me-2"></i>Confirm Deletion
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body py-4">
                            <p class="mb-2">Are you sure you want to delete borrower <strong id="modalBorrowerName" class="text-dark"></strong>?</p>
                            
                            <div id="modalLoansWarning" class="alert alert-warning small border-0 mb-0 d-none">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                                <strong>Safety Notice:</strong> This borrower has <span id="modalLoansCount">0</span> associated loan record(s). Borrowers with active or historical loans cannot be deleted to preserve financial audit integrity.
                            </div>
                        </div>
                        <div class="modal-footer border-top bg-light">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger btn-sm" id="modalDeleteBtn">
                                <i class="fa-solid fa-trash-can me-1"></i> Delete Borrower
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const deleteModal = document.getElementById('deleteBorrowerModal');
            if (deleteModal) {
                deleteModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const id = button.getAttribute('data-borrower-id');
                    const name = button.getAttribute('data-borrower-name');
                    const totalLoans = parseInt(button.getAttribute('data-total-loans') || '0', 10);

                    document.getElementById('modalBorrowerId').value = id;
                    document.getElementById('modalBorrowerName').textContent = name;

                    const warningDiv = document.getElementById('modalLoansWarning');
                    const deleteBtn = document.getElementById('modalDeleteBtn');
                    const countSpan = document.getElementById('modalLoansCount');

                    if (totalLoans > 0) {
                        warningDiv.classList.remove('d-none');
                        countSpan.textContent = totalLoans;
                        deleteBtn.disabled = true;
                        deleteBtn.title = "Cannot delete borrower with loans attached";
                    } else {
                        warningDiv.classList.add('d-none');
                        deleteBtn.disabled = false;
                        deleteBtn.title = "";
                    }
                });
            }
        });
        </script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
