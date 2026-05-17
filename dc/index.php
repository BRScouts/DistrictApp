<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$ctx = dc_require_access();

$viewerGroupIds = array_map('intval', (array) ($ctx['group_ids'] ?? []));
$isReviewer = (bool) ($ctx['is_reviewer'] ?? false);

$selectedGroupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0;
$selectedStatus = trim((string) ($_GET['status'] ?? 'active'));
$searchQuery = trim((string) ($_GET['q'] ?? ''));

$monthParam = trim((string) ($_GET['month'] ?? ''));

try {
    if (preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
        $monthStart = new DateTimeImmutable($monthParam . '-01 00:00:00');
    } else {
        $monthStart = new DateTimeImmutable('first day of this month 00:00:00');
    }
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
        static fn ($value): bool => $value !== null && $value !== '' && $value !== 0 && $value !== '0'
    );

    return '/dc/' . ($params ? '?' . http_build_query($params) : '');
}

function dc_user_can_manage_event_group(array $ctx, int $groupId): bool
{
    if (!empty($ctx['is_reviewer'])) {
        return true;
    }

    $groupIds = array_map('intval', (array) ($ctx['group_ids'] ?? []));

    return in_array($groupId, $groupIds, true);
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
    $allowedStatuses = [
        'draft',
        'submitted',
        'under_review',
        'approved',
        'changes_requested',
        'rejected',
        'cancelled',
    ];

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
    SELECT
        ce.*,
        g.group_name
    FROM calendar_events ce
    JOIN groups g
      ON g.id = ce.group_id
    WHERE " . implode("\n      AND ", $eventWhere) . "
    ORDER BY ce.starts_at ASC, ce.ends_at ASC, g.group_name ASC, ce.title ASC
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

        $columnStart = ((int) $barStartDay->format('N'));
        $spanDays = dc_days_between_inclusive($barStartDay, $barEndDay);

        $weeks[$weekIndex]['bars'][] = [
            'event' => $event,
            'column_start' => $columnStart,
            'span' => $spanDays,
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
$canAddForSelectedGroup = $selectedGroupId > 0 && dc_user_can_manage_event_group($ctx, $selectedGroupId);

$addEventGroupId = 0;

if ($canAddForSelectedGroup) {
    $addEventGroupId = $selectedGroupId;
} elseif ($singleViewerGroupId > 0) {
    $addEventGroupId = $singleViewerGroupId;
}

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
        gap: 1rem;
    }

    @media (min-width: 992px) {
        .dc-calendar-page {
            grid-template-columns: 280px minmax(0, 1fr);
            align-items: start;
        }
    }

    .dc-calendar-sidebar {
        background: #f5f5f5;
        border: 2px solid #000;
        padding: 1rem;
    }

    @media (min-width: 992px) {
        .dc-calendar-sidebar {
            position: sticky;
            top: 1rem;
        }
    }

    .dc-calendar-sidebar h2 {
        font-size: 1.35rem;
        font-weight: 900;
        margin-bottom: 1rem;
    }

    .dc-calendar-filter-form {
        display: grid;
        gap: 1rem;
    }

    .dc-calendar-sidebar-actions {
        display: grid;
        gap: 0.5rem;
        margin-top: 1rem;
    }

    .dc-calendar-main {
        min-width: 0;
    }

    .dc-calendar-toolbar {
        display: grid;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    @media (min-width: 768px) {
        .dc-calendar-toolbar {
            grid-template-columns: 1fr auto;
            align-items: center;
        }
    }

    .dc-calendar-month-title {
        font-size: clamp(1.5rem, 3vw, 2.35rem);
        font-weight: 900;
        margin: 0;
    }

    .dc-calendar-month-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
    }

    .dc-calendar-shell {
        background: #ffffff;
        border: 2px solid #000000;
        overflow: hidden;
    }

    .dc-calendar-weekdays {
        display: none;
        grid-template-columns: repeat(7, minmax(0, 1fr));
        background: #7413dc;
        color: #ffffff;
        font-weight: 900;
    }

    .dc-calendar-weekdays div {
        padding: 0.75rem;
        border-right: 1px solid rgba(255, 255, 255, 0.35);
    }

    .dc-calendar-weekdays div:last-child {
        border-right: 0;
    }

    .dc-calendar-week {
        display: grid;
        grid-template-columns: 1fr;
        border-top: 1px solid #d8d8d8;
    }

    .dc-calendar-week:first-of-type {
        border-top: 0;
    }

    .dc-calendar-days {
        display: grid;
        grid-template-columns: 1fr;
    }

    .dc-calendar-day {
        min-height: 72px;
        padding: 0.75rem;
        background: #ffffff;
        border-bottom: 1px solid #d8d8d8;
    }

    .dc-calendar-day.is-outside-month {
        background: #f5f5f5;
        color: #4a4a4a;
    }

    .dc-calendar-day.is-today {
        box-shadow: inset 0 0 0 4px #ffdd00;
    }

    .dc-calendar-day-heading {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 0.5rem;
    }

    .dc-calendar-day-heading strong {
        font-size: 1.1rem;
        font-weight: 900;
    }

    .dc-calendar-day-heading span {
        color: #4a4a4a;
        font-size: 0.9rem;
    }

    .dc-calendar-bars {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.4rem;
        padding: 0.5rem;
        background: #ffffff;
    }

    .dc-cal-event {
        display: block;
        color: #000000;
        background: #e7ddff;
        border-left: 6px solid #7413dc;
        padding: 0.45rem 0.55rem;
        min-width: 0;
        text-decoration: none;
        line-height: 1.2;
    }

    .dc-cal-event:hover,
    .dc-cal-event:focus {
        color: #000000;
        outline: 3px solid #ffdd00;
        outline-offset: 1px;
        text-decoration: none;
    }

    .dc-cal-event.is-locked {
        background: #e8f1ff;
        border-left-color: #006ddf;
        cursor: default;
    }

    .dc-cal-event-title {
        display: block;
        font-weight: 900;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dc-cal-event-meta {
        display: block;
        color: #4a4a4a;
        font-size: 0.82rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dc-cal-event-status {
        display: inline-block;
        margin-top: 0.25rem;
        font-size: 0.72rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.02em;
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
        background: #f5f5f5;
        border-left-color: #777777;
    }

    .dc-cal-event-cancelled,
    .dc-cal-event-rejected {
        background: #f7e5e3;
        border-left-color: #d4351c;
        text-decoration: line-through;
    }

    .dc-calendar-empty {
        padding: 1rem;
        background: #ffffff;
        border: 2px dashed #888;
    }

    .dc-mobile-event-stack {
        display: grid;
        gap: 0.4rem;
        margin-top: 0.5rem;
    }

    @media (min-width: 768px) {
        .dc-calendar-weekdays {
            display: grid;
        }

        .dc-calendar-week {
            display: block;
            border-top: 1px solid #d8d8d8;
        }

        .dc-calendar-days {
            grid-template-columns: repeat(7, minmax(0, 1fr));
            min-height: 92px;
        }

        .dc-calendar-day {
            min-height: 92px;
            border-right: 1px solid #d8d8d8;
            border-bottom: 0;
            padding: 0.5rem;
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
            grid-auto-rows: minmax(2.35rem, auto);
            align-items: stretch;
            gap: 0.25rem;
            padding: 0.35rem 0.5rem 0.7rem;
            min-height: 78px;
        }

        .dc-cal-event {
            padding: 0.35rem 0.45rem;
            border-left: 0;
            border-top: 5px solid #7413dc;
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
            border-top-color: #777777;
        }

        .dc-mobile-event-stack {
            display: none;
        }
    }

    @media (max-width: 767.98px) {
        .dc-calendar-shell {
            border: 0;
            background: transparent;
        }

        .dc-calendar-week {
            border-top: 0;
        }

        .dc-calendar-day {
            border: 2px solid #d8d8d8;
            margin-bottom: 0.75rem;
        }

        .dc-calendar-day.is-empty {
            display: none;
        }

        .dc-calendar-bars {
            display: none;
        }
    }

    .dc-filter-summary {
        font-size: 0.95rem;
        color: #4a4a4a;
        margin-top: 0.75rem;
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
                <input
                    id="q"
                    name="q"
                    class="form-control"
                    value="<?= e($searchQuery) ?>"
                    placeholder="Title, Group, leader or location"
                >
            </div>

            <button class="btn btn-primary lt-btn" type="submit">
                Apply filters
            </button>
        </form>

        <div class="dc-calendar-sidebar-actions">
            <a class="btn btn-primary lt-btn" href="<?= e($addEventUrl) ?>">
                Add event
            </a>

            <a class="btn lt-btn lt-btn-secondary" href="/dc/">
                Clear filters
            </a>
        </div>

        <p class="dc-filter-summary">
            <?= count($events) ?> event<?= count($events) === 1 ? '' : 's' ?> shown.
            You can open events only for Groups you have access to.
        </p>
    </aside>

    <section class="dc-calendar-main" aria-labelledby="calendar-heading">
        <div class="dc-calendar-toolbar">
            <div>
                <h2 id="calendar-heading" class="dc-calendar-month-title">
                    <?= e($monthStart->format('F Y')) ?>
                </h2>
                <p class="mb-0">
                    Activities across the District.
                </p>
            </div>

            <div class="dc-calendar-month-nav" aria-label="Calendar navigation">
                <a class="btn lt-btn lt-btn-secondary" href="<?= e(dc_index_url(['month' => $previousMonth])) ?>">
                    Previous
                </a>

                <a class="btn lt-btn lt-btn-secondary" href="<?= e(dc_index_url(['month' => $currentMonth])) ?>">
                    Today
                </a>

                <a class="btn lt-btn lt-btn-secondary" href="<?= e(dc_index_url(['month' => $nextMonth])) ?>">
                    Next
                </a>
            </div>
        </div>

        <?php if (!$events): ?>
            <div class="dc-calendar-empty">
                No events match the selected filters for this month.
            </div>
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

            foreach ($weeks as $week):
            ?>
                <div class="dc-calendar-week">
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
                                                $canManage = dc_user_can_manage_event_group($ctx, (int) $event['group_id']);
                                                $eventStatus = (string) $event['status'];
                                                $eventClass = dc_event_bar_class($eventStatus);
                                                $timeLabel = date('H:i', strtotime((string) $event['starts_at']));
                                            ?>

                                            <?php if ($canManage): ?>
                                                <a
                                                    class="<?= e($eventClass) ?>"
                                                    href="/dc/manage-event.php?id=<?= (int) $event['id'] ?>"
                                                >
                                                    <span class="dc-cal-event-title"><?= e((string) $event['title']) ?></span>
                                                    <span class="dc-cal-event-meta"><?= e($timeLabel) ?> · <?= e((string) $event['group_name']) ?></span>
                                                    <span class="dc-cal-event-status"><?= e(str_replace('_', ' ', $eventStatus)) ?></span>
                                                </a>
                                            <?php else: ?>
                                                <div class="<?= e($eventClass) ?> is-locked">
                                                    <span class="dc-cal-event-title"><?= e((string) $event['title']) ?></span>
                                                    <span class="dc-cal-event-meta"><?= e($timeLabel) ?> · <?= e((string) $event['group_name']) ?></span>
                                                    <span class="dc-cal-event-status"><?= e(str_replace('_', ' ', $eventStatus)) ?></span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($week['bars']): ?>
                        <div class="dc-calendar-bars" aria-label="Events for week beginning <?= e($week['start']->format('j F Y')) ?>">
                            <?php foreach ($week['bars'] as $bar): ?>
                                <?php
                                    $event = $bar['event'];
                                    $canManage = dc_user_can_manage_event_group($ctx, (int) $event['group_id']);
                                    $eventStatus = (string) $event['status'];
                                    $eventClass = dc_event_bar_class($eventStatus);

                                    $columnStart = (int) $bar['column_start'];
                                    $columnEnd = $columnStart + (int) $bar['span'];
                                    $columnEnd = min($columnEnd, 8);

                                    $startsText = date('D j M H:i', strtotime((string) $event['starts_at']));
                                    $endsText = date('D j M H:i', strtotime((string) $event['ends_at']));

                                    $continuationPrefix = $bar['continues_before'] ? '← ' : '';
                                    $continuationSuffix = $bar['continues_after'] ? ' →' : '';
                                ?>

                                <?php if ($canManage): ?>
                                    <a
                                        class="<?= e($eventClass) ?>"
                                        href="/dc/manage-event.php?id=<?= (int) $event['id'] ?>"
                                        style="grid-column: <?= $columnStart ?> / <?= $columnEnd ?>;"
                                        title="<?= e((string) $event['title']) ?> · <?= e($startsText) ?> to <?= e($endsText) ?>"
                                    >
                                        <span class="dc-cal-event-title">
                                            <?= e($continuationPrefix . (string) $event['title'] . $continuationSuffix) ?>
                                        </span>
                                        <span class="dc-cal-event-meta">
                                            <?= e((string) $event['group_name']) ?> · <?= e(date('H:i', strtotime((string) $event['starts_at']))) ?>
                                        </span>
                                        <span class="dc-cal-event-status"><?= e(str_replace('_', ' ', $eventStatus)) ?></span>
                                    </a>
                                <?php else: ?>
                                    <div
                                        class="<?= e($eventClass) ?> is-locked"
                                        style="grid-column: <?= $columnStart ?> / <?= $columnEnd ?>;"
                                        title="<?= e((string) $event['title']) ?> · <?= e($startsText) ?> to <?= e($endsText) ?>"
                                    >
                                        <span class="dc-cal-event-title">
                                            <?= e($continuationPrefix . (string) $event['title'] . $continuationSuffix) ?>
                                        </span>
                                        <span class="dc-cal-event-meta">
                                            <?= e((string) $event['group_name']) ?> · <?= e(date('H:i', strtotime((string) $event['starts_at']))) ?>
                                        </span>
                                        <span class="dc-cal-event-status"><?= e(str_replace('_', ' ', $eventStatus)) ?></span>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<?php require __DIR__ . '/layout-footer.php'; ?>