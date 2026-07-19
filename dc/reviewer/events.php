<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';

$ctx = dc_require_reviewer();

$status = (string) ($_GET['status'] ?? 'submitted');
$allowedStatuses = [
    'submitted',
    'under_review',
    'approved',
    'changes_requested',
    'rejected',
    'cancelled',
];

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'submitted';
}

$groupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0;
$q = trim((string) ($_GET['q'] ?? ''));

$params = [
    'status' => $status,
];

$where = [
    'ce.status = :status',
];

/*
 * Group-level reviewers can only see events for their reviewable groups.
 * District-level reviewers see everything.
 */
$isDistrictReviewer = dc_context_has_reviewer_access($ctx);
$reviewableGroupIds = dc_reviewable_group_ids($ctx);

if (!$isDistrictReviewer) {
    if (!$reviewableGroupIds) {
        redirect('/dc/403.php');
    }

    $where[] = 'ce.group_id IN (' . implode(',', array_map('intval', $reviewableGroupIds)) . ')';

    // If they picked a group_id that isn't in their reviewable list, ignore it
    if ($groupId > 0 && !in_array($groupId, $reviewableGroupIds, true)) {
        $groupId = 0;
    }
}

if ($groupId > 0) {
    $where[] = 'ce.group_id = :group_id';
    $params['group_id'] = $groupId;
}

if ($q !== '') {
    $where[] = "(
        ce.title LIKE :q
        OR ce.leader_name LIKE :q
        OR ce.leader_email LIKE :q
        OR ce.location_name LIKE :q
        OR ce.location_address LIKE :q
        OR g.group_name LIKE :q
    )";

    $params['q'] = '%' . $q . '%';
}

