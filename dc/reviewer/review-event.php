<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';

$ctx = dc_require_reviewer();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$event = dc_get_event($id);

if (!$event) {
    require __DIR__ . '/../404.php';
    exit;
}

$errors = [];

$allowedOutcomes = [
    'under_review',
    'approved',
    'changes_requested',
    'rejected',
    'cancelled',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = (string) ($_POST['status'] ?? 'under_review');
    $reviewerNotes = trim((string) ($_POST['reviewer_notes'] ?? ''));

    if (!in_array($status, $allowedOutcomes, true)) {
        $errors[] = 'Choose a valid review outcome.';
    }

    if (in_array($status, ['changes_requested', 'rejected'], true) && $reviewerNotes === '') {
        $errors[] = 'Add review notes when requesting changes or rejecting an event.';
    }

    if (!$errors) {
        $stmt = db()->prepare("
            UPDATE calendar_events
            SET
                status = :status,
                reviewer_notes = :notes,
                reviewed_by_person_id = :reviewed_by,
                reviewed_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'status' => $status,
            'notes' => $reviewerNotes !== '' ? $reviewerNotes : null,
            'reviewed_by' => $ctx['person_id'],
            'id' => $id,
        ]);

        dc_log(
            'calendar_event.reviewed',
            'calendar_event',
            $id,
            [
                'previous_status' => $event['status'] ?? null,
                'new_status' => $status,
                'notes_added' => $reviewerNotes !== '',
            ],
            (int) $event['group_id']
        );

        dc_queue_event_notifications($id, $status);

        redirect('/dc/reviewer/review-event.php?id=' . $id . '&reviewed=1');
    }
}

$event = dc_get_event($id);

