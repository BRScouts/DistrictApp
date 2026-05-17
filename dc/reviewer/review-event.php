<?php

declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
$ctx = dc_require_reviewer();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$event = dc_get_event($id);
if (!$event) { require __DIR__ . '/../404.php'; exit; }
$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $status=(string)($_POST['status'] ?? 'under_review');
    $allowed=['under_review','approved','changes_requested','rejected','cancelled'];
    if(!in_array($status,$allowed,true)){ $errors[]='Choose a valid review outcome.'; }
    if(!$errors){
        $stmt=db()->prepare('UPDATE calendar_events SET status=:status, reviewer_notes=:notes, reviewed_by_person_id=:reviewed_by, reviewed_at=NOW() WHERE id=:id');
        $stmt->execute(['status'=>$status,'notes'=>trim((string)($_POST['reviewer_notes']??''))?:null,'reviewed_by'=>$ctx['person_id'],'id'=>$id]);
        dc_log('calendar_event.reviewed','calendar_event',$id,['status'=>$status],(int)$event['group_id']);
        dc_queue_event_notifications($id, $status);
        redirect('/dc/reviewer/review-event.php?id='.$id.'&reviewed=1');
    }
}
$event=dc_get_event($id);
$stmt=db()->prepare('SELECT ra.* FROM event_risk_assessments era JOIN risk_assessments ra ON ra.id=era.risk_assessment_id WHERE era.calendar_event_id=:id'); $stmt->execute(['id'=>$id]); $risks=$stmt->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Review event'; $heroTitle=$event['title']; $heroText='Approve, request changes or reject this event. The action is audited.'; $active='review'; require __DIR__.'/../layout.php';
?>
<?php if(isset($_GET['reviewed'])): ?><div class="dc-success">Review saved and email added to the queue.</div><?php endif; ?>
<?php if($errors): ?><div class="dc-error-summary"><h2>Check the review</h2><ul><?php foreach($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="row"><div class="col-lg-7 mb-4"><section class="lt-panel"><h2 class="lt-section-title">Event summary</h2><dl class="dc-summary-list"><dt>Group</dt><dd><?= e($event['group_name']) ?></dd><dt>Leader</dt><dd><?= e($event['leader_name']) ?> — <?= e($event['leader_email']) ?></dd><dt>When</dt><dd><?= e(date('j M Y H:i',strtotime($event['starts_at']))) ?> to <?= e(date('j M Y H:i',strtotime($event['ends_at']))) ?></dd><dt>Location</dt><dd><?= e($event['location_name'] ?: '') ?> <?= e($event['location_address'] ?: '') ?></dd><dt>Description</dt><dd><?= nl2br(e($event['description'] ?: '')) ?></dd><dt>Status</dt><dd><span class="lt-badge dc-status dc-status-<?= e($event['status']) ?>"><?= e(str_replace('_',' ',$event['status'])) ?></span></dd></dl><?php if($risks): ?><h3 class="h5 font-weight-bold">Risk assessments</h3><ul class="dc-clean-list"><?php foreach($risks as $risk): ?><li><a href="/dc/download-risk-assessment.php?id=<?= (int)$risk['id'] ?>"><?= e($risk['title']) ?></a> <span class="lt-badge"><?= e($risk['visibility']) ?></span></li><?php endforeach; ?></ul><?php endif; ?></section></div><div class="col-lg-5 mb-4"><section class="lt-panel-grey"><h2 class="lt-section-title">Review outcome</h2><form method="post"><input type="hidden" name="id" value="<?= (int)$id ?>"><div class="form-group"><label for="status">Outcome</label><select id="status" name="status" class="form-control"><option value="under_review">Mark under review</option><option value="approved">Approve</option><option value="changes_requested">Request changes</option><option value="rejected">Reject</option><option value="cancelled">Cancel</option></select></div><div class="form-group"><label for="reviewer_notes">Review notes</label><textarea id="reviewer_notes" name="reviewer_notes" class="form-control" rows="5"><?= e($event['reviewer_notes']) ?></textarea></div><button class="btn btn-primary lt-btn" type="submit">Save review</button></form></section></div></div>
<?php require __DIR__.'/../layout-footer.php'; ?>
