<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$ctx = dc_require_access();

if (!function_exists('dc_index_url')) {
    function dc_index_url(array $changes = []): string
    {
        $query = $_GET;

        foreach ($changes as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return '/dc/' . ($query ? '?' . http_build_query($query) : '');
    }
}

if (!function_exists('dc_event_bar_class')) {
    function dc_event_bar_class(string $status): string
    {
        $status = strtolower(trim($status));

        $base = 'dc-cal-event';

        return match ($status) {
            'approved' => $base . ' dc-cal-event-approved',
            'submitted' => $base . ' dc-cal-event-submitted',
            'under_review' => $base . ' dc-cal-event-under_review',
            'changes_requested' => $base . ' dc-cal-event-changes_requested',
            'draft' => $base . ' dc-cal-event-draft',
            'cancelled' => $base . ' dc-cal-event-cancelled',
            'rejected' => $base . ' dc-cal-event-rejected',
            default => $base,
        };
    }
}

if (!function_exists('dc_index_user_can_manage_event_group')) {
    function dc_index_user_can_manage_event_group(array $ctx, int $groupId): bool
    {
        $accessLevels = array_filter(array_map(
            'strval',
            (array) ($ctx['access_levels'] ?? [])
        ));

        $membershipRoles = array_filter(array_map(
            'strval',
            (array) ($ctx['membership_roles'] ?? [])
        ));

        foreach (['access_level', 'role', 'membership_role'] as $key) {
            if (!empty($ctx[$key])) {
                $value = (string) $ctx[$key];

                if ($key === 'access_level') {
                    $accessLevels[] = $value;
                } else {
                    $membershipRoles[] = $value;
                }
            }
        }

        $accessLevels = array_values(array_unique($accessLevels));
        $membershipRoles = array_values(array_unique($membershipRoles));

        if (
            in_array('system_admin', $accessLevels, true)
            || in_array('district_admin', $accessLevels, true)
            || in_array('district_reviewer', $accessLevels, true)
            || !empty($ctx['is_reviewer'])
        ) {
            return true;
        }

        $groupIds = array_map('intval', (array) ($ctx['group_ids'] ?? []));

        if (!in_array($groupId, $groupIds, true)) {
            return false;
        }

        return in_array('group_admin', $accessLevels, true)
            || in_array('group_lead_volunteer', $accessLevels, true)
            || in_array('group_lead_volunteer', $membershipRoles, true);
    }
}

function dc_index_parse_month(?string $value): DateTimeImmutable
{
    $value = trim((string) $value);

    if ($value !== '' && preg_match('/^\d{4}-\d{2}$/', $value)) {
        try {
            return new DateTimeImmutable($value . '-01 00:00:00');
        } catch (Throwable $e) {
            // Fall through to current month.
        }
    }

    return new DateTimeImmutable('first day of this month 00:00:00');
}

function dc_index_start_of_week(DateTimeImmutable $date): DateTimeImmutable
{
    return $date->modify('monday this week')->setTime(0, 0, 0);
}

function dc_index_end_of_week(DateTimeImmutable $date): DateTimeImmutable
{
    return $date->modify('sunday this week')->setTime(23, 59, 59);
}

function dc_index_event_date_value(array $event, string $key): DateTimeImmutable
{
    try {
        return new DateTimeImmutable((string) $event[$key]);
    } catch (Throwable $e) {
        return new DateTimeImmutable('now');
    }
}

$accessibleGroups = dc_accessible_groups();
$allGroups = $accessibleGroups;

$requestedGroupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0;
$selectedGroupId = 0;

if ($requestedGroupId > 0) {
    $selectedGroupId = dc_selected_group_id($requestedGroupId);
}

$statusLabels = [
    'all' => 'All statuses',
    'approved' => 'Approved',
    'submitted' => 'Submitted',
    'under_review' => 'Under review',
    'changes_requested' => 'Changes requested',
    'draft' => 'Draft',
    'cancelled' => 'Cancelled',
    'rejected' => 'Rejected',
];

$selectedStatus = (string) ($_GET['status'] ?? 'all');

if (!array_key_exists($selectedStatus, $statusLabels)) {
    $selectedStatus = 'all';
}

$searchQuery = trim((string) ($_GET['q'] ?? ''));

$monthStart = dc_index_parse_month($_GET['month'] ?? null);
$monthEnd = $monthStart->modify('last day of this month 23:59:59');

$calendarStart = dc_index_start_of_week($monthStart);
$calendarEnd = dc_index_end_of_week($monthEnd);

$currentMonth = (new DateTimeImmutable('first day of this month'))->format('Y-m');
$previousMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');

$allowedGroupIds = array_values(array_unique(array_map('intval', (array) ($ctx['group_ids'] ?? []))));

if (!$allowedGroupIds && !empty($ctx['group_id'])) {
    $allowedGroupIds[] = (int) $ctx['group_id'];
}

$isDistrictLevelUser = false;

$ctxAccessLevels = array_filter(array_map(
    'strval',
    (array) ($ctx['access_levels'] ?? [])
));

if (!empty($ctx['access_level'])) {
    $ctxAccessLevels[] = (string) $ctx['access_level'];
}

$ctxAccessLevels = array_values(array_unique($ctxAccessLevels));

if (
    in_array('system_admin', $ctxAccessLevels, true)
    || in_array('district_admin', $ctxAccessLevels, true)
    || in_array('district_reviewer', $ctxAccessLevels, true)
    || !empty($ctx['is_reviewer'])
) {
    $isDistrictLevelUser = true;
}

$where = [];
$params = [];

$where[] = 'ce.starts_at <= ?';
$params[] = $calendarEnd->format('Y-m-d H:i:s');

$where[] = 'ce.ends_at >= ?';
$params[] = $calendarStart->format('Y-m-d H:i:s');

if (!$isDistrictLevelUser) {
    if (!$allowedGroupIds) {
        $allowedGroupIds = [0];
    }

    $where[] = 'ce.group_id IN (' . implode(',', array_fill(0, count($allowedGroupIds), '?')) . ')';

    foreach ($allowedGroupIds as $allowedGroupId) {
        $params[] = $allowedGroupId;
    }
}

if ($selectedGroupId > 0) {
    $where[] = 'ce.group_id = ?';
    $params[] = $selectedGroupId;
}

if ($selectedStatus !== 'all') {
    $where[] = 'ce.status = ?';
    $params[] = $selectedStatus;
}

if ($searchQuery !== '') {
    $where[] = '(
        ce.title LIKE ?
        OR ce.description LIKE ?
        OR ce.location_name LIKE ?
        OR ce.location_address LIKE ?
        OR ce.leader_name LIKE ?
        OR g.group_name LIKE ?
    )';

    $like = '%' . $searchQuery . '%';

    for ($i = 0; $i < 6; $i++) {
        $params[] = $like;
    }
}

