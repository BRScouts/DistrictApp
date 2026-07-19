<?php

declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
$ctx = dc_require_reviewer();

$isDistrictReviewer = dc_context_has_reviewer_access($ctx);
$reviewableGroupIds = dc_reviewable_group_ids($ctx);

$counts = [];
foreach (['submitted','under_review','approved','changes_requested','rejected'] as $status) {
    if ($isDistrictReviewer) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM calendar_events WHERE status = :status');
        $stmt->execute(['status' => $status]);
    } else {
        if (!$reviewableGroupIds) {
            $counts[$status] = 0;
            continue;
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM calendar_events WHERE status = :status AND group_id IN (' . implode(',', array_map('intval', $reviewableGroupIds)) . ')');
        $stmt->execute(['status' => $status]);
    }
    $counts[$status] = (int) $stmt->fetchColumn();
}

$pageTitle='Review'; $heroTitle='Review submitted events'; $heroText='Review, approve or request changes to submitted activity notifications.'; $active='review'; require __DIR__.'/../layout.php';
?>
<div class="row"><?php foreach($counts as $status=>$count): ?><div class="col-md-4 mb-3"><a class="lt-card-link" href="/dc/reviewer/events.php?status=<?= e($status) ?>"><div class="lt-task-card"><h2><?= (int)$count ?></h2><p><?= e(str_replace('_',' ',$status)) ?></p><span class="lt-action-link">View events</span></div></a></div><?php endforeach; ?></div>
<?php require __DIR__.'/../layout-footer.php'; ?>
