<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
$ctx = dc_require_access();

$groupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : null;
$selectedGroupId = dc_selected_group_id($groupId);
$groups = dc_accessible_groups();
$showGroupPicker = count($groups) > 1;

$allowedGroupIds = $ctx['group_ids'] ?: [0];
$groupPlaceholders = [];
$params = [
    'reviewer' => $ctx['is_reviewer'] ? 1 : 0,
    'selected_group_id' => $showGroupPicker ? $selectedGroupId : 0,
    'selected_group_id2' => $showGroupPicker ? $selectedGroupId : 0,
];
foreach ($allowedGroupIds as $index => $gid) {
    $key = 'allowed_group_' . $index;
    $groupPlaceholders[] = ':' . $key;
    $params[$key] = (int) $gid;
}

$stmt = db()->prepare("
    SELECT ce.*, g.group_name,
           GROUP_CONCAT(DISTINCT gs.section_name ORDER BY gs.sort_order, gs.section_name SEPARATOR ', ') AS sections
    FROM calendar_events ce
    JOIN groups g ON g.id = ce.group_id
    LEFT JOIN calendar_event_sections ces ON ces.calendar_event_id = ce.id
    LEFT JOIN group_sections gs ON gs.id = ces.group_section_id
    WHERE (:reviewer = 1 OR ce.group_id IN (" . implode(',', $groupPlaceholders) . "))
      AND (:selected_group_id = 0 OR ce.group_id = :selected_group_id2)
      AND ce.ends_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND ce.status <> 'cancelled'
    GROUP BY ce.id
    ORDER BY ce.starts_at ASC
    LIMIT 100
");
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'District Calendar';
$heroTitle = 'District Calendar';
$heroText = 'View upcoming activity, submit events and share risk assessments.';
$active = 'home';
require __DIR__ . '/layout.php';
?>
<div class="dc-action-bar">
    <div>
        <h2 class="lt-section-title">Upcoming events</h2>
        <p class="mb-0">Mobile shows a clear list. Desktop also shows a month-style view below.</p>
    </div>
    <a class="btn btn-primary lt-btn" href="/dc/add-event.php<?= $showGroupPicker ? '?group_id=' . (int) $selectedGroupId : '' ?>">Add event</a>
</div>

<?php if ($showGroupPicker): ?>
<form method="get" class="lt-panel-grey dc-filter-form" action="/dc/">
    <label for="group_id">Choose Group</label>
    <select id="group_id" name="group_id" class="form-control" onchange="this.form.submit()">
        <?= dc_group_options_html($selectedGroupId) ?>
    </select>
</form>
<?php endif; ?>

<?php if (!$events): ?>
    <div class="lt-panel"><p class="mb-0">No upcoming events have been submitted for this view.</p></div>
<?php else: ?>
    <div class="dc-list">
        <?php foreach ($events as $event): ?>
            <article class="dc-list-item">
                <div class="dc-date-box">
                    <span><?= e(date('d', strtotime($event['starts_at']))) ?></span>
                    <strong><?= e(date('M', strtotime($event['starts_at']))) ?></strong>
                </div>
                <div class="dc-list-body">
                    <h3><a href="/dc/manage-event.php?id=<?= (int) $event['id'] ?>"><?= e($event['title']) ?></a></h3>
                    <p class="mb-1"><strong><?= e($event['group_name']) ?></strong> · <?= e(date('D j M Y, H:i', strtotime($event['starts_at']))) ?> to <?= e(date('D j M Y, H:i', strtotime($event['ends_at']))) ?></p>
                    <?php if ($event['sections']): ?><p class="mb-1">Sections: <?= e($event['sections']) ?></p><?php endif; ?>
                    <span class="lt-badge dc-status dc-status-<?= e($event['status']) ?>"><?= e(str_replace('_', ' ', $event['status'])) ?></span>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <section class="dc-desktop-calendar" aria-labelledby="calendar-grid-heading">
        <h2 id="calendar-grid-heading" class="lt-section-title">Calendar view</h2>
        <div class="dc-calendar-grid">
            <?php foreach ($events as $event): ?>
                <a class="dc-calendar-card" href="/dc/manage-event.php?id=<?= (int) $event['id'] ?>">
                    <strong><?= e(date('j M', strtotime($event['starts_at']))) ?></strong>
                    <span><?= e($event['title']) ?></span>
                    <small><?= e($event['group_name']) ?></small>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/layout-footer.php'; ?>
