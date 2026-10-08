<?php
/**
 * MoneyLend - Notification System Helper (Phase 11)
 *
 * Provides central management for internal alerts, due-date notifications,
 * overdue monitors, repayment confirmations, and idempotent reminder generation.
 * Notifications are strictly read-only observers of authoritative financial records.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings_helper.php';
require_once __DIR__ . '/mail_helper.php';

/**
 * Create a new internal notification with strict idempotency protection.
 *
 * @param PDO $pdo
 * @param array $data Notification attributes
 * @return int|null Notification ID if inserted, null if duplicate or error
 */
function create_notification(PDO $pdo, array $data): ?int {
    try {
        $userId = isset($data['user_id']) ? (int)$data['user_id'] : 1;
        $borrowerId = !empty($data['borrower_id']) ? (int)$data['borrower_id'] : null;
        $loanId = !empty($data['loan_id']) ? (int)$data['loan_id'] : null;
        $repaymentId = !empty($data['repayment_id']) ? (int)$data['repayment_id'] : null;
        $type = substr(trim($data['type'] ?? 'system'), 0, 50);
        $title = substr(trim($data['title'] ?? 'Notification'), 0, 150);
        $message = trim($data['message'] ?? '');
        $severity = in_array($data['severity'] ?? '', ['info', 'success', 'warning', 'danger'], true) ? $data['severity'] : 'info';
        $eventKey = substr(trim($data['event_key'] ?? uniqid('evt_', true)), 0, 100);

        // Check for idempotency: if notification with same (user_id, event_key) already exists, skip
        $checkStmt = $pdo->prepare("SELECT id FROM notifications WHERE user_id = :uid AND event_key = :ek LIMIT 1");
        $checkStmt->execute([':uid' => $userId, ':ek' => $eventKey]);
        $existingId = $checkStmt->fetchColumn();
        if ($existingId) {
            return null; // Idempotent skip
        }

        $stmt = $pdo->prepare("
            INSERT INTO notifications (
                user_id, borrower_id, loan_id, repayment_id,
                type, title, message, severity, event_key,
                is_read, read_at, created_at
            ) VALUES (
                :user_id, :borrower_id, :loan_id, :repayment_id,
                :type, :title, :message, :severity, :event_key,
                0, NULL, NOW()
            )
        ");

        $stmt->execute([
            ':user_id'      => $userId,
            ':borrower_id'  => $borrowerId,
            ':loan_id'      => $loanId,
            ':repayment_id' => $repaymentId,
            ':type'         => $type,
            ':title'        => $title,
            ':message'      => $message,
            ':severity'     => $severity,
            ':event_key'    => $eventKey,
        ]);

        $notificationId = (int)$pdo->lastInsertId();

        // Optional email dispatch hook
        if ($borrowerId !== null && !empty($data['send_email'])) {
            try {
                $borrowerStmt = $pdo->prepare("SELECT full_name, email FROM borrowers WHERE id = :bid");
                $borrowerStmt->execute([':bid' => $borrowerId]);
                $borrower = $borrowerStmt->fetch(PDO::FETCH_ASSOC);
                if ($borrower && !empty($borrower['email'])) {
                    dispatch_notification_email(
                        $borrower['email'],
                        $borrower['full_name'],
                        $title,
                        $message,
                        ['loan_id' => $loanId, 'type' => $type]
                    );
                }
            } catch (Throwable $e) {
                error_log("Optional email dispatch error: " . $e->getMessage());
            }
        }

        return $notificationId;
    } catch (Throwable $e) {
        error_log("Notification creation error: " . $e->getMessage());
        return null;
    }
}

/**
 * Retrieve unread notification count for a specific user.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return int
 */
function get_unread_notification_count(PDO $pdo, int $userId): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0");
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log("Unread notification count error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Retrieve paginated notifications with optional read status, type, and severity filters.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param array $filters ['is_read' => ?int, 'type' => ?string, 'severity' => ?string]
 * @param int $limit
 * @param int $offset
 * @return array
 */
function get_user_notifications(PDO $pdo, int $userId, array $filters = [], int $limit = 20, int $offset = 0): array {
    try {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $sql = "
            SELECT 
                n.*,
                b.full_name AS borrower_name,
                b.phone AS borrower_phone,
                l.principal_amount,
                l.remaining_balance,
                l.due_date AS loan_due_date,
                l.status AS loan_status
            FROM notifications n
            LEFT JOIN borrowers b ON n.borrower_id = b.id
            LEFT JOIN loans l ON n.loan_id = l.id
            WHERE n.user_id = :uid
        ";
        $params = [':uid' => $userId];

        if (isset($filters['is_read']) && $filters['is_read'] !== '' && $filters['is_read'] !== null) {
            $sql .= " AND n.is_read = :is_read";
            $params[':is_read'] = (int)$filters['is_read'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND n.type = :type";
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['severity'])) {
            $sql .= " AND n.severity = :severity";
            $params[':severity'] = $filters['severity'];
        }

        $sql .= " ORDER BY n.id DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Fetch notifications error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get total count of notifications matching filters.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param array $filters
 * @return int
 */
function get_user_notifications_count(PDO $pdo, int $userId, array $filters = []): int {
    try {
        $sql = "SELECT COUNT(*) FROM notifications WHERE user_id = :uid";
        $params = [':uid' => $userId];

        if (isset($filters['is_read']) && $filters['is_read'] !== '' && $filters['is_read'] !== null) {
            $sql .= " AND is_read = :is_read";
            $params[':is_read'] = (int)$filters['is_read'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND type = :type";
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['severity'])) {
            $sql .= " AND severity = :severity";
            $params[':severity'] = $filters['severity'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log("Notification count error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Mark a single notification as read for a specific user.
 *
 * @param PDO $pdo
 * @param int $notificationId
 * @param int $userId
 * @return bool
 */
function mark_notification_read(PDO $pdo, int $notificationId, int $userId): bool {
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id = :id AND user_id = :uid
        ");
        return $stmt->execute([':id' => $notificationId, ':uid' => $userId]);
    } catch (Throwable $e) {
        error_log("Mark read error: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all unread notifications as read for a specific user.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return int Number of updated notifications
 */
function mark_all_notifications_read(PDO $pdo, int $userId): int {
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE user_id = :uid AND is_read = 0
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log("Mark all read error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Delete a specific notification for an authenticated user.
 *
 * @param PDO $pdo
 * @param int $notificationId
 * @param int $userId
 * @return bool
 */
function delete_notification(PDO $pdo, int $notificationId, int $userId): bool {
    try {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = :id AND user_id = :uid");
        return $stmt->execute([':id' => $notificationId, ':uid' => $userId]);
    } catch (Throwable $e) {
        error_log("Delete notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Safely purge old read notifications to prevent table bloat.
 * Never modifies financial or audit records.
 *
 * @param PDO $pdo
 * @param int $days Retention threshold in days (default 90)
 * @return int Number of deleted notifications
 */
function cleanup_old_notifications(PDO $pdo, int $days = 90): int {
    try {
        $days = max(7, $days);
        $stmt = $pdo->prepare("
            DELETE FROM notifications 
            WHERE is_read = 1 
              AND created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        $stmt->execute([':days' => $days]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log("Notification cleanup error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get display metadata (badge, icon, title label) for a notification type & severity.
 *
 * @param string $type
 * @param string $severity
 * @return array
 */
function get_notification_badge_info(string $type, string $severity): array {
    $severityMap = [
        'info'    => ['badge' => 'bg-info-subtle text-info-emphasis border-info-subtle', 'icon' => 'fa-circle-info', 'color' => 'info'],
        'success' => ['badge' => 'bg-success-subtle text-success border-success-subtle', 'icon' => 'fa-circle-check', 'color' => 'success'],
        'warning' => ['badge' => 'bg-warning-subtle text-warning-emphasis border-warning-subtle', 'icon' => 'fa-triangle-exclamation', 'color' => 'warning'],
        'danger'  => ['badge' => 'bg-danger-subtle text-danger border-danger-subtle', 'icon' => 'fa-circle-exclamation', 'color' => 'danger']
    ];

    $info = $severityMap[$severity] ?? $severityMap['info'];

    switch ($type) {
        case 'loan_due_soon':
            $info['icon'] = 'fa-clock';
            $info['label'] = 'Due Soon';
            break;
        case 'loan_due_today':
            $info['icon'] = 'fa-bell';
            $info['label'] = 'Due Today';
            break;
        case 'loan_overdue':
            $info['icon'] = 'fa-triangle-exclamation';
            $info['label'] = 'Overdue';
            break;
        case 'repayment_recorded':
            $info['icon'] = 'fa-receipt';
            $info['label'] = 'Repayment Logged';
            break;
        case 'loan_paid':
            $info['icon'] = 'fa-circle-check';
            $info['label'] = 'Loan Settled';
            break;
        default:
            $info['label'] = ucfirst(str_replace('_', ' ', $type));
            break;
    }

    return $info;
}

/**
 * Core Automation Engine: Scan active loans and generate due-date and overdue notifications.
 * Strictly idempotent: Multiple runs on the same day never produce duplicate notifications.
 * Never modifies financial calculations or loan balances.
 *
 * @param PDO $pdo
 * @param int|null $targetUserId System or user context (defaults to 1)
 * @param string|null $asOfDate Evaluation date in 'Y-m-d' format (defaults to current date)
 * @return array Execution summary metrics
 */
function generate_loan_notifications(PDO $pdo, ?int $targetUserId = null, ?string $asOfDate = null): array {
    $userId = $targetUserId ?? 1;
    $today = $asOfDate ?? date('Y-m-d');
    $currency = get_setting('currency_symbol', '₹');

    // Retrieve user notification preferences from settings
    $notifyDueSoon = (get_setting('notify_due_soon', '1') === '1');
    $notifyDueToday = (get_setting('notify_due_today', '1') === '1');
    $notifyOverdue = (get_setting('notify_overdue', '1') === '1');
    $dueSoonDays = max(1, (int)get_setting('reminder_due_days', '3'));
    $overdueInterval = max(1, (int)get_setting('reminder_overdue_interval', '7'));

    $stats = [
        'due_soon_created'   => 0,
        'due_today_created'  => 0,
        'overdue_created'    => 0,
        'total_created'      => 0,
        'duplicates_skipped' => 0,
        'evaluated_loans'    => 0
    ];

    try {
        // Query only active loans with outstanding balance > 0
        // Strictly exclude paid and cancelled loans
        $stmt = $pdo->prepare("
            SELECT 
                l.id,
                l.borrower_id,
                l.principal_amount,
                l.remaining_balance,
                l.due_date,
                l.status,
                b.full_name AS borrower_name,
                b.email AS borrower_email,
                b.phone AS borrower_phone
            FROM loans l
            JOIN borrowers b ON l.borrower_id = b.id
            WHERE l.status NOT IN ('cancelled', 'paid')
              AND l.remaining_balance > 0.001
            ORDER BY l.due_date ASC
        ");
        $stmt->execute();
        $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $todayTs = strtotime($today);

        foreach ($loans as $loan) {
            $stats['evaluated_loans']++;
            $loanId = (int)$loan['id'];
            $borrowerId = (int)$loan['borrower_id'];
            $borrowerName = $loan['borrower_name'];
            $balanceFormatted = $currency . number_format((float)$loan['remaining_balance'], 2);
            $dueDate = $loan['due_date'];
            $dueDateTs = strtotime($dueDate);
            $dueDateFormatted = date('M d, Y', $dueDateTs);

            // Compute calendar days difference
            // positive: future (due in N days)
            // 0: due today
            // negative: past due (overdue by N days)
            $diffDays = (int)round(($dueDateTs - $todayTs) / 86400);

            // 1. Due Today evaluation
            if ($diffDays === 0 && $notifyDueToday) {
                $eventKey = "due_today:loan_{$loanId}:{$dueDate}";
                $notifData = [
                    'user_id'     => $userId,
                    'borrower_id' => $borrowerId,
                    'loan_id'     => $loanId,
                    'type'        => 'loan_due_today',
                    'title'       => "Loan #LN-{$loanId} is due today",
                    'message'     => "Agreement #{$loanId} for {$borrowerName} reaches its due date today ({$dueDateFormatted}). Outstanding balance: {$balanceFormatted}.",
                    'severity'    => 'warning',
                    'event_key'   => $eventKey,
                    'send_email'  => true
                ];
                $createdId = create_notification($pdo, $notifData);
                if ($createdId !== null) {
                    $stats['due_today_created']++;
                    $stats['total_created']++;
                } else {
                    $stats['duplicates_skipped']++;
                }
            }
            // 2. Due Soon evaluation (approaching within reminder window, e.g. 1 to 3 days)
            elseif ($diffDays > 0 && $diffDays <= $dueSoonDays && $notifyDueSoon) {
                $daysWord = ($diffDays === 1) ? '1 day' : "{$diffDays} days";
                $eventKey = "due_soon:loan_{$loanId}:{$dueDate}";
                $notifData = [
                    'user_id'     => $userId,
                    'borrower_id' => $borrowerId,
                    'loan_id'     => $loanId,
                    'type'        => 'loan_due_soon',
                    'title'       => "Loan #LN-{$loanId} is due in {$daysWord}",
                    'message'     => "Agreement #{$loanId} for {$borrowerName} is due in {$daysWord} on {$dueDateFormatted}. Outstanding balance: {$balanceFormatted}.",
                    'severity'    => 'info',
                    'event_key'   => $eventKey,
                    'send_email'  => true
                ];
                $createdId = create_notification($pdo, $notifData);
                if ($createdId !== null) {
                    $stats['due_soon_created']++;
                    $stats['total_created']++;
                } else {
                    $stats['duplicates_skipped']++;
                }
            }
            // 3. Overdue evaluation
            elseif ($diffDays < 0 && $notifyOverdue) {
                $daysOverdue = abs($diffDays);
                $daysWord = ($daysOverdue === 1) ? '1 day' : "{$daysOverdue} days";

                // Interval bucket prevents daily spam: generates reminder on day 1, day 8, day 15, etc.
                $intervalBucket = (int)floor(($daysOverdue - 1) / $overdueInterval);
                $eventKey = "overdue:loan_{$loanId}:{$dueDate}:interval_{$intervalBucket}";

                $notifData = [
                    'user_id'     => $userId,
                    'borrower_id' => $borrowerId,
                    'loan_id'     => $loanId,
                    'type'        => 'loan_overdue',
                    'title'       => "Loan #LN-{$loanId} is overdue by {$daysWord}",
                    'message'     => "Agreement #{$loanId} for {$borrowerName} is past its maturity date ({$dueDateFormatted}) by {$daysWord}. Outstanding balance: {$balanceFormatted}.",
                    'severity'    => 'danger',
                    'event_key'   => $eventKey,
                    'send_email'  => true
                ];
                $createdId = create_notification($pdo, $notifData);
                if ($createdId !== null) {
                    $stats['overdue_created']++;
                    $stats['total_created']++;
                } else {
                    $stats['duplicates_skipped']++;
                }
            }
        }
    } catch (Throwable $e) {
        error_log("Generate notifications error: " . $e->getMessage());
    }

    return $stats;
}
