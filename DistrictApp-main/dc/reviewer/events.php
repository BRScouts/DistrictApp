 <?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_reviewer_or_admin();

$pdo = db();
$flash = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Handle review actions
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventId = (int)($_POST['event_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? ''));
    $comments = trim((string)($_POST['comments'] ?? ''));

    if ($eventId <= 0 || !in_array($action, ['approve', 'send_back'], true)) {
        $error = 'Invalid review action.';
    } else {
        $stmt = $pdo->prepare("
            SELECT
                e.id,
                e.group_id,
                e.contact_name,
                e.contact_email,
                e.event_title,
                e.status,
                g.group_name,
                g.lead_volunteer_email,
                g.notify_lead_on_event_created
            FROM events e
            INNER JOIN groups g ON g.id = e.group_id
            WHERE e.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $eventId]);
        $event = $stmt->fetch();

        if (!$event) {
            $error = 'Event not found.';
        } else {
            $newStatus = $action === 'approve' ? 'approved' : 'changes_requested';

            $update = $pdo->prepare("
                UPDATE events
                SET
                    status = :status,
                    admin_comments = :comments,
                    reviewed_by_admin_id = :reviewed_by,
                    reviewed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $update->execute([
                'status' => $newStatus,
                'comments' => $comments,
                'reviewed_by' => (int)auth_admin()['admin_user_id'],
                'id' => $eventId,
            ]);

            $log = $pdo->prepare("
                INSERT INTO event_reviews (
                    event_id,
                    admin_user_id,
                    action,
                    comments,
                    created_at
                ) VALUES (
                    :event_id,
                    :admin_user_id,
                    :action,
                    :comments,
                    NOW()
                )
            ");
            $log->execute([
                'event_id' => $eventId,
                'admin_user_id' => (int)auth_admin()['admin_user_id'],
                'action' => $newStatus,
                'comments' => $comments,
            ]);

            $viewLink = BASE_URL . '/add-event.php?event_id=' . (int)$event['id'];

            if ($newStatus === 'approved') {
                $subject = 'Away From Hut event approved: ' . $event['event_title'];
                $content = nl2br(e(
                    "Hello {$event['contact_name']},\n\n" .
                    "Your event \"{$event['event_title']}\" for {$event['group_name']} has been approved.\n\n" .
                    "View event: {$viewLink}\n\n" .
                    ($comments !== '' ? "Reviewer comments:\n{$comments}\n\n" : '') .
                    "Regards,\nDistrict Reviewer"
                ));
            } else {
                $subject = 'Changes requested: ' . $event['event_title'];
                $content = nl2br(e(
                    "Hello {$event['contact_name']},\n\n" .
                    "Your event \"{$event['event_title']}\" for {$event['group_name']} has been reviewed and changes are required.\n\n" .
                    "View event: {$viewLink}\n\n" .
                    ($comments !== '' ? "Reviewer comments:\n{$comments}\n\n" : '') .
                    "Please review and update your submission.\n\n" .
                    "Regards,\nDistrict Reviewer"
                ));
            }

            queue_email($event['contact_email'], $subject, $content);

            if (
                !empty($event['lead_volunteer_email']) &&
                (int)$event['notify_lead_on_event_created'] === 1
            ) {
                queue_email(
                    $event['lead_volunteer_email'],
                    $subject,
                    $content
                );
            }

            $flash = $newStatus === 'approved'
                ? 'Event approved and email queued.'
                : 'Changes requested and email queued.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch events
|--------------------------------------------------------------------------
*/
$stmt = $pdo->query("
    SELECT
        e.id,
        e.group_id,
        e.event_title,
        e.event_description,
        e.event_location,
        e.starts_at,
        e.ends_at,
        e.contact_name,
        e.contact_email,
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
        g.group_name
    FROM events e
    INNER JOIN groups g ON g.id = e.group_id
    WHERE e.status IN ('submitted', 'under_review', 'changes_requested')
    ORDER BY e.starts_at ASC, e.submitted_at ASC
");
$events = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Fetch attached risk assessments
|--------------------------------------------------------------------------
*/
$eventIds = array_map(fn($e) => (int)$e['id'], $events);
$eventRiskAssessments = [];

if (!empty($eventIds)) {
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

    $stmt = $pdo->prepare("
        SELECT
            era.event_id,
            era.source_type,
            ra.id AS risk_assessment_id,
            ra.title,
            ra.description,
            ra.activity_type,
            ra.location_summary,
            ra.visibility,
            ra.original_filename,
            ra.file_extension,
            ra.updated_at,
            gra.group_name
        FROM event_risk_assessments era
        INNER JOIN risk_assessments ra ON ra.id = era.risk_assessment_id
        INNER JOIN groups gra ON gra.id = ra.group_id
        WHERE era.event_id IN ($placeholders)
        ORDER BY era.event_id ASC, ra.updated_at DESC, ra.title ASC
    ");
    $stmt->execute($eventIds);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $eventRiskAssessments[(int)$row['event_id']][] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function review_status_badge(string $status): string
{
    return match ($status) {
        'approved' => '<span class="badge badge-success">Approved</span>',
        'changes_requested' => '<span class="badge badge-warning">Changes requested</span>',
        'rejected' => '<span class="badge badge-danger">Rejected</span>',
        'under_review' => '<span class="badge badge-info">Under review</span>',
        'submitted' => '<span class="badge badge-secondary">Submitted</span>',
        default => '<span class="badge badge-light">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>',
    };
}

function ra_source_label(string $sourceType): string
{
    return match ($sourceType) {
        'uploaded' => 'Uploaded',
        'selected_existing' => 'Existing',
        'reviewed_reupload' => 'Reviewed re-upload',
        default => ucwords(str_replace('_', ' ', $sourceType)),
    };
}

function can_inline_preview(array $ra): bool
{
    return strtolower((string)$ra['file_extension']) === 'pdf';
}

render_page_start('Review Events');
render_header('reviewer');
?>

<style>
.review-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 1.25rem;
}

.review-card {
    border: 1px solid #e9ecef;
    border-radius: 1rem;
    background: #fff;
    box-shadow: 0 0.25rem 0.8rem rgba(0,0,0,0.05);
    overflow: hidden;
}

.review-card-body {
    padding: 1.1rem;
}

.review-card-title {
    font-size: 1.1rem;
    font-weight: 800;
    margin-bottom: 0.25rem;
}

.review-card-meta {
    font-size: 0.92rem;
    color: #6c757d;
}

.review-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.5rem;
}

@media (max-width: 767.98px) {
    .review-stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

.review-stat {
    border: 1px solid #e9ecef;
    border-radius: 0.75rem;
    background: #f8f9fa;
    padding: 0.65rem;
}

.review-stat-label {
    font-size: 0.74rem;
    color: #6c757d;
    margin-bottom: 0.15rem;
}

.review-stat-value {
    font-size: 1.1rem;
    font-weight: 800;
    color: #212529;
}

.ra-mini-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 0.9rem;
}

.ra-mini-card {
    border: 1px solid #e9ecef;
    border-radius: 0.85rem;
    overflow: hidden;
    background: #fff;
}

.ra-mini-top {
    height: 110px;
    background: linear-gradient(135deg, #f8f9fa 0%, #eef2f6 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    border-bottom: 1px solid #e9ecef;
}

.ra-mini-sheet {
    width: 72%;
    height: 70%;
    background: #fff;
    border: 1px solid #dfe3e8;
    border-radius: 0.65rem;
    box-shadow: 0 0.2rem 0.6rem rgba(0,0,0,0.06);
    padding: 0.6rem;
}

.ra-mini-lines span {
    display: block;
    height: 6px;
    border-radius: 999px;
    background: #e9ecef;
    margin-bottom: 0.35rem;
}

.ra-mini-body {
    padding: 0.85rem;
}

.ra-mini-title {
    font-weight: 800;
    font-size: 0.95rem;
    line-height: 1.25;
    margin-bottom: 0.35rem;
}

.ra-mini-meta {
    font-size: 0.82rem;
    color: #6c757d;
}

.ra-mini-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.75rem;
}

.modal-section-box {
    border: 1px solid #e9ecef;
    border-radius: 0.9rem;
    padding: 1rem;
    background: #fff;
    margin-bottom: 1rem;
}
</style>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-1">Review Events</h1>
            <p class="text-muted mb-0">Review submissions, inspect attached risk assessments, and approve or return them.</p>
        </div>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-success"><?= e($flash) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (empty($events)): ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <p class="text-muted mb-0">There are no events waiting for review.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="review-grid">
            <?php foreach ($events as $event): ?>
                <?php $modalId = 'reviewModal' . (int)$event['id']; ?>
                <div class="review-card">
                    <div class="review-card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <div class="review-card-title"><?= e($event['event_title']) ?></div>
                                <div class="review-card-meta">
                                    <?= e($event['group_name']) ?> · <?= e($event['event_location']) ?>
                                </div>
                            </div>
                            <div><?= review_status_badge((string)$event['status']) ?></div>
                        </div>

                        <div class="review-card-meta mb-3">
                            <?= e(date('d M Y H:i', strtotime((string)$event['starts_at']))) ?>
                            to
                            <?= e(date('d M Y H:i', strtotime((string)$event['ends_at']))) ?><br>
                            <?= e($event['contact_name']) ?> · <?= e($event['contact_email']) ?>
                        </div>

                        <div class="review-stat-grid mb-3">
                            <div class="review-stat">
                                <div class="review-stat-label">Squirrels</div>
                                <div class="review-stat-value"><?= (int)($event['squirrels_count'] ?? 0) ?></div>
                            </div>
                            <div class="review-stat">
                                <div class="review-stat-label">Beavers</div>
                                <div class="review-stat-value"><?= (int)($event['beavers_count'] ?? 0) ?></div>
                            </div>
                            <div class="review-stat">
                                <div class="review-stat-label">Cubs</div>
                                <div class="review-stat-value"><?= (int)($event['cubs_count'] ?? 0) ?></div>
                            </div>
                            <div class="review-stat">
                                <div class="review-stat-label">Scouts</div>
                                <div class="review-stat-value"><?= (int)($event['scouts_count'] ?? 0) ?></div>
                            </div>
                            <div class="review-stat">
                                <div class="review-stat-label">Explorers</div>
                                <div class="review-stat-value"><?= (int)($event['explorers_count'] ?? 0) ?></div>
                            </div>
                            <div class="review-stat">
                                <div class="review-stat-label">Network</div>
                                <div class="review-stat-value"><?= (int)($event['network_count'] ?? 0) ?></div>
                            </div>
                            <div class="review-stat">
                                <div class="review-stat-label">Young people</div>
                                <div class="review-stat-value"><?= (int)($event['young_people_count'] ?? 0) ?></div>
                            </div>
                            <div class="review-stat">
                                <div class="review-stat-label">Adults</div>
                                <div class="review-stat-value"><?= (int)($event['adults_count'] ?? 0) ?></div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <strong>Risk assessments:</strong>
                            <div class="small text-muted">
                                <?= e((string)count($eventRiskAssessments[(int)$event['id']] ?? [])) ?> attached
                            </div>
                        </div>

                        <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            data-toggle="modal"
                            data-target="#<?= e($modalId) ?>">
                            Review
                        </button>
                    </div>
                </div>

                <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" role="dialog" aria-hidden="true">
                    <div class="modal-dialog modal-xl" role="document">
                        <div class="modal-content">
                            <form method="post">
                                <div class="modal-header">
                                    <div>
                                        <h5 class="modal-title mb-1">Review: <?= e($event['event_title']) ?></h5>
                                        <div class="small text-muted">
                                            <?= e($event['group_name']) ?> · submitted <?= e(date('d M Y H:i', strtotime((string)$event['submitted_at']))) ?>
                                        </div>
                                    </div>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>

                                <div class="modal-body">
                                    <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">

                                    <div class="modal-section-box">
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <strong>Group</strong><br>
                                                <?= e($event['group_name']) ?>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <strong>Contact</strong><br>
                                                <?= e($event['contact_name']) ?> (<?= e($event['contact_email']) ?>)
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <strong>Location</strong><br>
                                                <?= e($event['event_location']) ?>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <strong>Dates</strong><br>
                                                <?= e(date('d M Y H:i', strtotime((string)$event['starts_at']))) ?>
                                                to
                                                <?= e(date('d M Y H:i', strtotime((string)$event['ends_at']))) ?>
                                            </div>
                                        </div>

                                        <?php if (!empty($event['event_description'])): ?>
                                            <div>
                                                <strong>Description</strong>
                                                <div class="mt-2 text-muted">
                                                    <?= nl2br(e((string)$event['event_description'])) ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="modal-section-box">
                                        <h6 class="mb-3">Attendance</h6>
                                        <div class="review-stat-grid">
                                            <div class="review-stat">
                                                <div class="review-stat-label">Squirrels</div>
                                                <div class="review-stat-value"><?= (int)($event['squirrels_count'] ?? 0) ?></div>
                                            </div>
                                            <div class="review-stat">
                                                <div class="review-stat-label">Beavers</div>
                                                <div class="review-stat-value"><?= (int)($event['beavers_count'] ?? 0) ?></div>
                                            </div>
                                            <div class="review-stat">
                                                <div class="review-stat-label">Cubs</div>
                                                <div class="review-stat-value"><?= (int)($event['cubs_count'] ?? 0) ?></div>
                                            </div>
                                            <div class="review-stat">
                                                <div class="review-stat-label">Scouts</div>
                                                <div class="review-stat-value"><?= (int)($event['scouts_count'] ?? 0) ?></div>
                                            </div>
                                            <div class="review-stat">
                                                <div class="review-stat-label">Explorers</div>
                                                <div class="review-stat-value"><?= (int)($event['explorers_count'] ?? 0) ?></div>
                                            </div>
                                            <div class="review-stat">
                                                <div class="review-stat-label">Network</div>
                                                <div class="review-stat-value"><?= (int)($event['network_count'] ?? 0) ?></div>
                                            </div>
                                            <div class="review-stat">
                                                <div class="review-stat-label">Young people</div>
                                                <div class="review-stat-value"><?= (int)($event['young_people_count'] ?? 0) ?></div>
                                            </div>
                                            <div class="review-stat">
                                                <div class="review-stat-label">Adults</div>
                                                <div class="review-stat-value"><?= (int)($event['adults_count'] ?? 0) ?></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="modal-section-box">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="mb-0">Risk Assessments</h6>
                                            <span class="text-muted small"><?= e((string)count($eventRiskAssessments[(int)$event['id']] ?? [])) ?> attached</span>
                                        </div>

                                        <?php if (!empty($eventRiskAssessments[(int)$event['id']])): ?>
                                            <div class="ra-mini-grid">
                                                <?php foreach ($eventRiskAssessments[(int)$event['id']] as $index => $ra): ?>
                                                    <?php
                                                    $collapseId = 'raPreview' . (int)$event['id'] . '_' . $index;
                                                    ?>
                                                    <div class="ra-mini-card">
                                                        <div class="ra-mini-top">
                                                            <div class="ra-mini-sheet">
                                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                                    <span class="badge badge-light"><?= e(strtoupper((string)$ra['file_extension'])) ?></span>
                                                                    <span class="badge badge-secondary"><?= e(ra_source_label((string)$ra['source_type'])) ?></span>
                                                                </div>
                                                                <div class="ra-mini-lines">
                                                                    <span style="width:80%;"></span>
                                                                    <span style="width:94%;"></span>
                                                                    <span style="width:66%;"></span>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div class="ra-mini-body">
                                                            <div class="ra-mini-title"><?= e($ra['title']) ?></div>
                                                            <div class="ra-mini-meta">
                                                                <?= e($ra['group_name']) ?><br>
                                                                Updated <?= e(date('d M Y', strtotime((string)$ra['updated_at']))) ?>
                                                                <?php if (!empty($ra['activity_type'])): ?>
                                                                    <br><?= e($ra['activity_type']) ?>
                                                                <?php endif; ?>
                                                            </div>

                                                            <div class="ra-mini-actions">
                                                                <?php if (can_inline_preview($ra)): ?>
                                                                    <button
                                                                        type="button"
                                                                        class="btn btn-outline-primary btn-sm"
                                                                        data-toggle="collapse"
                                                                        data-target="#<?= e($collapseId) ?>"
                                                                        aria-expanded="false"
                                                                        aria-controls="<?= e($collapseId) ?>">
                                                                        Preview
                                                                    </button>
                                                                <?php endif; ?>

                                                                <a href="<?= e(BASE_URL . '/download-risk-assessment.php?id=' . (int)$ra['risk_assessment_id']) ?>"
                                                                   target="_blank"
                                                                   class="btn btn-primary btn-sm">
                                                                    Download
                                                                </a>
                                                            </div>

                                                            <?php if (can_inline_preview($ra)): ?>
                                                                <div class="collapse mt-3" id="<?= e($collapseId) ?>">
                                                                    <div class="border rounded overflow-hidden" style="height: 360px; background:#f8f9fa;">
                                                                        <iframe
                                                                            src="<?= e(BASE_URL . '/preview-risk-assessment.php?id=' . (int)$ra['risk_assessment_id']) ?>"
                                                                            style="width:100%; height:100%; border:0;"
                                                                            title="<?= e($ra['title']) ?>">
                                                                        </iframe>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-danger">No risk assessments attached to this event.</div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="modal-section-box mb-0">
                                        <div class="form-group mb-0">
                                            <label for="comments<?= (int)$event['id'] ?>">Reviewer comments</label>
                                            <textarea
                                                class="form-control"
                                                id="comments<?= (int)$event['id'] ?>"
                                                name="comments"
                                                rows="5"
                                                placeholder="Add comments for the group leader..."><?= e((string)$event['admin_comments']) ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <div class="modal-footer">
                                    <a href="<?= e(BASE_URL . '/add-event.php?event_id=' . (int)$event['id']) ?>"
                                       class="btn btn-outline-secondary">
                                        Open event page
                                    </a>

                                    <button type="submit" name="action" value="send_back" class="btn btn-outline-warning">
                                        Send back
                                    </button>
                                    <button type="submit" name="action" value="approve" class="btn btn-success">
                                        Approve
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php render_page_end(); ?>