<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_reviewer_or_admin();

$pdo = db();

$admin = auth_admin();
$adminUserId = (int)($admin['admin_user_id'] ?? $admin['id'] ?? 0);

$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
if ($eventId <= 0) {
    redirect(BASE_URL . '/reviewer/index.php');
}

$flash = '';
$error = '';

function review_status_badge(string $status): string
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

function review_ra_name(array $ra): string
{
    $filename = trim((string)($ra['original_filename'] ?? ''));
    if ($filename !== '') {
        return $filename;
    }

    $title = trim((string)($ra['title'] ?? ''));
    return $title !== '' ? $title : 'Risk assessment';
}

function review_ra_can_preview(array $ra): bool
{
    return strtolower((string)($ra['file_extension'] ?? '')) === 'pdf';
}

function portal_event_link(array $event): string
{
    $url = APP_URL . BASE_URL . '/add-event.php?event_id=' . (int)$event['id'];

    if (!empty($event['access_token'])) {
        $url .= '&token=' . urlencode((string)$event['access_token']);
    } else {
        $url .= '&group_id=' . (int)$event['group_id'];
    }

    return $url;
}

function audit_review_action(PDO $pdo, int $eventId, int $adminUserId, string $action, array $details = []): void
{
    $stmt = $pdo->prepare("
        INSERT INTO audit_log (
            actor_type,
            admin_user_id,
            group_id,
            entity_type,
            entity_id,
            action,
            details,
            created_at
        ) VALUES (
            'admin',
            :admin_user_id,
            NULL,
            'event',
            :entity_id,
            :action,
            :details,
            NOW()
        )
    ");

    $stmt->execute([
        'admin_user_id' => $adminUserId,
        'entity_id' => $eventId,
        'action' => $action,
        'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

/*
|--------------------------------------------------------------------------
| Handle review / cancellation action
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = trim((string)($_POST['form_action'] ?? 'review'));

    $stmt = $pdo->prepare("
        SELECT
            e.*,
            g.group_name,
            g.access_token
        FROM events e
        INNER JOIN groups g ON g.id = e.group_id
        WHERE e.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $eventId]);
    $eventForUpdate = $stmt->fetch();

    if (!$eventForUpdate) {
        redirect(BASE_URL . '/reviewer/index.php');
    }

    if ($formAction === 'cancel_approval') {
        $cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));
        $sendEmail = (string)($_POST['send_email'] ?? 'no') === 'yes';

        if ((string)$eventForUpdate['status'] !== 'approved') {
            $error = 'Only approved events can have their approval cancelled.';
        } elseif ($cancelReason === '') {
            $error = 'Please provide a reason for cancelling approval.';
        } else {
            $pdo->beginTransaction();

            try {
                $stmt = $pdo->prepare("
                    UPDATE events
                    SET status = 'cancelled',
                        admin_comments = :comments,
                        reviewed_by_admin_id = :admin_user_id,
                        reviewed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    'comments' => $cancelReason,
                    'admin_user_id' => $adminUserId,
                    'id' => $eventId,
                ]);

                $stmt = $pdo->prepare("
                    INSERT INTO event_reviews (
                        event_id,
                        admin_user_id,
                        action,
                        comments,
                        created_at
                    ) VALUES (
                        :event_id,
                        :admin_user_id,
                        'cancelled',
                        :comments,
                        NOW()
                    )
                ");
                $stmt->execute([
                    'event_id' => $eventId,
                    'admin_user_id' => $adminUserId,
                    'comments' => $cancelReason,
                ]);

                audit_review_action($pdo, $eventId, $adminUserId, 'approval_cancelled', [
                    'reason' => $cancelReason,
                    'email_sent' => $sendEmail,
                ]);

                if ($sendEmail) {
                    $eventLink = portal_event_link($eventForUpdate);

                    $body = "Hello {$eventForUpdate['contact_name']},\n\n";
                    $body .= "The previous approval for your Away From Hut event has been cancelled.\n\n";
                    $body .= "Event: {$eventForUpdate['event_title']}\n";
                    $body .= "Group: {$eventForUpdate['group_name']}\n\n";
                    $body .= "Reason:\n{$cancelReason}\n\n";
                    $body .= "View event: {$eventLink}\n";

                    queue_email(
                        (string)$eventForUpdate['contact_email'],
                        'Away From Hut approval cancelled: ' . (string)$eventForUpdate['event_title'],
                        nl2br(e($body))
                    );
                }

                $pdo->commit();
                redirect(BASE_URL . '/reviewer/review-event.php?event_id=' . $eventId . '&saved=1');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = 'Unable to cancel approval: ' . $e->getMessage();
            }
        }
    } else {
        $decision = trim((string)($_POST['decision'] ?? ''));
        $comments = trim((string)($_POST['comments'] ?? ''));
        $sendEmail = (string)($_POST['send_email'] ?? 'no') === 'yes';

        if (!in_array($decision, ['approved', 'changes_requested', 'rejected'], true)) {
            $error = 'Choose approve, request changes, or decline.';
        } elseif ($decision !== 'approved' && $comments === '') {
            $error = 'Please add a reason when requesting changes or declining.';
        } else {
            $pdo->beginTransaction();

            try {
                $stmt = $pdo->prepare("
                    UPDATE events
                    SET status = :status,
                        admin_comments = :comments,
                        reviewed_by_admin_id = :admin_user_id,
                        reviewed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    'status' => $decision,
                    'comments' => $comments !== '' ? $comments : null,
                    'admin_user_id' => $adminUserId,
                    'id' => $eventId,
                ]);

                $stmt = $pdo->prepare("
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
                $stmt->execute([
                    'event_id' => $eventId,
                    'admin_user_id' => $adminUserId,
                    'action' => $decision,
                    'comments' => $comments !== '' ? $comments : null,
                ]);

                audit_review_action($pdo, $eventId, $adminUserId, $decision, [
                    'comments' => $comments,
                    'email_sent' => $sendEmail,
                ]);

                if ($sendEmail) {
                    $label = match ($decision) {
                        'approved' => 'approved',
                        'changes_requested' => 'reviewed - changes requested',
                        'rejected' => 'declined',
                        default => 'reviewed',
                    };

                    $eventLink = portal_event_link($eventForUpdate);

                    $body = "Hello {$eventForUpdate['contact_name']},\n\n";
                    $body .= "Your Away From Hut event has been {$label}.\n\n";
                    $body .= "Event: {$eventForUpdate['event_title']}\n";
                    $body .= "Group: {$eventForUpdate['group_name']}\n\n";

                    if ($comments !== '') {
                        $body .= "Reviewer comments:\n{$comments}\n\n";
                    }

                    $body .= "View event: {$eventLink}\n";

                    queue_email(
                        (string)$eventForUpdate['contact_email'],
                        'Away From Hut review: ' . (string)$eventForUpdate['event_title'],
                        nl2br(e($body))
                    );
                }

                $pdo->commit();
                redirect(BASE_URL . '/reviewer/review-event.php?event_id=' . $eventId . '&saved=1');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = 'Unable to save review: ' . $e->getMessage();
            }
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $flash = 'Review saved successfully.';
}

/*
|--------------------------------------------------------------------------
| Load event
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        e.*,
        g.group_name,
        g.lead_volunteer_name,
        g.lead_volunteer_email,
        g.access_token
    FROM events e
    INNER JOIN groups g ON g.id = e.group_id
    WHERE e.id = :id
    LIMIT 1
");
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch();

if (!$event) {
    redirect(BASE_URL . '/reviewer/index.php');
}

$portalLink = portal_event_link($event);

/*
|--------------------------------------------------------------------------
| Risk assessments attached to this event
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        era.source_type,
        era.created_at AS linked_at,
        ra.*,
        g.group_name
    FROM event_risk_assessments era
    INNER JOIN risk_assessments ra ON ra.id = era.risk_assessment_id
    INNER JOIN groups g ON g.id = ra.group_id
    WHERE era.event_id = :event_id
      AND ra.is_active = 1
    ORDER BY era.created_at ASC
");
$stmt->execute(['event_id' => $eventId]);
$riskAssessments = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Last review for each attached RA, excluding current event
|--------------------------------------------------------------------------
*/
$raReviewMap = [];

foreach ($riskAssessments as $ra) {
    $stmt = $pdo->prepare("
        SELECT
            er.action,
            er.comments,
            er.created_at,
            e.event_title,
            e.id AS previous_event_id,
            au.full_name AS reviewer_name
        FROM event_risk_assessments era
        INNER JOIN events e ON e.id = era.event_id
        INNER JOIN event_reviews er ON er.event_id = e.id
        LEFT JOIN admin_users au ON au.id = er.admin_user_id
        WHERE era.risk_assessment_id = :ra_id
          AND e.id <> :event_id
          AND er.action IN ('approved', 'changes_requested', 'rejected', 'cancelled')
        ORDER BY er.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([
        'ra_id' => (int)$ra['id'],
        'event_id' => $eventId,
    ]);
    $raReviewMap[(int)$ra['id']] = $stmt->fetch() ?: null;
}

/*
|--------------------------------------------------------------------------
| Review history
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        er.*,
        au.full_name AS reviewer_name
    FROM event_reviews er
    LEFT JOIN admin_users au ON au.id = er.admin_user_id
    WHERE er.event_id = :event_id
    ORDER BY er.created_at DESC
");
$stmt->execute(['event_id' => $eventId]);
$reviewHistory = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Audit history
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        al.*,
        au.full_name AS admin_name,
        g.group_name AS actor_group_name
    FROM audit_log al
    LEFT JOIN admin_users au ON au.id = al.admin_user_id
    LEFT JOIN groups g ON g.id = al.group_id
    WHERE al.entity_type = 'event'
      AND al.entity_id = :event_id
    ORDER BY al.created_at DESC
    LIMIT 100
");
$stmt->execute(['event_id' => $eventId]);
$auditHistory = $stmt->fetchAll();

$totalYoungPeople = (int)($event['young_people_count'] ?? 0);
$totalAdults = (int)($event['adults_count'] ?? 0);
$isApproved = (string)$event['status'] === 'approved';

render_page_start('Review Event');
render_header('reviewer');
?>

<style>
    @media print {
        .no-print,
        .navbar,
        header,
        nav,
        .btn,
        form,
        .alert-success,
        .alert-danger {
            display: none !important;
        }

        .container-fluid {
            width: 100% !important;
            max-width: none !important;
        }

        .card {
            border: 1px solid #999 !important;
            box-shadow: none !important;
            break-inside: avoid;
        }

        .col-xl-8,
        .col-xl-4 {
            flex: 0 0 100%;
            max-width: 100%;
        }

        a[href]:after {
            content: "";
        }

        body {
            font-size: 12pt;
        }

        .print-only {
            display: block !important;
        }
    }

    .print-only {
        display: none;
    }

    .review-meta-box {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: .5rem;
        padding: 1rem;
    }

    .audit-item,
    .history-item {
        border-left: 3px solid #dee2e6;
        padding-left: .75rem;
        margin-bottom: 1rem;
    }
</style>

<div class="container-fluid">
    <div class="d-md-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="mb-1">Review Event</h1>
            <p class="text-muted mb-0">
                <?= e($event['group_name']) ?> · <?= e(date('d M Y H:i', strtotime((string)$event['starts_at']))) ?>
            </p>
        </div>

        <div class="mt-3 mt-md-0 no-print">
            <button type="button" class="btn btn-primary" onclick="window.print()">
                Print review pack
            </button>

            <a href="<?= e(BASE_URL . '/add-event.php?event_id=' . (int)$event['id'] . '&group_id=' . (int)$event['group_id']) ?>"
               class="btn btn-outline-secondary">
                Open edit page
            </a>

            <a href="<?= e(BASE_URL . '/reviewer/index.php') ?>" class="btn btn-outline-primary">
                Dashboard
            </a>
        </div>
    </div>

    <div class="print-only mb-4">
        <h2>Event review pack</h2>
        <p>
            Printed: <?= e(date('d M Y H:i')) ?><br>
            Portal link: <?= e($portalLink) ?>
        </p>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-success no-print"><?= e($flash) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger no-print"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-xl-8 mb-4">
            <div class="card shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="d-md-flex justify-content-between">
                        <div>
                            <h2 class="h3 mb-1"><?= e($event['event_title']) ?></h2>
                            <p class="text-muted mb-2"><?= e($event['event_location']) ?></p>
                        </div>

                        <div>
                            <span class="badge badge-<?= e(review_status_badge((string)$event['status'])) ?> p-2">
                                <?= e(ucwords(str_replace('_', ' ', (string)$event['status']))) ?>
                            </span>
                        </div>
                    </div>

                    <div class="review-meta-box mt-3">
                        <strong>Portal link:</strong><br>
                        <a href="<?= e($portalLink) ?>" target="_blank"><?= e($portalLink) ?></a>
                    </div>

                    <?php if (!empty($event['event_description'])): ?>
                        <hr>
                        <p><?= nl2br(e((string)$event['event_description'])) ?></p>
                    <?php endif; ?>

                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h3 class="h6 text-muted">Dates</h3>
                            <p class="mb-1"><strong>Start:</strong> <?= e(date('d M Y H:i', strtotime((string)$event['starts_at']))) ?></p>
                            <p class="mb-0"><strong>End:</strong> <?= e(date('d M Y H:i', strtotime((string)$event['ends_at']))) ?></p>
                        </div>

                        <div class="col-md-6 mt-3 mt-md-0">
                            <h3 class="h6 text-muted">Contact</h3>
                            <p class="mb-1"><?= e($event['contact_name']) ?></p>
                            <p class="mb-0">
                                <a href="mailto:<?= e($event['contact_email']) ?>"><?= e($event['contact_email']) ?></a>
                            </p>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h3 class="h6 text-muted">Lead volunteer</h3>
                            <p class="mb-1"><?= e((string)($event['lead_volunteer_name'] ?? '')) ?></p>
                            <?php if (!empty($event['lead_volunteer_email'])): ?>
                                <p class="mb-0">
                                    <a href="mailto:<?= e($event['lead_volunteer_email']) ?>"><?= e($event['lead_volunteer_email']) ?></a>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6 mt-3 mt-md-0">
                            <h3 class="h6 text-muted">Review state</h3>
                            <p class="mb-1">
                                <strong>Submitted:</strong>
                                <?= !empty($event['submitted_at']) ? e(date('d M Y H:i', strtotime((string)$event['submitted_at']))) : 'Not recorded' ?>
                            </p>
                            <p class="mb-0">
                                <strong>Last updated:</strong>
                                <?= !empty($event['updated_at']) ? e(date('d M Y H:i', strtotime((string)$event['updated_at']))) : 'Not recorded' ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h4 mb-3">Attendance</h2>

                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <tbody>
                            <tr><th>Squirrels</th><td><?= e((string)($event['squirrels_count'] ?? 0)) ?></td></tr>
                            <tr><th>Beavers</th><td><?= e((string)($event['beavers_count'] ?? 0)) ?></td></tr>
                            <tr><th>Cubs</th><td><?= e((string)($event['cubs_count'] ?? 0)) ?></td></tr>
                            <tr><th>Scouts</th><td><?= e((string)($event['scouts_count'] ?? 0)) ?></td></tr>
                            <tr><th>Explorers</th><td><?= e((string)($event['explorers_count'] ?? 0)) ?></td></tr>
                            <tr><th>Network</th><td><?= e((string)($event['network_count'] ?? 0)) ?></td></tr>
                            <tr class="font-weight-bold"><th>Total young people</th><td><?= e((string)$totalYoungPeople) ?></td></tr>
                            <tr class="font-weight-bold"><th>Adults</th><td><?= e((string)$totalAdults) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h4 mb-3">Risk assessments</h2>

                    <?php if (empty($riskAssessments)): ?>
                        <div class="alert alert-warning mb-0">
                            No risk assessments are attached to this event.
                        </div>
                    <?php else: ?>
                        <?php foreach ($riskAssessments as $ra): ?>
                            <?php
                            $lastReview = $raReviewMap[(int)$ra['id']] ?? null;
                            $recentlyReviewed = $lastReview && strtotime((string)$lastReview['created_at']) >= strtotime('-90 days');
                            ?>
                            <div class="border rounded p-3 mb-3">
                                <div class="d-md-flex justify-content-between align-items-start">
                                    <div>
                                        <h3 class="h5 mb-1"><?= e(review_ra_name($ra)) ?></h3>
                                        <p class="text-muted mb-1">
                                            <?= e($ra['group_name']) ?> ·
                                            Uploaded <?= e(date('d M Y', strtotime((string)$ra['uploaded_at']))) ?> ·
                                            <?= e(strtoupper((string)$ra['file_extension'])) ?>
                                        </p>

                                        <p class="mb-2">
                                            <span class="badge badge-secondary"><?= e(str_replace('_', ' ', (string)$ra['source_type'])) ?></span>
                                            <?php if ($recentlyReviewed): ?>
                                                <span class="badge badge-success">Reviewed recently</span>
                                            <?php elseif ($lastReview): ?>
                                                <span class="badge badge-warning">Previous review older than 90 days</span>
                                            <?php else: ?>
                                                <span class="badge badge-info">No previous review found</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>

                                    <div class="text-nowrap mt-2 mt-md-0 no-print">
                                        <?php if (review_ra_can_preview($ra)): ?>
                                            <a href="<?= e(BASE_URL . '/preview-risk-assessment.php?id=' . (int)$ra['id']) ?>"
                                               target="_blank"
                                               class="btn btn-outline-primary btn-sm">
                                                View
                                            </a>
                                        <?php endif; ?>

                                        <a href="<?= e(BASE_URL . '/download-risk-assessment.php?id=' . (int)$ra['id']) ?>"
                                           target="_blank"
                                           class="btn btn-outline-secondary btn-sm">
                                            Download
                                        </a>
                                    </div>
                                </div>

                                <?php if ($lastReview): ?>
                                    <div class="alert alert-light border mt-3 mb-0">
                                        <strong>Previous linked review:</strong>
                                        <?= e(ucwords(str_replace('_', ' ', (string)$lastReview['action']))) ?>
                                        on <?= e(date('d M Y', strtotime((string)$lastReview['created_at']))) ?>
                                        <?php if (!empty($lastReview['reviewer_name'])): ?>
                                            by <?= e($lastReview['reviewer_name']) ?>
                                        <?php endif; ?>
                                        <br>
                                        <small class="text-muted">
                                            Event:
                                            <a href="<?= e(BASE_URL . '/reviewer/review-event.php?event_id=' . (int)$lastReview['previous_event_id']) ?>">
                                                <?= e($lastReview['event_title']) ?>
                                            </a>
                                        </small>

                                        <?php if (!empty($lastReview['comments'])): ?>
                                            <div class="mt-2"><?= nl2br(e((string)$lastReview['comments'])) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h4 mb-3">Audit history</h2>

                    <?php if (empty($auditHistory)): ?>
                        <p class="text-muted mb-0">No audit history recorded.</p>
                    <?php else: ?>
                        <?php foreach ($auditHistory as $entry): ?>
                            <?php
                            $actor = 'System';
                            if ((string)$entry['actor_type'] === 'admin' && !empty($entry['admin_name'])) {
                                $actor = (string)$entry['admin_name'];
                            } elseif ((string)$entry['actor_type'] === 'group_link' && !empty($entry['actor_group_name'])) {
                                $actor = (string)$entry['actor_group_name'];
                            }

                            $details = [];
                            if (!empty($entry['details'])) {
                                $decoded = json_decode((string)$entry['details'], true);
                                if (is_array($decoded)) {
                                    $details = $decoded;
                                }
                            }
                            ?>
                            <div class="audit-item">
                                <strong><?= e(ucfirst(str_replace('_', ' ', (string)$entry['action']))) ?></strong><br>
                                <small class="text-muted">
                                    <?= e($actor) ?> · <?= e(date('d M Y H:i', strtotime((string)$entry['created_at']))) ?>
                                </small>

                                <?php if (!empty($details)): ?>
                                    <div class="small mt-1 text-muted">
                                        <?php foreach ($details as $key => $value): ?>
                                            <?php if (is_scalar($value)): ?>
                                                <div>
                                                    <?= e(ucfirst(str_replace('_', ' ', (string)$key))) ?>:
                                                    <?= e((string)$value) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-4 mb-4">
            <?php if ($isApproved): ?>
                <div class="card shadow-sm mb-4 no-print">
                    <div class="card-body p-4">
                        <h2 class="h4 mb-3">Cancel approval</h2>

                        <div class="alert alert-success">
                            This event is approved. To cancel click below.
                        </div>

                        <form method="post">
                            <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                            <input type="hidden" name="form_action" value="cancel_approval">

                            <div class="form-group">
                                <label for="cancel_reason">Reason for cancelling approval</label>
                                <textarea class="form-control" id="cancel_reason" name="cancel_reason" rows="5" required></textarea>
                            </div>

                            <div class="form-group">
                                <label>Email contact?</label>

                                <div class="custom-control custom-radio">
                                    <input type="radio" id="cancel_send_email_yes" name="send_email" value="yes" class="custom-control-input" checked>
                                    <label class="custom-control-label" for="cancel_send_email_yes">Send cancellation email</label>
                                </div>

                                <div class="custom-control custom-radio">
                                    <input type="radio" id="cancel_send_email_no" name="send_email" value="no" class="custom-control-input">
                                    <label class="custom-control-label" for="cancel_send_email_no">Do not send email</label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-danger btn-block">
                                Cancel approval
                            </button>
                        </form>
                    </div>
                </div>
            <?php elseif (!in_array((string)$event['status'], ['rejected', 'cancelled'], true)): ?>
                <div class="card shadow-sm mb-4 no-print">
                    <div class="card-body p-4">
                        <h2 class="h4 mb-3">Decision</h2>

                        <form method="post">
                            <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                            <input type="hidden" name="form_action" value="review">

                            <div class="form-group">
                                <label for="decision">Review outcome</label>
                                <select class="form-control" id="decision" name="decision" required>
                                    <option value="">Choose outcome</option>
                                    <option value="approved">Approve</option>
                                    <option value="changes_requested">Request changes</option>
                                    <option value="rejected">Decline</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="comments">Reason / comments</label>
                                <textarea class="form-control" id="comments" name="comments" rows="5" placeholder="Optional for approval, required for decline or changes requested."></textarea>
                            </div>

                            <div class="form-group">
                                <label>Email contact?</label>

                                <div class="custom-control custom-radio">
                                    <input type="radio" id="send_email_yes" name="send_email" value="yes" class="custom-control-input" checked>
                                    <label class="custom-control-label" for="send_email_yes">Send email with decision and comments</label>
                                </div>

                                <div class="custom-control custom-radio">
                                    <input type="radio" id="send_email_no" name="send_email" value="no" class="custom-control-input">
                                    <label class="custom-control-label" for="send_email_no">Do not send email</label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary btn-block">
                                Save review decision
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="card shadow-sm mb-4 no-print">
                    <div class="card-body p-4">
                        <h2 class="h4 mb-3">Decision</h2>
                        <p class="text-muted mb-0">
                            This event is <?= e(str_replace('_', ' ', (string)$event['status'])) ?>. The decision panel is hidden.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h4 mb-3">Review history</h2>

                    <?php if (empty($reviewHistory)): ?>
                        <p class="text-muted mb-0">No review history yet.</p>
                    <?php else: ?>
                        <?php foreach ($reviewHistory as $review): ?>
                            <div class="history-item">
                                <strong><?= e(ucwords(str_replace('_', ' ', (string)$review['action']))) ?></strong><br>
                                <small class="text-muted">
                                    <?= e(date('d M Y H:i', strtotime((string)$review['created_at']))) ?>
                                    <?php if (!empty($review['reviewer_name'])): ?>
                                        · <?= e($review['reviewer_name']) ?>
                                    <?php endif; ?>
                                </small>

                                <?php if (!empty($review['comments'])): ?>
                                    <div class="mt-2"><?= nl2br(e((string)$review['comments'])) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const decision = document.getElementById('decision');
    const comments = document.getElementById('comments');

    if (!decision || !comments) return;

    function syncCommentRequirement() {
        comments.required = decision.value === 'changes_requested' || decision.value === 'rejected';
    }

    decision.addEventListener('change', syncCommentRequirement);
    syncCommentRequirement();
});
</script>

<?php render_page_end(); ?>