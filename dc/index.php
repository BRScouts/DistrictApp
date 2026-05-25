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
        display: grid;
        align-content: start;
        gap: 0.65rem;
        min-height: 120px;
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

    .dc-calendar-day-events {
        display: grid;
        gap: 0.45rem;
        min-width: 0;
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

        .dc-calendar-days {
            grid-template-columns: repeat(7, minmax(0, 1fr));
        }

        .dc-calendar-day {
            min-height: 168px;
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

        .dc-cal-event {
            padding: 0.45rem 0.5rem;
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

        .dc-cal-event-title {
            font-size: 0.88rem;
        }

        .dc-cal-event-meta {
            font-size: 0.78rem;
        }

        .dc-cal-event-status {
            font-size: 0.68rem;
        }
    }

    @media (min-width: 1300px) {
        .dc-calendar-day {
            min-height: 190px;
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
                                            ?>
                                            <button
                                                type="button"
                                                class="<?= e($eventClass) ?> <?= $canManage ? '' : 'is-locked' ?>"
                                                data-event-id="<?= (int) $event['id'] ?>"
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