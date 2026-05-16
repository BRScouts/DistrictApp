<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_auth();

$pdo = db();

$currentGroup = auth_group();
$isAdminOrReviewer = is_reviewer_or_admin();

/*
|--------------------------------------------------------------------------
| Month navigation
|--------------------------------------------------------------------------
*/
$month = isset($_GET['month']) ? max(1, min(12, (int)$_GET['month'])) : (int)date('n');
$year  = isset($_GET['year']) ? max(2020, min(2100, (int)$_GET['year'])) : (int)date('Y');

$allGroups = get_all_active_groups();

if (isset($_GET['groups']) && is_array($_GET['groups'])) {
    $selectedGroupIds = get_selected_group_ids();
} else {
    $selectedGroupIds = array_map(fn($group) => (int)$group['id'], $allGroups);
}

/*
|--------------------------------------------------------------------------
| Calendar boundaries
|--------------------------------------------------------------------------
*/
$firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
$lastOfMonth  = $firstOfMonth->modify('last day of this month')->setTime(23, 59, 59);

$startCalendar = $firstOfMonth->modify('-' . ((int)$firstOfMonth->format('N') - 1) . ' days');
$endCalendar   = $lastOfMonth->modify('+' . (7 - (int)$lastOfMonth->format('N')) . ' days')->setTime(23, 59, 59);

$prevMonth = $firstOfMonth->modify('-1 month');
$nextMonth = $firstOfMonth->modify('+1 month');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function group_color(int $groupId): string
{
    $palette = [
        '#1f6feb',
        '#2da44e',
        '#8250df',
        '#d97706',
        '#0ea5a4',
        '#db2777',
        '#4f46e5',
        '#059669',
        '#dc2626',
    ];

    return $palette[$groupId % count($palette)];
}

function calendar_url_for_month(DateTimeImmutable $date): string
{
    $params = [
        'month' => $date->format('n'),
        'year' => $date->format('Y'),
    ];

    if (isset($_GET['groups']) && is_array($_GET['groups'])) {
        $params['groups'] = array_map('intval', $_GET['groups']);
    }

    return ROUTE_CALENDAR . '?' . http_build_query($params);
}

function event_status_emoji(string $status): string
{
    return match ($status) {
        'approved' => '✅',
        'submitted', 'under_review' => '🟠',
        'changes_requested', 'rejected', 'cancelled' => '❌',
        default => '',
    };
}

