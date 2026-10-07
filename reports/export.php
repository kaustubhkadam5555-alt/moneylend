<?php
/**
 * MoneyLend - Reports CSV Export Handler
 * 
 * Streams real-time database report exports in clean CSV format.
 * Supports filtering by date range, loan status, payment method, and borrower.
 * Exports either Loan Portfolio Report or Repayments Collection Report.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

/**
 * Sanitize a CSV cell value against CSV formula injection (DDE / command execution).
 * If a non-numeric text string begins with '=', '+', '-', '@', "\t", or "\r", prefix with a single quote (').
 *
 * @param mixed $value
 * @return mixed
 */
function sanitize_csv_cell($value) {
    if (is_string($value) && $value !== '') {
        $firstChar = $value[0];
        if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"], true)) {
            if (!is_numeric($value)) {
                return "'" . $value;
            }
        }
    }
    return $value;
}

$pdo = getDBConnection();

$exportType     = $_GET['type'] ?? 'loans'; // 'loans' or 'repayments'
$dateRange      = $_GET['date_range'] ?? 'all';
$fromDate       = $_GET['from_date'] ?? '';
$toDate         = $_GET['to_date'] ?? '';
$statusFilter   = $_GET['status'] ?? '';
$methodFilter   = $_GET['method'] ?? '';
$borrowerFilter = isset($_GET['borrower_id']) ? (int)$_GET['borrower_id'] : 0;

// Resolve Date Range Boundaries
$today = date('Y-m-d');
$startDate = null;
$endDate = null;

switch ($dateRange) {
    case 'today':
        $startDate = $today;
        $endDate = $today;
        break;
    case 'yesterday':
        $startDate = date('Y-m-d', strtotime('-1 day'));
        $endDate = $startDate;
        break;
    case 'this_week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'this_month':
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
        break;
    case 'last_month':
        $startDate = date('Y-m-01', strtotime('first day of last month'));
        $endDate = date('Y-m-t', strtotime('last day of last month'));
        break;
    case 'this_year':
        $startDate = date('Y-01-01');
        $endDate = date('Y-12-31');
        break;
    case 'custom':
        if (!empty($fromDate) && strtotime($fromDate)) {
            $startDate = $fromDate;
        }
        if (!empty($toDate) && strtotime($toDate)) {
            $endDate = $toDate;
        }
        break;
    case 'all':
    default:
        $startDate = null;
        $endDate = null;
        $dateRange = 'all';
        break;
}

