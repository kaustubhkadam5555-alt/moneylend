<?php
/**
 * MoneyLend - Loan Calculation & Status Helper
 * 
 * Centralized financial calculations for loan interest, due dates,
 * repayment balances, and automated loan status evaluation.
 */

/**
 * Calculate total interest and total payable for flat or simple interest.
 *
 * @param float $principal
 * @param float $interestRate
 * @param string $interestType 'flat' or 'simple'
 * @param int $durationValue
 * @param string $durationUnit 'months' or 'years'
 * @return array ['total_interest' => float, 'total_payable' => float]
 */
function calculate_loan_totals(float $principal, float $interestRate, string $interestType, int $durationValue, string $durationUnit): array {
    $principal = max(0, $principal);
    $interestRate = max(0, $interestRate);
    $durationValue = max(1, $durationValue);

    if ($interestType === 'simple') {
        // Simple Interest: (P * R * T) / 100 where T is in years
        $timeInYears = ($durationUnit === 'years') ? (float)$durationValue : ((float)$durationValue / 12.0);
        $totalInterest = ($principal * $interestRate * $timeInYears) / 100.0;
    } else {
        // Flat Interest: P * R / 100
        $totalInterest = ($principal * $interestRate) / 100.0;
    }

    $totalInterest = round($totalInterest, 2);
    $totalPayable = round($principal + $totalInterest, 2);

    return [
        'total_interest' => $totalInterest,
        'total_payable'  => $totalPayable
    ];
}

/**
 * Calculate recommended due date from start date and duration.
 *
 * @param string $startDate 'Y-m-d'
 * @param int $durationValue
 * @param string $durationUnit 'months' or 'years'
 * @return string 'Y-m-d'
 */
function calculate_due_date(string $startDate, int $durationValue, string $durationUnit): string {
    try {
        $date = new DateTime($startDate);
    } catch (Exception $e) {
        $date = new DateTime();
    }

    $durationValue = max(1, $durationValue);
    if ($durationUnit === 'years') {
        $date->modify("+{$durationValue} years");
    } else {
        $date->modify("+{$durationValue} months");
    }

    return $date->format('Y-m-d');
}

/**
 * Automatically determine appropriate loan status based on balance and due date.
 *
 * Rules:
 * - Paid: remaining_balance <= 0
 * - Partially Paid: amount_repaid > 0 AND remaining_balance > 0
 * - Overdue: current date > due_date AND remaining_balance > 0
 * - Active: remaining_balance > 0 AND current date <= due_date
 * - Cancelled: manual status preserved
 *
 * @param string $currentStatus
 * @param float $remainingBalance
 * @param float $amountRepaid
 * @param string $dueDate
 * @return string
 */
function determine_loan_status(string $currentStatus, float $remainingBalance, float $amountRepaid, string $dueDate): string {
    if ($currentStatus === 'cancelled') {
        return 'cancelled';
    }

    if ($remainingBalance <= 0.001) {
        return 'paid';
    }

    $today = date('Y-m-d');
    if ($today > $dueDate && $remainingBalance > 0) {
        return 'overdue';
    }

    if ($amountRepaid > 0 && $remainingBalance > 0) {
        return 'partially_paid';
    }

    return 'active';
}

/**
 * Get CSS badge markup for loan status.
 *
 * @param string $status
 * @return string HTML badge string
 */
function get_loan_status_badge(string $status): string {
    switch ($status) {
        case 'active':
            return '<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><i class="fa-solid fa-circle-dot me-1" style="font-size: 0.55rem;"></i> Active</span>';
        case 'partially_paid':
            return '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-2 py-1"><i class="fa-solid fa-circle-half-stroke me-1" style="font-size: 0.55rem;"></i> Partially Paid</span>';
        case 'paid':
            return '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-circle-check me-1" style="font-size: 0.55rem;"></i> Paid</span>';
        case 'overdue':
            return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1"><i class="fa-solid fa-triangle-exclamation me-1" style="font-size: 0.55rem;"></i> Overdue</span>';
        case 'cancelled':
            return '<span class="badge bg-secondary-subtle text-secondary border px-2 py-1"><i class="fa-solid fa-ban me-1" style="font-size: 0.55rem;"></i> Cancelled</span>';
        default:
            return '<span class="badge bg-light text-secondary border px-2 py-1">' . htmlspecialchars(ucfirst($status)) . '</span>';
    }
}

/**
 * Atomically synchronize a loan's repayment sum, remaining balance, and status.
 * Should be called inside an active transaction or with a valid PDO instance.
 *
 * @param PDO $pdo
 * @param int $loanId
 * @return array ['total_payable' => float, 'amount_repaid' => float, 'remaining_balance' => float, 'status' => string]
 * @throws Exception
 */
function sync_loan_balances(PDO $pdo, int $loanId): array {
    $stmt = $pdo->prepare("SELECT id, total_payable, status, due_date FROM loans WHERE id = :id FOR UPDATE");
    $stmt->execute([':id' => $loanId]);
    $loan = $stmt->fetch();
    if (!$loan) {
        throw new Exception("Loan #{$loanId} not found.");
    }

    // Sum all recorded repayments for this loan
    $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM repayments WHERE loan_id = :id");
    $sumStmt->execute([':id' => $loanId]);
    $totalRepaid = round((float)$sumStmt->fetchColumn(), 2);

    $totalPayable = round((float)$loan['total_payable'], 2);
    $remainingBalance = round(max(0.0, $totalPayable - $totalRepaid), 2);

    // Automated status determination
    $newStatus = determine_loan_status($loan['status'], $remainingBalance, $totalRepaid, $loan['due_date']);

    // Persist to database
    $updateStmt = $pdo->prepare("
        UPDATE loans 
        SET amount_repaid = :repaid,
            remaining_balance = :remaining,
            status = :status,
            updated_at = NOW()
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':repaid'    => $totalRepaid,
        ':remaining' => $remainingBalance,
        ':status'    => $newStatus,
        ':id'        => $loanId
    ]);

    return [
        'total_payable'     => $totalPayable,
        'amount_repaid'     => $totalRepaid,
        'remaining_balance' => $remainingBalance,
        'status'            => $newStatus
    ];
}

