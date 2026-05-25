<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$ctx = dc_require_access();

$viewerGroupIds = array_map('intval', (array) ($ctx['group_ids'] ?? []));
$selectedGroupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0;
$selectedStatus = trim((string) ($_GET['status'] ?? 'active'));
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$monthParam = trim((string) ($_GET['month'] ?? ''));

try {
    $monthStart = preg_match('/^\d{4}-\d{2}$/', $monthParam)
        ? new DateTimeImmutable($monthParam . '-01 00:00:00')
        : new DateTimeImmutable('first day of this month 00:00:00');
} catch (Throwable $e) {
    $monthStart = new DateTimeImmutable('first day of this month 00:00:00');
}

$monthEnd = $monthStart->modify('last day of this month 23:59:59');
$calendarStart = $monthStart->modify('monday this week');

if ($calendarStart > $monthStart) {
    $calendarStart = $calendarStart->modify('-7 days');
}

$calendarEnd = $monthEnd->modify('sunday this week');

if ($calendarEnd < $monthEnd) {
    $calendarEnd = $calendarEnd->modify('+7 days');
}

$previousMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');
$currentMonth = (new DateTimeImmutable('first day of this month'))->format('Y-m');

function dc_index_url(array $overrides = []): string
{
    $params = [
        'month' => $_GET['month'] ?? null,
        'group_id' => $_GET['group_id'] ?? null,
        'status' => $_GET['status'] ?? null,
        'q' => $_GET['q'] ?? null,
    ];

    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    $params = array_filter(
        $params,
        static fn($value): bool => $value !== null && $value !== '' && $value !== 0 && $value !== '0'
    );

    return '/dc/' . ($params ? '?' . http_build_query($params) : '');
}

function dc_index_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function dc_index_table_exists(string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute(['table_name' => $table]);

        return $cache[$table] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function dc_index_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function dc_index_find_event_risk_mapping(): ?array
{
    static $mapping = null;
    static $checked = false;

    if ($checked) {
        return $mapping;
    }

    $checked = true;

    $preferredTables = [
        'event_risk_assessments',
        'calendar_event_risk_assessments',
        'calendar_event_risk_assessment_links',
        'event_risk_assessment_links',
        'risk_assessment_event_links',
        'event_risk_links',
    ];

    foreach ($preferredTables as $table) {
        if (!dc_index_table_exists($table)) {
            continue;
        }

        if (dc_index_column_exists($table, 'event_id') && dc_index_column_exists($table, 'risk_assessment_id')) {
            return $mapping = ['table' => $table, 'event_column' => 'event_id', 'risk_column' => 'risk_assessment_id'];
        }

        if (dc_index_column_exists($table, 'calendar_event_id') && dc_index_column_exists($table, 'risk_assessment_id')) {
            return $mapping = ['table' => $table, 'event_column' => 'calendar_event_id', 'risk_column' => 'risk_assessment_id'];
        }
    }

    try {
        $stmt = db()->query("
            SELECT DISTINCT TABLE_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND COLUMN_NAME IN ('event_id', 'calendar_event_id', 'risk_assessment_id')
            ORDER BY TABLE_NAME ASC
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $table = (string) $table;

            if (!preg_match('/risk|assessment|event/i', $table)) {
                continue;
            }

            if (dc_index_column_exists($table, 'event_id') && dc_index_column_exists($table, 'risk_assessment_id')) {
                return $mapping = ['table' => $table, 'event_column' => 'event_id', 'risk_column' => 'risk_assessment_id'];
            }

            if (dc_index_column_exists($table, 'calendar_event_id') && dc_index_column_exists($table, 'risk_assessment_id')) {
                return $mapping = ['table' => $table, 'event_column' => 'calendar_event_id', 'risk_column' => 'risk_assessment_id'];
            }
        }
    } catch (Throwable $e) {
        return $mapping = null;
    }

    return $mapping = null;
}

function dc_index_fetch_event_risks(array $eventIds): array
{
    $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds))));

    if (!$eventIds) {
        return [];
    }

    $mapping = dc_index_find_event_risk_mapping();

    if (!$mapping) {
        return [];
    }

    $table = dc_index_quote_identifier((string) $mapping['table']);
    $eventColumn = dc_index_quote_identifier((string) $mapping['event_column']);
    $riskColumn = dc_index_quote_identifier((string) $mapping['risk_column']);

    $sql = "
        SELECT
            er.{$eventColumn} AS event_id,
            ra.id,
            ra.title,
            ra.visibility,
            g.group_name
        FROM {$table} er
        JOIN risk_assessments ra
          ON ra.id = er.{$riskColumn}
        JOIN groups g
          ON g.id = ra.group_id
        WHERE er.{$eventColumn} IN (" . implode(',', array_fill(0, count($eventIds), '?')) . ")
          AND ra.status = 'active'
          AND ra.admin_review_status = 'available'
        ORDER BY ra.title ASC
    ";

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($eventIds);

        $out = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['event_id']][] = $row;
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function dc_index_user_can_manage_event_group(array $ctx, int $groupId): bool
{
    if (!empty($ctx['is_reviewer'])) {
        return true;
    }

    return in_array($groupId, array_map('intval', (array) ($ctx['group_ids'] ?? [])), true);
}