if ($exportType === 'repayments') {
    // -------------------------------------------------------------
    // EXPORT: REPAYMENTS COLLECTION REPORT
    // -------------------------------------------------------------
    $filename = "moneylend_repayments_report_" . date('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // Output UTF-8 BOM for proper Excel rendering
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // CSV Header row
    fputcsv($output, [
        'Receipt ID',
        'Payment Date',
        'Borrower Name',
        'Borrower Phone',
        'Loan ID',
        'Amount Paid (INR)',
        'Payment Method',
        'Transaction Notes',
        'Recorded By',
        'Logged At'
    ]);

    $query = "
        SELECT 
            r.id,
            r.payment_date,
            b.full_name AS borrower_name,
            b.phone AS borrower_phone,
            r.loan_id,
            r.amount,
            r.payment_method,
            COALESCE(r.notes, '') AS notes,
            COALESCE(u.name, 'Admin User') AS recorded_by_name,
            r.created_at
        FROM repayments r
        JOIN loans l ON r.loan_id = l.id
        JOIN borrowers b ON l.borrower_id = b.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE 1=1
    ";
    $params = [];

    if ($startDate !== null) {
        $query .= " AND r.payment_date >= :start_date";
        $params[':start_date'] = $startDate;
    }
    if ($endDate !== null) {
        $query .= " AND r.payment_date <= :end_date";
        $params[':end_date'] = $endDate;
    }
    if (!empty($methodFilter)) {
        $query .= " AND r.payment_method = :method";
        $params[':method'] = $methodFilter;
    }
    if ($borrowerFilter > 0) {
        $query .= " AND l.borrower_id = :borrower_id";
        $params[':borrower_id'] = $borrowerFilter;
    }

    $query .= " ORDER BY r.payment_date DESC, r.id DESC";

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $csvRow = [
                '#' . $row['id'],
                $row['payment_date'],
                $row['borrower_name'],
                $row['borrower_phone'],
                'Loan #' . $row['loan_id'],
                number_format((float)$row['amount'], 2, '.', ''),
                $row['payment_method'],
                $row['notes'],
                $row['recorded_by_name'],
                $row['created_at']
            ];
            fputcsv($output, array_map('sanitize_csv_cell', $csvRow));
        }
    } catch (PDOException $e) {
        error_log("CSV Export error: " . $e->getMessage());
    }

    fclose($output);
    exit;

} else {
    // -------------------------------------------------------------
    // EXPORT: LOANS PORTFOLIO REPORT
    // -------------------------------------------------------------
    $filename = "moneylend_loans_portfolio_" . date('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // Output UTF-8 BOM for proper Excel rendering
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // CSV Header row
    fputcsv($output, [
        'Loan ID',
        'Borrower Name',
        'Borrower Phone',
        'Borrower Email',
        'Principal Amount (INR)',
        'Interest Rate (%)',
        'Interest Type',
        'Duration',
        'Total Interest (INR)',
        'Total Payable (INR)',
        'Amount Repaid (INR)',
        'Remaining Balance (INR)',
        'Start Date',
        'Due Date',
        'Status',
        'Created At'
    ]);

    $query = "
        SELECT 
            l.id,
            b.full_name AS borrower_name,
            b.phone AS borrower_phone,
            COALESCE(b.email, '') AS borrower_email,
            l.principal_amount,
            l.interest_rate,
            l.interest_type,
            CONCAT(l.duration_value, ' ', l.duration_unit) AS duration_str,
            l.total_interest,
            l.total_payable,
            l.amount_repaid,
            l.remaining_balance,
            l.start_date,
            l.due_date,
            l.status,
            l.created_at
        FROM loans l
        JOIN borrowers b ON l.borrower_id = b.id
        WHERE l.status != 'cancelled'
    ";
    $params = [];

    if ($startDate !== null) {
        $query .= " AND l.start_date >= :start_date";
        $params[':start_date'] = $startDate;
    }
    if ($endDate !== null) {
        $query .= " AND l.start_date <= :end_date";
        $params[':end_date'] = $endDate;
    }
    if (!empty($statusFilter)) {
        $allowed = ['active', 'partially_paid', 'paid', 'overdue'];
        if (in_array($statusFilter, $allowed)) {
            $query .= " AND l.status = :status";
            $params[':status'] = $statusFilter;
        }
    }
    if ($borrowerFilter > 0) {
        $query .= " AND l.borrower_id = :borrower_id";
        $params[':borrower_id'] = $borrowerFilter;
    }

    $query .= " ORDER BY l.id DESC";

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $csvRow = [
                '#' . $row['id'],
                $row['borrower_name'],
                $row['borrower_phone'],
                $row['borrower_email'],
                number_format((float)$row['principal_amount'], 2, '.', ''),
                number_format((float)$row['interest_rate'], 2, '.', ''),
                ucfirst($row['interest_type']),
                $row['duration_str'],
                number_format((float)$row['total_interest'], 2, '.', ''),
                number_format((float)$row['total_payable'], 2, '.', ''),
                number_format((float)$row['amount_repaid'], 2, '.', ''),
                number_format((float)$row['remaining_balance'], 2, '.', ''),
                $row['start_date'],
                $row['due_date'],
                ucfirst(str_replace('_', ' ', $row['status'])),
                $row['created_at']
            ];
            fputcsv($output, array_map('sanitize_csv_cell', $csvRow));
        }
    } catch (PDOException $e) {
        error_log("CSV Export error: " . $e->getMessage());
    }

    fclose($output);
    exit;
}
