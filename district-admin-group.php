<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/district-admin-helpers.php';

require_login();

$pdo = db();
$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

$memberships = da_current_memberships((int) $user['id']);
$actorAccessLevel = da_current_access_level($user, $memberships);
$actorIsAdmin = da_is_district_admin($actorAccessLevel);
$actorPersonId = (int) $user['id'];

if (!$actorIsAdmin) {
    http_response_code(403);

    $pageTitle = 'District Admin | ' . $appName;
    $heroTitle = 'District Admin';
    $heroText = 'This area is for District administrators only.';
    $breadcrumb = '<a href="/index.php">Home</a> / <a href="/district-admin.php">District Admin</a> / Group';

    include __DIR__ . '/header.php';

    echo '<main class="lt-main"><div class="alert alert-danger"><strong>Access denied:</strong> You must be a District Admin or System Admin to use this page.</div></main>';

    include __DIR__ . '/footer.php';
    exit;
}

$groupId = (int) ($_GET['group_id'] ?? $_POST['group_id'] ?? 0);
$group = $groupId > 0 ? da_fetch_group($groupId) : null;

$errors = [];
$success = null;
$newLinkUrl = null;
$userSearch = trim((string) ($_GET['user_search'] ?? $_POST['user_search'] ?? ''));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create_group') {
            $pdo->beginTransaction();

            $groupId = da_create_group($_POST, $actorPersonId);

            $leadPersonId = (int) ($_POST['lead_person_id'] ?? 0);

            if ($leadPersonId > 0) {
                da_assign_group_editor(
                    $leadPersonId,
                    $groupId,
                    'group_lead_volunteer',
                    $actorPersonId
                );
            }

            if (isset($_POST['create_group_link'])) {
                $newLinkUrl = da_generate_group_link(
                    $groupId,
                    $actorPersonId,
                    'Main Group calendar link',
                    true
                );
            }

            $pdo->commit();

            $group = da_fetch_group($groupId);

            audit_log(AUDIT_GROUP_CREATED, 'group', $groupId, null, [
                'group_name' => $group['group_name'] ?? '',
                'lead_person_id' => $leadPersonId ?: null,
            ], $groupId);

            $success = 'Group created.';
        } elseif ($action === 'update_group') {
            da_update_group_details($groupId, $_POST, $actorPersonId);

            $group = da_fetch_group($groupId);

            audit_log(AUDIT_GROUP_UPDATED, 'group', $groupId, null, [
                'group_name' => $group['group_name'] ?? '',
            ], $groupId);

            $success = 'Group details saved.';
        } elseif ($action === 'set_group_status') {
            $newStatus = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

            da_set_group_status($groupId, $newStatus, $actorPersonId);

            $group = da_fetch_group($groupId);

            audit_log(AUDIT_GROUP_STATUS_CHANGED, 'group', $groupId, null, [
                'new_status' => $newStatus === 1 ? 'active' : 'inactive',
            ], $groupId);

            $success = $newStatus === 1 ? 'Group reactivated.' : 'Group deactivated.';
        } elseif ($action === 'assign_group_editor') {
            $personId = (int) ($_POST['person_id'] ?? 0);
            $editorRole = (string) ($_POST['editor_role'] ?? 'group_editor');

            da_assign_group_editor(
                $personId,
                $groupId,
                $editorRole,
                $actorPersonId
            );

            $group = da_fetch_group($groupId);

            audit_log(AUDIT_GROUP_EDITOR_ASSIGNED, 'group', $groupId, $personId, [
                'editor_role' => $editorRole,
            ], $groupId);

            $success = 'Group editor permission assigned.';
        } elseif ($action === 'remove_group_editor') {
            $personId = (int) ($_POST['person_id'] ?? 0);

            da_remove_group_editor($personId, $groupId, $actorPersonId);

            $group = da_fetch_group($groupId);

            audit_log(AUDIT_GROUP_EDITOR_REMOVED, 'group', $groupId, $personId, [
                'person_id' => $personId,
            ], $groupId);

            $success = 'Group editor permission removed.';
        } elseif ($action === 'generate_group_link') {
            $label = trim((string) ($_POST['label'] ?? 'Main Group calendar link'));
            $disableExisting = isset($_POST['disable_existing']);

            $newLinkUrl = da_generate_group_link(
                $groupId,
                $actorPersonId,
                $label,
                $disableExisting
            );

            $group = da_fetch_group($groupId);

            audit_log(
                $disableExisting ? AUDIT_GROUP_LINK_ROTATED : AUDIT_GROUP_LINK_CREATED,
                'group',
                $groupId,
                null,
                ['label' => $label, 'disabled_existing' => $disableExisting],
                $groupId
            );

            $success = $disableExisting
                ? 'Group calendar link rotated. Existing active links were disabled.'
                : 'New Group calendar link generated.';
        } elseif ($action === 'disable_group_link') {
            $linkId = (int) ($_POST['link_id'] ?? 0);

            if ($linkId < 1 || $groupId < 1) {
                throw new RuntimeException('Choose a Group link to disable.');
            }

            $stmt = $pdo->prepare("
                UPDATE group_access_links
                SET status = 'disabled'
                WHERE id = :link_id
                  AND group_id = :group_id
            ");
            $stmt->execute([
                'link_id' => $linkId,
                'group_id' => $groupId,
            ]);

            da_log_action($actorPersonId, 'group_link_disabled', 'group', $groupId, [
                'link_id' => $linkId,
            ]);

            audit_log(AUDIT_GROUP_LINK_DISABLED, 'group', $groupId, null, [
                'link_id' => $linkId,
            ], $groupId);

            $group = da_fetch_group($groupId);
            $success = 'Group calendar link disabled.';
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $errors[] = $e->getMessage() ?: 'The request could not be completed.';
}

$isNewGroup = $groupId < 1 || !$group;
$activePeople = da_fetch_active_people($userSearch, 150);
$groupEditors = !$isNewGroup ? da_fetch_group_editors($groupId) : [];
$groupLinks = !$isNewGroup ? da_fetch_group_links($groupId) : [];

$pageTitle = ($isNewGroup ? 'Add Group' : 'Edit Group') . ' | ' . $appName;
$heroTitle = $isNewGroup ? 'Add Group' : 'Edit Group';
$heroText = $isNewGroup
    ? 'Create a new Group, assign the first editor, and generate its calendar link.'
    : 'Edit Group details, calendar links, and people who can manage this Group.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/district-admin.php">District Admin</a> / ' . ($isNewGroup ? 'Add Group' : 'Edit Group');

include __DIR__ . '/header.php';
?>

<style>
    .da-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 992px) {
        .da-grid-2 {
            grid-template-columns: minmax(0, 2fr) minmax(320px, 1fr);
        }
    }

    .da-subnav {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        margin-bottom: 1rem;
    }

    .da-muted {
        color: #555;
    }

    .da-table-wrap {
        overflow-x: auto;
    }

    .da-badge {
        display: inline-block;
        padding: .2rem .45rem;
        font-weight: 900;
        font-size: .78rem;
    }

    .da-badge-active {
        background: #d1e7dd;
        color: #0f5132;
    }

    .da-badge-inactive {
        background: #f8d7da;
        color: #842029;
    }

    .da-badge-admin {
        background: #e7f1ff;
        color: #084298;
    }

    .da-link-box {
        display: grid;
        gap: .5rem;
    }

    @media (min-width: 768px) {
        .da-link-box {
            grid-template-columns: minmax(0, 1fr) auto;
        }
    }

    .da-danger-panel {
        border: 2px solid #842029;
        background: #fff;
        padding: 1rem;
    }

    .da-action-row {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
    }