$stmt = db()->prepare("
    SELECT
        ce.*,
        g.group_name,
        DATEDIFF(DATE(ce.starts_at), CURDATE()) AS days_until_event,
        COUNT(DISTINCT era.risk_assessment_id) AS risk_count
    FROM calendar_events ce
    JOIN groups g
      ON g.id = ce.group_id
    LEFT JOIN event_risk_assessments era
      ON era.calendar_event_id = ce.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY ce.id
    ORDER BY
        CASE
            WHEN ce.starts_at >= NOW() THEN 0
            ELSE 1
        END,
        ce.starts_at ASC
    LIMIT 300
");

$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$groups = dc_accessible_groups();

/*
 * For group-level reviewers, only show groups they can review in the filter dropdown.
 */
if (!$isDistrictReviewer && $reviewableGroupIds) {
    $groups = array_filter($groups, function (array $group) use ($reviewableGroupIds): bool {
        return in_array((int) ($group['id'] ?? $group['group_id'] ?? 0), $reviewableGroupIds, true);
    });
    $groups = array_values($groups);
}

/*
 * Status counts — scoped to reviewable groups for group-level reviewers.
 */
if ($isDistrictReviewer) {
    $statusCountsStmt = db()->query("
        SELECT status, COUNT(*) AS total
        FROM calendar_events
        GROUP BY status
    ");
} else {
    $statusCountsStmt = db()->prepare("
        SELECT status, COUNT(*) AS total
        FROM calendar_events
        WHERE group_id IN (" . implode(',', array_map('intval', $reviewableGroupIds)) . ")
        GROUP BY status
    ");
    $statusCountsStmt->execute();
}

$statusCounts = [];

foreach ($statusCountsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $statusCounts[(string) $row['status']] = (int) $row['total'];
}

function dc_reviewer_days_label(?int $days): string
{
    if ($days === null) {
        return 'Date unknown';
    }

    if ($days < 0) {
        return abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' ago';
    }

    if ($days === 0) {
        return 'Today';
    }

    if ($days === 1) {
        return 'Tomorrow';
    }

    return 'In ' . $days . ' days';
}

function dc_reviewer_days_class(?int $days): string
{
    if ($days === null) {
        return 'dc-days-muted';
    }

    if ($days < 0) {
        return 'dc-days-past';
    }

    if ($days <= 7) {
        return 'dc-days-urgent';
    }

    if ($days <= 30) {
        return 'dc-days-soon';
    }

    return 'dc-days-normal';
}

$statusLabels = [
    'submitted' => 'Submitted',
    'under_review' => 'Under review',
    'approved' => 'Approved',
    'changes_requested' => 'Changes requested',
    'rejected' => 'Rejected',
    'cancelled' => 'Cancelled',
];

$pageTitle = 'Events for review';
$heroTitle = 'Events for review';
$heroText = 'Filter submitted activity notifications and open events for review.';
$active = 'review';

require __DIR__ . '/../layout.php';
?>

<style>
    .dc-review-layout {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 992px) {
        .dc-review-layout {
            grid-template-columns: 270px minmax(0, 1fr);
            align-items: start;
        }
    }

    .dc-review-sidebar {
        border: 2px solid #000;
        background: #f5f5f5;
        padding: 1rem;
    }

    @media (min-width: 992px) {
        .dc-review-sidebar {
            position: sticky;
            top: 1rem;
        }
    }

    .dc-review-sidebar h2 {
        font-size: 1.3rem;
        font-weight: 900;
        margin-bottom: 1rem;
    }

    .dc-status-tabs {
        display: grid;
        gap: 0.35rem;
        margin-bottom: 1rem;
    }

    .dc-status-tabs a {
        display: flex;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.55rem 0.65rem;
        background: #fff;
        border: 1px solid #d8d8d8;
        color: #000;
        text-decoration: none;
        font-weight: 800;
    }

    .dc-status-tabs a.active {
        background: #7413dc;
        color: #fff;
        border-color: #7413dc;
    }

    .dc-review-filter-form {
        display: grid;
        gap: 0.75rem;
    }

    .dc-review-table-wrap {
        overflow-x: auto;
        border: 1px solid #d8d8d8;
        background: #fff;
    }

    .dc-review-table {
        width: 100%;
        min-width: 860px;
        border-collapse: collapse;
        margin: 0;
    }

    .dc-review-table th,
    .dc-review-table td {
        padding: 0.75rem;
        border-bottom: 1px solid #d8d8d8;
        text-align: left;
        vertical-align: top;
    }

    .dc-review-table th {
        background: #f5f5f5;
        font-weight: 900;
    }

    .dc-clickable-row {
        cursor: pointer;
    }

    .dc-clickable-row:hover {
        background: #f5f5f5;
    }

    .dc-clickable-row:focus-within {
        outline: 3px solid #ffdd00;
        outline-offset: -3px;
    }

    .dc-row-link {
        color: inherit;
        text-decoration: none;
    }

    .dc-row-link:hover,
    .dc-row-link:focus {
        color: inherit;
        text-decoration: underline;
        outline: none;
    }

    .dc-event-title {
        font-weight: 900;
        font-size: 1.05rem;
    }

    .dc-event-meta {
        color: #4a4a4a;
        font-size: 0.92rem;
        margin-top: 0.25rem;
    }

    .dc-days-pill {
        display: inline-block;
        font-weight: 900;
        padding: 0.3rem 0.5rem;
        border: 2px solid #000;
        background: #fff;
        white-space: nowrap;
    }

    .dc-days-urgent {
        background: #fff8d6;
        border-color: #ffdd00;
    }

    .dc-days-soon {
        background: #e8f1ff;
        border-color: #006ddf;
    }

    .dc-days-normal {
        background: #e9f8f4;
        border-color: #00a794;
    }

    .dc-days-past {
        background: #fff1f0;
        border-color: #d4351c;
    }

    .dc-days-muted {
        background: #f5f5f5;
        border-color: #777;
    }

    .dc-mobile-review-list {
        display: grid;
        gap: 0.75rem;
    }

    .dc-mobile-review-card {
        display: block;
        border: 1px solid #d8d8d8;
        background: #fff;
        padding: 0.75rem;
        color: #000;
        text-decoration: none;
    }

    .dc-mobile-review-card:hover,
    .dc-mobile-review-card:focus {
        background: #f5f5f5;
        color: #000;
        outline: 3px solid #ffdd00;
        outline-offset: 0;
        text-decoration: none;
    }

    .dc-mobile-review-card h3 {
        color: #000;
    }

    @media (min-width: 768px) {
        .dc-mobile-review-list {
            display: none;
        }
    }

    @media (max-width: 767.98px) {
        .dc-review-table-wrap {
            display: none;
        }
    }
</style>

<div class="dc-review-layout">
    <aside class="dc-review-sidebar" aria-labelledby="review-filters-heading">
        <h2 id="review-filters-heading">Review filters</h2>

        <nav class="dc-status-tabs" aria-label="Review status">
            <?php foreach ($statusLabels as $value => $label): ?>
                <a class="<?= $value === $status ? 'active' : '' ?>" href="/dc/reviewer/events.php?status=<?= e($value) ?>">
                    <span><?= e($label) ?></span>
                    <span><?= (int) ($statusCounts[$value] ?? 0) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <form method="get" class="dc-review-filter-form" action="/dc/reviewer/events.php">
            <input type="hidden" name="status" value="<?= e($status) ?>">

            <div class="form-group mb-0">
                <label for="group_id">Group</label>
                <select id="group_id" name="group_id" class="form-control">
                    <option value="0">All Groups</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>" <?= $groupId === (int) $group['id'] ? 'selected' : '' ?>>
                            <?= e((string) $group['group_name']) ?>
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
                    value="<?= e($q) ?>"
                    placeholder="Title, leader, location"
                >
            </div>

            <button class="btn btn-primary lt-btn" type="submit">
                Apply
            </button>

            <a class="btn lt-btn lt-btn-secondary" href="/dc/reviewer/events.php?status=<?= e($status) ?>">
                Clear
            </a>
        </form>
    </aside>

    <section class="lt-panel">
        <div class="dc-action-bar">
            <div>
                <h2 class="lt-section-title mb-1"><?= e($statusLabels[$status] ?? 'Events') ?></h2>
                <p class="mb-0">
                    <?= count($events) ?> event<?= count($events) === 1 ? '' : 's' ?> found.
                </p>
            </div>
        </div>

        <?php if (!$events): ?>
            <p class="mb-0">No events match this review view.</p>
        <?php else: ?>
            <div class="dc-review-table-wrap">
                <table class="dc-review-table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Group</th>
                            <th>Leader</th>
                            <th>When</th>
                            <th>Days until</th>
                            <th>Risk assessments</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <?php
                                $days = isset($event['days_until_event']) ? (int) $event['days_until_event'] : null;
                                $reviewUrl = '/dc/reviewer/review-event.php?id=' . (int) $event['id'];
                            ?>
                            <tr
                                class="dc-clickable-row"
                                data-href="<?= e($reviewUrl) ?>"
                                tabindex="0"
                                role="link"
                                aria-label="Open review for <?= e((string) $event['title']) ?>"
                            >
                                <td>
                                    <a class="dc-row-link" href="<?= e($reviewUrl) ?>">
                                        <div class="dc-event-title"><?= e((string) $event['title']) ?></div>
                                    </a>
                                    <?php if (!empty($event['location_name'])): ?>
                                        <div class="dc-event-meta"><?= e((string) $event['location_name']) ?></div>
                                    <?php endif; ?>
                                    <span class="lt-badge dc-status dc-status-<?= e((string) $event['status']) ?>">
                                        <?= e(str_replace('_', ' ', (string) $event['status'])) ?>
                                    </span>
                                </td>
                                <td><?= e((string) $event['group_name']) ?></td>
                                <td>
                                    <?= e((string) ($event['leader_name'] ?? '')) ?>
                                    <?php if (!empty($event['leader_email'])): ?>
                                        <div class="dc-event-meta"><?= e((string) $event['leader_email']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= e(date('D j M Y, H:i', strtotime((string) $event['starts_at']))) ?>
                                    <div class="dc-event-meta">
                                        to <?= e(date('D j M Y, H:i', strtotime((string) $event['ends_at']))) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="dc-days-pill <?= e(dc_reviewer_days_class($days)) ?>">
                                        <?= e(dc_reviewer_days_label($days)) ?>
                                    </span>
                                </td>
                                <td><?= (int) ($event['risk_count'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="dc-mobile-review-list">
                <?php foreach ($events as $event): ?>
                    <?php
                        $days = isset($event['days_until_event']) ? (int) $event['days_until_event'] : null;
                        $reviewUrl = '/dc/reviewer/review-event.php?id=' . (int) $event['id'];
                    ?>
                    <a class="dc-mobile-review-card" href="<?= e($reviewUrl) ?>">
                        <h3 class="mb-1">
                            <?= e((string) $event['title']) ?>
                        </h3>
                        <p class="mb-1">
                            <strong><?= e((string) $event['group_name']) ?></strong><br>
                            <?= e(date('D j M Y, H:i', strtotime((string) $event['starts_at']))) ?>
                        </p>
                        <p class="mb-2">
                            <?= e((string) ($event['leader_name'] ?? '')) ?>
                        </p>
                        <span class="dc-days-pill <?= e(dc_reviewer_days_class($days)) ?>">
                            <?= e(dc_reviewer_days_label($days)) ?>
                        </span>
                        <span class="lt-badge dc-status dc-status-<?= e((string) $event['status']) ?>">
                            <?= e(str_replace('_', ' ', (string) $event['status'])) ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
(function () {
    document.querySelectorAll('.dc-clickable-row[data-href]').forEach(function (row) {
        row.addEventListener('click', function (event) {
            if (event.target.closest('a, button, input, select, textarea')) {
                return;
            }

            window.location.href = row.getAttribute('data-href');
        });

        row.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                window.location.href = row.getAttribute('data-href');
            }
        });
    });
})();
</script>

<?php require __DIR__ . '/../layout-footer.php'; ?>