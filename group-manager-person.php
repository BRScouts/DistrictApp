<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/group-manager-helpers.php';

require_login();

if (function_exists('user_needs_group_onboarding') && user_needs_group_onboarding()) {
    redirect('/onboarding.php');
}

$pdo = db();
$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
$memberships = gm_current_memberships($user);
$isDistrictAdmin = gm_actor_is_district_admin($user, $memberships);
$manageableGroups = gm_manageable_groups((int) $user['id'], $isDistrictAdmin);

if (!$manageableGroups) {
    http_response_code(403);

    $pageTitle = 'Manage person | ' . $appName;
    $heroTitle = 'Manage person';
    $heroText = 'This area is for Group Lead Volunteers and District administrators.';
    $breadcrumb = '<a href="/index.php">Home</a> / <a href="/group-manager.php">Group Manager</a> / Manage person';

    include __DIR__ . '/header.php';
    echo '<main class="lt-main"><div class="alert alert-danger"><strong>Access denied:</strong> You do not currently manage any Groups.</div></main>';
    include __DIR__ . '/footer.php';
    exit;
}

$selectedGroupId = gm_selected_group_id($manageableGroups);
$selectedGroup = gm_fetch_group($selectedGroupId);
$personId = (int) ($_GET['person_id'] ?? $_POST['person_id'] ?? 0);

if (!$selectedGroup || $personId < 1) {
    http_response_code(404);
    include __DIR__ . '/header.php';
    echo '<main class="lt-main"><div class="alert alert-danger">Person or Group not found.</div></main>';
    include __DIR__ . '/footer.php';
    exit;
}

$errors = [];
$success = null;
$createdInviteUrl = null;
$actorPersonId = (int) $user['id'];
$roleOptions = gm_membership_role_options();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');

        if (!gm_group_is_manageable($selectedGroupId, $manageableGroups)) {
            throw new RuntimeException('You do not have permission to manage that Group.');
        }

        if ($action === 'update_details') {
            $pdo->beginTransaction();

            gm_update_person_details(
                $personId,
                $selectedGroupId,
                (string) ($_POST['full_name'] ?? ''),
                (string) ($_POST['primary_email'] ?? ''),
                trim((string) ($_POST['phone'] ?? '')),
                isset($_POST['visible_in_directory']) ? 1 : 0,
                isset($_POST['share_phone']) ? 1 : 0,
                $actorPersonId
            );

            $pdo->commit();
            $success = 'Person details saved.';
        } elseif ($action === 'update_role') {
            $membershipRole = (string) ($_POST['membership_role'] ?? '');

            $pdo->beginTransaction();
            gm_update_group_role($personId, $selectedGroupId, $membershipRole, $actorPersonId);
            $pdo->commit();

            $success = 'Role updated.';
        } elseif ($action === 'set_status') {
            $newStatus = (string) ($_POST['new_status'] ?? 'inactive');

            $pdo->beginTransaction();
            gm_set_person_membership_status($personId, $selectedGroupId, $newStatus, $actorPersonId);
            $pdo->commit();

            $success = $newStatus === 'active'
                ? 'Person reactivated for this Group.'
                : 'Person made inactive for this Group.';
        } elseif ($action === 'send_microsoft_instructions') {
            $person = gm_fetch_person_for_group($selectedGroupId, $personId);

            if (!$person) {
                throw new RuntimeException('Person not found.');
            }

            gm_send_microsoft_instructions($person, $selectedGroupId, $actorPersonId);
            $success = 'Microsoft sign-in instructions have been queued.';
        } elseif ($action === 'send_calendar_link') {
            $person = gm_fetch_person_for_group($selectedGroupId, $personId);

            if (!$person) {
                throw new RuntimeException('Person not found.');
            }

            $createdInviteUrl = gm_send_calendar_link_instructions($person, $selectedGroupId, $actorPersonId);
            $success = 'Calendar access instructions have been queued.';
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $errors[] = $e->getMessage() ?: 'The request could not be completed.';
}

$person = gm_fetch_person_for_group($selectedGroupId, $personId);

