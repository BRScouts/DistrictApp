<?php

declare(strict_types=1);

/**
 * System Admin helpers — access control and audit log queries.
 */

function sa_is_system_admin(?array $user): bool
{
    if (!$user) {
        return false;
    }

    $level = (string) ($user['highest_access_level'] ?? $user['role'] ?? 'member');

    return in_array($level, ['system_admin', 'district_admin'], true);
}

function sa_require_system_admin(): array
{
    require_login();

    $user = current_user();

    if (!sa_is_system_admin($user)) {
        http_response_code(403);
        $appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
        $pageTitle = 'Access denied | ' . $appName;
        $heroTitle = 'Access denied';
        $heroText = '';
        $breadcrumb = '<a href="/index.php">Home</a> / System Admin';
        include __DIR__ . '/../header.php';
        echo '<main class="lt-main"><div class="alert alert-danger"><strong>Access denied:</strong> This area is only available to System Administrators and District Administrators.</div></main>';
        include __DIR__ . '/../footer.php';
        exit;
    }

    return $user;
}

/**
 * Fetch paginated audit log entries with optional filters.
 */
function sa_fetch_audit_log(array $filters = [], int $page = 1, int $perPage = 50): array
{
    $where = [];
    $params = [];

    if (!empty($filters['category'])) {
        $where[] = "al.action LIKE :category_prefix";
        $params['category_prefix'] = $filters['category'] . '.%';
    }

    if (!empty($filters['action'])) {
        $where[] = "al.action = :action";
        $params['action'] = $filters['action'];
    }

    if (!empty($filters['actor_person_id'])) {
        $where[] = "al.actor_person_id = :actor_person_id";
        $params['actor_person_id'] = (int) $filters['actor_person_id'];
    }

    if (!empty($filters['target_person_id'])) {
        $where[] = "(al.target_person_id = :target_person_id OR al.actor_person_id = :target_person_id2)";
        $params['target_person_id'] = (int) $filters['target_person_id'];
        $params['target_person_id2'] = (int) $filters['target_person_id'];
    }

    if (!empty($filters['group_id'])) {
        $where[] = "al.group_id = :group_id";
        $params['group_id'] = (int) $filters['group_id'];
    }

    if (!empty($filters['entity_type'])) {
        $where[] = "al.entity_type = :entity_type";
        $params['entity_type'] = $filters['entity_type'];
    }

    if (!empty($filters['entity_id'])) {
        $where[] = "al.entity_id = :entity_id";
        $params['entity_id'] = (int) $filters['entity_id'];
    }

    if (!empty($filters['date_from'])) {
        $where[] = "al.created_at >= :date_from";
        $params['date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if (!empty($filters['date_to'])) {
        $where[] = "al.created_at <= :date_to";
        $params['date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    if (!empty($filters['severity'])) {
        // Filter by severity requires matching event codes of that severity
        $codes = [];
        foreach (audit_event_types() as $code => $meta) {
            if ($meta[2] === $filters['severity']) {
                $codes[] = $code;
            }
        }
        if ($codes) {
            $placeholders = [];
            foreach ($codes as $i => $code) {
                $key = 'sev_code_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $code;
            }
            $where[] = "al.action IN (" . implode(', ', $placeholders) . ")";
        } else {
            // No matching codes — return empty
            return ['rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0];
        }
    }

    if (!empty($filters['search'])) {
        $where[] = "(
            p.full_name LIKE :search
            OR tp.full_name LIKE :search2
            OR al.action LIKE :search3
            OR al.details_json LIKE :search4
            OR al.ip_address LIKE :search5
        )";
        $searchTerm = '%' . $filters['search'] . '%';
        $params['search'] = $searchTerm;
        $params['search2'] = $searchTerm;
        $params['search3'] = $searchTerm;
        $params['search4'] = $searchTerm;
        $params['search5'] = $searchTerm;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $offset = ($page - 1) * $perPage;

    // Count total
    $countSql = "
        SELECT COUNT(*)
        FROM audit_log al
        LEFT JOIN people p ON p.id = al.actor_person_id
        LEFT JOIN people tp ON tp.id = al.target_person_id
        {$whereClause}
    ";

    try {
        $stmt = db()->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return ['rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0];
    }

    // Fetch rows
    $sql = "
        SELECT
            al.*,
            p.full_name AS actor_name,
            p.primary_email AS actor_email,
            tp.full_name AS target_name,
            tp.primary_email AS target_email,
            g.group_name
        FROM audit_log al
        LEFT JOIN people p ON p.id = al.actor_person_id
        LEFT JOIN people tp ON tp.id = al.target_person_id
        LEFT JOIN groups g ON g.id = al.group_id
        {$whereClause}
        ORDER BY al.created_at DESC, al.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ";

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }

    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => (int) ceil($total / $perPage),
    ];
}

/**
 * Get summary stats for the audit log dashboard.
 */
function sa_audit_stats(): array
{
    $stats = [
        'total' => 0,
        'today' => 0,
        'logins_today' => 0,
        'failed_logins_today' => 0,
        'critical_today' => 0,
    ];

    try {
        $stmt = db()->query("SELECT COUNT(*) FROM audit_log");
        $stats['total'] = (int) $stmt->fetchColumn();

        $stmt = db()->query("SELECT COUNT(*) FROM audit_log WHERE DATE(created_at) = CURDATE()");
        $stats['today'] = (int) $stmt->fetchColumn();

        $stmt = db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login_success' AND DATE(created_at) = CURDATE()");
        $stats['logins_today'] = (int) $stmt->fetchColumn();

        $stmt = db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login_failed' AND DATE(created_at) = CURDATE()");
        $stats['failed_logins_today'] = (int) $stmt->fetchColumn();

        // Critical events today
        $criticalCodes = [];
        foreach (audit_event_types() as $code => $meta) {
            if ($meta[2] === 'critical') {
                $criticalCodes[] = $code;
            }
        }
        if ($criticalCodes) {
            $placeholders = implode(',', array_fill(0, count($criticalCodes), '?'));
            $stmt = db()->prepare("SELECT COUNT(*) FROM audit_log WHERE action IN ({$placeholders}) AND DATE(created_at) = CURDATE()");
            $stmt->execute($criticalCodes);
            $stats['critical_today'] = (int) $stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        // Stats are non-critical
    }

    return $stats;
}

/**
 * Fetch all groups for filter dropdowns.
 */
function sa_fetch_all_groups(): array
{
    try {
        $stmt = db()->query("SELECT id, group_name, is_active FROM groups ORDER BY group_name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Export audit log to CSV.
 */
function sa_export_audit_csv(array $filters = []): void
{
    $result = sa_fetch_audit_log($filters, 1, 10000);
    $rows = $result['rows'];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log-' . date('Y-m-d-His') . '.csv"');

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'Timestamp',
        'Event',
        'Category',
        'Severity',
        'Actor',
        'Actor Email',
        'Target',
        'Target Email',
        'Group',
        'Entity Type',
        'Entity ID',
        'IP Address',
        'Details',
    ]);

    foreach ($rows as $row) {
        $eventCode = (string) ($row['action'] ?? '');
        fputcsv($output, [
            $row['created_at'] ?? '',
            audit_event_label($eventCode),
            audit_event_category($eventCode),
            audit_event_severity($eventCode),
            $row['actor_name'] ?? ($row['actor_type'] ?? 'System'),
            $row['actor_email'] ?? '',
            $row['target_name'] ?? '',
            $row['target_email'] ?? '',
            $row['group_name'] ?? '',
            $row['entity_type'] ?? '',
            $row['entity_id'] ?? '',
            $row['ip_address'] ?? '',
            $row['details_json'] ?? '',
        ]);
    }

    fclose($output);
    exit;
}
