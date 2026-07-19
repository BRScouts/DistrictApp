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
$roleOptions = gm_membership_role_options($selectedGroupId);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate();
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

            audit_log(AUDIT_USER_EDITED, 'person', $personId, $personId, [
                'group_id' => $selectedGroupId,
                'fields_changed' => 'details',
            ], $selectedGroupId);

            $success = 'Person details saved.';
        } elseif ($action === 'update_role') {
            $membershipRole = (string) ($_POST['membership_role'] ?? '');
            $targetGroupId = (int) ($_POST['target_group_id'] ?? $selectedGroupId);

            // Can update if it's their own group, or a group they manage, or they're district admin.
            if ($targetGroupId !== $selectedGroupId && !$isDistrictAdmin && !gm_group_is_manageable($targetGroupId, $manageableGroups)) {
                throw new RuntimeException('You do not have permission to update roles in that group.');
            }

            $pdo->beginTransaction();
            gm_update_group_role($personId, $targetGroupId, $membershipRole, $actorPersonId);
            $pdo->commit();

            audit_log(AUDIT_USER_ROLE_CHANGED, 'person', $personId, $personId, [
                'group_id' => $targetGroupId,
                'membership_role' => $membershipRole,
            ], $targetGroupId);

            $success = 'Role updated.';
        } elseif ($action === 'set_status') {
            $newStatus = (string) ($_POST['new_status'] ?? 'inactive');

            $pdo->beginTransaction();
            gm_set_person_membership_status($personId, $selectedGroupId, $newStatus, $actorPersonId);
            $pdo->commit();

            audit_log(
                $newStatus === 'active' ? AUDIT_USER_REACTIVATED : AUDIT_USER_DEACTIVATED,
                'person',
                $personId,
                $personId,
                ['group_id' => $selectedGroupId, 'new_status' => $newStatus],
                $selectedGroupId
            );

            $success = $newStatus === 'active'
                ? 'Person reactivated for this Group.'
                : 'Person made inactive for this Group.';
        } elseif ($action === 'send_microsoft_instructions') {
            $person = gm_fetch_person_for_group($selectedGroupId, $personId);

            if (!$person) {
                throw new RuntimeException('Person not found.');
            }

            gm_send_microsoft_instructions($person, $selectedGroupId, $actorPersonId);

            audit_log(AUDIT_USER_INSTRUCTIONS_SENT, 'person', $personId, $personId, [
                'type' => 'microsoft_sso',
                'group_id' => $selectedGroupId,
            ], $selectedGroupId);

            $success = 'Microsoft sign-in instructions have been queued.';
        } elseif ($action === 'send_calendar_link') {
            $person = gm_fetch_person_for_group($selectedGroupId, $personId);

            if (!$person) {
                throw new RuntimeException('Person not found.');
            }

            $createdInviteUrl = gm_send_calendar_link_instructions($person, $selectedGroupId, $actorPersonId);

            audit_log(AUDIT_USER_INSTRUCTIONS_SENT, 'person', $personId, $personId, [
                'type' => 'calendar_link',
                'group_id' => $selectedGroupId,
            ], $selectedGroupId);

            $success = 'Calendar access instructions have been queued.';
        } elseif ($action === 'request_m365_account') {
            $person = gm_fetch_person_for_group($selectedGroupId, $personId);

            if (!$person) {
                throw new RuntimeException('Person not found.');
            }

            if ((int) ($person['has_microsoft_account'] ?? 0) > 0) {
                throw new RuntimeException('This person already has a Microsoft 365 account.');
            }

            if (gm_person_has_pending_account_request($personId, (string) $person['primary_email'])) {
                throw new RuntimeException('A Microsoft 365 account request is already pending for this person.');
            }

            // Split full name into first/last for email generation.
            $nameParts = explode(' ', trim((string) $person['full_name']), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';

            if ($firstName === '' || $lastName === '') {
                throw new RuntimeException('This person needs both a first and last name to generate a District email. Update their name first.');
            }

            $suggestion = gm_available_district_email($firstName, $lastName);
            $requestedUpn = strtolower(trim((string) $suggestion['email']));

            if ($requestedUpn === '' || !filter_var($requestedUpn, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Could not generate a valid District email address. Please contact a District Administrator.');
            }

            $created = gm_create_district_email_request(
                $actorPersonId,
                $personId,
                $selectedGroupId,
                $requestedUpn,
                (string) $person['primary_email'],
                'Requested from Group Manager person page by ' . (string) $user['full_name']
            );

            if (!$created) {
                throw new RuntimeException('The account request could not be saved. Please try again.');
            }

            gm_log_action($actorPersonId, 'm365_account_requested', 'person', $personId, [
                'group_id' => $selectedGroupId,
                'requested_upn' => $requestedUpn,
            ]);

            audit_log(AUDIT_ADMIN_M365_ACCOUNT_REQ, 'person', $personId, $personId, [
                'requested_email' => $requestedUpn,
                'group_id' => $selectedGroupId,
                'method' => 'person_page',
            ], $selectedGroupId);

            $success = 'Microsoft 365 account requested. This will be provisioned within 5 minutes and ' . e($person['full_name']) . ' will receive their login details to their personal email (' . e($person['primary_email']) . '). Please check their email address is correct.';
        } elseif ($action === 'set_primary') {
            if (!$isDistrictAdmin) {
                throw new RuntimeException('Only District Administrators can set a primary role.');
            }

            $targetGroupId = (int) ($_POST['group_id'] ?? $selectedGroupId);

            $pdo->beginTransaction();
            gm_set_primary_membership($personId, $targetGroupId, $actorPersonId);
            $pdo->commit();

            audit_log(AUDIT_USER_PRIMARY_SET, 'person', $personId, $personId, [
                'group_id' => $targetGroupId,
            ], $targetGroupId);

            $success = 'This membership has been set as the primary role for this person.';
        } elseif ($action === 'set_group_reviewer') {
            if (!$isDistrictAdmin) {
                throw new RuntimeException('Only District Administrators can grant or revoke event review permissions.');
            }

            $grantReviewer = isset($_POST['is_group_reviewer']) && $_POST['is_group_reviewer'] === '1';

            gm_set_group_reviewer($personId, $selectedGroupId, $grantReviewer, $actorPersonId);

            audit_log(AUDIT_USER_ROLE_CHANGED, 'person', $personId, $personId, [
                'group_id' => $selectedGroupId,
                'action' => $grantReviewer ? 'group_reviewer_granted' : 'group_reviewer_revoked',
            ], $selectedGroupId);

            $success = $grantReviewer
                ? 'Event review permission granted. This person can now review and approve events for this Group.'
                : 'Event review permission removed for this Group.';
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

$gmNavCurrent = 'people';

include __DIR__ . '/header.php';
include __DIR__ . '/app/group-manager-nav.php';
?>

<style>
    .gmp-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1.25rem;
        background: #fff;
        border: 2px solid #e5e5e5;
        margin-bottom: 1.5rem;
    }
    .gmp-avatar {
        width: 56px; height: 56px; border-radius: 50%;
        background: #4d0b93; color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.4rem; font-weight: 900; flex-shrink: 0;
    }
    .gmp-header-info h2 { margin: 0 0 .2rem; font-size: 1.25rem; font-weight: 900; color: #1d1d1b; }
    .gmp-header-meta { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; font-size: .88rem; color: #555; }
    .gmp-layout { display: grid; gap: 1.5rem; }
    @media (min-width: 992px) { .gmp-layout { grid-template-columns: minmax(0, 2fr) 340px; align-items: start; } }
    .gmp-main { display: grid; gap: 1.5rem; }
    .gmp-sidebar { display: grid; gap: 1.5rem; }
    @media (min-width: 992px) { .gmp-sidebar { position: sticky; top: 1rem; } }
    .gmp-card { background: #fff; border: 2px solid #e5e5e5; padding: 1.25rem; }
    .gmp-card-grey { background: #f7f5fb; border-color: #e5e5e5; }
    .gmp-card-title { margin: 0 0 .75rem; font-size: 1rem; font-weight: 900; color: #4d0b93; padding-bottom: .4rem; border-bottom: 2px solid #f0ecf5; }
    .gmp-badge { display: inline-block; padding: .15rem .4rem; font-weight: 900; font-size: .72rem; text-transform: uppercase; letter-spacing: .02em; }
    .gmp-badge-active { background: #d1e7dd; color: #0f5132; }
    .gmp-badge-inactive { background: #f8d7da; color: #842029; }
    .gmp-badge-sso { background: #e7f1ff; color: #004085; }
    .gmp-badge-pending { background: #fff3cd; color: #664d03; }
    .gmp-badge-link { background: #f8d7da; color: #842029; }
    .gmp-badge-reviewer { background: #e8e0f3; color: #4d0b93; }
    .gmp-badge-primary { background: #f0ecf5; color: #4d0b93; }
    .gmp-stat-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem; margin-top: .75rem; }
    .gmp-stat { text-align: center; padding: .5rem; border: 1px solid #e5e5e5; background: #fff; }
    .gmp-stat strong { display: block; font-size: 1.3rem; color: #4d0b93; line-height: 1.2; }
    .gmp-stat span { font-size: .75rem; color: #555; }
    .gmp-role-item { border: 1px solid #e5e5e5; padding: .75rem; margin-bottom: .5rem; background: #fff; }
    .gmp-role-item-primary { border-left: 4px solid #4d0b93; }
    .gmp-role-label { font-weight: 900; font-size: .9rem; margin-bottom: .3rem; }
    .gmp-muted { color: #555; font-size: .85rem; }
    .gmp-link-box { display: grid; gap: .5rem; }
    @media (min-width: 576px) { .gmp-link-box { grid-template-columns: minmax(0, 1fr) auto; } }
    .gmp-danger-zone { border: 2px solid #f8d7da; background: #fff; padding: 1.25rem; }
    .gmp-danger-zone .gmp-card-title { color: #842029; border-bottom-color: #f8d7da; }
</style>

<main class="lt-main">

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
            <div class="gmp-link-box mt-2">
                <input class="form-control" type="text" value="<?= e($createdInviteUrl) ?>" readonly>
                <button class="btn btn-secondary lt-btn gm-copy" type="button" data-copy="<?= e($createdInviteUrl) ?>">Copy</button>
            </div>
        </div>
    <?php endif; ?>

    <?php
        $personCurrentAccessLevel = (string) ($person['access_level'] ?? 'member');
        $isCurrentlyGroupReviewer = $personCurrentAccessLevel === 'group_reviewer';
        $hasHigherAccess = in_array($personCurrentAccessLevel, ['district_reviewer', 'district_admin', 'system_admin'], true);
        $initials = implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(explode(' ', trim((string) $person['full_name'])), 0, 2)));
    ?>

    <!-- Person header -->
    <div class="gmp-header">
        <div class="gmp-avatar"><?= e($initials) ?></div>
        <div class="gmp-header-info">
            <h2><?= e($person['full_name']) ?></h2>
            <div class="gmp-header-meta">
                <span><?= e(gm_role_title_from_membership_role((string) $person['membership_role'])) ?></span>
                <span>·</span>
                <?= (string) $person['membership_status'] === 'active'
                    ? '<span class="gmp-badge gmp-badge-active">Active</span>'
                    : '<span class="gmp-badge gmp-badge-inactive">Inactive</span>' ?>
                <span class="gmp-badge <?= e(match($accessLabel) { 'Microsoft SSO' => 'gmp-badge-sso', 'Account requested' => 'gmp-badge-pending', default => 'gmp-badge-link' }) ?>"><?= e($accessLabel) ?></span>
                <?php if ($isCurrentlyGroupReviewer): ?>
                    <span class="gmp-badge gmp-badge-reviewer">Reviewer</span>
                <?php endif; ?>
                <?php if ($hasHigherAccess): ?>
                    <span class="gmp-badge gmp-badge-reviewer"><?= e(ucwords(str_replace('_', ' ', $personCurrentAccessLevel))) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Main layout -->
    <div class="gmp-layout">
        <div class="gmp-main">

            <!-- Details -->
            <div class="gmp-card">
                <h3 class="gmp-card-title">Details</h3>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_details">
                    <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                    <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                    <div class="form-group">
                        <label for="full_name">Name</label>
                        <input class="form-control" type="text" id="full_name" name="full_name" value="<?= e($person['full_name']) ?>" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-7">
                            <label for="primary_email">Email</label>
                            <input class="form-control" type="email" id="primary_email" name="primary_email" value="<?= e($person['primary_email']) ?>" required>
                        </div>
                        <div class="form-group col-md-5">
                            <label for="phone">Phone</label>
                            <input class="form-control" type="text" id="phone" name="phone" value="<?= e($person['phone'] ?? '') ?>">
                        </div>
                    </div>
                    <label class="lt-check">
                        <input type="checkbox" name="visible_in_directory" value="1" <?= (int) ($person['visible_in_directory'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span>Show in District Directory</span>
                    </label>
                    <label class="lt-check mt-2">
                        <input type="checkbox" name="share_phone" value="1" <?= (int) ($person['share_phone'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span>Show phone in Directory</span>
                    </label>
                    <button class="btn btn-primary lt-btn mt-3" type="submit">Save details</button>
                </form>
            </div>

            <!-- Roles -->
            <div class="gmp-card">
                <?php
                    $stmtAllMemberships = $pdo->prepare("
                        SELECT gm.group_id, gm.membership_role, gm.access_level, gm.status AS membership_status, gm.is_primary, g.group_name
                        FROM group_memberships gm
                        INNER JOIN groups g ON g.id = gm.group_id
                        WHERE gm.person_id = :person_id AND gm.status = 'active'
                        ORDER BY gm.is_primary DESC, g.group_name ASC
                    ");
                    $stmtAllMemberships->execute(['person_id' => $personId]);
                    $allMemberships = $stmtAllMemberships->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <?php if (count($allMemberships) > 1 || $isDistrictAdmin): ?>
                    <h3 class="gmp-card-title">Roles across Groups</h3>

                    <?php foreach ($allMemberships as $membership):
                        $mGroupId = (int) $membership['group_id'];
                        $mGroupName = (string) $membership['group_name'];
                        $mRole = (string) $membership['membership_role'];
                        $mAccessLevel = (string) ($membership['access_level'] ?? 'member');
                        $mIsPrimary = (int) ($membership['is_primary'] ?? 0) === 1;
                        $mRoleOptions = gm_membership_role_options($mGroupId);
                        $canEditThisRole = $isDistrictAdmin || gm_group_is_manageable($mGroupId, $manageableGroups);
                    ?>
                        <div class="gmp-role-item <?= $mIsPrimary ? 'gmp-role-item-primary' : '' ?>">
                            <div class="gmp-role-label">
                                <?= e($mGroupName) ?>
                                <?php if ($mIsPrimary): ?><span class="gmp-badge gmp-badge-primary">Primary</span><?php endif; ?>
                                <?php if ($mAccessLevel === 'group_reviewer'): ?><span class="gmp-badge gmp-badge-reviewer">Reviewer</span><?php endif; ?>
                            </div>
                            <?php if ($canEditThisRole): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_role">
                                    <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                                    <input type="hidden" name="target_group_id" value="<?= (int) $mGroupId ?>">
                                    <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                                    <div class="form-group mb-2">
                                        <select class="form-control form-control-sm" name="membership_role" required>
                                            <?php foreach ($mRoleOptions as $value => $label): ?>
                                                <option value="<?= e($value) ?>" <?= $mRole === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button class="btn btn-sm btn-primary lt-btn" type="submit">Save</button>
                                </form>
                                <?php if ($isDistrictAdmin && !$mIsPrimary): ?>
                                    <form method="post" style="display:inline-block;margin-top:.4rem;" onsubmit="return confirm('Set this as the primary role?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_primary">
                                        <input type="hidden" name="group_id" value="<?= (int) $mGroupId ?>">
                                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                                        <button class="btn btn-sm btn-outline-secondary lt-btn" type="submit">Set as primary</button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="mb-0 gmp-muted"><?= e(gm_role_title_from_membership_role($mRole)) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($isDistrictAdmin): ?>
                        <p class="gmp-muted mt-2" style="font-size:.8rem;">
                            The <span class="gmp-badge gmp-badge-primary">Primary</span> role determines Microsoft 365 job title and department.
                        </p>
                    <?php endif; ?>

                <?php else: ?>
                    <h3 class="gmp-card-title">Role in this Group</h3>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_role">
                        <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                        <div class="form-group">
                            <label for="membership_role">Role</label>
                            <select class="form-control" id="membership_role" name="membership_role" required>
                                <?php foreach ($roleOptions as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= (string) $person['membership_role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="gmp-muted">Group Lead Volunteer gives this person Group Admin access. Other roles have standard member access.</p>
                        <button class="btn btn-primary lt-btn" type="submit">Update role</button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Event review permission (district admin only) -->
            <?php if ($isDistrictAdmin && (string) $person['membership_status'] === 'active'): ?>
            <div class="gmp-card">
                <h3 class="gmp-card-title">Event review permission</h3>
                <?php if ($hasHigherAccess): ?>
                    <p class="gmp-muted">
                        This person has <strong><?= e(str_replace('_', ' ', $personCurrentAccessLevel)) ?></strong> access
                        and can already review events across the entire District.
                    </p>
                <?php else: ?>
                    <p class="gmp-muted">Allow this person to review and approve calendar events for <strong><?= e($selectedGroup['group_name']) ?></strong>.</p>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_group_reviewer">
                        <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                        <label class="lt-check">
                            <input type="checkbox" name="is_group_reviewer" value="1" <?= $isCurrentlyGroupReviewer ? 'checked' : '' ?>>
                            <span>Can review and approve events for this Group</span>
                        </label>
                        <button class="btn btn-primary lt-btn mt-3" type="submit">Save</button>
                    </form>
                    <?php if ($isCurrentlyGroupReviewer): ?>
                        <p class="gmp-muted mt-2" style="font-size:.82rem;">This person receives email notifications when events are submitted for this Group.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>

        <!-- Sidebar -->
        <div class="gmp-sidebar">

            <!-- Stats -->
            <div class="gmp-card gmp-card-grey">
                <h3 class="gmp-card-title">Activity</h3>
                <div class="gmp-stat-row">
                    <div class="gmp-stat"><strong><?= (int) $person['total_events'] ?></strong><span>Events</span></div>
                    <div class="gmp-stat"><strong><?= (int) $person['in_review_events'] ?></strong><span>In review</span></div>
                    <div class="gmp-stat"><strong><?= (int) $person['approved_events'] ?></strong><span>Approved</span></div>
                </div>
            </div>

            <!-- Access & instructions -->
            <div class="gmp-card">
                <h3 class="gmp-card-title">Access &amp; instructions</h3>
                <?php if ((int) ($person['has_microsoft_account'] ?? 0) > 0 || $accessLabel === 'Microsoft SSO'): ?>
                    <p class="gmp-muted">This person signs in with Microsoft 365.</p>
                    <form method="post" class="mb-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="send_microsoft_instructions">
                        <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                        <button class="btn btn-secondary lt-btn btn-block" type="submit">Resend sign-in instructions</button>
                    </form>
                <?php elseif ($accessLabel === 'Account requested'): ?>
                    <div class="alert alert-info mb-2" style="font-size:.85rem;">
                        <strong>Microsoft 365 account requested.</strong><br>This will be set up within 5 minutes.
                    </div>
                    <form method="post" class="mb-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="send_calendar_link">
                        <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                        <button class="btn btn-secondary lt-btn btn-block" type="submit">Send calendar link meanwhile</button>
                    </form>
                <?php else: ?>
                    <form method="post" class="mb-3" onsubmit="return confirm('Request a Microsoft 365 account? Login details will be sent to <?= e($person['primary_email']) ?>.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="request_m365_account">
                        <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                        <button class="btn btn-primary lt-btn btn-block" type="submit">Request Microsoft 365 account</button>
                    </form>
                    <p class="gmp-muted" style="font-size:.82rem;">Credentials sent to <strong><?= e($person['primary_email']) ?></strong>.</p>
                    <hr style="margin:.75rem 0;">
                    <form method="post" class="mb-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="send_calendar_link">
                        <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                        <button class="btn btn-secondary lt-btn btn-block" type="submit">Send calendar link only</button>
                    </form>
                    <p class="gmp-muted" style="font-size:.82rem;">For people who only need calendar access.</p>
                <?php endif; ?>
            </div>

            <!-- Membership / remove -->
            <div class="gmp-danger-zone">
                <h3 class="gmp-card-title">Membership</h3>
                <?php if ((string) $person['membership_status'] === 'active'): ?>
                    <p class="gmp-muted">Making someone inactive removes them from Group lists. Their history is preserved.</p>
                    <form method="post" onsubmit="return confirm('Make this person inactive for this Group?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                        <input type="hidden" name="new_status" value="inactive">
                        <button class="btn btn-outline-danger lt-btn btn-block" type="submit">Make inactive</button>
                    </form>
                <?php else: ?>
                    <p class="gmp-muted">Reactivating adds this person back to Group lists.</p>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                        <input type="hidden" name="new_status" value="active">
                        <button class="btn btn-primary lt-btn btn-block" type="submit">Reactivate</button>
                    </form>
                <?php endif; ?>
            </div>

        </div>
    </div>
</main>

<script>
(function () {
    document.querySelectorAll('.gm-copy').forEach(function (button) {
        button.addEventListener('click', function () {
            var value = button.getAttribute('data-copy') || '';
            if (!value) return;
            navigator.clipboard.writeText(value).then(function () {
                var original = button.textContent;
                button.textContent = 'Copied';
                window.setTimeout(function () { button.textContent = original; }, 1500);
            });
        });
    });
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>