<?php

declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
$ctx = dc_require_reviewer();
$status = (string)($_GET['status'] ?? 'submitted');
$allowed = ['submitted','under_review','approved','changes_requested','rejected','cancelled'];
if (!in_array($status,$allowed,true)) { $status='submitted'; }
$stmt = db()->prepare('SELECT ce.*, g.group_name FROM calendar_events ce JOIN groups g ON g.id=ce.group_id WHERE ce.status=:status ORDER BY ce.starts_at ASC LIMIT 200');
$stmt->execute(['status'=>$status]); $events=$stmt->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Events for review'; $heroTitle='Events for review'; $heroText='Filter and open submitted activity notifications.'; $active='review'; require __DIR__.'/../layout.php';
?>
<div class="dc-tabs"><?php foreach($allowed as $s): ?><a class="<?= $s===$status?'active':'' ?>" href="/dc/reviewer/events.php?status=<?= e($s) ?>"><?= e(str_replace('_',' ',$s)) ?></a><?php endforeach; ?></div>
<div class="dc-list"><?php foreach($events as $event): ?><article class="dc-list-item"><div class="dc-date-box"><span><?= e(date('d',strtotime($event['starts_at']))) ?></span><strong><?= e(date('M',strtotime($event['starts_at']))) ?></strong></div><div class="dc-list-body"><h3><a href="/dc/reviewer/review-event.php?id=<?= (int)$event['id'] ?>"><?= e($event['title']) ?></a></h3><p><strong><?= e($event['group_name']) ?></strong> · <?= e($event['leader_name']) ?> · <?= e(date('j M Y H:i',strtotime($event['starts_at']))) ?></p><span class="lt-badge dc-status dc-status-<?= e($event['status']) ?>"><?= e(str_replace('_',' ',$event['status'])) ?></span></div></article><?php endforeach; ?></div>
<?php require __DIR__.'/../layout-footer.php'; ?>
