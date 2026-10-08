<?php
/**
 * MoneyLend - Activity & Audit Trail Helper (Phase 10)
 *
 * Provides centralized, secure logging of administrative actions across
 * borrowers, loans, repayments, settings, and authentication events.
 * Never logs credentials, passwords, or security tokens.
 */

/**
 * Record an audit activity event.
 *
 * @param PDO $pdo
 * @param string $action e.g. 'login', 'borrower_create', 'loan_create', 'repayment_create', etc.
 * @param string|null $entityType e.g. 'user', 'borrower', 'loan', 'repayment', 'settings'
 * @param int|null $entityId ID of the affected entity
 * @param string $description Human-readable summary of the action
 * @param int|null $userId User ID who performed the action (defaults to current session user)
 * @return bool True if logged successfully, false otherwise
 */
function log_activity(PDO $pdo, string $action, ?string $entityType = null, ?int $entityId = null, string $description = '', ?int $userId = null): bool {
    try {
        if ($userId === null && isset($_SESSION['user_id'])) {
            $userId = (int)$_SESSION['user_id'];
        }

        // Sanitize & truncate strings to database field constraints
        $action = substr(trim($action), 0, 50);
        $entityType = $entityType !== null ? substr(trim($entityType), 0, 50) : null;
        $description = substr(trim($description), 0, 255);

        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, entity_type, entity_id, description, created_at)
            VALUES (:user_id, :action, :entity_type, :entity_id, :description, NOW())
        ");

        return $stmt->execute([
            ':user_id'     => $userId,
            ':action'      => $action,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':description' => $description
        ]);
    } catch (Throwable $e) {
        // Logging should fail gracefully without disrupting core transactions
        error_log("Activity log error: " . $e->getMessage());
        return false;
    }
}

/**
 * Retrieve paginated activity logs with optional action/entity filters.
 *
 * @param PDO $pdo
 * @param int $limit
 * @param int $offset
 * @param string|null $actionFilter
 * @param string|null $entityFilter
 * @return array
 */
function get_recent_activities(PDO $pdo, int $limit = 20, int $offset = 0, ?string $actionFilter = null, ?string $entityFilter = null): array {
    try {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $sql = "
            SELECT 
                a.*,
                u.name AS user_name,
                u.email AS user_email
            FROM activity_logs a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($actionFilter)) {
            $sql .= " AND a.action = :action";
            $params[':action'] = $actionFilter;
        }

        if (!empty($entityFilter)) {
            $sql .= " AND a.entity_type = :entity";
            $params[':entity'] = $entityFilter;
        }

        $sql .= " ORDER BY a.id DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Get activities error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get total count of activity logs matching filters.
 *
 * @param PDO $pdo
 * @param string|null $actionFilter
 * @param string|null $entityFilter
 * @return int
 */
function get_activity_count(PDO $pdo, ?string $actionFilter = null, ?string $entityFilter = null): int {
    try {
        $sql = "SELECT COUNT(*) FROM activity_logs WHERE 1=1";
        $params = [];

        if (!empty($actionFilter)) {
            $sql .= " AND action = :action";
            $params[':action'] = $actionFilter;
        }

        if (!empty($entityFilter)) {
            $sql .= " AND entity_type = :entity";
            $params[':entity'] = $entityFilter;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log("Activity count error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get human-readable icon and badge color for an activity action.
 *
 * @param string $action
 * @return array ['icon' => string, 'badge' => string, 'label' => string]
 */
function get_activity_badge_info(string $action): array {
    switch ($action) {
        case 'login':
            return ['icon' => 'fa-right-to-bracket', 'badge' => 'bg-info-subtle text-info-emphasis border-info-subtle', 'label' => 'Login'];
        case 'logout':
            return ['icon' => 'fa-right-from-bracket', 'badge' => 'bg-secondary-subtle text-secondary border-secondary-subtle', 'label' => 'Logout'];
        case 'borrower_create':
            return ['icon' => 'fa-user-plus', 'badge' => 'bg-primary-subtle text-primary border-primary-subtle', 'label' => 'Borrower Added'];
        case 'borrower_update':
            return ['icon' => 'fa-user-pen', 'badge' => 'bg-info-subtle text-info-emphasis border-info-subtle', 'label' => 'Borrower Updated'];
        case 'borrower_delete':
            return ['icon' => 'fa-user-minus', 'badge' => 'bg-danger-subtle text-danger border-danger-subtle', 'label' => 'Borrower Deleted'];
        case 'loan_create':
            return ['icon' => 'fa-file-circle-plus', 'badge' => 'bg-primary-subtle text-primary border-primary-subtle', 'label' => 'Loan Created'];
        case 'loan_update':
            return ['icon' => 'fa-pen-to-square', 'badge' => 'bg-info-subtle text-info-emphasis border-info-subtle', 'label' => 'Loan Updated'];
        case 'loan_cancel':
            return ['icon' => 'fa-ban', 'badge' => 'bg-warning-subtle text-warning-emphasis border-warning-subtle', 'label' => 'Loan Cancelled'];
        case 'loan_delete':
            return ['icon' => 'fa-trash-can', 'badge' => 'bg-danger-subtle text-danger border-danger-subtle', 'label' => 'Loan Deleted'];
        case 'repayment_create':
            return ['icon' => 'fa-money-bill-transfer', 'badge' => 'bg-success-subtle text-success border-success-subtle', 'label' => 'Repayment Recorded'];
        case 'repayment_update':
            return ['icon' => 'fa-receipt', 'badge' => 'bg-info-subtle text-info-emphasis border-info-subtle', 'label' => 'Repayment Updated'];
        case 'repayment_delete':
            return ['icon' => 'fa-trash-can', 'badge' => 'bg-danger-subtle text-danger border-danger-subtle', 'label' => 'Repayment Deleted'];
        case 'settings_update':
            return ['icon' => 'fa-sliders', 'badge' => 'bg-secondary-subtle text-secondary border-secondary-subtle', 'label' => 'Settings Updated'];
        case 'password_change':
            return ['icon' => 'fa-key', 'badge' => 'bg-warning-subtle text-warning-emphasis border-warning-subtle', 'label' => 'Password Changed'];
        default:
            return ['icon' => 'fa-circle-info', 'badge' => 'bg-light text-secondary border', 'label' => ucfirst(str_replace('_', ' ', $action))];
    }
}