function dc_days_between_inclusive(DateTimeImmutable $start, DateTimeImmutable $end): int
{
    $startDay = new DateTimeImmutable($start->format('Y-m-d 00:00:00'));
    $endDay = new DateTimeImmutable($end->format('Y-m-d 00:00:00'));

    return (int) $startDay->diff($endDay)->days + 1;
}

function dc_clamp_date(DateTimeImmutable $date, DateTimeImmutable $min, DateTimeImmutable $max): DateTimeImmutable
{
    if ($date < $min) {
        return $min;
    }

    if ($date > $max) {
        return $max;
    }

    return $date;
}

function dc_event_bar_class(string $status): string
{
    $status = preg_replace('/[^a-z0-9_-]/i', '', $status);

    return 'dc-cal-event dc-cal-event-' . $status;
}

function dc_index_compact(?string $value, int $limit = 220): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strlen') && mb_strlen($value) > $limit) {
        return mb_substr($value, 0, $limit - 1) . '…';
    }

    if (strlen($value) > $limit) {
        return substr($value, 0, $limit - 1) . '…';
    }

    return $value;
}

$stmt = db()->query("
    SELECT id, group_name
    FROM groups
    WHERE is_active = 1
    ORDER BY group_name ASC
");

$allGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

$eventWhere = [
    'ce.starts_at <= :calendar_end',
    'ce.ends_at >= :calendar_start',
];

$params = [
    'calendar_start' => $calendarStart->format('Y-m-d H:i:s'),
    'calendar_end' => $calendarEnd->format('Y-m-d H:i:s'),
];

if ($selectedGroupId > 0) {
    $eventWhere[] = 'ce.group_id = :selected_group_id';
    $params['selected_group_id'] = $selectedGroupId;
}

if ($selectedStatus === 'active') {
    $eventWhere[] = "ce.status <> 'cancelled'";
} elseif ($selectedStatus !== 'all') {
    $allowedStatuses = ['draft', 'submitted', 'under_review', 'approved', 'changes_requested', 'rejected', 'cancelled'];

    if (in_array($selectedStatus, $allowedStatuses, true)) {
        $eventWhere[] = 'ce.status = :selected_status';
        $params['selected_status'] = $selectedStatus;
    } else {
        $selectedStatus = 'active';
        $eventWhere[] = "ce.status <> 'cancelled'";
    }
}

if ($searchQuery !== '') {
    $eventWhere[] = "(
        ce.title LIKE :search
        OR ce.description LIKE :search
        OR ce.location_name LIKE :search
        OR ce.location_address LIKE :search
        OR ce.leader_name LIKE :search
        OR g.group_name LIKE :search
    )";

    $params['search'] = '%' . $searchQuery . '%';
}

$sql = "
    SELECT ce.*, g.group_name
    FROM calendar_events ce
    JOIN groups g ON g.id = ce.group_id
    WHERE " . implode("\n      AND ", $eventWhere) . "
    ORDER BY ce.starts_at ASC, ce.ends_at ASC, g.group_name ASC, ce.title ASC
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$risksByEvent = dc_index_fetch_event_risks(array_map(static fn(array $event): int => (int) $event['id'], $events));

$popupEvents = [];

foreach ($events as $event) {
    $eventId = (int) $event['id'];
    $canManage = dc_index_user_can_manage_event_group($ctx, (int) $event['group_id']);

    $popupEvents[$eventId] = [
        'id' => $eventId,
        'title' => (string) ($event['title'] ?? 'Untitled event'),
        'description' => dc_index_compact((string) ($event['description'] ?? ''), 220),
        'group_name' => (string) ($event['group_name'] ?? ''),
        'status' => (string) ($event['status'] ?? ''),
        'starts_at' => !empty($event['starts_at']) ? date('D j M Y, H:i', strtotime((string) $event['starts_at'])) : '',
        'ends_at' => !empty($event['ends_at']) ? date('D j M Y, H:i', strtotime((string) $event['ends_at'])) : '',
        'location_name' => (string) ($event['location_name'] ?? ''),
        'location_address' => (string) ($event['location_address'] ?? ''),
        'leader_name' => (string) ($event['leader_name'] ?? ''),
        'leader_email' => (string) ($event['leader_email'] ?? ''),
        'leader_phone' => (string) ($event['leader_phone'] ?? ''),
        'can_manage' => $canManage,
        'manage_url' => $canManage ? '/dc/manage-event.php?id=' . $eventId : '',
        'risks' => array_map(static fn(array $risk): array => [
            'id' => (int) $risk['id'],
            'title' => (string) $risk['title'],
            'download_url' => '/dc/download-risk-assessment.php?id=' . (int) $risk['id'],
            'group_name' => (string) ($risk['group_name'] ?? ''),
            'visibility' => (string) ($risk['visibility'] ?? ''),
        ], $risksByEvent[$eventId] ?? []),
    ];
}

