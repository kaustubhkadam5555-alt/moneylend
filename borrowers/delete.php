<?php
/**
 * MoneyLend - Delete Borrower Handler
 * 
 * Safely processes borrower deletion requests.
 * Enforces foreign-key safety: blocks deletion if borrower has loan contracts.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Protect page
require_login();

// Only allow POST requests for deletion
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . BASE_URL . "borrowers/index.php");
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
$borrowerId = (int)($_POST['borrower_id'] ?? 0);

// Validate CSRF token
if (!verify_csrf_token($csrfToken)) {
    set_flash('danger', 'Security validation failed. Deletion was cancelled.');
    header("Location: " . BASE_URL . "borrowers/index.php");
    exit;
}

if ($borrowerId <= 0) {
    set_flash('danger', 'Invalid borrower ID specified for deletion.');
    header("Location: " . BASE_URL . "borrowers/index.php");
    exit;
}

$pdo = getDBConnection();

try {
    // 1. Fetch borrower to confirm existence and get name
    $stmt = $pdo->prepare("SELECT id, full_name FROM borrowers WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $borrowerId]);
    $borrower = $stmt->fetch();

    if (!$borrower) {
        set_flash('warning', 'Borrower record not found or already deleted.');
        header("Location: " . BASE_URL . "borrowers/index.php");
        exit;
    }

    // 2. Check for associated loans (foreign-key safeguard)
    $loanCheckStmt = $pdo->prepare("SELECT COUNT(*) AS total_loans FROM loans WHERE borrower_id = :id");
    $loanCheckStmt->execute([':id' => $borrowerId]);
    $loanCount = (int)$loanCheckStmt->fetchColumn();

    if ($loanCount > 0) {
        set_flash('danger', "Cannot delete borrower '{$borrower['full_name']}' because they have {$loanCount} associated loan record(s). Financial records must be retained for audit integrity.");
        header("Location: " . BASE_URL . "borrowers/index.php");
        exit;
    }

    // 3. Safe deletion
    $deleteStmt = $pdo->prepare("DELETE FROM borrowers WHERE id = :id");
    $deleteStmt->execute([':id' => $borrowerId]);

    set_flash('success', "Borrower '{$borrower['full_name']}' has been permanently deleted.");
    header("Location: " . BASE_URL . "borrowers/index.php");
    exit;
} catch (PDOException $e) {
    error_log("Borrower delete error: " . $e->getMessage());
    set_flash('danger', 'A database error occurred while attempting to delete the borrower.');
    header("Location: " . BASE_URL . "borrowers/index.php");
    exit;
}
