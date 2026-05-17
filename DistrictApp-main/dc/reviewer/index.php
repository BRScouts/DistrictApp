<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_reviewer_or_admin();

$pdo = db();

$selectedMonth = trim((string)($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

$monthStart = $selectedMonth . '-01 00:00:00';
$monthEnd = date('Y-m-d H:i:s', strtotime($monthStart . ' +1 month'));

$pendingCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM events
    WHERE status IN ('submitted', 'under_review', 'changes_requested')
")->fetchColumn();

$upcomingCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM events
    WHERE starts_at >= NOW()
      AND status NOT IN ('cancelled', 'rejected')
")->fetchColumn();

$groupCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM groups
    WHERE is_active = 1
")->fetchColumn();

$raCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM risk_assessments
    WHERE is_active = 1
")->fetchColumn();

$stmt = $pdo->query("
    SELECT
        e.id,
        e.event_title,
        e.event_location,
        e.starts_at,
        e.status,
        e.contact_name,
        g.group_name
    FROM events e
    INNER JOIN groups g ON g.id = e.group_id
    WHERE e.status IN ('submitted', 'under_review', 'changes_requested')
    ORDER BY e.submitted_at ASC
    LIMIT 10
");
$reviewQueue = $stmt->fetchAll();

$stmt = $pdo->query("
    SELECT
        e.id,
        e.event_title,
        e.event_location,
        e.starts_at,
        e.status,
        g.group_name
    FROM events e
    INNER JOIN groups g ON g.id = e.group_id
    WHERE e.starts_at >= NOW()
      AND e.status NOT IN ('cancelled', 'rejected')
    ORDER BY e.starts_at ASC
    LIMIT 10
");
$upcomingEvents = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        g.id,
        g.group_name,
        COUNT(ra.id) AS ra_count
    FROM groups g
    LEFT JOIN risk_assessments ra
        ON ra.group_id = g.id
       AND ra.is_active = 1
       AND ra.uploaded_at >= :month_start
       AND ra.uploaded_at < :month_end
    WHERE g.is_active = 1
    GROUP BY g.id, g.group_name
    ORDER BY g.group_name ASC
");
$stmt->execute([
    'month_start' => $monthStart,
    'month_end' => $monthEnd,
]);
$groupRaCounts = $stmt->fetchAll();

function reviewer_status_badge(string $status): string
{
    return match ($status) {
        'approved' => 'success',
        'submitted', 'under_review' => 'primary',
        'changes_requested' => 'warning',
        'rejected', 'cancelled' => 'danger',
        'draft' => 'secondary',
        default => 'secondary',
    };
}

render_page_start('Reviewer Dashboard');
render_header('reviewer');
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-1">Reviewer Dashboard</h1>
            <p class="text-muted mb-0">Overview of submissions, upcoming events, groups, and risk assessments.</p>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6 col-xl-3 mb-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 text-muted mb-2">Pending review</h2>
                    <div class="display-4 font-weight-bold"><?= e((string)$pendingCount) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3 mb-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 text-muted mb-2">Upcoming events</h2>
                    <div class="display-4 font-weight-bold"><?= e((string)$upcomingCount) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3 mb-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 text-muted mb-2">Active groups</h2>
                    <div class="display-4 font-weight-bold"><?= e((string)$groupCount) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3 mb-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 text-muted mb-2">Risk assessments</h2>
                    <div class="display-4 font-weight-bold"><?= e((string)$raCount) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-7 mb-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h4 mb-3">Review queue</h2>

                    <?php if (empty($reviewQueue)): ?>
                        <p class="text-muted mb-0">No events are waiting for review.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                <tr>
                                    <th>Event</th>
                                    <th>Group</th>
                                    <th>Start</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($reviewQueue as $event): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($event['event_title']) ?></strong><br>
                                            <small class="text-muted"><?= e($event['event_location']) ?></small>
                                        </td>
                                        <td><?= e($event['group_name']) ?></td>
                                        <td><?= e(date('d M Y H:i', strtotime((string)$event['starts_at']))) ?></td>
                                        <td>
                                            <span class="badge badge-<?= e(reviewer_status_badge((string)$event['status'])) ?>">
                                                <?= e(ucwords(str_replace('_', ' ', (string)$event['status']))) ?>
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <a href="<?= e(BASE_URL . '/reviewer/review-event.php?event_id=' . (int)$event['id']) ?>"
                                               class="btn btn-primary btn-sm">
                                                Review
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-5 mb-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h4 mb-3">Upcoming events</h2>

                    <?php if (empty($upcomingEvents)): ?>
                        <p class="text-muted mb-0">No upcoming events found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                <tr>
                                    <th>Event</th>
                                    <th>Start</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($upcomingEvents as $event): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($event['event_title']) ?></strong><br>
                                            <small class="text-muted"><?= e($event['group_name']) ?></small>
                                        </td>
                                        <td><?= e(date('d M Y', strtotime((string)$event['starts_at']))) ?></td>
                                        <td class="text-right">
                                            <a href="<?= e(BASE_URL . '/reviewer/review-event.php?event_id=' . (int)$event['id']) ?>"
                                               class="btn btn-outline-primary btn-sm">
                                                Open
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-5">
        <div class="card-body">
            <div class="d-md-flex justify-content-between align-items-center mb-3">
                <div>
                    <h2 class="h4 mb-1">Risk assessments by group</h2>
                    <p class="text-muted mb-0">Uploaded during the selected month.</p>
                </div>

                <form method="get" class="form-inline mt-3 mt-md-0">
                    <label for="month" class="mr-2">Month</label>
                    <input type="month" class="form-control mr-2" id="month" name="month" value="<?= e($selectedMonth) ?>">
                    <button type="submit" class="btn btn-outline-primary">Apply</button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead>
                    <tr>
                        <th>Group</th>
                        <th class="text-right">Risk assessments submitted</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($groupRaCounts as $row): ?>
                        <tr>
                            <td><?= e($row['group_name']) ?></td>
                            <td class="text-right font-weight-bold"><?= e((string)$row['ra_count']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php render_page_end(); ?>