$weeks = [];
$weekCursor = $calendarStart;

while ($weekCursor <= $calendarEnd) {
    $weekDays = [];
    $dayCursor = $weekCursor;

    for ($i = 0; $i < 7; $i++) {
        $weekDays[] = $dayCursor;
        $dayCursor = $dayCursor->modify('+1 day');
    }

    $weeks[] = [
        'start' => $weekCursor,
        'end' => $weekCursor->modify('+6 days'),
        'days' => $weekDays,
        'bars' => [],
    ];

    $weekCursor = $weekCursor->modify('+7 days');
}

foreach ($events as $event) {
    try {
        $eventStart = new DateTimeImmutable((string) $event['starts_at']);
        $eventEnd = new DateTimeImmutable((string) $event['ends_at']);
    } catch (Throwable $e) {
        continue;
    }

    foreach ($weeks as $weekIndex => $week) {
        $weekStart = new DateTimeImmutable($week['start']->format('Y-m-d 00:00:00'));
        $weekEnd = new DateTimeImmutable($week['end']->format('Y-m-d 23:59:59'));

        if ($eventStart > $weekEnd || $eventEnd < $weekStart) {
            continue;
        }

        $barStart = dc_clamp_date($eventStart, $weekStart, $weekEnd);
        $barEnd = dc_clamp_date($eventEnd, $weekStart, $weekEnd);

        $barStartDay = new DateTimeImmutable($barStart->format('Y-m-d 00:00:00'));
        $barEndDay = new DateTimeImmutable($barEnd->format('Y-m-d 00:00:00'));

        $weeks[$weekIndex]['bars'][] = [
            'event' => $event,
            'column_start' => (int) $barStartDay->format('N'),
            'span' => dc_days_between_inclusive($barStartDay, $barEndDay),
            'continues_before' => $eventStart < $weekStart,
            'continues_after' => $eventEnd > $weekEnd,
        ];
    }
}

$statusLabels = [
    'active' => 'Active events',
    'all' => 'All statuses',
    'draft' => 'Draft',
    'submitted' => 'Submitted',
    'under_review' => 'Under review',
    'approved' => 'Approved',
    'changes_requested' => 'Changes requested',
    'rejected' => 'Rejected',
    'cancelled' => 'Cancelled',
];

$singleViewerGroupId = count($viewerGroupIds) === 1 ? $viewerGroupIds[0] : 0;
$canAddForSelectedGroup = $selectedGroupId > 0 && dc_index_user_can_manage_event_group($ctx, $selectedGroupId);
$addEventGroupId = $canAddForSelectedGroup ? $selectedGroupId : $singleViewerGroupId;
$addEventUrl = '/dc/add-event.php' . ($addEventGroupId > 0 ? '?group_id=' . $addEventGroupId : '');

$pageTitle = 'District Calendar';
$heroTitle = 'District Calendar';
$heroText = 'View activities across the District, submit events and share risk assessments.';
$active = 'home';

require __DIR__ . '/layout.php';
?>