$whereSql = implode("\n    AND ", $where);

$sql = "
    SELECT
        ce.*,
        g.group_name
    FROM calendar_events ce
    JOIN groups g ON g.id = ce.group_id
    WHERE {$whereSql}
    ORDER BY ce.starts_at ASC, ce.ends_at ASC, ce.title ASC
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$eventIds = array_values(array_filter(array_map(
    static fn (array $event): int => (int) ($event['id'] ?? 0),
    $events
)));

$risksByEventId = [];

if ($eventIds) {
    $riskSql = "
        SELECT
            era.calendar_event_id,
            ra.id,
            ra.title,
            ra.group_id,
            g.group_name
        FROM event_risk_assessments era
        JOIN risk_assessments ra ON ra.id = era.risk_assessment_id
        JOIN groups g ON g.id = ra.group_id
        WHERE era.calendar_event_id IN (" . implode(',', array_fill(0, count($eventIds), '?')) . ")
        ORDER BY ra.title ASC
    ";

    $riskStmt = db()->prepare($riskSql);
    $riskStmt->execute($eventIds);

    foreach ($riskStmt->fetchAll(PDO::FETCH_ASSOC) as $risk) {
        $eventId = (int) $risk['calendar_event_id'];

        $risksByEventId[$eventId][] = [
            'id' => (int) $risk['id'],
            'title' => (string) $risk['title'],
            'group_id' => (int) $risk['group_id'],
            'group_name' => (string) $risk['group_name'],
            'download_url' => '/dc/download-risk-assessment.php?id=' . (int) $risk['id'],
        ];
    }
}

