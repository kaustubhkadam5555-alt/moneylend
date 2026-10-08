<?php
/**
 * MoneyLend - User Management & Administration (Phase 12)
 *
 * Centralized user management and role-based access control.
 * Restricted strictly to users with the 'admin' role.
 * Includes self-protection rules and last-active-admin safeguards.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/activity_helper.php';

// Strict administrative authorization check
require_role('admin');

$pageTitle = "User Management";
$activePage = "users";
$pdo = getDBConnection();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$errors = [];
$successMessage = '';

// Retrieve password policy setting
$minPasswordLength = (int)get_setting('min_password_length', '8');

// -------------------------------------------------------------
// POST ACTIONS (CSRF Protected)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        $errors[] = "Security token mismatch. Please reload the page and try again.";
    } else {
        $action = $_POST['form_action'] ?? '';

        // 1. CREATE USER
        if ($action === 'create_user') {
            $name            = trim($_POST['name'] ?? '');
            $email           = trim($_POST['email'] ?? '');
            $role            = trim($_POST['role'] ?? 'staff');
            $status          = trim($_POST['status'] ?? 'active');
            $password        = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($name)) {
                $errors[] = "User full name is required.";
            } elseif (mb_strlen($name) > 100) {
                $errors[] = "User name must not exceed 100 characters.";
            }

            if (empty($email)) {
                $errors[] = "Email address is required.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Please provide a valid email address.";
            } elseif (mb_strlen($email) > 150) {
                $errors[] = "Email must not exceed 150 characters.";
            } else {
                $chkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
                $chkStmt->execute([':email' => $email]);
                if ($chkStmt->fetch()) {
                    $errors[] = "The email '{$email}' is already registered to another user.";
                }
            }

            if (!in_array($role, ['admin', 'staff'], true)) {
                $errors[] = "Invalid user role selected.";
            }

            if (!in_array($status, ['active', 'inactive'], true)) {
                $errors[] = "Invalid user status selected.";
            }

            if (empty($password)) {
                $errors[] = "Password is required.";
            } elseif (mb_strlen($password) < $minPasswordLength) {
                $errors[] = "Password must be at least {$minPasswordLength} characters long.";
            }

            if ($password !== $confirmPassword) {
                $errors[] = "Password and confirm password do not match.";
            }

            if (empty($errors)) {
                try {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $insStmt = $pdo->prepare("
                        INSERT INTO users (name, email, password, role, status, created_at)
                        VALUES (:name, :email, :password, :role, :status, NOW())
                    ");
                    $insStmt->execute([
                        ':name'     => $name,
                        ':email'    => $email,
                        ':password' => $hashedPassword,
                        ':role'     => $role,
                        ':status'   => $status
                    ]);
                    $newUserId = (int)$pdo->lastInsertId();

                    log_activity($pdo, 'user_create', 'user', $newUserId, "Created user account: {$email} ({$role})");
                    set_flash('success', "User account for '{$email}' created successfully.");
                    redirect(BASE_URL . "settings/users.php");
                } catch (PDOException $e) {
                    error_log("Create user error: " . $e->getMessage());
                    $errors[] = "Unable to create user account. Please try again.";
                }
            }
        }

        // 2. TOGGLE STATUS (Activate / Deactivate)
        elseif ($action === 'toggle_status') {
            $targetId = (int)($_POST['target_user_id'] ?? 0);
            $newStatus = trim($_POST['new_status'] ?? '');

            if (!in_array($newStatus, ['active', 'inactive'], true)) {
                $errors[] = "Invalid status specified.";
            } elseif ($targetId <= 0) {
                $errors[] = "Invalid target user ID.";
            } elseif ($targetId === $currentUserId) {
                // Self-protection guard
                $errors[] = "You cannot deactivate your own administrator account.";
            } else {
                // Fetch target user details
                $tgtStmt = $pdo->prepare("SELECT id, name, email, role, status FROM users WHERE id = :id LIMIT 1");
                $tgtStmt->execute([':id' => $targetId]);
                $targetUser = $tgtStmt->fetch();

                if (!$targetUser) {
                    $errors[] = "User record not found.";
                } elseif ($newStatus === 'inactive' && $targetUser['role'] === 'admin') {
                    // Last active administrator protection guard
                    $adminCountStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id != :id");
                    $adminCountStmt->execute([':id' => $targetId]);
                    $remainingActiveAdmins = (int)$adminCountStmt->fetchColumn();

                    if ($remainingActiveAdmins < 1) {
                        $errors[] = "Cannot deactivate the last active administrator in the system.";
                    }
                }

                if (empty($errors)) {
                    try {
                        $updStmt = $pdo->prepare("UPDATE users SET status = :status WHERE id = :id");
                        $updStmt->execute([':status' => $newStatus, ':id' => $targetId]);

                        $logAction = ($newStatus === 'active') ? 'user_activate' : 'user_deactivate';
                        log_activity($pdo, $logAction, 'user', $targetId, "Changed status of {$targetUser['email']} to {$newStatus}");

                        set_flash('success', "User status for '{$targetUser['name']}' updated to {$newStatus}.");
                        redirect(BASE_URL . "settings/users.php");
                    } catch (PDOException $e) {
                        error_log("Update status error: " . $e->getMessage());
                        $errors[] = "Database error while updating user status.";
                    }
                }
            }
        }

        // 3. CHANGE ROLE
        elseif ($action === 'change_role') {
            $targetId = (int)($_POST['target_user_id'] ?? 0);
            $newRole = trim($_POST['new_role'] ?? '');

            if (!in_array($newRole, ['admin', 'staff'], true)) {
                $errors[] = "Invalid role specified.";
            } elseif ($targetId <= 0) {
                $errors[] = "Invalid target user ID.";
            } elseif ($targetId === $currentUserId) {
                // Self-protection guard
                $errors[] = "You cannot modify your own administrative role.";
            } else {
                $tgtStmt = $pdo->prepare("SELECT id, name, email, role, status FROM users WHERE id = :id LIMIT 1");
                $tgtStmt->execute([':id' => $targetId]);
                $targetUser = $tgtStmt->fetch();

                if (!$targetUser) {
                    $errors[] = "User record not found.";
                } elseif ($targetUser['role'] === 'admin' && $newRole !== 'admin') {
                    // Last active admin protection guard
                    $adminCountStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id != :id");
                    $adminCountStmt->execute([':id' => $targetId]);
                    $remainingActiveAdmins = (int)$adminCountStmt->fetchColumn();

                    if ($remainingActiveAdmins < 1) {
                        $errors[] = "Cannot demote the last active administrator in the system.";
                    }
                }

                if (empty($errors)) {
                    try {
                        $updStmt = $pdo->prepare("UPDATE users SET role = :role WHERE id = :id");
                        $updStmt->execute([':role' => $newRole, ':id' => $targetId]);

                        log_activity($pdo, 'user_role_change', 'user', $targetId, "Changed role of {$targetUser['email']} to {$newRole}");
                        set_flash('success', "User role for '{$targetUser['name']}' updated to " . ucfirst($newRole) . ".");
                        redirect(BASE_URL . "settings/users.php");
                    } catch (PDOException $e) {
                        error_log("Update role error: " . $e->getMessage());
                        $errors[] = "Database error while updating user role.";
                    }
                }
            }
        }

        // 4. RESET USER PASSWORD
        elseif ($action === 'reset_password') {
            $targetId        = (int)($_POST['target_user_id'] ?? 0);
            $newPassword     = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($targetId <= 0) {
                $errors[] = "Invalid target user ID.";
            }

            if (empty($newPassword)) {
                $errors[] = "New password is required.";
            } elseif (mb_strlen($newPassword) < $minPasswordLength) {
                $errors[] = "New password must be at least {$minPasswordLength} characters long.";
            }

            if ($newPassword !== $confirmPassword) {
                $errors[] = "New password and confirmation do not match.";
            }

            if (empty($errors)) {
                $tgtStmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :id LIMIT 1");
                $tgtStmt->execute([':id' => $targetId]);
                $targetUser = $tgtStmt->fetch();

                if (!$targetUser) {
                    $errors[] = "User record not found.";
                } else {
                    try {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $updStmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
                        $updStmt->execute([':password' => $hashedPassword, ':id' => $targetId]);

                        log_activity($pdo, 'user_password_reset', 'user', $targetId, "Administrative password reset performed for {$targetUser['email']}");
                        set_flash('success', "Password for '{$targetUser['name']}' has been reset successfully.");
                        redirect(BASE_URL . "settings/users.php");
                    } catch (PDOException $e) {
                        error_log("Reset password error: " . $e->getMessage());
                        $errors[] = "Database error while resetting password.";
                    }
                }
            }
        }

        // 5. DELETE USER (Safe with integrity checks)
        elseif ($action === 'delete_user') {
            $targetId = (int)($_POST['target_user_id'] ?? 0);

            if ($targetId <= 0) {
                $errors[] = "Invalid target user ID.";
            } elseif ($targetId === $currentUserId) {
                $errors[] = "You cannot delete your own administrator account.";
            } else {
                $tgtStmt = $pdo->prepare("SELECT id, name, email, role, status FROM users WHERE id = :id LIMIT 1");
                $tgtStmt->execute([':id' => $targetId]);
                $targetUser = $tgtStmt->fetch();

                if (!$targetUser) {
                    $errors[] = "User record not found.";
                } else {
                    if ($targetUser['role'] === 'admin') {
                        $adminCountStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id != :id");
                        $adminCountStmt->execute([':id' => $targetId]);
                        if ((int)$adminCountStmt->fetchColumn() < 1) {
                            $errors[] = "Cannot delete the last active administrator in the system.";
                        }
                    }

                    // Check foreign key dependencies
                    if (empty($errors)) {
                        $repCheck = $pdo->prepare("SELECT COUNT(*) FROM repayments WHERE user_id = :id");
                        $repCheck->execute([':id' => $targetId]);
                        $repCount = (int)$repCheck->fetchColumn();

                        $actCheck = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE user_id = :id");
                        $actCheck->execute([':id' => $targetId]);
                        $actCount = (int)$actCheck->fetchColumn();

                        if ($repCount > 0 || $actCount > 0) {
                            $errors[] = "Cannot delete this user because they have recorded repayments or audit trail entries. Deactivate the account instead to disable access.";
                        }
                    }

                    if (empty($errors)) {
                        try {
                            $delStmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
                            $delStmt->execute([':id' => $targetId]);

                            log_activity($pdo, 'user_delete', 'user', null, "Deleted user account: {$targetUser['email']}");
                            set_flash('success', "User account '{$targetUser['name']}' was deleted.");
                            redirect(BASE_URL . "settings/users.php");
                        } catch (PDOException $e) {
                            error_log("Delete user error: " . $e->getMessage());
                            $errors[] = "Database error while deleting user.";
                        }
                    }
                }
            }
        }
    }
}

// Fetch all users
$userList = [];
$counts = ['total' => 0, 'active' => 0, 'inactive' => 0, 'admins' => 0, 'staff' => 0];

try {
    $listStmt = $pdo->query("SELECT id, name, email, role, status, last_login_at, created_at FROM users ORDER BY id ASC");
    $userList = $listStmt->fetchAll();

    foreach ($userList as $u) {
        $counts['total']++;
        if ($u['status'] === 'active') {
            $counts['active']++;
        } else {
            $counts['inactive']++;
        }
        if ($u['role'] === 'admin') {
            $counts['admins']++;
        } else {
            $counts['staff']++;
        }
    }
} catch (PDOException $e) {
    error_log("Users list query error: " . $e->getMessage());
}

$flash = get_flash();
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
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>settings/" class="text-decoration-none">Settings</a></li>
                    <li class="breadcrumb-item active" aria-current="page">User Management</li>
                </ol>
            </nav>

            <!-- Flash Alert -->
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show shadow-sm border-0 mb-4" role="alert">
                    <i class="fa-solid <?php echo ($flash['type'] === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> me-2"></i>
                    <?php echo htmlspecialchars($flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Form Errors -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 mb-4" role="alert">
                    <div class="d-flex align-items-center mb-1">
                        <i class="fa-solid fa-circle-exclamation me-2"></i>
                        <strong>Please correct the following errors:</strong>
                    </div>
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="page-title">User Administration &amp; Access Control</h1>
                    <p class="page-subtitle">Manage administrative accounts, role assignments, and authentication status</p>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary quick-action-btn" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="fa-solid fa-user-plus"></i>
                        <span>Add New User</span>
                    </button>
                    <a href="<?php echo BASE_URL; ?>settings/" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-gear"></i>
                        <span>System Settings</span>
                    </a>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card py-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="stat-label">Total Users</span>
                                <div class="h4 fw-bold text-dark mb-0 tabular-nums"><?php echo $counts['total']; ?></div>
                            </div>
                            <div class="stat-icon-wrapper stat-icon-blue" style="width: 40px; height: 40px; font-size: 1.1rem;">
                                <i class="fa-solid fa-users"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="stat-card py-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="stat-label">Active Accounts</span>
                                <div class="h4 fw-bold text-success mb-0 tabular-nums"><?php echo $counts['active']; ?></div>
                            </div>
                            <div class="stat-icon-wrapper stat-icon-emerald" style="width: 40px; height: 40px; font-size: 1.1rem;">
                                <i class="fa-solid fa-user-check"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="stat-card py-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="stat-label">Administrators</span>
                                <div class="h4 fw-bold text-primary mb-0 tabular-nums"><?php echo $counts['admins']; ?></div>
                            </div>
                            <div class="stat-icon-wrapper stat-icon-amber" style="width: 40px; height: 40px; font-size: 1.1rem;">
                                <i class="fa-solid fa-user-shield"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="stat-card py-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="stat-label">Staff Members</span>
                                <div class="h4 fw-bold text-secondary mb-0 tabular-nums"><?php echo $counts['staff']; ?></div>
                            </div>
                            <div class="stat-icon-wrapper stat-icon-teal" style="width: 40px; height: 40px; font-size: 1.1rem;">
                                <i class="fa-solid fa-id-badge"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Users Directory Card -->
            <div class="content-card">
                <div class="content-card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h2 class="content-card-title mb-0">System Users</h2>
                        <p class="content-card-subtitle mb-0">List of registered accounts with authorization roles and security statuses</p>
                    </div>
                    <span class="badge bg-light text-secondary border"><?php echo count($userList); ?> Accounts</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="min-width: 220px;">User</th>
                                <th scope="col" style="min-width: 120px;">Role</th>
                                <th scope="col" style="min-width: 110px;">Status</th>
                                <th scope="col" style="min-width: 170px;">Last Login</th>
                                <th scope="col" style="min-width: 140px;">Created</th>
                                <th scope="col" class="text-end" style="min-width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($userList)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="fa-solid fa-user-slash fs-2 mb-2 d-block text-secondary"></i>
                                        No users registered in the system.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($userList as $user): 
                                    $isSelf = ((int)$user['id'] === $currentUserId);
                                    $initial = strtoupper(substr($user['name'] ?? 'U', 0, 1));
                                ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="navbar-user-avatar" style="width: 38px; height: 38px; font-size: 0.95rem;">
                                                    <?php echo htmlspecialchars($initial); ?>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold text-dark d-flex align-items-center gap-2">
                                                        <?php echo htmlspecialchars($user['name']); ?>
                                                        <?php if ($isSelf): ?>
                                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.65rem;">You</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($user['email']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($user['role'] === 'admin'): ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                                    <i class="fa-solid fa-user-shield me-1"></i> Admin
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-2 py-1">
                                                    <i class="fa-solid fa-id-badge me-1"></i> Staff
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($user['status'] === 'active'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                    <i class="fa-solid fa-circle-check me-1"></i> Active
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                                    <i class="fa-solid fa-ban me-1"></i> Inactive
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($user['last_login_at'])): ?>
                                                <div class="small text-dark tabular-nums"><?php echo date('M d, Y', strtotime($user['last_login_at'])); ?></div>
                                                <div class="text-muted" style="font-size: 0.72rem;"><?php echo date('h:i A', strtotime($user['last_login_at'])); ?></div>
                                            <?php else: ?>
                                                <span class="text-muted small">Never logged in</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small text-dark tabular-nums"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></div>
                                        </td>
                                        <td class="text-end">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User actions">
                                                    Manage
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                    <?php if ($isSelf): ?>
                                                        <li>
                                                            <a class="dropdown-item" href="<?php echo BASE_URL; ?>settings/index.php?tab=profile">
                                                                <i class="fa-solid fa-user-pen me-2 text-primary"></i> Edit My Profile
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item" href="<?php echo BASE_URL; ?>settings/index.php?tab=security">
                                                                <i class="fa-solid fa-key me-2 text-warning"></i> Change My Password
                                                            </a>
                                                        </li>
                                                    <?php else: ?>
                                                        <!-- Reset Password -->
                                                        <li>
                                                            <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#resetPasswordModal"
                                                                    data-user-id="<?php echo (int)$user['id']; ?>"
                                                                    data-user-name="<?php echo htmlspecialchars($user['name']); ?>">
                                                                <i class="fa-solid fa-key me-2 text-warning"></i> Reset Password
                                                            </button>
                                                        </li>

                                                        <!-- Change Role -->
                                                        <li>
                                                            <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#changeRoleModal"
                                                                    data-user-id="<?php echo (int)$user['id']; ?>"
                                                                    data-user-name="<?php echo htmlspecialchars($user['name']); ?>"
                                                                    data-current-role="<?php echo htmlspecialchars($user['role']); ?>">
                                                                <i class="fa-solid fa-user-shield me-2 text-info"></i> Change Role
                                                            </button>
                                                        </li>

                                                        <!-- Toggle Status -->
                                                        <li>
                                                            <form method="POST" action="<?php echo BASE_URL; ?>settings/users.php" class="d-inline" onsubmit="return confirm('Are you sure you want to <?php echo ($user['status'] === 'active') ? 'deactivate' : 'activate'; ?> this user?');">
                                                                <?php echo csrf_field(); ?>
                                                                <input type="hidden" name="form_action" value="toggle_status">
                                                                <input type="hidden" name="target_user_id" value="<?php echo (int)$user['id']; ?>">
                                                                <input type="hidden" name="new_status" value="<?php echo ($user['status'] === 'active') ? 'inactive' : 'active'; ?>">
                                                                <button type="submit" class="dropdown-item <?php echo ($user['status'] === 'active') ? 'text-danger' : 'text-success'; ?>">
                                                                    <?php if ($user['status'] === 'active'): ?>
                                                                        <i class="fa-solid fa-user-slash me-2"></i> Deactivate Account
                                                                    <?php else: ?>
                                                                        <i class="fa-solid fa-user-check me-2"></i> Activate Account
                                                                    <?php endif; ?>
                                                                </button>
                                                            </form>
                                                        </li>

                                                        <li><hr class="dropdown-divider"></li>

                                                        <!-- Delete Account Option -->
                                                        <li>
                                                            <form method="POST" action="<?php echo BASE_URL; ?>settings/users.php" class="d-inline" onsubmit="return confirm('Permanently delete user <?php echo htmlspecialchars($user['name']); ?>? This cannot be undone.');">
                                                                <?php echo csrf_field(); ?>
                                                                <input type="hidden" name="form_action" value="delete_user">
                                                                <input type="hidden" name="target_user_id" value="<?php echo (int)$user['id']; ?>">
                                                                <button type="submit" class="dropdown-item text-danger">
                                                                    <i class="fa-solid fa-trash-can me-2"></i> Delete User
                                                                </button>
                                                            </form>
                                                        </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Security Policy Notice Card -->
            <div class="card mt-4 border-0 shadow-sm bg-light">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex align-items-start gap-3">
                        <div class="stat-icon-wrapper stat-icon-blue flex-shrink-0" style="width: 38px; height: 38px;">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div>
                            <h3 class="h6 fw-bold text-dark mb-1">Administrative Safety Guarantees</h3>
                            <p class="small text-muted mb-0">
                                <strong>Self-Protection:</strong> You cannot deactivate, demote, or delete your own active administrator session.
                                <br>
                                <strong>Last Admin Protection:</strong> The system strictly prohibits deactivating or demoting the last active administrator, ensuring administrative continuity.
                                <br>
                                <strong>Credential Privacy:</strong> Passwords are never shown in plaintext, never written to activity logs, and are securely hashed using bcrypt.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- ============================================================= -->
<!-- MODAL 1: ADD NEW USER                                         -->
<!-- ============================================================= -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="<?php echo BASE_URL; ?>settings/users.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_action" value="create_user">

                <div class="modal-header border-bottom">
                    <h5 class="modal-title fw-bold" id="addUserModalLabel">
                        <i class="fa-solid fa-user-plus text-primary me-2"></i> Add New User
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="new_name" class="form-label fw-semibold small text-secondary">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="new_name" name="name" required maxlength="100" placeholder="e.g. Rahul Verma">
                    </div>

                    <div class="mb-3">
                        <label for="new_email" class="form-label fw-semibold small text-secondary">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="new_email" name="email" required maxlength="150" placeholder="e.g. rahul@moneylend.local">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="new_role" class="form-label fw-semibold small text-secondary">Role <span class="text-danger">*</span></label>
                            <select class="form-select" id="new_role" name="role" required>
                                <option value="staff" selected>Staff (Operational)</option>
                                <option value="admin">Admin (Full Control)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label for="new_status" class="form-label fw-semibold small text-secondary">Initial Status <span class="text-danger">*</span></label>
                            <select class="form-select" id="new_status" name="status" required>
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label fw-semibold small text-secondary">Password <span class="text-danger">*</span> (Min <?php echo $minPasswordLength; ?> chars)</label>
                        <input type="password" class="form-control" id="new_password" name="password" required minlength="<?php echo $minPasswordLength; ?>" placeholder="Enter secure password">
                    </div>

                    <div class="mb-3">
                        <label for="new_confirm_password" class="form-label fw-semibold small text-secondary">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="new_confirm_password" name="confirm_password" required minlength="<?php echo $minPasswordLength; ?>" placeholder="Confirm password">
                    </div>
                </div>

                <div class="modal-footer border-top bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-check me-1"></i> Create User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================= -->
<!-- MODAL 2: RESET PASSWORD                                       -->
<!-- ============================================================= -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="<?php echo BASE_URL; ?>settings/users.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_action" value="reset_password">
                <input type="hidden" name="target_user_id" id="reset_user_id" value="">

                <div class="modal-header border-bottom">
                    <h5 class="modal-title fw-bold" id="resetPasswordModalLabel">
                        <i class="fa-solid fa-key text-warning me-2"></i> Reset User Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <p class="small text-muted mb-3">
                        Set a new password for <strong id="reset_user_name_display" class="text-dark">User</strong>.
                    </p>

                    <div class="mb-3">
                        <label for="reset_new_password" class="form-label fw-semibold small text-secondary">New Password <span class="text-danger">*</span> (Min <?php echo $minPasswordLength; ?> chars)</label>
                        <input type="password" class="form-control" id="reset_new_password" name="new_password" required minlength="<?php echo $minPasswordLength; ?>" placeholder="Enter new password">
                    </div>

                    <div class="mb-3">
                        <label for="reset_confirm_password" class="form-label fw-semibold small text-secondary">Confirm New Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="reset_confirm_password" name="confirm_password" required minlength="<?php echo $minPasswordLength; ?>" placeholder="Confirm new password">
                    </div>
                </div>

                <div class="modal-footer border-top bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fa-solid fa-check me-1"></i> Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================= -->
<!-- MODAL 3: CHANGE ROLE                                          -->
<!-- ============================================================= -->
<div class="modal fade" id="changeRoleModal" tabindex="-1" aria-labelledby="changeRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="<?php echo BASE_URL; ?>settings/users.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_action" value="change_role">
                <input type="hidden" name="target_user_id" id="role_user_id" value="">

                <div class="modal-header border-bottom">
                    <h5 class="modal-title fw-bold" id="changeRoleModalLabel">
                        <i class="fa-solid fa-user-shield text-info me-2"></i> Change User Role
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <p class="small text-muted mb-3">
                        Adjust permission role for <strong id="role_user_name_display" class="text-dark">User</strong>.
                    </p>

                    <div class="mb-3">
                        <label for="role_select" class="form-label fw-semibold small text-secondary">Assign Role <span class="text-danger">*</span></label>
                        <select class="form-select" id="role_select" name="new_role" required>
                            <option value="staff">Staff (Borrowers, Loans, Repayments, Reports, Notifications)</option>
                            <option value="admin">Admin (Full System Access &amp; User Administration)</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer border-top bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info text-white">
                        <i class="fa-solid fa-check me-1"></i> Update Role
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Reset Password modal population
    const resetModal = document.getElementById('resetPasswordModal');
    if (resetModal) {
        resetModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const userId = button.getAttribute('data-user-id');
            const userName = button.getAttribute('data-user-name');
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_user_name_display').textContent = userName;
            document.getElementById('reset_new_password').value = '';
            document.getElementById('reset_confirm_password').value = '';
        });
    }

    // Change Role modal population
    const roleModal = document.getElementById('changeRoleModal');
    if (roleModal) {
        roleModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const userId = button.getAttribute('data-user-id');
            const userName = button.getAttribute('data-user-name');
            const currentRole = button.getAttribute('data-current-role');
            document.getElementById('role_user_id').value = userId;
            document.getElementById('role_user_name_display').textContent = userName;
            document.getElementById('role_select').value = currentRole;
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