$stmt = db()->prepare("
    SELECT
        ra.*,
        era.source_type
    FROM event_risk_assessments era
    JOIN risk_assessments ra
      ON ra.id = era.risk_assessment_id
    WHERE era.calendar_event_id = :id
    ORDER BY ra.uploaded_at DESC, ra.title ASC
");

$stmt->execute(['id' => $id]);
$risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = db()->prepare("
    SELECT
        al.*,
        p.full_name AS actor_name
    FROM audit_log al
    LEFT JOIN people p
      ON p.id = al.actor_person_id
    WHERE al.entity_type = 'calendar_event'
      AND al.entity_id = :id
    ORDER BY al.created_at DESC, al.id DESC
    LIMIT 50
");

$stmt->execute(['id' => $id]);
$auditRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = db()->prepare("
    SELECT
        nq.*
    FROM notification_log nq
    WHERE nq.related_entity_type = 'calendar_event'
      AND nq.related_entity_id = :id
    ORDER BY nq.created_at DESC, nq.id DESC
    LIMIT 25
");

try {
    $stmt->execute(['id' => $id]);
    $notificationRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $notificationRows = [];
}

function dc_review_count(?array $event, string $key): int
{
    return isset($event[$key]) && $event[$key] !== null ? (int) $event[$key] : 0;
}

$sectionCounts = [
    'Squirrels' => dc_review_count($event, 'squirrels_count'),
    'Beavers' => dc_review_count($event, 'beavers_count'),
    'Cubs' => dc_review_count($event, 'cubs_count'),
    'Scouts' => dc_review_count($event, 'scouts_count'),
    'Explorers' => dc_review_count($event, 'explorers_count'),
];

$youngPeopleTotal = array_sum($sectionCounts);

$daysUntil = null;

if (!empty($event['starts_at'])) {
    $startDate = new DateTimeImmutable(date('Y-m-d', strtotime((string) $event['starts_at'])));
    $today = new DateTimeImmutable('today');
    $diff = (int) $today->diff($startDate)->format('%r%a');
    $daysUntil = $diff;
}

$pageTitle = 'Review event';
$heroTitle = (string) $event['title'];
$heroText = 'Approve, request changes, reject or cancel this event. The action is audited and email notifications are queued.';
$active = 'review';

require __DIR__ . '/../layout.php';
?>

<style>
    .dc-review-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 992px) {
        .dc-review-grid {
            grid-template-columns: minmax(0, 1.3fr) 420px;
            align-items: start;
        }
    }

    .dc-review-sticky {
        position: static;
    }

    @media (min-width: 992px) {
        .dc-review-sticky {
            position: sticky;
            top: 1rem;
        }
    }

    .dc-summary-grid {
        display: grid;
        gap: 0.75rem;
    }

    @media (min-width: 768px) {
        .dc-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .dc-summary-card {
        border: 1px solid #d8d8d8;
        background: #fff;
        padding: 0.75rem;
    }

    .dc-summary-card dt {
        font-weight: 900;
        margin-bottom: 0.2rem;
    }

    .dc-summary-card dd {
        margin: 0;
    }

    .dc-section-counts {
        display: grid;
        gap: 0.5rem;
    }

    .dc-section-row {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        border-bottom: 1px solid #d8d8d8;
        padding: 0.4rem 0;
    }

    .dc-section-row strong {
        font-weight: 900;
    }

    .dc-risk-review-list,
    .dc-audit-list,
    .dc-notification-list {
        display: grid;
        gap: 0.75rem;
    }

    .dc-risk-review-item,
    .dc-audit-item,
    .dc-notification-item {
        border: 1px solid #d8d8d8;
        background: #fff;
        padding: 0.75rem;
    }

    .dc-audit-item {
        border-left: 5px solid #7413dc;
    }

    .dc-notification-item {
        border-left: 5px solid #006ddf;
    }

    .dc-review-warning {
        border-left: 6px solid #ffdd00;
        background: #fff8d6;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .dc-days-panel {
        border: 2px solid #000;
        background: #f5f5f5;
        padding: 0.75rem;
        margin-bottom: 1rem;
        font-weight: 900;
    }
</style>

<?php if (isset($_GET['reviewed'])): ?>
    <div class="dc-success">
        Review saved. Email notifications have been added to the queue and notification log.
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="dc-error-summary" role="alert">
        <h2>Check the review</h2>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="dc-review-grid">
    <div>
        <section class="lt-panel">
            <div class="dc-action-bar">
                <div>
                    <h2 class="lt-section-title">Event summary</h2>
                    <p class="mb-0">
                        <span class="lt-badge dc-status dc-status-<?= e((string) $event['status']) ?>">
                            <?= e(str_replace('_', ' ', (string) $event['status'])) ?>
                        </span>
                    </p>
                </div>

                <a class="btn lt-btn lt-btn-secondary" href="/dc/manage-event.php?id=<?= (int) $id ?>">
                    View event page
                </a>
            </div>

            <?php if ($daysUntil !== null): ?>
                <div class="dc-days-panel">
                    <?php if ($daysUntil < 0): ?>
                        Event was <?= abs($daysUntil) ?> day<?= abs($daysUntil) === 1 ? '' : 's' ?> ago.
                    <?php elseif ($daysUntil === 0): ?>
                        Event is today.
                    <?php elseif ($daysUntil === 1): ?>
                        Event is tomorrow.
                    <?php else: ?>
                        Event is in <?= $daysUntil ?> days.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <dl class="dc-summary-grid">
                <div class="dc-summary-card">
                    <dt>Group</dt>
                    <dd><?= e((string) $event['group_name']) ?></dd>
                </div>

                <div class="dc-summary-card">
                    <dt>Leader</dt>
                    <dd>
                        <?= e((string) ($event['leader_name'] ?? '')) ?>
                        <?php if (!empty($event['leader_email'])): ?>
                            <br><?= e((string) $event['leader_email']) ?>
                        <?php endif; ?>
                        <?php if (!empty($event['leader_phone'])): ?>
                            <br><?= e((string) $event['leader_phone']) ?>
                        <?php endif; ?>
                    </dd>
                </div>

                <div class="dc-summary-card">
                    <dt>When</dt>
                    <dd>
                        <?= e(date('D j M Y, H:i', strtotime((string) $event['starts_at']))) ?>
                        <br>
                        to <?= e(date('D j M Y, H:i', strtotime((string) $event['ends_at']))) ?>
                    </dd>
                </div>

                <div class="dc-summary-card">
                    <dt>Location</dt>
                    <dd>
                        <?= e((string) ($event['location_name'] ?: 'Not provided')) ?>
                        <?php if (!empty($event['location_address'])): ?>
                            <br><?= nl2br(e((string) $event['location_address'])) ?>
                        <?php endif; ?>
                    </dd>
                </div>

                <div class="dc-summary-card">
                    <dt>Adults</dt>
                    <dd><?= e((string) ($event['adult_count'] ?? 0)) ?></dd>
                </div>

                <div class="dc-summary-card">
                    <dt>Young people total</dt>
                    <dd><?= e((string) ($event['young_people_count'] ?? $youngPeopleTotal)) ?></dd>
                </div>
            </dl>

            <?php if (!empty($event['description'])): ?>
                <hr>
                <h3>Description</h3>
                <p><?= nl2br(e((string) $event['description'])) ?></p>
            <?php endif; ?>
        </section>

        <section class="lt-panel">
            <h2 class="lt-section-title">Young people by section</h2>

            <div class="dc-section-counts">
                <?php foreach ($sectionCounts as $label => $count): ?>
                    <div class="dc-section-row">
                        <strong><?= e($label) ?></strong>
                        <span><?= (int) $count ?></span>
                    </div>
                <?php endforeach; ?>

                <div class="dc-section-row">
                    <strong>Total</strong>
                    <span><?= (int) ($event['young_people_count'] ?? $youngPeopleTotal) ?></span>
                </div>
            </div>
        </section>

        <section class="lt-panel">
            <h2 class="lt-section-title">Risk assessments</h2>

            <?php if (!$risks): ?>
                <div class="dc-review-warning">
                    <strong>No risk assessments linked.</strong>
                    <p class="mb-0">
                        Consider requesting changes if this activity needs a risk assessment before approval.
                    </p>
                </div>
            <?php else: ?>
                <div class="dc-risk-review-list">
                    <?php foreach ($risks as $risk): ?>
                        <article class="dc-risk-review-item">
                            <h3 class="h5 mb-1">
                                <a href="/dc/download-risk-assessment.php?id=<?= (int) $risk['id'] ?>" target="_blank" rel="noopener">
                                    <?= e((string) $risk['title']) ?>
                                </a>
                            </h3>

                            <p class="mb-1">
                                <span class="lt-badge"><?= e((string) $risk['visibility']) ?></span>
                                <span class="lt-badge"><?= e((string) ($risk['source_type'] ?? 'linked')) ?></span>
                            </p>

                            <p class="mb-1">
                                Uploaded by <?= e((string) ($risk['uploaded_by_name'] ?? 'Unknown')) ?>
                                <?php if (!empty($risk['uploaded_at'])): ?>
                                    on <?= e(date('j M Y', strtotime((string) $risk['uploaded_at']))) ?>
                                <?php endif; ?>
                            </p>

                            <?php if (!empty($risk['description'])): ?>
                                <p class="mb-0"><?= nl2br(e((string) $risk['description'])) ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="lt-panel">
            <h2 class="lt-section-title">Audit trail</h2>

            <?php if (!$auditRows): ?>
                <p class="mb-0">No audit entries have been recorded for this event.</p>
            <?php else: ?>
                <div class="dc-audit-list">
                    <?php foreach ($auditRows as $row): ?>
                        <?php
                            $details = json_decode((string) ($row['details_json'] ?? ''), true);
                            if (!is_array($details)) {
                                $details = [];
                            }
                        ?>
                        <article class="dc-audit-item">
                            <strong><?= e(ucwords(str_replace(['.', '_'], ' ', (string) $row['action']))) ?></strong>
                            <br>
                            <small>
                                <?= e(date('j M Y H:i', strtotime((string) $row['created_at']))) ?>
                                ·
                                <?= e((string) ($row['actor_name'] ?? $row['actor_type'] ?? 'Unknown')) ?>
                            </small>

                            <?php if ($details): ?>
                                <div class="mt-2">
                                    <?php foreach ($details as $key => $value): ?>
                                        <?php if ($value === null || $value === '') continue; ?>
                                        <div>
                                            <strong><?= e(ucwords(str_replace('_', ' ', (string) $key))) ?>:</strong>
                                            <?= e(is_scalar($value) ? (string) $value : json_encode($value)) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="lt-panel">
            <h2 class="lt-section-title">Notification log</h2>

            <?php if (!$notificationRows): ?>
                <p class="mb-0">
                    No notification records have been logged for this event yet.
                </p>
            <?php else: ?>
                <div class="dc-notification-list">
                    <?php foreach ($notificationRows as $notification): ?>
                        <article class="dc-notification-item">
                            <strong><?= e((string) $notification['subject']) ?></strong>
                            <br>
                            <small>
                                <?= e((string) ($notification['recipient_email'] ?? '')) ?>
                                <?php if (!empty($notification['created_at'])): ?>
                                    · <?= e(date('j M Y H:i', strtotime((string) $notification['created_at']))) ?>
                                <?php endif; ?>
                                · <?= !empty($notification['sent_successfully']) ? 'Sent' : 'Queued/pending' ?>
                            </small>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="dc-review-sticky">
        <section class="lt-panel-grey">
            <h2 class="lt-section-title">Review outcome</h2>

            <form method="post">
                <input type="hidden" name="id" value="<?= (int) $id ?>">

                <div class="form-group">
                    <label for="status">Outcome</label>
                    <select id="status" name="status" class="form-control">
                        <?php foreach ($allowedOutcomes as $outcome): ?>
                            <option value="<?= e($outcome) ?>" <?= (string) $event['status'] === $outcome ? 'selected' : '' ?>>
                                <?= e(ucwords(str_replace('_', ' ', $outcome))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="reviewer_notes">Review notes</label>
                    <textarea
                        id="reviewer_notes"
                        name="reviewer_notes"
                        class="form-control"
                        rows="7"
                    ><?= e((string) ($event['reviewer_notes'] ?? '')) ?></textarea>
                    <p class="form-text">
                        Required if requesting changes or rejecting. These comments are shown to the submitter.
                    </p>
                </div>

                <button class="btn btn-primary lt-btn" type="submit">
                    Save review
                </button>

                <a class="btn lt-btn lt-btn-secondary" href="/dc/reviewer/events.php?status=submitted">
                    Back to review list
                </a>
            </form>
        </section>
    </aside>
</div>

<?php require __DIR__ . '/../layout-footer.php'; ?>