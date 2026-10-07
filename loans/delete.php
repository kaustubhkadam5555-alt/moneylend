<?php
/**
 * MoneyLend - Delete / Cancel Loan Handler
 * 
 * Safely handles loan deletion or cancellation requests.
 * Enforces financial audit integrity: loans with repayments cannot be deleted,
 * but are safely marked as 'cancelled' instead.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Protect page
require_login();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . BASE_URL . "loans/index.php");
    exit;
}

$csrfToken  = $_POST['csrf_token'] ?? '';
$loanId     = (int)($_POST['loan_id'] ?? 0);
$actionType = $_POST['action_type'] ?? 'delete';

// Validate CSRF token
if (!verify_csrf_token($csrfToken)) {
    set_flash('danger', 'Security validation failed. Loan action was cancelled.');
    header("Location: " . BASE_URL . "loans/index.php");
    exit;
}

if ($loanId <= 0) {
    set_flash('danger', 'Invalid loan ID specified.');
    header("Location: " . BASE_URL . "loans/index.php");
    exit;
}

$pdo = getDBConnection();

try {
    // 1. Fetch loan to confirm existence and inspect repayments
    $stmt = $pdo->prepare("SELECT id, borrower_id, principal_amount, amount_repaid, status FROM loans WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $loanId]);
    $loan = $stmt->fetch();

    if (!$loan) {
        set_flash('warning', "Loan agreement #{$loanId} was not found or already deleted.");
        header("Location: " . BASE_URL . "loans/index.php");
        exit;
    }

    // 2. Check for repayments in the repayments table
    $repStmt = $pdo->prepare("SELECT COUNT(*) AS rep_count, COALESCE(SUM(amount), 0) AS total_repaid FROM repayments WHERE loan_id = :id");
    $repStmt->execute([':id' => $loanId]);
    $repInfo = $repStmt->fetch();

    $repaymentCount = (int)$repInfo['rep_count'];
    $repaidAmount   = (float)$repInfo['total_repaid'];

    // 3. Process cancellation or deletion
    if ($actionType === 'cancel' || $repaymentCount > 0 || $repaidAmount > 0) {
        // Safe cancellation instead of deletion
        $cancelStmt = $pdo->prepare("UPDATE loans SET status = 'cancelled', updated_at = NOW() WHERE id = :id");
        $cancelStmt->execute([':id' => $loanId]);

        set_flash('warning', "Loan agreement #{$loanId} has repayments recorded (" . CURRENCY_SYMBOL . number_format($repaidAmount, 2) . "). It was marked as Cancelled to preserve audit integrity.");
        header("Location: " . BASE_URL . "loans/index.php");
        exit;
    } else {
        // Safe permanent deletion (zero repayments)
        $delStmt = $pdo->prepare("DELETE FROM loans WHERE id = :id");
        $delStmt->execute([':id' => $loanId]);

        set_flash('success', "Loan agreement #{$loanId} has been permanently deleted.");
        header("Location: " . BASE_URL . "loans/index.php");
        exit;
    }

} catch (PDOException $e) {
    error_log("Loan delete/cancel error: " . $e->getMessage());
    set_flash('danger', 'A database error occurred while processing the loan action.');
    header("Location: " . BASE_URL . "loans/index.php");
    exit;
}