function event_status_label(string $status): string
{
    return match ($status) {
        'approved' => 'Approved',
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'changes_requested' => 'Changes requested',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function status_badge_html(string $status): string
{
    return match ($status) {
        'approved' => '<span class="badge" style="background:#28a745;color:#fff;">Approved</span>',
        'submitted' => '<span class="badge" style="background:#ffc107;color:#212529;">Submitted</span>',
        'under_review' => '<span class="badge" style="background:#17a2b8;color:#fff;">Under review</span>',
        'changes_requested' => '<span class="badge" style="background:#ffc107;color:#212529;">Changes requested</span>',
        'rejected' => '<span class="badge" style="background:#dc3545;color:#fff;">Rejected</span>',
        'cancelled' => '<span class="badge" style="background:#343a40;color:#fff;">Cancelled</span>',
        default => '<span class="badge" style="background:#6c757d;color:#fff;">' . e(event_status_label($status)) . '</span>',
    };
}

function can_show_status_for_event(array $event, bool $isAdminOrReviewer, ?array $currentGroup): bool
{
    if ($isAdminOrReviewer) {
        return true;
    }

    return $currentGroup && (int)$event['group_id'] === (int)$currentGroup['group_id'];
}

function can_open_event_page(array $event, bool $isAdminOrReviewer, ?array $currentGroup): bool
{
    if ($isAdminOrReviewer) {
        return true;
    }

    return $currentGroup && (int)$event['group_id'] === (int)$currentGroup['group_id'];
}

function event_page_url(array $event, bool $isAdminOrReviewer, ?array $currentGroup): string
{
    $url = BASE_URL . '/add-event.php?event_id=' . (int)$event['id'];

    if ($isAdminOrReviewer) {
        $url .= '&group_id=' . (int)$event['group_id'];
    } elseif ($currentGroup && !empty($currentGroup['access_token'])) {
        $url .= '&token=' . urlencode((string)$currentGroup['access_token']);
    } elseif (!empty($event['access_token'])) {
        $url .= '&token=' . urlencode((string)$event['access_token']);
    }

    return $url;
}

function date_create_url(string $dateKey, ?array $currentGroup): string
{
    $url = BASE_URL . '/add-event.php?starts_at_date=' . urlencode($dateKey);

    if ($currentGroup && !empty($currentGroup['access_token'])) {
        $url .= '&token=' . urlencode((string)$currentGroup['access_token']);
    }

    return $url;
}

function attending_sections(array $event): array
{
    $sections = [
        'Squirrels' => (int)($event['squirrels_count'] ?? 0),
        'Beavers' => (int)($event['beavers_count'] ?? 0),
        'Cubs' => (int)($event['cubs_count'] ?? 0),
        'Scouts' => (int)($event['scouts_count'] ?? 0),
        'Explorers' => (int)($event['explorers_count'] ?? 0),
        'Network' => (int)($event['network_count'] ?? 0),
        'Adults' => (int)($event['adults_count'] ?? 0),
    ];

    return array_filter($sections, fn($count) => $count > 0);
}

function ra_modal_name(array $ra): string
{
    $filename = trim((string)($ra['original_filename'] ?? ''));

    if ($filename !== '') {
        return $filename;
    }

    $title = trim((string)($ra['title'] ?? ''));
    return $title !== '' ? $title : 'Risk assessment';
}

function ra_can_preview_modal(array $ra): bool
{
    return strtolower((string)($ra['file_extension'] ?? '')) === 'pdf';
}

/*
|--------------------------------------------------------------------------
| Fetch events
|--------------------------------------------------------------------------
*/
$events = [];
$eventsByDate = [];
$eventIds = [];

if (!empty($selectedGroupIds)) {
    $placeholders = implode(',', array_fill(0, count($selectedGroupIds), '?'));

    $sql = "
        SELECT
            e.id,
            e.group_id,
            g.group_name,
            g.access_token,
            e.contact_name,
            e.contact_email,
            e.event_title,
            e.event_description,
            e.event_location,
            e.starts_at,
            e.ends_at,
            e.squirrels_count,
            e.beavers_count,
            e.cubs_count,
            e.scouts_count,
            e.explorers_count,
            e.network_count,
            e.young_people_count,
            e.adults_count,
            e.status,
            e.admin_comments,
            e.submitted_at,
            e.reviewed_at,
            (
                SELECT COUNT(*)
                FROM event_risk_assessments era
                WHERE era.event_id = e.id
            ) AS risk_assessment_count
        FROM events e
        INNER JOIN groups g ON g.id = e.group_id
        WHERE e.starts_at <= ?
          AND e.ends_at >= ?
          AND e.group_id IN ($placeholders)
        ORDER BY e.starts_at ASC, e.event_title ASC
    ";

    $params = [
        $endCalendar->format('Y-m-d H:i:s'),
        $startCalendar->format('Y-m-d H:i:s'),
    ];
    $params = array_merge($params, $selectedGroupIds);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();

    foreach ($events as $event) {
        $eventIds[] = (int)$event['id'];

        $eventStart = new DateTimeImmutable((string)$event['starts_at']);
        $eventEnd   = new DateTimeImmutable((string)$event['ends_at']);

        $current = $eventStart->setTime(0, 0, 0);
        $last    = $eventEnd->setTime(0, 0, 0);

        while ($current <= $last) {
            $dateKey = $current->format('Y-m-d');
            $eventsByDate[$dateKey][] = $event;
            $current = $current->modify('+1 day');
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch attached risk assessments
|--------------------------------------------------------------------------
*/
$eventRiskAssessments = [];

if (!empty($eventIds)) {
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

    $sql = "
        SELECT
            era.event_id,
            ra.id AS risk_assessment_id,
            ra.title,
            ra.original_filename,
            ra.file_extension,
            ra.updated_at,
            g.group_name
        FROM event_risk_assessments era
        INNER JOIN risk_assessments ra ON ra.id = era.risk_assessment_id
        INNER JOIN groups g ON g.id = ra.group_id
        WHERE era.event_id IN ($placeholders)
          AND ra.is_active = 1
        ORDER BY ra.updated_at DESC, ra.original_filename ASC, ra.title ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($eventIds);

    foreach ($stmt->fetchAll() as $row) {
        $eventRiskAssessments[(int)$row['event_id']][] = $row;
    }
}

render_page_start('Calendar');
render_header('calendar');
?>

<style>
html,
body {
    min-height: 100%;
}

.calendar-page {
    display: grid;
    grid-template-columns: 270px minmax(0, 1fr);
    gap: 1rem;
    min-height: calc(100vh - 105px);
}

.calendar-sidebar {
    background: #fff;
    border: 1px solid #d9dee5;
    border-radius: .85rem;
    padding: 1rem;
    align-self: start;
    position: sticky;
    top: 1rem;
    max-height: calc(100vh - 125px);
    overflow-y: auto;
}

.calendar-sidebar-title {
    font-size: 1.05rem;
    font-weight: 800;
    margin-bottom: .75rem;
}

.calendar-group-list {
    max-height: 55vh;
    overflow-y: auto;
    padding-right: .25rem;
}

.calendar-legend-dot {
    width: 12px;
    height: 12px;
    border-radius: 999px;
    display: inline-block;
    margin-right: .45rem;
    vertical-align: middle;
}

.calendar-main-wrap {
    min-width: 0;
}

.calendar-toolbar {
    background: #fff;
    border: 1px solid #d9dee5;
    border-radius: .85rem;
    padding: .75rem;
    margin-bottom: .75rem;
}

.calendar-toolbar-main {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .75rem;
}

.calendar-title {
    font-size: 1.75rem;
    font-weight: 800;
    margin: 0;
}

.calendar-nav {
    display: flex;
    gap: .4rem;
    align-items: center;
}

.calendar-main {
    background: #fff;
    border: 1px solid #d9dee5;
    border-radius: .85rem;
    overflow: hidden;
}

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    height: calc(100vh - 225px);
    min-height: 680px;
    border-top: 1px solid #d9dee5;
    border-left: 1px solid #d9dee5;
}

.calendar-weekday {
    background: #f8fafc;
    border-right: 1px solid #d9dee5;
    border-bottom: 1px solid #d9dee5;
    min-height: 42px;
    padding: .55rem .35rem;
    text-align: center;
    font-weight: 800;
    color: #1565d8;
    font-size: .95rem;
}

.calendar-cell {
    position: relative;
    min-height: 112px;
    border-right: 1px solid #d9dee5;
    border-bottom: 1px solid #d9dee5;
    padding: .35rem;
    background: #fff;
    cursor: pointer;
    overflow: hidden;
}

.calendar-cell:hover {
    background: #f8fbff;
}

.calendar-cell--muted {
    background: #fbfcfd;
}

.calendar-cell--today {
    background: #f4f8ff;
    outline: 2px solid rgba(21, 101, 216, .25);
    outline-offset: -2px;
}

.calendar-date {
    text-align: right;
    font-size: .9rem;
    font-weight: 800;
    color: #1565d8;
    margin-bottom: .35rem;
}

.calendar-date--muted {
    color: #b5c3d9;
}

.calendar-events {
    display: flex;
    flex-direction: column;
    gap: .28rem;
}

.calendar-event {
    display: block;
    width: 100%;
    border: 0;
    border-radius: .45rem;
    text-align: left;
    color: #fff;
    padding: .28rem .42rem;
    font-size: .82rem;
    font-weight: 700;
    line-height: 1.18;
    cursor: pointer;
    transition: opacity .12s ease, transform .12s ease;
}

.calendar-event:hover,
.calendar-event:focus {
    opacity: .94;
    transform: translateY(-1px);
    outline: none;
}

.calendar-event-time {
    font-weight: 900;
    margin-right: .2rem;
}

.event-modal-section {
    border: 1px solid #e9ecef;
    border-radius: .8rem;
    background: #fff;
    padding: 1rem;
    margin-bottom: 1rem;
}

.event-stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(115px, 1fr));
    gap: .65rem;
}

.event-stat {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: .75rem;
    padding: .65rem;
}

.event-stat-label {
    font-size: .75rem;
    color: #6c757d;
    margin-bottom: .1rem;
}

.event-stat-value {
    font-size: 1.1rem;
    font-weight: 800;
    color: #212529;
}

@media (max-width: 991.98px) {
    .calendar-page {
        display: block;
    }

    .calendar-sidebar {
        position: static;
        max-height: none;
        margin-bottom: .75rem;
    }

    .calendar-group-list {
        max-height: 260px;
    }

    .calendar-toolbar-main {
        align-items: flex-start;
        flex-direction: column;
    }

    .calendar-nav {
        width: 100%;
    }

    .calendar-nav .btn {
        flex: 1;
    }

    .calendar-grid {
        height: auto;
        min-height: 0;
        display: block;
        border-left: 0;
    }

    .calendar-weekday {
        display: none;
    }

    .calendar-cell {
        min-height: auto;
        border-right: 0;
        padding: .75rem;
    }

    .calendar-cell--muted:not(.calendar-cell--today) {
        display: none;
    }

    .calendar-date {
        text-align: left;
        font-size: 1rem;
    }

    .calendar-events:empty::after {
        content: "No events";
        color: #adb5bd;
        font-size: .9rem;
    }

    .calendar-event {
        font-size: .95rem;
        padding: .5rem .65rem;
    }
}

@media (max-width: 575.98px) {
    .calendar-title {
        font-size: 1.35rem;
    }

    .modal-dialog {
        margin: .5rem;
    }
}
</style>

<div class="container-fluid">
    <div class="calendar-page">
        <aside class="calendar-sidebar">
            <div class="calendar-sidebar-title">Groups / legend</div>

            <form method="get" action="<?= e(ROUTE_CALENDAR) ?>">
                <input type="hidden" name="month" value="<?= e((string)$month) ?>">
                <input type="hidden" name="year" value="<?= e((string)$year) ?>">

                <div class="calendar-group-list mb-3">
                    <?php foreach ($allGroups as $group): ?>
                        <?php
                        $groupId = (int)$group['id'];
                        $checked = in_array($groupId, $selectedGroupIds, true);
                        ?>
                        <div class="form-check mb-2">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="groups[]"
                                value="<?= e((string)$groupId) ?>"
                                id="group_<?= e((string)$groupId) ?>"
                                <?= $checked ? 'checked' : '' ?>
                            >
                            <label class="form-check-label" for="group_<?= e((string)$groupId) ?>">
                                <span class="calendar-legend-dot" style="background:<?= e(group_color($groupId)) ?>"></span>
                                <?= e($group['group_name']) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex mb-3">
                    <button type="submit" class="btn btn-primary btn-sm mr-2">Apply</button>
                    <a href="<?= e(ROUTE_CALENDAR) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>

            <hr>

            <div class="small text-muted">
                By default, all groups are shown. Filter here to focus the calendar.
            </div>
        </aside>

        <div class="calendar-main-wrap">
            <div class="calendar-toolbar">
                <div class="calendar-toolbar-main">
                    <div>
                        <h1 class="calendar-title"><?= e($firstOfMonth->format('F Y')) ?></h1>
                        <div class="text-muted small">Click a date to start a new event. Click an event to view details.</div>
                    </div>

                    <div class="calendar-nav">
                        <a class="btn btn-dark btn-sm" href="<?= e(calendar_url_for_month($prevMonth)) ?>">&lsaquo;</a>
                        <a class="btn btn-dark btn-sm" href="<?= e(ROUTE_CALENDAR . '?month=' . date('n') . '&year=' . date('Y')) ?>">Today</a>
                        <a class="btn btn-dark btn-sm" href="<?= e(calendar_url_for_month($nextMonth)) ?>">&rsaquo;</a>
                    </div>
                </div>
            </div>

            <main class="calendar-main">
                <div class="calendar-grid">
                    <div class="calendar-weekday">Mon</div>
                    <div class="calendar-weekday">Tue</div>
                    <div class="calendar-weekday">Wed</div>
                    <div class="calendar-weekday">Thu</div>
                    <div class="calendar-weekday">Fri</div>
                    <div class="calendar-weekday">Sat</div>
                    <div class="calendar-weekday">Sun</div>

                    <?php
                    $cursor = $startCalendar;
                    $today = date('Y-m-d');

                    while ($cursor <= $endCalendar):
                        $dateKey = $cursor->format('Y-m-d');
                        $isCurrentMonth = $cursor->format('n') === $firstOfMonth->format('n');
                        $isToday = $dateKey === $today;

                        $classes = ['calendar-cell'];
                        if (!$isCurrentMonth) {
                            $classes[] = 'calendar-cell--muted';
                        }
                        if ($isToday) {
                            $classes[] = 'calendar-cell--today';
                        }

                        $createUrl = date_create_url($dateKey, $currentGroup);
                        ?>
                        <div class="<?= e(implode(' ', $classes)) ?>"
                             data-create-url="<?= e($createUrl) ?>"
                             role="button"
                             tabindex="0"
                             aria-label="Create event on <?= e($cursor->format('d F Y')) ?>">
                            <div class="calendar-date <?= !$isCurrentMonth ? 'calendar-date--muted' : '' ?>">
                                <?= e($cursor->format('D j')) ?>
                            </div>

                            <div class="calendar-events">
                                <?php if (!empty($eventsByDate[$dateKey])): ?>
                                    <?php foreach ($eventsByDate[$dateKey] as $event): ?>
                                        <?php
                                        $timeText = (new DateTimeImmutable((string)$event['starts_at']))->format('H:i');
                                        $showStatus = can_show_status_for_event($event, $isAdminOrReviewer, $currentGroup);
                                        $emoji = $showStatus ? event_status_emoji((string)$event['status']) : '';
                                        ?>
                                        <button
                                            type="button"
                                            class="calendar-event"
                                            style="background:<?= e(group_color((int)$event['group_id'])) ?>;"
                                            data-toggle="modal"
                                            data-target="#eventModal<?= (int)$event['id'] ?>">
                                            <span class="calendar-event-time"><?= e($timeText) ?></span>
                                            <?php if ($emoji !== ''): ?>
                                                <span><?= e($emoji) ?></span>
                                            <?php endif; ?>
                                            <?= e($event['event_title']) ?>
                                        </button>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php
                        $cursor = $cursor->modify('+1 day');
                    endwhile;
                    ?>
                </div>
            </main>
        </div>
    </div>
</div>

<?php foreach ($events as $event): ?>
    <?php
    $modalId = 'eventModal' . (int)$event['id'];
    $showStatus = can_show_status_for_event($event, $isAdminOrReviewer, $currentGroup);
    $canOpen = can_open_event_page($event, $isAdminOrReviewer, $currentGroup);
    $sections = attending_sections($event);
    $linkedRas = $eventRiskAssessments[(int)$event['id']] ?? [];
    ?>
    <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1"><?= e($event['event_title']) ?></h5>
                        <div class="small text-muted">
                            <?= e($event['group_name']) ?> ·
                            <?= e(date('d M Y H:i', strtotime((string)$event['starts_at']))) ?>
                            to
                            <?= e(date('d M Y H:i', strtotime((string)$event['ends_at']))) ?>
                        </div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    <?php if ($showStatus): ?>
                        <div class="event-modal-section">
                            <h6 class="mb-2">Status</h6>
                            <?= status_badge_html((string)$event['status']) ?>

                            <?php if (!empty($event['admin_comments'])): ?>
                                <div class="mt-3">
                                    <strong>Reviewer comments</strong>
                                    <div class="border rounded p-3 bg-light mt-2">
                                        <?= nl2br(e((string)$event['admin_comments'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="event-modal-section">
                        <h6 class="mb-3">Event summary</h6>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <strong>Location</strong><br>
                                <?= e($event['event_location']) ?>
                            </div>

                            <div class="col-md-6 mb-3">
                                <strong>Contact</strong><br>
                                <?= e($event['contact_name']) ?><br>
                                <span class="text-muted"><?= e($event['contact_email']) ?></span>
                            </div>
                        </div>

                        <?php if (!empty($event['event_description'])): ?>
                            <strong>Description</strong>
                            <div class="mt-2 text-muted">
                                <?= nl2br(e(mb_strimwidth((string)$event['event_description'], 0, 350, '...'))) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($sections)): ?>
                        <div class="event-modal-section">
                            <h6 class="mb-3">Attending</h6>
                            <div class="event-stat-grid">
                                <?php foreach ($sections as $sectionName => $count): ?>
                                    <div class="event-stat">
                                        <div class="event-stat-label"><?= e($sectionName) ?></div>
                                        <div class="event-stat-value"><?= e((string)$count) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="event-modal-section">
                        <h6 class="mb-3">Risk assessments</h6>

                        <?php if (empty($linkedRas)): ?>
                            <p class="text-muted mb-0">No risk assessments linked to this event.</p>
                        <?php elseif ($canOpen): ?>
                            <p class="text-muted mb-0">
                                <?= e((string)count($linkedRas)) ?>
                                risk assessment<?= count($linkedRas) === 1 ? '' : 's' ?> linked.
                                Open the event page for full details.
                            </p>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($linkedRas as $ra): ?>
                                    <div class="list-group-item">
                                        <div class="d-md-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?= e(ra_modal_name($ra)) ?></strong><br>
                                                <small class="text-muted">
                                                    <?= e($ra['group_name']) ?> · updated <?= e(date('d M Y', strtotime((string)$ra['updated_at']))) ?>
                                                </small>
                                            </div>

                                            <div class="mt-2 mt-md-0">
                                                <?php if (ra_can_preview_modal($ra)): ?>
                                                    <a href="<?= e(BASE_URL . '/preview-risk-assessment.php?id=' . (int)$ra['risk_assessment_id']) ?>"
                                                       target="_blank"
                                                       class="btn btn-outline-primary btn-sm">
                                                        View
                                                    </a>
                                                <?php endif; ?>

                                                <a href="<?= e(BASE_URL . '/download-risk-assessment.php?id=' . (int)$ra['risk_assessment_id']) ?>"
                                                   target="_blank"
                                                   class="btn btn-outline-secondary btn-sm">
                                                    Download
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="modal-footer">
                    <?php if ($canOpen): ?>
                        <a href="<?= e(event_page_url($event, $isAdminOrReviewer, $currentGroup)) ?>"
                           class="btn btn-outline-secondary">
                            Open event page
                        </a>
                    <?php else: ?>
                        <span class="text-muted small mr-auto">
                            Only the submitting group or reviewers can open the full event page.
                        </span>
                    <?php endif; ?>

                    <button type="button" class="btn btn-primary" data-dismiss="modal">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.calendar-event').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    });

    document.querySelectorAll('.calendar-cell').forEach(function (cell) {
        function openCreatePage(event) {
            if (
                event.target.closest('.calendar-event') ||
                event.target.closest('button') ||
                event.target.closest('a')
            ) {
                return;
            }

            const url = cell.dataset.createUrl;
            if (url) {
                window.location.href = url;
            }
        }

        cell.addEventListener('click', openCreatePage);

        cell.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                const url = cell.dataset.createUrl;
                if (url) {
                    window.location.href = url;
                }
            }
        });
    });
});
</script>

<?php render_page_end(); ?>