$popupEvents = [];

foreach ($events as $event) {
    $eventId = (int) $event['id'];
    $canManage = dc_index_user_can_manage_event_group($ctx, (int) $event['group_id']);

    $popupEvents[$eventId] = [
        'id' => $eventId,
        'title' => (string) ($event['title'] ?? ''),
        'group_id' => (int) ($event['group_id'] ?? 0),
        'group_name' => (string) ($event['group_name'] ?? ''),
        'status' => (string) ($event['status'] ?? ''),
        'starts_at' => !empty($event['starts_at']) ? date('j M Y H:i', strtotime((string) $event['starts_at'])) : '',
        'ends_at' => !empty($event['ends_at']) ? date('j M Y H:i', strtotime((string) $event['ends_at'])) : '',
        'location_name' => (string) ($event['location_name'] ?? ''),
        'location_address' => (string) ($event['location_address'] ?? ''),
        'leader_name' => (string) ($event['leader_name'] ?? ''),
        'leader_email' => (string) ($event['leader_email'] ?? ''),
        'leader_phone' => (string) ($event['leader_phone'] ?? ''),
        'description' => (string) ($event['description'] ?? ''),
        'risks' => $risksByEventId[$eventId] ?? [],
        'can_manage' => $canManage,
        'manage_url' => $canManage ? '/dc/manage-event.php?id=' . $eventId : null,
    ];
}

/*
 * Build a colour palette for groups so each group's events are visually
 * distinct on the calendar. Colours are assigned in order from a curated
 * palette that works well on white backgrounds.
 */
$groupColorPalette = [
    '#7413dc', // Purple (Scouts brand)
    '#00a794', // Teal
    '#006ddf', // Blue
    '#d4a300', // Amber
    '#dc2626', // Red
    '#16a34a', // Green
    '#9333ea', // Violet
    '#0891b2', // Cyan
    '#ea580c', // Orange
    '#6366f1', // Indigo
    '#db2777', // Pink
    '#65a30d', // Lime
];

$groupColorsMap = []; // group_id => color hex
$groupsInCalendar = []; // group_id => group_name (only groups that have events this month)

foreach ($events as $event) {
    $gId = (int) ($event['group_id'] ?? 0);
    if ($gId && !isset($groupsInCalendar[$gId])) {
        $groupsInCalendar[$gId] = (string) ($event['group_name'] ?? 'Unknown');
    }
}

$colorIndex = 0;
foreach ($groupsInCalendar as $gId => $gName) {
    $groupColorsMap[$gId] = $groupColorPalette[$colorIndex % count($groupColorPalette)];
    $colorIndex++;
}

$weeks = [];
$cursor = $calendarStart;

while ($cursor <= $calendarEnd) {
    $weekStart = $cursor;
    $days = [];

    for ($i = 0; $i < 7; $i++) {
        $days[] = $cursor;
        $cursor = $cursor->modify('+1 day');
    }

    $weekEnd = end($days)->setTime(23, 59, 59);
    $weekBars = [];

    foreach ($events as $event) {
        $eventStart = dc_index_event_date_value($event, 'starts_at');
        $eventEnd = dc_index_event_date_value($event, 'ends_at');

        if ($eventStart <= $weekEnd && $eventEnd >= $weekStart) {
            $weekBars[] = [
                'event' => $event,
            ];
        }
    }

    $weeks[] = [
        'start' => $weekStart,
        'days' => $days,
        'bars' => $weekBars,
    ];
}

$addEventUrl = '/dc/add-event.php';

if ($selectedGroupId > 0) {
    $addEventUrl .= '?group_id=' . $selectedGroupId;
}

/*
 * Upcoming events for the user's groups (next 14 days).
 * This powers the compact table shown above the calendar on mobile.
 */
$upcomingWhere = ["ce.starts_at >= NOW()", "ce.starts_at <= DATE_ADD(NOW(), INTERVAL 14 DAY)"];
$upcomingParams = [];

