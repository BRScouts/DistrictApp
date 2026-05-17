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

$eventsByDate = [];

foreach ($events as $event) {
    try {
        $eventStart = new DateTimeImmutable((string) $event['starts_at']);
        $eventEnd = new DateTimeImmutable((string) $event['ends_at']);
    } catch (Throwable $e) {
        continue;
    }

    $cursor = $eventStart < $calendarStart ? $calendarStart : $eventStart;
    $last = $eventEnd > $calendarEnd ? $calendarEnd : $eventEnd;

    $cursor = new DateTimeImmutable($cursor->format('Y-m-d 00:00:00'));
    $last = new DateTimeImmutable($last->format('Y-m-d 00:00:00'));

    while ($cursor <= $last) {
        $key = $cursor->format('Y-m-d');
        $eventsByDate[$key][] = $event;
        $cursor = $cursor->modify('+1 day');
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

$canAddForSelectedGroup = $selectedGroupId > 0 && dc_user_can_manage_event_group($ctx, $selectedGroupId);
$singleViewerGroupId = count($viewerGroupIds) === 1 ? $viewerGroupIds[0] : 0;

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
    .dc-calendar-toolbar {
        display: grid;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    @media (min-width: 900px) {
        .dc-calendar-toolbar {
            grid-template-columns: 1fr auto;
            align-items: center;
        }
    }

    .dc-calendar-month-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
    }

    .dc-calendar-month-title {
        font-size: clamp(1.5rem, 3vw, 2.25rem);
        font-weight: 900;
        margin: 0;
    }

    .dc-calendar-filter-form {
        display: grid;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    @media (min-width: 900px) {
        .dc-calendar-filter-form {
            grid-template-columns: 1.4fr 1fr 1.5fr auto;
            align-items: end;
        }
    }

    .dc-calendar-shell {
        background: #ffffff;
        border: 2px solid #000000;
        overflow: hidden;
        margin-bottom: 1.5rem;
    }

    .dc-calendar-weekdays {
        display: none;
        grid-template-columns: repeat(7, 1fr);
        background: #7413dc;
        color: #ffffff;
        font-weight: 900;
    }

    .dc-calendar-weekdays div {
        padding: 0.75rem;
        border-right: 1px solid rgba(255, 255, 255, 0.35);
    }

    .dc-calendar-grid {
        display: grid;
        grid-template-columns: 1fr;
    }

    .dc-calendar-day {
        min-height: 120px;
        border-top: 1px solid #d8d8d8;
        padding: 0.75rem;
        background: #ffffff;
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
        gap: 0.75rem;
        align-items: baseline;
        margin-bottom: 0.5rem;
    }

    .dc-calendar-day-heading strong {
        font-size: 1.1rem;
        font-weight: 900;
    }

    .dc-calendar-day-heading span {
        font-size: 0.9rem;
        color: #4a4a4a;
    }

    .dc-calendar-event-list {
        display: grid;
        gap: 0.4rem;
    }

    .dc-calendar-event {
        display: block;
        border-left: 5px solid #7413dc;
        background: #f5f5f5;
        padding: 0.5rem;
        color: #000000;
        text-decoration: none;
    }

    .dc-calendar-event:hover,
    .dc-calendar-event:focus {
        outline: 3px solid #ffdd00;
        outline-offset: 1px;
        color: #000000;
        text-decoration: none;
    }

    .dc-calendar-event.is-locked {
        border-left-color: #006ddf;
        cursor: default;
    }

    .dc-calendar-event-title {
        display: block;
        font-weight: 900;
        line-height: 1.2;
    }

    .dc-calendar-event-meta {
        display: block;
        font-size: 0.85rem;
        color: #4a4a4a;
        margin-top: 0.15rem;
    }

    .dc-calendar-event-status {
        display: inline-block;
        margin-top: 0.35rem;
        font-size: 0.75rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    @media (min-width: 768px) {
        .dc-calendar-weekdays {
            display: grid;
        }

        .dc-calendar-grid {
            grid-template-columns: repeat(7, minmax(0, 1fr));
        }

        .dc-calendar-day {
            border-right: 1px solid #d8d8d8;
            min-height: 165px;
        }

        .dc-calendar-day:nth-child(7n) {
            border-right: 0;
        }

        .dc-calendar-day-heading span {
            display: none;
        }
    }

    @media (max-width: 767.98px) {
        .dc-calendar-shell {
            border: 0;
            background: transparent;
        }

        .dc-calendar-day {
            border: 2px solid #d8d8d8;
            margin-bottom: 0.75rem;
        }

        .dc-calendar-day.is-empty {
            display: none;
        }
    }

    .dc-event-list {
        display: grid;
        gap: 0.75rem;
    }

    .dc-event-list-item {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: 0.75rem;
        border: 1px solid #d8d8d8;
        background: #ffffff;
        padding: 0.75rem;
    }

    .dc-date-box {
        min-width: 64px;
        border: 2px solid #000000;
        text-align: center;
        background: #ffffff;
    }

    .dc-date-box span {
        display: block;
        font-size: 1.6rem;
        font-weight: 900;
        line-height: 1;
        padding-top: 0.5rem;
    }

    .dc-date-box strong {
        display: block;
        background: #7413dc;
        color: #ffffff;
        padding: 0.25rem;
        font-size: 0.85rem;
        text-transform: uppercase;
    }

    .dc-locked-note {
        color: #4a4a4a;
        font-size: 0.9rem;
        margin-top: 0.25rem;
    }
</style>

<div class="dc-calendar-toolbar">
    <div>
        <h2 class="dc-calendar-month-title">
            <?= e($monthStart->format('F Y')) ?>
        </h2>
        <p class="mb-0">
            Showing activities from all Groups. You can only open and manage events for Groups you have access to.
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
        <a class="btn btn-primary lt-btn" href="<?= e($addEventUrl) ?>">
            Add event
        </a>
    </div>
</div>

<form method="get" class="lt-panel-grey dc-calendar-filter-form" action="/dc/">
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
            placeholder="Search title, Group, leader or location"
        >
    </div>

    <button class="btn btn-primary lt-btn" type="submit">
        Apply filters
    </button>
</form>

<section class="dc-calendar-shell" aria-labelledby="calendar-heading">
    <h2 id="calendar-heading" class="sr-only">Calendar for <?= e($monthStart->format('F Y')) ?></h2>

    <div class="dc-calendar-weekdays" aria-hidden="true">
        <div>Monday</div>
        <div>Tuesday</div>
        <div>Wednesday</div>
        <div>Thursday</div>
        <div>Friday</div>
        <div>Saturday</div>
        <div>Sunday</div>
    </div>

    <div class="dc-calendar-grid">
        <?php
        $day = $calendarStart;
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        while ($day <= $calendarEnd):
            $dateKey = $day->format('Y-m-d');
            $dayEvents = $eventsByDate[$dateKey] ?? [];
            $isOutsideMonth = $day->format('Y-m') !== $monthStart->format('Y-m');
            $isToday = $dateKey === $today;
            $isEmpty = !$dayEvents;
        ?>
            <div class="dc-calendar-day <?= $isOutsideMonth ? 'is-outside-month' : '' ?> <?= $isToday ? 'is-today' : '' ?> <?= $isEmpty ? 'is-empty' : '' ?>">
                <div class="dc-calendar-day-heading">
                    <strong><?= e($day->format('j')) ?></strong>
                    <span><?= e($day->format('D j M')) ?></span>
                </div>

                <?php if ($dayEvents): ?>
                    <div class="dc-calendar-event-list">
                        <?php foreach ($dayEvents as $event): ?>
                            <?php
                                $canManage = dc_user_can_manage_event_group($ctx, (int) $event['group_id']);
                                $eventStart = new DateTimeImmutable((string) $event['starts_at']);
                                $eventEnd = new DateTimeImmutable((string) $event['ends_at']);
                                $eventLabel = date('H:i', strtotime((string) $event['starts_at'])) . ' · ' . $event['group_name'];
                                $eventStatus = (string) $event['status'];
                                $eventClass = 'dc-calendar-event dc-status-' . preg_replace('/[^a-z0-9_-]/i', '', $eventStatus);
                            ?>

                            <?php if ($canManage): ?>
                                <a
                                    class="<?= e($eventClass) ?>"
                                    href="/dc/manage-event.php?id=<?= (int) $event['id'] ?>"
                                    title="<?= e((string) $event['title']) ?>"
                                >
                                    <span class="dc-calendar-event-title"><?= e((string) $event['title']) ?></span>
                                    <span class="dc-calendar-event-meta"><?= e($eventLabel) ?></span>
                                    <span class="dc-calendar-event-status"><?= e(str_replace('_', ' ', $eventStatus)) ?></span>
                                </a>
                            <?php else: ?>
                                <div
                                    class="<?= e($eventClass) ?> is-locked"
                                    title="You can view this activity but cannot manage it"
                                >
                                    <span class="dc-calendar-event-title"><?= e((string) $event['title']) ?></span>
                                    <span class="dc-calendar-event-meta"><?= e($eventLabel) ?></span>
                                    <span class="dc-calendar-event-status"><?= e(str_replace('_', ' ', $eventStatus)) ?></span>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php
            $day = $day->modify('+1 day');
        endwhile;
        ?>
    </div>
</section>

<section class="lt-panel" aria-labelledby="event-list-heading">
    <div class="dc-action-bar">
        <div>
            <h2 id="event-list-heading" class="lt-section-title">Events in this view</h2>
            <p class="mb-0">
                <?= count($events) ?> event<?= count($events) === 1 ? '' : 's' ?> found.
            </p>
        </div>
    </div>

    <?php if (!$events): ?>
        <p class="mb-0">No events match the selected filters.</p>
    <?php else: ?>
        <div class="dc-event-list">
            <?php foreach ($events as $event): ?>
                <?php
                    $canManage = dc_user_can_manage_event_group($ctx, (int) $event['group_id']);
                    $manageUrl = '/dc/manage-event.php?id=' . (int) $event['id'];
                ?>

                <article class="dc-event-list-item">
                    <div class="dc-date-box">
                        <span><?= e(date('d', strtotime((string) $event['starts_at']))) ?></span>
                        <strong><?= e(date('M', strtotime((string) $event['starts_at']))) ?></strong>
                    </div>

                    <div>
                        <h3 class="mb-1">
                            <?php if ($canManage): ?>
                                <a href="<?= e($manageUrl) ?>"><?= e((string) $event['title']) ?></a>
                            <?php else: ?>
                                <?= e((string) $event['title']) ?>
                            <?php endif; ?>
                        </h3>

                        <p class="mb-1">
                            <strong><?= e((string) $event['group_name']) ?></strong>
                            ·
                            <?= e(date('D j M Y, H:i', strtotime((string) $event['starts_at']))) ?>
                            to
                            <?= e(date('D j M Y, H:i', strtotime((string) $event['ends_at']))) ?>
                        </p>

                        <?php if (!empty($event['location_name'])): ?>
                            <p class="mb-1"><?= e((string) $event['location_name']) ?></p>
                        <?php endif; ?>

                        <span class="lt-badge dc-status dc-status-<?= e((string) $event['status']) ?>">
                            <?= e(str_replace('_', ' ', (string) $event['status'])) ?>
                        </span>

                        <?php if (!$canManage): ?>
                            <div class="dc-locked-note">
                                You can see this District activity, but you do not have access to manage it.
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/layout-footer.php'; ?>