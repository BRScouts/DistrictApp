<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_auth();

$pdo = db();

$currentGroup = auth_group();
$isAdminOrReviewer = is_reviewer_or_admin();

$allGroups = [];

/*
|--------------------------------------------------------------------------
| Resolve group securely
|--------------------------------------------------------------------------
*/
if ($isAdminOrReviewer) {
    $stmt = $pdo->query("
        SELECT id, group_name
        FROM groups
        WHERE is_active = 1
        ORDER BY group_name ASC
    ");
    $allGroups = $stmt->fetchAll();

    $groupId = (int)($_GET['group_id'] ?? $_POST['group_id'] ?? 0);

    if ($groupId <= 0 && !empty($allGroups)) {
        $groupId = (int)$allGroups[0]['id'];
    }
} else {
    if (!$currentGroup) {
        redirect(ROUTE_403);
    }

    $requestedGroupId = (int)($_GET['group_id'] ?? $_POST['group_id'] ?? 0);
    $sessionGroupId = (int)$currentGroup['group_id'];

    if ($requestedGroupId > 0 && $requestedGroupId !== $sessionGroupId) {
        redirect(ROUTE_403);
    }

    $groupId = $sessionGroupId;
}

if ($groupId <= 0) {
    redirect(ROUTE_403);
}

$flash = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Handle contact deactivate/reactivate
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = trim((string)($_POST['form_type'] ?? ''));
    $contactId = (int)($_POST['contact_id'] ?? 0);

    if ($contactId <= 0) {
        $error = 'Invalid contact selected.';
    } elseif (in_array($formType, ['deactivate_contact', 'reactivate_contact'], true)) {
        $isActive = $formType === 'reactivate_contact' ? 1 : 0;

        $stmt = $pdo->prepare("
            UPDATE group_contacts
            SET is_active = :is_active,
                updated_at = NOW()
            WHERE id = :id
              AND group_id = :group_id
        ");
        $stmt->execute([
            'is_active' => $isActive,
            'id' => $contactId,
            'group_id' => $groupId,
        ]);

        redirect(BASE_URL . '/glv.php?saved=1' . ($isAdminOrReviewer ? '&group_id=' . $groupId : ''));
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $flash = 'Group contact updated successfully.';
}

/*
|--------------------------------------------------------------------------
| Load group
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        id,
        group_name,
        district_name,
        access_token,
        lead_volunteer_name,
        lead_volunteer_email,
        notify_lead_on_event_created
    FROM groups
    WHERE id = :id
      AND is_active = 1
    LIMIT 1
");
$stmt->execute(['id' => $groupId]);
$group = $stmt->fetch();

if (!$group) {
    redirect(ROUTE_403);
}

/*
|--------------------------------------------------------------------------
| Stats
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE group_id = :group_id");
$stmt->execute(['group_id' => $groupId]);
$totalEvents = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM events
    WHERE group_id = :group_id
      AND starts_at >= NOW()
      AND status NOT IN ('cancelled', 'rejected')
");
$stmt->execute(['group_id' => $groupId]);
$upcomingCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM events
    WHERE group_id = :group_id
      AND status = 'approved'
      AND starts_at >= NOW()
");
$stmt->execute(['group_id' => $groupId]);
$approvedUpcomingCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM events
    WHERE group_id = :group_id
      AND status IN ('submitted', 'under_review', 'changes_requested')
");
$stmt->execute(['group_id' => $groupId]);
$pendingCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM risk_assessments
    WHERE group_id = :group_id
      AND is_active = 1
");
$stmt->execute(['group_id' => $groupId]);
$activeRaCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM group_contacts
    WHERE group_id = :group_id
      AND is_active = 1
");
$stmt->execute(['group_id' => $groupId]);
$activeContactCount = (int)$stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| Upcoming events
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        id,
        event_title,
        event_location,
        starts_at,
        ends_at,
        status,
        contact_name,
        young_people_count,
        adults_count
    FROM events
    WHERE group_id = :group_id
      AND starts_at >= NOW()
      AND status NOT IN ('cancelled')
    ORDER BY starts_at ASC
    LIMIT 10
");
$stmt->execute(['group_id' => $groupId]);
$upcomingEvents = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Approved upcoming events
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        id,
        event_title,
        event_location,
        starts_at,
        ends_at,
        contact_name,
        young_people_count,
        adults_count
    FROM events
    WHERE group_id = :group_id
      AND starts_at >= NOW()
      AND status = 'approved'
    ORDER BY starts_at ASC
    LIMIT 10
");
$stmt->execute(['group_id' => $groupId]);
$approvedEvents = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Contacts
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        id,
        full_name,
        email,
        is_active,
        last_used_at,
        created_at,
        updated_at
    FROM group_contacts
    WHERE group_id = :group_id
    ORDER BY is_active DESC, full_name ASC, email ASC
");
$stmt->execute(['group_id' => $groupId]);
$contacts = $stmt->fetchAll();

function glv_status_badge_html(string $status): string
{
    return match ($status) {
        'approved' => '<span class="badge" style="background:#28a745;color:#fff;">Approved</span>',
        'submitted' => '<span class="badge" style="background:#ffc107;color:#212529;">Submitted</span>',
        'under_review' => '<span class="badge" style="background:#17a2b8;color:#fff;">Under review</span>',
        'changes_requested' => '<span class="badge" style="background:#ffc107;color:#212529;">Changes requested</span>',
        'rejected' => '<span class="badge" style="background:#dc3545;color:#fff;">Rejected</span>',
        'cancelled' => '<span class="badge" style="background:#343a40;color:#fff;">Cancelled</span>',
        'draft' => '<span class="badge" style="background:#6c757d;color:#fff;">Draft</span>',
        default => '<span class="badge" style="background:#6c757d;color:#fff;">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>',
    };
}

function glv_event_url(int $eventId, int $groupId, bool $isAdminOrReviewer): string
{
    $url = BASE_URL . '/add-event.php?event_id=' . $eventId;

    if ($isAdminOrReviewer) {
        $url .= '&group_id=' . $groupId;
    }

    return $url;
}

render_page_start('Group Lead Volunteer');
render_header('glv');
?>

<div class="container-fluid">
    <div class="d-md-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="mb-1">Group Lead Volunteer</h1>
            <p class="text-muted mb-0">
                <?= e((string)$group['group_name']) ?>
                <?php if (!empty($group['district_name'])): ?>
                    · <?= e((string)$group['district_name']) ?>
                <?php endif; ?>
            </p>
        </div>

        <div class="mt-3 mt-md-0">
            <a href="<?= e(BASE_URL . '/add-event.php' . ($isAdminOrReviewer ? '?group_id=' . (int)$groupId : '')) ?>" class="btn btn-primary">
                Add event
            </a>
            <a href="<?= e(BASE_URL . '/risk-assessments.php') ?>" class="btn btn-outline-primary">
                Risk assessments
            </a>
        </div>
    </div>

    <?php if ($isAdminOrReviewer): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="get" class="form-inline">
                    <label for="group_id" class="mr-2 font-weight-bold">Viewing group dashboard</label>

                    <select class="form-control mr-2" id="group_id" name="group_id" onchange="this.form.submit()">
                        <?php foreach ($allGroups as $optionGroup): ?>
                            <option value="<?= (int)$optionGroup['id'] ?>" <?= (int)$optionGroup['id'] === $groupId ? 'selected' : '' ?>>
                                <?= e($optionGroup['group_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="btn btn-outline-primary">Open</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h4 mb-3">Assigned GLV</h2>

            <?php if (!empty($group['lead_volunteer_name']) || !empty($group['lead_volunteer_email'])): ?>
                <p class="mb-1">
                    <strong><?= e((string)($group['lead_volunteer_name'] ?: 'Group Lead Volunteer')) ?></strong>
                </p>

                <?php if (!empty($group['lead_volunteer_email'])): ?>
                    <p class="mb-2">
                        <a href="mailto:<?= e((string)$group['lead_volunteer_email']) ?>">
                            <?= e((string)$group['lead_volunteer_email']) ?>
                        </a>
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-muted mb-2">No Group Lead Volunteer is currently assigned for this group.</p>
            <?php endif; ?>

            <p class="text-muted mb-0">
                To change the assigned GLV, please contact your District Lead Volunteer.
            </p>
        </div>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-success"><?= e($flash) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-6 col-xl-2 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted mb-2">Total events</h2>
                    <div class="display-4 font-weight-bold"><?= e((string)$totalEvents) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-2 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted mb-2">Upcoming</h2>
                    <div class="display-4 font-weight-bold"><?= e((string)$upcomingCount) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-2 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted mb-2">Approved upcoming</h2>
                    <div class="display-4 font-weight-bold"><?= e((string)$approvedUpcomingCount) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-2 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted mb-2">Needs review</h2>
                    <div class="display-4 font-weight-bold"><?= e((string)$pendingCount) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-2 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted mb-2">Active RAs</h2>
                    <div class="display-4 font-weight-bold"><?= e((string)$activeRaCount) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-2 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted mb-2">Active contacts</h2>
                    <div class="display-4 font-weight-bold"><?= e((string)$activeContactCount) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-7 mb-4">
            <div class="card shadow-sm h-100">
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
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Contact</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($upcomingEvents as $event): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($event['event_title']) ?></strong><br>
                                            <small class="text-muted"><?= e($event['event_location']) ?></small>
                                        </td>
                                        <td>
                                            <?= e(date('d M Y H:i', strtotime((string)$event['starts_at']))) ?><br>
                                            <small class="text-muted">
                                                to <?= e(date('d M Y H:i', strtotime((string)$event['ends_at']))) ?>
                                            </small>
                                        </td>
                                        <td><?= glv_status_badge_html((string)$event['status']) ?></td>
                                        <td><?= e($event['contact_name']) ?></td>
                                        <td class="text-right">
                                            <a href="<?= e(glv_event_url((int)$event['id'], $groupId, $isAdminOrReviewer)) ?>" class="btn btn-outline-primary btn-sm">
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

        <div class="col-xl-5 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h4 mb-3">Approved upcoming events</h2>

                    <?php if (empty($approvedEvents)): ?>
                        <p class="text-muted mb-0">No approved upcoming events yet.</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($approvedEvents as $event): ?>
                                <a href="<?= e(glv_event_url((int)$event['id'], $groupId, $isAdminOrReviewer)) ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex justify-content-between">
                                        <strong><?= e($event['event_title']) ?></strong>
                                        <?= glv_status_badge_html('approved') ?>
                                    </div>
                                    <div class="small text-muted">
                                        <?= e(date('d M Y H:i', strtotime((string)$event['starts_at']))) ?>
                                        · <?= e($event['event_location']) ?>
                                    </div>
                                    <div class="small text-muted">
                                        <?= e((string)((int)$event['young_people_count'] + (int)$event['adults_count'])) ?> attending
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-5">
        <div class="card-body">
            <h2 class="h4 mb-3">Group contacts</h2>

            <?php if (empty($contacts)): ?>
                <p class="text-muted mb-0">No contacts saved yet. Contacts are added automatically when events are submitted.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Last used</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($contacts as $contact): ?>
                            <tr>
                                <td><?= e($contact['full_name']) ?></td>
                                <td>
                                    <a href="mailto:<?= e($contact['email']) ?>"><?= e($contact['email']) ?></a>
                                </td>
                                <td>
                                    <?php if ((int)$contact['is_active'] === 1): ?>
                                        <span class="badge" style="background:#28a745;color:#fff;">Active</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#6c757d;color:#fff;">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= !empty($contact['last_used_at'])
                                        ? e(date('d M Y H:i', strtotime((string)$contact['last_used_at'])))
                                        : 'Never' ?>
                                </td>
                                <td class="text-right">
                                    <?php if ((int)$contact['is_active'] === 1): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="form_type" value="deactivate_contact">
                                            <input type="hidden" name="contact_id" value="<?= (int)$contact['id'] ?>">
                                            <?php if ($isAdminOrReviewer): ?>
                                                <input type="hidden" name="group_id" value="<?= (int)$groupId ?>">
                                            <?php endif; ?>
                                            <button type="submit"
                                                    class="btn btn-outline-danger btn-sm"
                                                    onclick="return confirm('Mark this contact as inactive?');">
                                                Make inactive
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="form_type" value="reactivate_contact">
                                            <input type="hidden" name="contact_id" value="<?= (int)$contact['id'] ?>">
                                            <?php if ($isAdminOrReviewer): ?>
                                                <input type="hidden" name="group_id" value="<?= (int)$groupId ?>">
                                            <?php endif; ?>
                                            <button type="submit" class="btn btn-outline-success btn-sm">
                                                Reactivate
                                            </button>
                                        </form>
                                    <?php endif; ?>
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

<?php render_page_end(); ?>