<style>
    .dc-calendar-page {
        display: grid;
        gap: 1.5rem;
    }

    @media (min-width: 1100px) {
        .dc-calendar-page {
            grid-template-columns: 320px minmax(0, 1fr);
            align-items: start;
            gap: 2rem;
        }
    }

    .dc-calendar-sidebar {
        background: #ffffff;
        border: 1px solid #d8dde3;
        border-top: 8px solid #7413dc;
        padding: 1.25rem;
        box-shadow: 0 2px 0 rgba(16, 24, 32, 0.08);
    }

    @media (min-width: 1100px) {
        .dc-calendar-sidebar {
            position: sticky;
            top: 1.25rem;
        }
    }

    .dc-calendar-sidebar h2 {
        margin: 0 0 1rem;
        color: #101820;
        font-size: 1.5rem;
        font-weight: 900;
        line-height: 1.05;
        letter-spacing: -0.025em;
    }

    .dc-calendar-filter-form {
        display: grid;
        gap: 1rem;
    }

    .dc-calendar-filter-form label {
        display: block;
        margin-bottom: 0.35rem;
        color: #101820;
        font-weight: 900;
    }

    .dc-calendar-filter-form .form-control {
        min-height: 46px;
        border: 2px solid #101820;
        border-radius: 0;
        color: #101820;
        font-weight: 700;
    }

    .dc-calendar-filter-form .form-control:focus {
        outline: 3px solid #ffdd00;
        outline-offset: 0;
        box-shadow: 0 0 0 5px #000000;
        border-color: #101820;
    }

    .dc-calendar-sidebar-actions {
        display: grid;
        gap: 0.65rem;
        margin-top: 1.25rem;
    }

    .dc-calendar-sidebar-actions .lt-btn,
    .dc-calendar-filter-form .lt-btn {
        min-height: 48px;
        border-radius: 0;
        font-weight: 900;
    }

    .dc-filter-summary {
        margin: 1.25rem 0 0;
        padding-top: 1rem;
        border-top: 1px solid #d8dde3;
        color: #4b5563;
        font-size: 0.98rem;
        font-weight: 800;
        line-height: 1.45;
    }

    .dc-calendar-main {
        min-width: 0;
    }

    .dc-calendar-toolbar {
        display: grid;
        gap: 1rem;
        margin-bottom: 1.25rem;
        padding: 1.25rem;
        background: #ffffff;
        border: 1px solid #d8dde3;
        border-left: 8px solid #00a794;
    }

    @media (min-width: 768px) {
        .dc-calendar-toolbar {
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 1.5rem;
        }
    }

    .dc-calendar-month-title {
        margin: 0;
        color: #101820;
        font-size: clamp(1.8rem, 3vw, 2.75rem);
        font-weight: 900;
        line-height: 1;
        letter-spacing: -0.045em;
    }

    .dc-calendar-toolbar p {
        margin-top: 0.45rem;
        color: #4b5563;
        font-weight: 800;
    }

    .dc-calendar-month-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
        align-items: center;
    }

    .dc-calendar-month-nav .lt-btn {
        min-height: 44px;
        border-radius: 0;
        font-weight: 900;
    }

    .dc-calendar-shell {
        background: #ffffff;
        border: 1px solid #d8dde3;
        box-shadow: 0 2px 0 rgba(16, 24, 32, 0.08);
        overflow: hidden;
    }

    .dc-calendar-weekdays {
        display: none;
        grid-template-columns: repeat(7, minmax(0, 1fr));
        background: #4d0b93;
        color: #ffffff;
        font-weight: 900;
    }

    .dc-calendar-weekdays div {
        padding: 0.9rem 0.85rem;
        border-right: 1px solid rgba(255, 255, 255, 0.25);
        font-size: 0.95rem;
        line-height: 1.1;
    }

    .dc-calendar-weekdays div:last-child {
        border-right: 0;
    }

    .dc-calendar-week {
        display: grid;
        grid-template-columns: 1fr;
        border-top: 1px solid #d8dde3;
    }

    .dc-calendar-week:first-of-type {
        border-top: 0;
    }

    .dc-calendar-days {
        display: grid;
        grid-template-columns: 1fr;
    }

    .dc-calendar-day {
        min-height: 88px;
        padding: 0.9rem;
        background: #ffffff;
        border-bottom: 1px solid #d8dde3;
    }

    .dc-calendar-day.is-outside-month {
        background: #f5f6f8;
        color: #4b5563;
    }

    .dc-calendar-day.is-today {
        box-shadow: inset 0 0 0 4px #ffdd00;
        background: #fffdf0;
    }

    .dc-calendar-day-heading {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 0.5rem;
    }

    .dc-calendar-day-heading strong {
        color: #101820;
        font-size: 1.2rem;
        font-weight: 900;
        line-height: 1;
    }

    .dc-calendar-day-heading span {
        color: #4b5563;
        font-size: 0.95rem;
        font-weight: 800;
    }

    .dc-calendar-bars-wrap {
        background: #ffffff;
        border-top: 1px solid #edf0f2;
    }

    .dc-calendar-bars {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.5rem;
        padding: 0.75rem;
        background: #ffffff;
    }

    .dc-calendar-week.is-collapsed .dc-calendar-bar-extra {
        display: none;
    }

    .dc-calendar-expand {
        display: none;
        width: calc(100% - 1.5rem);
        margin: 0 0.75rem 0.9rem;
        border: 2px solid #101820;
        border-radius: 0;
        background: #ffffff;
        color: #101820;
        cursor: pointer;
        font-weight: 900;
        padding: 0.65rem 0.75rem;
        text-align: left;
    }

    .dc-calendar-expand:hover {
        background: #101820;
        color: #ffffff;
    }

    .dc-calendar-week.has-hidden-events .dc-calendar-expand {
        display: block;
    }

    .dc-cal-event {
        display: block;
        width: 100%;
        min-width: 0;
        padding: 0.55rem 0.65rem;
        background: #eee6ff;
        border: 0;
        border-left: 7px solid #7413dc;
        color: #101820;
        cursor: pointer;
        line-height: 1.25;
        text-align: left;
        text-decoration: none;
    }

    .dc-cal-event:hover,
    .dc-cal-event:focus {
        color: #101820;
        outline: 3px solid #ffdd00;
        outline-offset: 1px;
        box-shadow: 0 0 0 4px #000000;
        text-decoration: none;
    }

    .dc-cal-event.is-locked {
        background: #e8f1ff;
        border-left-color: #006ddf;
    }

    .dc-cal-event-title {
        display: block;
        overflow: hidden;
        color: #101820;
        font-size: 0.95rem;
        font-weight: 900;
        line-height: 1.2;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dc-cal-event-meta {
        display: block;
        overflow: hidden;
        margin-top: 0.2rem;
        color: #4b5563;
        font-size: 0.84rem;
        font-weight: 800;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dc-cal-event-status {
        display: inline-block;
        margin-top: 0.35rem;
        color: #101820;
        font-size: 0.72rem;
        font-weight: 900;
        letter-spacing: 0.035em;
        line-height: 1.1;
        text-transform: uppercase;
    }

    .dc-cal-event-approved {
        background: #e9f8f4;
        border-left-color: #00a794;
    }

    .dc-cal-event-submitted,
    .dc-cal-event-under_review {
        background: #fff8d6;
        border-left-color: #ffdd00;
    }

    .dc-cal-event-changes_requested {
        background: #fff1f0;
        border-left-color: #d4351c;
    }

    .dc-cal-event-draft {
        background: #f5f6f8;
        border-left-color: #6b7280;
    }

    .dc-cal-event-cancelled,
    .dc-cal-event-rejected {
        background: #f7e5e3;
        border-left-color: #d4351c;
        text-decoration: line-through;
    }

    .dc-calendar-empty {
        margin-bottom: 1.25rem;
        padding: 1.25rem;
        background: #ffffff;
        border: 2px dashed #6b7280;
        color: #101820;
        font-weight: 800;
    }

    .dc-mobile-event-stack {
        display: grid;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }

    .dc-event-modal-backdrop {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        background: rgba(16, 24, 32, 0.72);
        padding: 1rem;
        z-index: 2000;
    }

    .dc-event-modal-backdrop.is-open {
        display: flex;
    }

    .dc-event-modal {
        width: min(720px, 100%);
        max-height: calc(100vh - 2rem);
        overflow-y: auto;
        background: #ffffff;
        border: 2px solid #101820;
        box-shadow: 0 20px 64px rgba(0, 0, 0, 0.35);
    }

    .dc-event-modal-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: flex-start;
        padding: 1.25rem;
        background: #4d0b93;
        color: #ffffff;
        border-bottom: 6px solid #00a794;
    }

    .dc-event-modal-header h2 {
        margin: 0;
        color: #ffffff;
        font-size: clamp(1.4rem, 3vw, 2rem);
        font-weight: 900;
        line-height: 1.05;
        letter-spacing: -0.025em;
    }

    .dc-event-modal-close {
        min-width: 44px;
        min-height: 44px;
        border: 2px solid #ffffff;
        border-radius: 0;
        background: transparent;
        color: #ffffff;
        cursor: pointer;
        font-size: 1.7rem;
        font-weight: 900;
        line-height: 1;
    }

    .dc-event-modal-close:hover {
        background: #ffffff;
        color: #4d0b93;
    }

    .dc-event-modal-body {
        padding: 1.25rem;
    }

    .dc-event-modal-body dl {
        display: grid;
        gap: 0.7rem;
        margin: 0 0 1.25rem;
    }

    @media (min-width: 640px) {
        .dc-event-modal-body dl {
            grid-template-columns: 150px minmax(0, 1fr);
        }
    }

    .dc-event-modal-body dt {
        color: #101820;
        font-weight: 900;
    }

    .dc-event-modal-body dd {
        margin: 0;
        color: #101820;
        font-weight: 700;
        line-height: 1.45;
    }

    .dc-event-modal-risk-list {
        margin: 0;
        padding-left: 1.2rem;
    }

    .dc-event-modal-risk-list li {
        margin-bottom: 0.35rem;
    }

    .dc-event-modal-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 1.25rem;
        padding-top: 1rem;
        border-top: 1px solid #d8dde3;
    }

    @media (min-width: 768px) {
        .dc-calendar-weekdays {
            display: grid;
        }

        .dc-calendar-week {
            display: block;
            border-top: 1px solid #d8dde3;
        }

        .dc-calendar-days {
            grid-template-columns: repeat(7, minmax(0, 1fr));
            min-height: 118px;
        }

        .dc-calendar-day {
            min-height: 118px;
            padding: 0.7rem;
            border-right: 1px solid #d8dde3;
            border-bottom: 0;
        }

        .dc-calendar-day:nth-child(7n) {
            border-right: 0;
        }

        .dc-calendar-day-heading {
            display: block;
        }

        .dc-calendar-day-heading span {
            display: none;
        }

        .dc-calendar-bars {
            grid-template-columns: repeat(7, minmax(0, 1fr));
            grid-auto-rows: minmax(2.9rem, auto);
            align-items: stretch;
            gap: 0.35rem;
            padding: 0.5rem 0.7rem 0.8rem;
        }

        .dc-cal-event {
            padding: 0.45rem 0.55rem;
            border-left: 0;
            border-top: 6px solid #7413dc;
            overflow: hidden;
        }

        .dc-cal-event.is-locked {
            border-top-color: #006ddf;
        }

        .dc-cal-event-approved {
            border-top-color: #00a794;
        }

        .dc-cal-event-submitted,
        .dc-cal-event-under_review {
            border-top-color: #ffdd00;
        }

        .dc-cal-event-changes_requested,
        .dc-cal-event-cancelled,
        .dc-cal-event-rejected {
            border-top-color: #d4351c;
        }

        .dc-cal-event-draft {
            border-top-color: #6b7280;
        }

        .dc-mobile-event-stack {
            display: none;
        }
    }

    @media (min-width: 1300px) {
        .dc-calendar-days {
            min-height: 132px;
        }

        .dc-calendar-day {
            min-height: 132px;
        }

        .dc-calendar-bars {
            grid-auto-rows: minmax(3.1rem, auto);
        }
    }

    @media (max-width: 767.98px) {
        .dc-calendar-page {
            gap: 1rem;
        }

        .dc-calendar-shell {
            border: 0;
            background: transparent;
            box-shadow: none;
        }

        .dc-calendar-week {
            border-top: 0;
        }

        .dc-calendar-day {
            margin-bottom: 0.85rem;
            border: 1px solid #d8dde3;
            border-left: 6px solid #7413dc;
            box-shadow: 0 2px 0 rgba(16, 24, 32, 0.08);
        }

        .dc-calendar-day.is-empty {
            display: none;
        }

        .dc-calendar-bars-wrap {
            display: none;
        }

        .dc-calendar-month-nav {
            display: grid;
            grid-template-columns: 1fr;
        }

        .dc-calendar-month-nav .lt-btn {
            width: 100%;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .dc-calendar-page *,
        .dc-calendar-page *::before,
        .dc-calendar-page *::after,
        .dc-event-modal *,
        .dc-event-modal *::before,
        .dc-event-modal *::after {
            scroll-behavior: auto !important;
            transition-duration: 0.001ms !important;
            animation-duration: 0.001ms !important;
            animation-iteration-count: 1 !important;
        }
    }
</style>

<div class="dc-calendar-page">
    <aside class="dc-calendar-sidebar" aria-labelledby="calendar-filters-heading">
        <h2 id="calendar-filters-heading">Filters</h2>

        <form method="get" class="dc-calendar-filter-form" action="/dc/">
            <input type="hidden" name="month" value="<?= e($monthStart->format('Y-m')) ?>">

            <div class="form-group mb-0">
                <label for="group_id">Group</label>
                <select id="group_id" name="group_id" class="form-control">
                    <option value="0">All Groups</option>
                    <?php foreach ($allGroups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>" <?= $selectedGroupId === (int) $group['id'] ? 'selected' : '' ?>>
                            <?= e((string) $group['group_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group mb-0">
                <label for="status">Status</label>
                <select id="status" name="status" class="form-control">
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $selectedStatus === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group mb-0">
                <label for="q">Search</label>
                <input id="q" name="q" class="form-control" value="<?= e($searchQuery) ?>" placeholder="Title, Group, leader or location">
            </div>

            <button class="btn btn-primary lt-btn" type="submit">Apply filters</button>
        </form>

        <div class="dc-calendar-sidebar-actions">
            <a class="btn btn-primary lt-btn" href="<?= e($addEventUrl) ?>">Add event</a>
            <a class="btn lt-btn lt-btn-secondary" href="/dc/">Clear filters</a>
        </div>

        <p class="dc-filter-summary">
            <?= count($events) ?> event<?= count($events) === 1 ? '' : 's' ?> shown.
            Click any event to see details.
        </p>
    </aside>

    <section class="dc-calendar-main" aria-labelledby="calendar-heading">
        <div class="dc-calendar-toolbar">
            <div>
                <h2 id="calendar-heading" class="dc-calendar-month-title"><?= e($monthStart->format('F Y')) ?></h2>
                <p class="mb-0">Activities across the District.</p>
            </div>

            <div class="dc-calendar-month-nav" aria-label="Calendar navigation">
                <a class="btn lt-btn lt-btn-secondary" href="<?= e(dc_index_url(['month' => $previousMonth])) ?>">Previous</a>
                <a class="btn lt-btn lt-btn-secondary" href="<?= e(dc_index_url(['month' => $currentMonth])) ?>">Today</a>
                <a class="btn lt-btn lt-btn-secondary" href="<?= e(dc_index_url(['month' => $nextMonth])) ?>">Next</a>
            </div>
        </div>

        <?php if (!$events): ?>
            <div class="dc-calendar-empty">No events match the selected filters for this month.</div>
        <?php endif; ?>

        <div class="dc-calendar-shell">
            <div class="dc-calendar-weekdays" aria-hidden="true">
                <div>Monday</div><div>Tuesday</div><div>Wednesday</div><div>Thursday</div><div>Friday</div><div>Saturday</div><div>Sunday</div>
            </div>

            <?php
            $today = (new DateTimeImmutable('today'))->format('Y-m-d');

            foreach ($weeks as $weekIndex => $week):
                $visibleLimit = 3;
                $hiddenCount = max(0, count($week['bars']) - $visibleLimit);
                $hasHidden = $hiddenCount > 0;
            ?>
                <div class="dc-calendar-week <?= $hasHidden ? 'is-collapsed has-hidden-events' : '' ?>" data-week="<?= (int) $weekIndex ?>">
                    <div class="dc-calendar-days">
                        <?php foreach ($week['days'] as $day): ?>
                            <?php
                            $dateKey = $day->format('Y-m-d');
                            $isOutsideMonth = $day->format('Y-m') !== $monthStart->format('Y-m');
                            $isToday = $dateKey === $today;
                            $mobileDayEvents = [];

                            foreach ($week['bars'] as $bar) {
                                $event = $bar['event'];

                                try {
                                    $eventStart = new DateTimeImmutable((string) $event['starts_at']);
                                    $eventEnd = new DateTimeImmutable((string) $event['ends_at']);
                                } catch (Throwable $e) {
                                    continue;
                                }

                                $dayStart = new DateTimeImmutable($dateKey . ' 00:00:00');
                                $dayEnd = new DateTimeImmutable($dateKey . ' 23:59:59');

                                if ($eventStart <= $dayEnd && $eventEnd >= $dayStart) {
                                    $mobileDayEvents[] = $event;
                                }
                            }
                            ?>

                            <div class="dc-calendar-day <?= $isOutsideMonth ? 'is-outside-month' : '' ?> <?= $isToday ? 'is-today' : '' ?> <?= !$mobileDayEvents ? 'is-empty' : '' ?>">
                                <div class="dc-calendar-day-heading">
                                    <strong><?= e($day->format('j')) ?></strong>
                                    <span><?= e($day->format('D j M')) ?></span>
                                </div>

                                <?php if ($mobileDayEvents): ?>
                                    <div class="dc-mobile-event-stack">
                                        <?php foreach ($mobileDayEvents as $event): ?>
                                            <?php
                                            $eventStatus = (string) $event['status'];
                                            $eventClass = dc_event_bar_class($eventStatus);
                                            $timeLabel = date('H:i', strtotime((string) $event['starts_at']));
                                            ?>
                                            <button type="button" class="<?= e($eventClass) ?> <?= dc_index_user_can_manage_event_group($ctx, (int) $event['group_id']) ? '' : 'is-locked' ?>" data-event-id="<?= (int) $event['id'] ?>">
                                                <span class="dc-cal-event-title"><?= e((string) $event['title']) ?></span>
                                                <span class="dc-cal-event-meta"><?= e($timeLabel) ?> · <?= e((string) $event['group_name']) ?></span>
                                                <span class="dc-cal-event-status"><?= e(str_replace('_', ' ', $eventStatus)) ?></span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($week['bars']): ?>
                        <div class="dc-calendar-bars-wrap">
                            <div class="dc-calendar-bars" aria-label="Events for week beginning <?= e($week['start']->format('j F Y')) ?>">
                                <?php foreach ($week['bars'] as $barIndex => $bar): ?>
                                    <?php
                                    $event = $bar['event'];
                                    $canManage = dc_index_user_can_manage_event_group($ctx, (int) $event['group_id']);
                                    $eventStatus = (string) $event['status'];
                                    $eventClass = dc_event_bar_class($eventStatus);

                                    $columnStart = (int) $bar['column_start'];
                                    $columnEnd = min($columnStart + (int) $bar['span'], 8);

                                    $continuationPrefix = $bar['continues_before'] ? '← ' : '';
                                    $continuationSuffix = $bar['continues_after'] ? ' →' : '';
                                    ?>
                                    <button
                                        type="button"
                                        class="<?= e($eventClass) ?> <?= $canManage ? '' : 'is-locked' ?> <?= $barIndex >= $visibleLimit ? 'dc-calendar-bar-extra' : '' ?>"
                                        style="grid-column: <?= $columnStart ?> / <?= $columnEnd ?>;"
                                        data-event-id="<?= (int) $event['id'] ?>"
                                    >
                                        <span class="dc-cal-event-title"><?= e($continuationPrefix . (string) $event['title'] . $continuationSuffix) ?></span>
                                        <span class="dc-cal-event-meta"><?= e((string) $event['group_name']) ?> · <?= e(date('H:i', strtotime((string) $event['starts_at']))) ?></span>
                                        <span class="dc-cal-event-status"><?= e(str_replace('_', ' ', $eventStatus)) ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($hasHidden): ?>
                                <button
                                    type="button"
                                    class="dc-calendar-expand"
                                    data-week-toggle="<?= (int) $weekIndex ?>"
                                    data-collapsed-text="Show all events this week (+<?= (int) $hiddenCount ?>)"
                                    data-expanded-text="Hide extra events"
                                >
                                    Show all events this week (+<?= (int) $hiddenCount ?>)
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<div class="dc-event-modal-backdrop" id="dc-event-modal" aria-hidden="true">
    <div class="dc-event-modal" role="dialog" aria-modal="true" aria-labelledby="dc-event-modal-title">
        <div class="dc-event-modal-header">
            <h2 id="dc-event-modal-title">Event details</h2>
            <button type="button" class="dc-event-modal-close" id="dc-event-modal-close" aria-label="Close event details">×</button>
        </div>
        <div class="dc-event-modal-body" id="dc-event-modal-body"></div>
    </div>
</div>

<script>
(function () {
    var events = <?= json_encode($popupEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var modal = document.getElementById('dc-event-modal');
    var modalTitle = document.getElementById('dc-event-modal-title');
    var modalBody = document.getElementById('dc-event-modal-body');
    var modalClose = document.getElementById('dc-event-modal-close');

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
        });
    }

    function eventHtml(event) {
        var location = event.location_name || event.location_address || 'Location not named';

        var risks = event.risks && event.risks.length
            ? '<ul class="dc-event-modal-risk-list">' + event.risks.map(function (risk) {
                return '<li><a href="' + escapeHtml(risk.download_url) + '">' + escapeHtml(risk.title) + '</a>' +
                    (risk.group_name ? ' <span>(' + escapeHtml(risk.group_name) + ')</span>' : '') +
                    '</li>';
            }).join('') + '</ul>'
            : '<p>No linked risk assessments.</p>';

        var contact = event.leader_name || 'Not set';
        if (event.leader_email) contact += '<br><a href="mailto:' + escapeHtml(event.leader_email) + '">' + escapeHtml(event.leader_email) + '</a>';
        if (event.leader_phone) contact += '<br>' + escapeHtml(event.leader_phone);

        var action = event.can_manage && event.manage_url
            ? '<a class="btn btn-primary lt-btn" href="' + escapeHtml(event.manage_url) + '">Open event</a>'
            : '<p class="mb-0"><strong>You can view the basic details, but only the owning Group or District reviewers can manage this event.</strong></p>';

        return '<dl>' +
            '<dt>Group</dt><dd>' + escapeHtml(event.group_name) + '</dd>' +
            '<dt>Status</dt><dd>' + escapeHtml(String(event.status || '').replace(/_/g, ' ')) + '</dd>' +
            '<dt>When</dt><dd>' + escapeHtml(event.starts_at) + (event.ends_at ? '<br>to ' + escapeHtml(event.ends_at) : '') + '</dd>' +
            '<dt>Where</dt><dd>' + escapeHtml(location) + (event.location_address && event.location_address !== event.location_name ? '<br>' + escapeHtml(event.location_address) : '') + '</dd>' +
            '<dt>Main contact</dt><dd>' + contact + '</dd>' +
            (event.description ? '<dt>Description</dt><dd>' + escapeHtml(event.description) + '</dd>' : '') +
            '<dt>Risk assessments</dt><dd>' + risks + '</dd>' +
            '</dl><div class="dc-event-modal-actions">' + action + '</div>';
    }

    function openEvent(eventId) {
        var event = events[String(eventId)] || events[eventId];
        if (!event || !modal || !modalTitle || !modalBody) return;

        modalTitle.textContent = event.title || 'Event details';
        modalBody.innerHTML = eventHtml(event);
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        if (modalClose) modalClose.focus();
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('[data-event-id]').forEach(function (button) {
        button.addEventListener('click', function () {
            openEvent(button.getAttribute('data-event-id'));
        });
    });

    document.querySelectorAll('[data-week-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var week = document.querySelector('[data-week="' + button.getAttribute('data-week-toggle') + '"]');
            if (!week) return;

            var collapsed = week.classList.toggle('is-collapsed');
            button.textContent = collapsed
                ? button.getAttribute('data-collapsed-text') || 'Show all events this week'
                : button.getAttribute('data-expanded-text') || 'Hide extra events';
        });
    });

    if (modalClose) modalClose.addEventListener('click', closeModal);

    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeModal();
    });
}());
</script>

<?php require __DIR__ . '/layout-footer.php'; ?>