if (!$isDistrictLevelUser) {
    $upcomingAllowed = $allowedGroupIds ?: [0];
    $upcomingWhere[] = 'ce.group_id IN (' . implode(',', array_fill(0, count($upcomingAllowed), '?')) . ')';
    foreach ($upcomingAllowed as $gid) {
        $upcomingParams[] = $gid;
    }
}

$upcomingWhere[] = "ce.status NOT IN ('cancelled', 'rejected')";

$upcomingWhereSql = implode("\n    AND ", $upcomingWhere);
$upcomingSql = "
    SELECT ce.id, ce.title, ce.starts_at, ce.ends_at, ce.location_name, ce.status, g.group_name, ce.group_id
    FROM calendar_events ce
    JOIN groups g ON g.id = ce.group_id
    WHERE {$upcomingWhereSql}
    ORDER BY ce.starts_at ASC
    LIMIT 20
";

$upcomingStmt = db()->prepare($upcomingSql);
$upcomingStmt->execute($upcomingParams);
$upcomingEvents = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'District Calendar';
$heroTitle = 'District Calendar';
$heroText = null;
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
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
        padding: 1.25rem;
    }

    @media (min-width: 1100px) {
        .dc-calendar-sidebar {
            position: sticky;
            top: 1.25rem;
        }
    }

    .dc-calendar-sidebar h2 {
        margin: 0 0 1rem;
        color: var(--dc-ink, #1d2939);
        font-size: 1.2rem;
        font-weight: 700;
        line-height: 1.15;
        letter-spacing: -0.01em;
    }

    .dc-calendar-filter-form {
        display: grid;
        gap: 1rem;
    }

    .dc-calendar-filter-form label {
        display: block;
        margin-bottom: 0.35rem;
        color: var(--dc-ink, #1d2939);
        font-weight: 700;
        font-size: 0.9rem;
    }

    .dc-calendar-filter-form .form-control {
        min-height: 42px;
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
        color: var(--dc-ink, #1d2939);
        font-weight: 500;
    }

    .dc-calendar-filter-form .form-control:focus {
        outline: 3px solid #ffdd00;
        outline-offset: 0;
        box-shadow: none;
        border-color: var(--dc-scouts-purple, #7413dc);
    }

    .dc-calendar-sidebar-actions {
        display: grid;
        gap: 0.5rem;
        margin-top: 1.25rem;
    }

    .dc-calendar-sidebar-actions .lt-btn,
    .dc-calendar-filter-form .lt-btn {
        min-height: 42px;
        border-radius: 0;
        font-weight: 700;
    }

    .dc-filter-summary {
        margin: 1.25rem 0 0;
        padding-top: 1rem;
        border-top: 1px solid var(--dc-border, #e2e8f0);
        color: var(--dc-muted, #64748b);
        font-size: 0.88rem;
        font-weight: 600;
        line-height: 1.5;
    }

    .dc-group-legend {
        margin-top: 1.25rem;
        padding-top: 1rem;
        border-top: 1px solid var(--dc-border, #e2e8f0);
    }

    .dc-group-legend-title {
        margin: 0 0 0.75rem;
        color: var(--dc-ink, #1d2939);
        font-size: 0.95rem;
        font-weight: 700;
    }

    .dc-group-legend-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: grid;
        gap: 0.5rem;
    }

    .dc-group-legend-item {
        display: flex;
        align-items: center;
        gap: 0.55rem;
    }

    .dc-group-legend-swatch {
        flex-shrink: 0;
        width: 14px;
        height: 14px;
        border-radius: 2px;
    }

    .dc-group-legend-label {
        color: var(--dc-ink, #1d2939);
        font-size: 0.85rem;
        font-weight: 600;
        line-height: 1.3;
    }

    .dc-calendar-main {
        min-width: 0;
    }

    .dc-calendar-toolbar {
        display: grid;
        gap: 1rem;
        margin-bottom: 1.25rem;
        padding: 1rem 1.25rem;
        background: #ffffff;
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
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
        color: var(--dc-ink, #1d2939);
        font-size: clamp(1.4rem, 2.5vw, 2rem);
        font-weight: 800;
        line-height: 1.1;
        letter-spacing: -0.025em;
    }

    .dc-calendar-toolbar p {
        margin-top: 0.3rem;
        color: var(--dc-muted, #64748b);
        font-weight: 600;
        font-size: 0.9rem;
    }

    .dc-calendar-month-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
    }

    .dc-calendar-month-nav .lt-btn {
        min-height: 38px;
        border-radius: 0;
        font-weight: 700;
        font-size: 0.88rem;
    }

    .dc-calendar-shell {
        background: #ffffff;
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
        overflow: hidden;
    }

    .dc-calendar-weekdays {
        display: none;
        grid-template-columns: repeat(7, minmax(0, 1fr));
        background: var(--dc-canvas, #f8fafc);
        color: var(--dc-ink, #1d2939);
        font-weight: 700;
        font-size: 0.82rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .dc-calendar-weekdays div {
        padding: 0.7rem 0.65rem;
        border-right: 1px solid var(--dc-border, #e2e8f0);
        border-bottom: 1px solid var(--dc-border, #e2e8f0);
        line-height: 1.1;
    }

    .dc-calendar-weekdays div:last-child {
        border-right: 0;
    }

    .dc-calendar-week {
        border-top: 1px solid var(--dc-border, #e2e8f0);
    }

    .dc-calendar-week:first-of-type {
        border-top: 0;
    }

    .dc-calendar-days {
        display: grid;
        grid-template-columns: 1fr;
    }

    .dc-calendar-day {
        display: grid;
        align-content: start;
        gap: 0.5rem;
        min-height: 110px;
        padding: 0.75rem;
        background: #ffffff;
        border-bottom: 1px solid var(--dc-border, #e2e8f0);
    }

    .dc-calendar-day.is-outside-month {
        background: var(--dc-canvas, #f8fafc);
        color: var(--dc-muted, #64748b);
    }

    .dc-calendar-day.is-today {
        box-shadow: inset 0 0 0 2px var(--dc-scouts-teal, #00a794);
        background: #f0fdf9;
    }

    .dc-calendar-day-heading {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 0.5rem;
    }

    .dc-calendar-day-heading strong {
        color: var(--dc-ink, #1d2939);
        font-size: 1rem;
        font-weight: 700;
        line-height: 1;
    }

    .dc-calendar-day-heading span {
        color: var(--dc-muted, #64748b);
        font-size: 0.85rem;
        font-weight: 600;
    }

    .dc-calendar-day-events {
        display: grid;
        gap: 0.45rem;
        min-width: 0;
    }

    .dc-cal-event {
        display: block;
        width: 100%;
        min-width: 0;
        padding: 0.45rem 0.55rem;
        background: #f8f5fc;
        border: 0;
        border-left: 4px solid var(--dc-group-color, #7413dc);
        border-radius: 0;
        color: var(--dc-ink, #1d2939);
        cursor: pointer;
        line-height: 1.25;
        text-align: left;
        text-decoration: none;
    }

    .dc-cal-event:hover,
    .dc-cal-event:focus {
        color: var(--dc-ink, #1d2939);
        outline: 2px solid var(--dc-group-color, #7413dc);
        outline-offset: 1px;
        box-shadow: none;
        text-decoration: none;
        background: #f0e7fb;
    }

    .dc-cal-event.is-locked {
        background: #eff6ff;
        border-left-color: var(--dc-group-color, #006ddf);
    }

    .dc-cal-event-title {
        display: block;
        overflow: hidden;
        color: var(--dc-ink, #1d2939);
        font-size: 0.85rem;
        font-weight: 700;
        line-height: 1.25;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dc-cal-event-meta {
        display: block;
        overflow: hidden;
        margin-top: 0.15rem;
        color: var(--dc-muted, #64748b);
        font-size: 0.78rem;
        font-weight: 600;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dc-cal-event-status {
        display: inline-block;
        margin-top: 0.25rem;
        color: var(--dc-muted, #64748b);
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        line-height: 1.1;
        text-transform: uppercase;
    }

    .dc-cal-event-approved {
        background: #f0fdf9;
        border-left-color: var(--dc-group-color, #00a794);
    }

    .dc-cal-event-submitted,
    .dc-cal-event-under_review {
        background: #fefce8;
        border-left-color: var(--dc-group-color, #d4a300);
    }

    .dc-cal-event-changes_requested {
        background: #fef2f2;
        border-left-color: var(--dc-group-color, #dc2626);
    }

    .dc-cal-event-draft {
        background: var(--dc-canvas, #f8fafc);
        border-left-color: var(--dc-group-color, #94a3b8);
    }

    .dc-cal-event-cancelled,
    .dc-cal-event-rejected {
        background: #fef2f2;
        border-left-color: var(--dc-group-color, #dc2626);
        opacity: 0.7;
        text-decoration: line-through;
    }

    .dc-calendar-empty {
        margin-bottom: 1.25rem;
        padding: 1.25rem;
        background: #ffffff;
        border: 1px dashed var(--dc-border, #e2e8f0);
        border-radius: 0;
        color: var(--dc-muted, #64748b);
        font-weight: 600;
    }

    .dc-event-modal-backdrop {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        background: rgba(0, 0, 0, 0.4);
        padding: 1rem;
        z-index: 2000;
    }

    .dc-event-modal-backdrop.is-open {
        display: flex;
    }

    .dc-event-modal {
        width: min(680px, 100%);
        max-height: calc(100vh - 2rem);
        overflow-y: auto;
        background: #ffffff;
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15), 0 4px 16px rgba(0, 0, 0, 0.08);
    }

    .dc-event-modal-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: flex-start;
        padding: 1.25rem;
        background: var(--dc-scouts-purple-dark, #4d0b93);
        color: #ffffff;
        border-bottom: none;
        border-radius: 0;
    }

    .dc-event-modal-header h2 {
        margin: 0;
        color: #ffffff;
        font-size: clamp(1.2rem, 2.5vw, 1.5rem);
        font-weight: 700;
        line-height: 1.2;
        letter-spacing: -0.015em;
    }

    .dc-event-modal-close {
        min-width: 36px;
        min-height: 36px;
        border: 1px solid rgba(255, 255, 255, 0.4);
        border-radius: 0;
        background: transparent;
        color: #ffffff;
        cursor: pointer;
        font-size: 1.4rem;
        font-weight: 400;
        line-height: 1;
    }

    .dc-event-modal-close:hover {
        background: rgba(255, 255, 255, 0.15);
        color: #ffffff;
    }

    .dc-event-modal-body {
        padding: 1.25rem;
    }

    .dc-event-modal-body dl {
        display: grid;
        gap: 0.6rem;
        margin: 0 0 1.25rem;
    }

    @media (min-width: 640px) {
        .dc-event-modal-body dl {
            grid-template-columns: 140px minmax(0, 1fr);
        }
    }

    .dc-event-modal-body dt {
        color: var(--dc-muted, #64748b);
        font-weight: 700;
        font-size: 0.88rem;
    }

    .dc-event-modal-body dd {
        margin: 0;
        color: var(--dc-ink, #1d2939);
        font-weight: 500;
        line-height: 1.5;
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
        border-top: 1px solid var(--dc-border, #e2e8f0);
    }

    @media (min-width: 768px) {
        .dc-calendar-weekdays {
            display: grid;
        }

        .dc-calendar-days {
            grid-template-columns: repeat(7, minmax(0, 1fr));
        }

        .dc-calendar-day {
            min-height: 140px;
            padding: 0.6rem;
            border-right: 1px solid var(--dc-border, #e2e8f0);
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

        .dc-cal-event {
            padding: 0.35rem 0.45rem;
            border-left: 0;
            border-top: 3px solid var(--dc-group-color, #7413dc);
            border-radius: 0;
            overflow: hidden;
        }

        .dc-cal-event.is-locked {
            border-top-color: var(--dc-group-color, #006ddf);
        }

        .dc-cal-event-approved {
            border-top-color: var(--dc-group-color, #00a794);
        }

        .dc-cal-event-submitted,
        .dc-cal-event-under_review {
            border-top-color: var(--dc-group-color, #d4a300);
        }

        .dc-cal-event-changes_requested,
        .dc-cal-event-cancelled,
        .dc-cal-event-rejected {
            border-top-color: var(--dc-group-color, #dc2626);
        }

        .dc-cal-event-draft {
            border-top-color: var(--dc-group-color, #94a3b8);
        }

        .dc-cal-event-title {
            font-size: 0.8rem;
        }

        .dc-cal-event-meta {
            font-size: 0.72rem;
        }

        .dc-cal-event-status {
            font-size: 0.65rem;
        }
    }

    @media (min-width: 1300px) {
        .dc-calendar-day {
            min-height: 160px;
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
            margin-bottom: 0.65rem;
            border: 1px solid var(--dc-border, #e2e8f0);
            border-left: 3px solid var(--dc-scouts-purple, #7413dc);
            border-radius: 0;
        }

        .dc-calendar-day.is-empty {
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

        <?php if ($groupsInCalendar): ?>
            <div class="dc-group-legend" aria-label="Group colour key">
                <h3 class="dc-group-legend-title">Groups</h3>
                <ul class="dc-group-legend-list">
                    <?php foreach ($groupsInCalendar as $gId => $gName): ?>
                        <li class="dc-group-legend-item">
                            <span class="dc-group-legend-swatch" style="background-color: <?= e($groupColorsMap[$gId]) ?>;"></span>
                            <span class="dc-group-legend-label"><?= e($gName) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
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
                <div>Monday</div>
                <div>Tuesday</div>
                <div>Wednesday</div>
                <div>Thursday</div>
                <div>Friday</div>
                <div>Saturday</div>
                <div>Sunday</div>
            </div>

            <?php
            $today = (new DateTimeImmutable('today'))->format('Y-m-d');

            foreach ($weeks as $weekIndex => $week):
            ?>
                <div class="dc-calendar-week" data-week="<?= (int) $weekIndex ?>">
                    <div class="dc-calendar-days">
                        <?php foreach ($week['days'] as $day): ?>
                            <?php
                            $dateKey = $day->format('Y-m-d');
                            $isOutsideMonth = $day->format('Y-m') !== $monthStart->format('Y-m');
                            $isToday = $dateKey === $today;
                            $dayEvents = [];

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
                                    $dayEvents[] = [
                                        'event' => $event,
                                        'continues_before' => $eventStart < $dayStart,
                                        'continues_after' => $eventEnd > $dayEnd,
                                    ];
                                }
                            }
                            ?>

                            <div class="dc-calendar-day <?= $isOutsideMonth ? 'is-outside-month' : '' ?> <?= $isToday ? 'is-today' : '' ?> <?= !$dayEvents ? 'is-empty' : '' ?>">
                                <div class="dc-calendar-day-heading">
                                    <strong><?= e($day->format('j')) ?></strong>
                                    <span><?= e($day->format('D j M')) ?></span>
                                </div>

                                <?php if ($dayEvents): ?>
                                    <div class="dc-calendar-day-events">
                                        <?php foreach ($dayEvents as $dayEvent): ?>
                                            <?php
                                            $event = $dayEvent['event'];
                                            $eventStatus = (string) $event['status'];
                                            $eventClass = dc_event_bar_class($eventStatus);
                                            $canManage = dc_index_user_can_manage_event_group($ctx, (int) $event['group_id']);
                                            $timeLabel = date('H:i', strtotime((string) $event['starts_at']));
                                            $continuationPrefix = $dayEvent['continues_before'] ? '← ' : '';
                                            $continuationSuffix = $dayEvent['continues_after'] ? ' →' : '';
                                            $groupColor = $groupColorsMap[(int) $event['group_id']] ?? '#7413dc';
                                            ?>
                                            <button
                                                type="button"
                                                class="<?= e($eventClass) ?> <?= $canManage ? '' : 'is-locked' ?>"
                                                data-event-id="<?= (int) $event['id'] ?>"
                                                style="--dc-group-color: <?= e($groupColor) ?>;"
                                            >
                                                <span class="dc-cal-event-title"><?= e($continuationPrefix . (string) $event['title'] . $continuationSuffix) ?></span>
                                                <span class="dc-cal-event-meta"><?= e($timeLabel) ?> · <?= e((string) $event['group_name']) ?></span>
                                                <span class="dc-cal-event-status"><?= e(str_replace('_', ' ', $eventStatus)) ?></span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
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

    if (modalClose) {
        modalClose.addEventListener('click', closeModal);
    }

    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
}());
</script>

<?php require __DIR__ . '/layout-footer.php'; ?>