if (!$person) {
    http_response_code(404);
    include __DIR__ . '/header.php';
    echo '<main class="lt-main"><div class="alert alert-danger">Person not found in this Group.</div></main>';
    include __DIR__ . '/footer.php';
    exit;
}

$accessLabel = gm_access_status_label($person);
$accessClass = match ($accessLabel) {
    'Microsoft SSO' => 'gm-badge-sso',
    'Account requested' => 'gm-badge-pending',
    default => 'gm-badge-link',
};

$pageTitle = 'Manage person | ' . $appName;
$heroTitle = 'Manage person';
$heroText = (string) $person['full_name'] . ' in ' . (string) $selectedGroup['group_name'] . '.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/group-manager.php?group_id=' . $selectedGroupId . '">Group Manager</a> / Manage person';

include __DIR__ . '/header.php';
?>

<style>
    .gm-back-link {
        display: inline-block;
        margin-bottom: 1.25rem;
        font-weight: 900;
        font-size: .95rem;
        color: var(--iv-grey-700);
        text-decoration: none;
    }

    .gm-back-link::before {
        content: "\2190";
        margin-right: .4rem;
    }

    .gm-back-link:hover {
        color: var(--iv-black);
        text-decoration: underline;
    }

    .gm-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 992px) {
        .gm-grid-2 {
            grid-template-columns: minmax(0, 2fr) minmax(320px, 1fr);
        }
    }

    .gm-badge {
        display: inline-block;
        padding: .2rem .45rem;
        font-weight: 900;
        font-size: .78rem;
    }

    .gm-badge-sso {
        background: #e7f1ff;
        color: #004085;
    }

    .gm-badge-pending {
        background: #fff3cd;
        color: #664d03;
    }

    .gm-badge-link {
        background: #f8d7da;
        color: #842029;
    }

    .gm-badge-active {
        background: #d1e7dd;
        color: #0f5132;
    }

    .gm-badge-inactive {
        background: #f8d7da;
        color: #842029;
    }

    .gm-muted {
        color: #555;
    }

    .gm-stat-list {
        display: grid;
        gap: .75rem;
    }

    .gm-stat-item {
        border: 2px solid #e5e5e5;
        background: #fff;
        padding: .75rem;
    }

    .gm-stat-item strong {
        display: block;
        color: var(--iv-purple);
        font-size: 1.5rem;
        line-height: 1;
    }

    .gm-link-box {
        display: grid;
        gap: .5rem;
    }

    @media (min-width: 768px) {
        .gm-link-box {
            grid-template-columns: minmax(0, 1fr) auto;
        }
    }
</style>

