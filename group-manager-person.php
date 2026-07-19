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
                <?= csrf_field() ?>
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
            <?php
                // Fetch ALL active memberships for this person across all groups.
                $stmtAllMemberships = $pdo->prepare("
                    SELECT
                        gm.group_id,
                        gm.membership_role,
                        gm.status AS membership_status,
                        gm.is_primary,
                        g.group_name
                    FROM group_memberships gm
                    INNER JOIN groups g ON g.id = gm.group_id
                    WHERE gm.person_id = :person_id
                      AND gm.status = 'active'
                    ORDER BY gm.is_primary DESC, g.group_name ASC
                ");
                $stmtAllMemberships->execute(['person_id' => $personId]);
                $allMemberships = $stmtAllMemberships->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <?php if (count($allMemberships) > 1 || $isDistrictAdmin): ?>
                <h2 class="lt-section-title">All roles</h2>

                <?php if (count($allMemberships) === 0): ?>
                    <p class="gm-muted">No active memberships found.</p>
                <?php else: ?>
                    <?php foreach ($allMemberships as $membership):
                        $mGroupId = (int) $membership['group_id'];
                        $mGroupName = (string) $membership['group_name'];
                        $mRole = (string) $membership['membership_role'];
                        $mIsPrimary = (int) ($membership['is_primary'] ?? 0) === 1;
                        $mRoleOptions = gm_membership_role_options($mGroupId);
                        // Can edit if district admin, or if this is a group they manage.
                        $canEditThisRole = $isDistrictAdmin || gm_group_is_manageable($mGroupId, $manageableGroups);
                    ?>
                        <?php if ($canEditThisRole): ?>
                            <div class="mb-3" style="border:1px solid #e5e5e5;padding:.75rem;border-radius:4px;<?= $mIsPrimary ? 'border-left:4px solid #4d0b93;' : '' ?>">
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_role">
                                    <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                                    <input type="hidden" name="target_group_id" value="<?= (int) $mGroupId ?>">
                                    <input type="hidden" name="person_id" value="<?= (int) $personId ?>">

                                    <div class="form-group mb-2">
                                        <label class="mb-1" style="font-weight:bold;">
                                            <?= e($mGroupName) ?>
                                            <?php if ($mIsPrimary): ?>
                                                <span class="gm-badge gm-badge-active" style="font-size:.7rem;vertical-align:middle;">Primary</span>
                                            <?php endif; ?>
                                        </label>
                                        <select class="form-control form-control-sm" name="membership_role" required>
                                            <?php foreach ($mRoleOptions as $value => $label): ?>
                                                <option value="<?= e($value) ?>" <?= $mRole === $value ? 'selected' : '' ?>>
                                                    <?= e($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <button class="btn btn-sm btn-primary lt-btn" type="submit">Save</button>
                                </form>
                                <?php if ($isDistrictAdmin && !$mIsPrimary): ?>
                                    <form method="post" style="display:inline-block;margin:.5rem 0 0 0;" onsubmit="return confirm('Set this as the primary role for this person?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_primary">
                                        <input type="hidden" name="group_id" value="<?= (int) $mGroupId ?>">
                                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                                        <button class="btn btn-sm btn-outline-secondary lt-btn" type="submit">Set as primary</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="mb-3" style="border:1px solid #e5e5e5;padding:.75rem;border-radius:4px;background:#f9f9f9;<?= $mIsPrimary ? 'border-left:4px solid #4d0b93;' : '' ?>">
                                <label class="mb-1" style="font-weight:bold;">
                                    <?= e($mGroupName) ?>
                                    <?php if ($mIsPrimary): ?>
                                        <span class="gm-badge gm-badge-active" style="font-size:.7rem;vertical-align:middle;">Primary</span>
                                    <?php endif; ?>
                                </label>
                                <p class="mb-0"><?= e(gm_role_title_from_membership_role($mRole)) ?></p>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>

            <?php else: ?>
                <h2 class="lt-section-title">Role in this Group</h2>

                <form method="post">
                    <?= csrf_field() ?>
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
            <?php endif; ?>
        </section>

        <aside class="lt-panel-grey">
            <h2 class="lt-section-title">Access instructions</h2>
            <p>Use these to resend access instructions if someone cannot find their original email.</p>

            <?php if ((int) ($person['has_microsoft_account'] ?? 0) > 0 || $accessLabel === 'Microsoft SSO'): ?>
                <form method="post" class="mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_microsoft_instructions">
                    <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                    <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                    <button class="btn btn-secondary lt-btn btn-block" type="submit">Resend Microsoft sign-in instructions</button>
                </form>
                <p class="gm-muted" style="font-size:.85rem;">Sends an email explaining how to sign in with their District Microsoft 365 account.</p>
            <?php elseif ($accessLabel === 'Account requested'): ?>
                <div class="alert alert-info mb-2" style="font-size:.85rem;">
                    <strong>Microsoft 365 account requested.</strong><br>
                    This will be set up within 5 minutes. Login details will be sent to their personal email.
                </div>
                <form method="post" class="mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_calendar_link">
                    <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                    <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                    <button class="btn btn-secondary lt-btn btn-block" type="submit">Send calendar link in the meantime</button>
                </form>
            <?php else: ?>
                <form method="post" class="mb-3" onsubmit="return confirm('Request a Microsoft 365 account for this person? Their login details will be sent to their personal email (<?= e($person['primary_email']) ?>). Make sure this email is correct before proceeding.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="request_m365_account">
                    <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                    <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                    <button class="btn btn-primary lt-btn btn-block" type="submit">Request Microsoft 365 account</button>
                </form>
                <p class="gm-muted" style="font-size:.85rem;">
                    This will create a District Microsoft 365 account for this person within 5 minutes.
                    Their username and temporary password will be emailed to <strong><?= e($person['primary_email']) ?></strong> — make sure this is correct in their profile above.
                </p>

                <hr style="margin:1rem 0;">

                <form method="post" class="mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_calendar_link">
                    <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                    <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                    <button class="btn btn-secondary lt-btn btn-block" type="submit">Send calendar link only</button>
                </form>
                <p class="gm-muted" style="font-size:.85rem;">If this person only needs calendar access and does not need a Microsoft 365 account.</p>
            <?php endif; ?>
        </aside>
    </div>

    <?php if ($isDistrictAdmin && count($allMemberships ?? []) > 0): ?>
    <p class="gm-muted mt-2" style="font-size:.85rem;">
        The <span class="gm-badge gm-badge-active" style="font-size:.65rem;">Primary</span> role determines this person's Microsoft 365 job title and department.
    </p>
    <?php endif; ?>

    <section class="lt-panel mt-4">
        <h2 class="lt-section-title">Remove or reactivate</h2>

        <?php if ((string) $person['membership_status'] === 'active'): ?>
            <p class="gm-muted">
                This makes the person inactive for <?= e($selectedGroup['group_name']) ?>. It does not delete their history or previous calendar events.
            </p>

            <form method="post" onsubmit="return confirm('Make this person inactive for this Group?');">
                <?= csrf_field() ?>
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

    <?php if ($isDistrictAdmin && (string) $person['membership_status'] === 'active'): ?>
    <?php
        $personCurrentAccessLevel = (string) ($person['access_level'] ?? 'member');
        $isCurrentlyGroupReviewer = $personCurrentAccessLevel === 'group_reviewer';
        $hasHigherAccess = in_array($personCurrentAccessLevel, ['district_reviewer', 'district_admin', 'system_admin'], true);
    ?>
    <section class="lt-panel mt-4">
        <h2 class="lt-section-title">Event review permission</h2>

        <?php if ($hasHigherAccess): ?>
            <p class="gm-muted">
                This person has <strong><?= e(str_replace('_', ' ', $personCurrentAccessLevel)) ?></strong> access
                and can already review events across the entire District. No group-level permission is needed.
            </p>
        <?php else: ?>
            <p class="gm-muted">
                Allow this person to review and approve calendar events submitted for <strong><?= e($selectedGroup['group_name']) ?></strong>.
                They will be able to approve, reject, or request changes to events for this Group only.
            </p>

            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_group_reviewer">
                <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                <input type="hidden" name="person_id" value="<?= (int) $personId ?>">

                <label class="lt-check">
                    <input type="checkbox" name="is_group_reviewer" value="1" <?= $isCurrentlyGroupReviewer ? 'checked' : '' ?>>
                    <span>Can review and approve events for this Group</span>
                </label>

                <button class="btn btn-primary lt-btn mt-3" type="submit">Save review permission</button>
            </form>

            <?php if ($isCurrentlyGroupReviewer): ?>
                <p class="gm-muted mt-2" style="font-size:.85rem;">
                    This person will receive email notifications when events are submitted for review in this Group.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php endif; ?>
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