</style>

<main class="lt-main">
    <div class="da-subnav">
        <a class="btn btn-secondary lt-btn" href="/district-admin.php">Back to Groups</a>

        <?php if (!$isNewGroup): ?>
            <a class="btn btn-secondary lt-btn" href="/group-manager.php?group_id=<?= (int) $groupId ?>">Manage people</a>
            <a class="btn btn-secondary lt-btn" href="/dc/index.php?group_id=<?= (int) $groupId ?>">Open calendar</a>
        <?php endif; ?>
    </div>

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

    <?php if ($newLinkUrl): ?>
        <div class="alert alert-info">
            <strong>New Group calendar link:</strong>
            <div class="da-link-box mt-2">
                <input class="form-control" type="text" value="<?= e($newLinkUrl) ?>" readonly>
                <button class="btn btn-secondary lt-btn da-copy" type="button" data-copy="<?= e($newLinkUrl) ?>">Copy</button>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isNewGroup): ?>
        <div class="da-grid da-grid-2">
            <section class="lt-panel">
                <h2 class="lt-section-title">Group details</h2>
                <p class="lt-lede">
                    Add the core Group record first. You can add leaders after this from Group Manager.
                </p>

                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_group">

                    <div class="form-group">
                        <label for="group_name">Group name</label>
                        <input class="form-control" type="text" id="group_name" name="group_name" placeholder="Example: 1st Irwell Valley Scout Group" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="public_email">Public/contact email</label>
                            <input class="form-control" type="email" id="public_email" name="public_email">
                        </div>

                        <div class="form-group col-md-6">
                            <label for="website_url">Website URL</label>
                            <input class="form-control" type="url" id="website_url" name="website_url">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-8">
                            <label for="meeting_place">Meeting place</label>
                            <input class="form-control" type="text" id="meeting_place" name="meeting_place">
                        </div>

                        <div class="form-group col-md-4">
                            <label for="postcode">Postcode</label>
                            <input class="form-control" type="text" id="postcode" name="postcode">
                        </div>
                    </div>

                    <hr>

                    <div class="form-group">
                        <label for="lead_person_id">First person who can edit this Group</label>
                        <select class="form-control" id="lead_person_id" name="lead_person_id">
                            <option value="">Assign later</option>
                            <?php foreach ($activePeople as $person): ?>
                                <option value="<?= (int) $person['id'] ?>">
                                    <?= e($person['full_name'] . ' — ' . $person['primary_email']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <label class="lt-check mb-3">
                        <input type="checkbox" name="create_group_link" value="1" checked>
                        <span>Generate the main Group calendar link now</span>
                    </label>

                    <button class="btn btn-primary lt-btn" type="submit">Create Group</button>
                </form>
            </section>

            <aside class="lt-panel-grey">
                <h2 class="lt-section-title">What happens next</h2>
                <ol class="pl-3 font-weight-bold">
                    <li>The Group is created.</li>
                    <li>The selected person can edit the Group in Group Manager.</li>
                    <li>The Group calendar link can be shared with the Group.</li>
                    <li>More leaders can be added from Group Manager.</li>
                </ol>
            </aside>
        </div>
    <?php else: ?>
        <div class="da-grid da-grid-2">
            <section class="lt-panel">
                <h2 class="lt-section-title">Group details</h2>

                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_group">
                    <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">

                    <div class="form-group">
                        <label for="group_name">Group name</label>
                        <input class="form-control" type="text" id="group_name" name="group_name" value="<?= e($group['group_name'] ?? $group['name'] ?? '') ?>" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="public_email">Public/contact email</label>
                            <input class="form-control" type="email" id="public_email" name="public_email" value="<?= e($group['public_email'] ?? $group['contact_email'] ?? '') ?>">
                        </div>

                        <div class="form-group col-md-6">
                            <label for="website_url">Website URL</label>
                            <input class="form-control" type="url" id="website_url" name="website_url" value="<?= e($group['website_url'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-8">
                            <label for="meeting_place">Meeting place</label>
                            <input class="form-control" type="text" id="meeting_place" name="meeting_place" value="<?= e($group['meeting_place'] ?? '') ?>">
                        </div>

                        <div class="form-group col-md-4">
                            <label for="postcode">Postcode</label>
                            <input class="form-control" type="text" id="postcode" name="postcode" value="<?= e($group['postcode'] ?? '') ?>">
                        </div>
                    </div>

                    <button class="btn btn-primary lt-btn" type="submit">Save Group details</button>
                </form>
            </section>

            <aside class="lt-panel-grey">
                <h2 class="lt-section-title">Group status</h2>

                <p>
                    Current status:
                    <?= (int) ($group['is_active'] ?? 0) === 1
                        ? '<span class="da-badge da-badge-active">Active</span>'
                        : '<span class="da-badge da-badge-inactive">Inactive</span>' ?>
                </p>

                <p class="da-muted">
                    Deactivating a Group hides it from normal active Group lists. It does not delete people, events, or audit history.
                </p>

                <form method="post" onsubmit="return confirm('Change this Group status?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_group_status">
                    <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                    <input type="hidden" name="is_active" value="<?= (int) ($group['is_active'] ?? 0) === 1 ? 0 : 1 ?>">

                    <?php if ((int) ($group['is_active'] ?? 0) === 1): ?>
                        <button class="btn btn-outline-danger lt-btn" type="submit">Deactivate Group</button>
                    <?php else: ?>
                        <button class="btn btn-primary lt-btn" type="submit">Reactivate Group</button>
                    <?php endif; ?>
                </form>
            </aside>
        </div>

        <section class="lt-panel mt-4">
            <h2 class="lt-section-title">People who can edit this Group</h2>
            <p class="lt-lede">
                These people get Group Admin access for this Group. They can use Group Manager for this Group.
            </p>

            <form method="get" class="form-row align-items-end mb-3">
                <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">

                <div class="form-group col-md-8">
                    <label for="user_search">Search people</label>
                    <input class="form-control" type="search" id="user_search" name="user_search" value="<?= e($userSearch) ?>" placeholder="Name or email">
                </div>

                <div class="form-group col-md-4">
                    <button class="btn btn-secondary lt-btn btn-block" type="submit">Search</button>
                </div>
            </form>

            <form method="post" class="lt-panel-grey mb-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assign_group_editor">
                <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">

                <div class="form-row align-items-end">
                    <div class="form-group col-md-7">
                        <label for="person_id">Person</label>
                        <select class="form-control" id="person_id" name="person_id" required>
                            <option value="">Choose active person</option>
                            <?php foreach ($activePeople as $person): ?>
                                <option value="<?= (int) $person['id'] ?>">
                                    <?= e($person['full_name'] . ' — ' . $person['primary_email']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-3">
                        <label for="editor_role">Role</label>
                        <select class="form-control" id="editor_role" name="editor_role">
                            <option value="group_editor">Group editor</option>
                            <option value="group_lead_volunteer">Group Lead Volunteer</option>
                        </select>
                    </div>

                    <div class="form-group col-md-2">
                        <button class="btn btn-primary lt-btn btn-block" type="submit">Assign</button>
                    </div>
                </div>
            </form>

            <?php if ($groupEditors): ?>
                <div class="da-table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Person</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Permission</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($groupEditors as $editor): ?>
                            <tr>
                                <td><strong><?= e($editor['full_name']) ?></strong></td>
                                <td><?= e($editor['primary_email']) ?></td>
                                <td>
                                    <?= e($editor['membership_role'] === 'group_lead_volunteer' ? 'Group Lead Volunteer' : 'Group editor') ?>
                                </td>
                                <td>
                                    <span class="da-badge da-badge-admin">
                                        <?= e(str_replace('_', ' ', (string) $editor['access_level'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Remove this person’s edit permission for this Group?');">
                                        <input type="hidden" name="action" value="remove_group_editor">
                                        <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                                        <input type="hidden" name="person_id" value="<?= (int) $editor['id'] ?>">
                                        <button class="btn btn-outline-danger btn-sm" type="submit">Remove edit permission</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-warning mb-0">
                    No one has edit permission for this Group yet.
                </div>
            <?php endif; ?>
        </section>

        <section class="lt-panel mt-4">
            <h2 class="lt-section-title">Group calendar links</h2>
            <p class="lt-lede">
                Generate or rotate the unique link leaders use to enter this Group’s calendar.
            </p>

            <form method="post" class="lt-panel-grey mb-4">
                <input type="hidden" name="action" value="generate_group_link">
                <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">

                <div class="form-group">
                    <label for="label">Link label</label>
                    <input class="form-control" type="text" id="label" name="label" value="Main Group calendar link">
                </div>

                <label class="lt-check mb-3">
                    <input type="checkbox" name="disable_existing" value="1" checked>
                    <span>Disable existing active links for this Group</span>
                </label>

                <button class="btn btn-primary lt-btn" type="submit">Generate link</button>
            </form>

            <?php if ($groupLinks): ?>
                <div class="da-table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th>Status</th>
                                <th>Link</th>
                                <th>Created</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($groupLinks as $link): ?>
                            <?php $url = da_group_link_url($link); ?>

                            <tr>
                                <td><?= e($link['label'] ?? 'Group calendar link') ?></td>
                                <td><?= e($link['status'] ?? '') ?></td>
                                <td>
                                    <?php if ($url): ?>
                                        <div class="da-link-box">
                                            <input class="form-control" type="text" value="<?= e($url) ?>" readonly>
                                            <button class="btn btn-secondary btn-sm da-copy" type="button" data-copy="<?= e($url) ?>">Copy</button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-warning font-weight-bold">No visible token. Rotate this link.</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($link['created_at'] ?? '—') ?></td>
                                <td>
                                    <?php if (($link['status'] ?? '') === 'active'): ?>
                                        <form method="post" onsubmit="return confirm('Disable this Group calendar link?');">
                                            <input type="hidden" name="action" value="disable_group_link">
                                            <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                                            <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                                            <button class="btn btn-outline-danger btn-sm" type="submit">Disable</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-warning mb-0">
                    No Group calendar links exist yet.
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<script <?= csp_nonce_attr() ?>>
(function () {
    document.querySelectorAll('.da-copy').forEach(function (button) {
        button.addEventListener('click', function () {
            var value = button.getAttribute('data-copy') || '';

            if (!value) {
                return;
            }

            navigator.clipboard.writeText(value).then(function () {
                var old = button.textContent;
                button.textContent = 'Copied';

                window.setTimeout(function () {
                    button.textContent = old;
                }, 1500);
            });
        });
    });
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>