<main class="lt-main">
    <a class="gm-back-link" href="/group-manager.php?group_id=<?= (int) $selectedGroupId ?>">Back to Group Manager</a>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <strong>There is a problem:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($createdInviteUrl): ?>
        <div class="alert alert-info">
            <strong>Access link:</strong>
            <div class="gm-link-box mt-2">
                <input class="form-control" type="text" value="<?= e($createdInviteUrl) ?>" readonly>
                <button class="btn btn-secondary lt-btn gm-copy" type="button" data-copy="<?= e($createdInviteUrl) ?>">Copy</button>
            </div>
        </div>
    <?php endif; ?>

    <div class="gm-grid gm-grid-2">
        <section class="lt-panel">
            <h2 class="lt-section-title">Details</h2>

            <form method="post">
                <input type="hidden" name="action" value="update_details">
                <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                <input type="hidden" name="person_id" value="<?= (int) $personId ?>">

                <div class="form-group">
                    <label for="full_name">Name</label>
                    <input class="form-control" type="text" id="full_name" name="full_name" value="<?= e($person['full_name']) ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-8">
                        <label for="primary_email">Email</label>
                        <input class="form-control" type="email" id="primary_email" name="primary_email" value="<?= e($person['primary_email']) ?>" required>
                    </div>

                    <div class="form-group col-md-4">
                        <label for="phone">Phone</label>
                        <input class="form-control" type="text" id="phone" name="phone" value="<?= e($person['phone'] ?? '') ?>">
                    </div>
                </div>

                <label class="lt-check">
                    <input type="checkbox" name="visible_in_directory" value="1" <?= (int) ($person['visible_in_directory'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <span>Show this person in the District Directory</span>
                </label>

                <label class="lt-check mt-2">
                    <input type="checkbox" name="share_phone" value="1" <?= (int) ($person['share_phone'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <span>Show their phone number in the Directory</span>
                </label>

                <button class="btn btn-primary lt-btn mt-3" type="submit">Save details</button>
            </form>
        </section>

        <aside class="lt-panel-grey">
            <h2 class="lt-section-title">Status</h2>

            <p>
                Group status:
                <?= (string) $person['membership_status'] === 'active'
                    ? '<span class="gm-badge gm-badge-active">Active</span>'
                    : '<span class="gm-badge gm-badge-inactive">Inactive</span>' ?>
            </p>

            <p>
                Access:
                <span class="gm-badge <?= e($accessClass) ?>"><?= e($accessLabel) ?></span>
            </p>

            <div class="gm-stat-list mt-3">
                <div class="gm-stat-item">
                    <strong><?= (int) $person['total_events'] ?></strong>
                    <span>linked calendar events</span>
                </div>
                <div class="gm-stat-item">
                    <strong><?= (int) $person['in_review_events'] ?></strong>
                    <span>events in review</span>
                </div>
                <div class="gm-stat-item">
                    <strong><?= (int) $person['approved_events'] ?></strong>
                    <span>approved events</span>
                </div>
            </div>
        </aside>
    </div>

    <div class="gm-grid gm-grid-2 mt-4">
        <section class="lt-panel">
            <h2 class="lt-section-title">Role in this Group</h2>

            <form method="post">
                <input type="hidden" name="action" value="update_role">
                <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                <input type="hidden" name="person_id" value="<?= (int) $personId ?>">

                <div class="form-group">
                    <label for="membership_role">Role</label>
                    <select class="form-control" id="membership_role" name="membership_role" required>
                        <?php foreach ($roleOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= (string) $person['membership_role'] === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <p class="gm-muted">
                    Group Lead Volunteer gives this person Group Admin access for this Group. Other roles are normal member access.
                </p>

                <button class="btn btn-primary lt-btn" type="submit">Update role</button>
            </form>
        </section>

        <aside class="lt-panel-grey">
            <h2 class="lt-section-title">Access instructions</h2>
            <p>Use these when someone cannot find their original email.</p>

            <form method="post" class="mb-2">
                <input type="hidden" name="action" value="send_microsoft_instructions">
                <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                <button class="btn btn-secondary lt-btn btn-block" type="submit">Send Microsoft instructions</button>
            </form>

            <form method="post" onsubmit="return confirm('Send calendar-link access instructions to this person?');">
                <input type="hidden" name="action" value="send_calendar_link">
                <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                <button class="btn btn-secondary lt-btn btn-block" type="submit">Send calendar link</button>
            </form>
        </aside>
    </div>

    <section class="lt-panel mt-4">
        <h2 class="lt-section-title">Remove or reactivate</h2>

        <?php if ((string) $person['membership_status'] === 'active'): ?>
            <p class="gm-muted">
                This makes the person inactive for <?= e($selectedGroup['group_name']) ?>. It does not delete their history or previous calendar events.
            </p>

            <form method="post" onsubmit="return confirm('Make this person inactive for this Group?');">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                <input type="hidden" name="new_status" value="inactive">
                <button class="btn btn-outline-danger lt-btn" type="submit">Make inactive</button>
            </form>
        <?php else: ?>
            <p class="gm-muted">
                Reactivating adds this person back to normal Group lists and leader selectors.
            </p>

            <form method="post">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                <input type="hidden" name="new_status" value="active">
                <button class="btn btn-primary lt-btn" type="submit">Reactivate person</button>
            </form>
        <?php endif; ?>
    </section>
</main>

<script>
(function () {
    document.querySelectorAll('.gm-copy').forEach(function (button) {
        button.addEventListener('click', function () {
            var value = button.getAttribute('data-copy') || '';

            if (!value) {
                return;
            }

            navigator.clipboard.writeText(value).then(function () {
                var original = button.textContent;
                button.textContent = 'Copied';

                window.setTimeout(function () {
                    button.textContent = original;
                }, 1500);
            });
        });
    });
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>