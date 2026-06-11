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

    $pageTitle = 'Add person | ' . $appName;
    $heroTitle = 'Add person';
    $heroText = 'This area is for Group Lead Volunteers and District administrators.';
    $breadcrumb = '<a href="/index.php">Home</a> / <a href="/group-manager.php">Group Manager</a> / Add person';

    include __DIR__ . '/header.php';
    echo '<main class="lt-main"><div class="alert alert-danger"><strong>Access denied:</strong> You do not currently manage any Groups.</div></main>';
    include __DIR__ . '/footer.php';
    exit;
}

$selectedGroupId = gm_selected_group_id($manageableGroups);
$selectedGroup = gm_fetch_group($selectedGroupId);

if (!$selectedGroup) {
    http_response_code(404);
    include __DIR__ . '/header.php';
    echo '<main class="lt-main"><div class="alert alert-danger">Group not found.</div></main>';
    include __DIR__ . '/footer.php';
    exit;
}

$errors = [];
$success = null;
$createdInviteUrl = null;
$createdPersonId = 0;
$posted = [];
$districtEmailSuggestion = null;
$graphChecked = false;
$actorPersonId = (int) $user['id'];
$roleOptions = gm_membership_role_options();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'add_person');
    $posted = $_POST;

    try {
        if (!gm_group_is_manageable($selectedGroupId, $manageableGroups)) {
            throw new RuntimeException('You do not have permission to manage that Group.');
        }

        if ($action === 'suggest_email') {
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));

            if ($firstName === '' || $lastName === '') {
                $errors[] = 'Enter the person\'s first and last name before checking the District email.';
            } else {
                $suggestion = gm_available_district_email($firstName, $lastName);
                $districtEmailSuggestion = $suggestion['email'];
                $graphChecked = (bool) $suggestion['checked_graph'];
                $posted['requested_district_email'] = $districtEmailSuggestion;

                $success = $graphChecked
                    ? 'District email checked and suggested.'
                    : 'District email suggested from local records.';
            }
        } elseif ($action === 'add_person') {
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $fullName = trim($firstName . ' ' . $lastName);
            $personalEmail = strtolower(trim((string) ($_POST['personal_email'] ?? '')));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $membershipRole = (string) ($_POST['membership_role'] ?? 'section_leader');
            $visibleInDirectory = isset($_POST['visible_in_directory']) ? 1 : 0;
            $sharePhone = isset($_POST['share_phone']) ? 1 : 0;
            $useCalendarLinkOnly = isset($_POST['use_calendar_link_only']);
            $requestedDistrictEmail = strtolower(trim((string) ($_POST['requested_district_email'] ?? '')));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($firstName === '') {
                $errors[] = 'Enter the person\'s first name.';
            }

            if ($lastName === '') {
                $errors[] = 'Enter the person\'s last name.';
            }

            if ($personalEmail === '' || !filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid personal or Scouting email address.';
            }

            if (!array_key_exists($membershipRole, $roleOptions)) {
                $errors[] = 'Choose a valid role.';
            }

            if (!$useCalendarLinkOnly) {
                if ($requestedDistrictEmail === '') {
                    $suggestion = gm_available_district_email($firstName, $lastName);
                    $requestedDistrictEmail = $suggestion['email'];
                    $posted['requested_district_email'] = $requestedDistrictEmail;
                }

                if (!filter_var($requestedDistrictEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'The suggested District email address is not valid.';
                }
            }

            if (!$errors) {
                $existingPerson = gm_find_person_by_email($personalEmail);

                $pdo->beginTransaction();

                if ($existingPerson) {
                    $personId = (int) $existingPerson['id'];

                    $stmt = $pdo->prepare("
                        UPDATE people
                        SET full_name = CASE
                                WHEN full_name IS NULL OR full_name = '' THEN :full_name
                                ELSE full_name
                            END,
                            phone = COALESCE(NULLIF(phone, ''), :phone),
                            status = 'active'
                        WHERE id = :person_id
                    ");
                    $stmt->execute([
                        'full_name' => $fullName,
                        'phone' => $phone !== '' ? $phone : null,
                        'person_id' => $personId,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO people (
                            full_name,
                            primary_email,
                            phone,
                            status
                        )
                        VALUES (
                            :full_name,
                            :primary_email,
                            :phone,
                            'active'
                        )
                    ");
                    $stmt->execute([
                        'full_name' => $fullName,
                        'primary_email' => $personalEmail,
                        'phone' => $phone !== '' ? $phone : null,
                    ]);

                    $personId = (int) $pdo->lastInsertId();
                }

                gm_upsert_membership($personId, $selectedGroupId, $membershipRole);
                gm_upsert_directory_profile(
                    $personId,
                    gm_role_title_from_membership_role($membershipRole),
                    $visibleInDirectory,
                    $sharePhone
                );

                $requestRecorded = false;

                if (!$useCalendarLinkOnly) {
                    $requestRecorded = gm_create_district_email_request(
                        $actorPersonId,
                        $personId,
                        $selectedGroupId,
                        $requestedDistrictEmail,
                        $personalEmail,
                        $notes
                    );

                    $ssoUrl = gm_absolute_url('/auth/microsoft-start.php');
                    $dashboardUrl = gm_absolute_url('/index.php');
                    $calendarUrl = gm_absolute_url('/dc/');

                    gm_queue_email_and_log(
                        $personId,
                        $personalEmail,
                        $fullName,
                        'Your Irwell Valley District Microsoft 365 account request',
                        "Hello {$firstName},\n\n"
                        . "Your Group Lead Volunteer has requested a District Microsoft 365 account for you.\n\n"
                        . "Requested address: {$requestedDistrictEmail}\n\n"
                        . "Once the account is created, use the Microsoft sign-in button to access the District Dashboard and District Calendar.\n\n"
                        . "Sign in with Microsoft:\n{$ssoUrl}\n\n"
                        . "Dashboard:\n{$dashboardUrl}\n\n"
                        . "District Calendar:\n{$calendarUrl}\n\n"
                        . "Irwell Valley Scout District",
                        'district_account_requested'
                    );
                } else {
                    $createdInviteUrl = gm_create_unique_invite($actorPersonId, $personId, $selectedGroupId);

                    gm_queue_email_and_log(
                        $personId,
                        $personalEmail,
                        $fullName,
                        'Your Irwell Valley District Calendar access',
                        "Hello {$firstName},\n\n"
                        . "Your Group Lead Volunteer has added you to the District Calendar.\n\n"
                        . "Access the calendar here:\n{$createdInviteUrl}\n\n"
                        . "If you later receive a District Microsoft 365 account, please use the Microsoft sign-in button instead.\n\n"
                        . "Irwell Valley Scout District",
                        'group_calendar_invite'
                    );
                }

                gm_log_action($actorPersonId, $existingPerson ? 'group_person_linked' : 'group_person_created', 'person', $personId, [
                    'group_id' => $selectedGroupId,
                    'membership_role' => $membershipRole,
                    'district_account_requested' => !$useCalendarLinkOnly,
                    'requested_district_email' => $requestedDistrictEmail,
                    'request_recorded' => $requestRecorded,
                ]);

                $pdo->commit();

                $createdPersonId = $personId;
                $success = $existingPerson
                    ? 'Existing person found by email and linked to this Group.'
                    : 'Person added to this Group.';

                if (!$useCalendarLinkOnly) {
                    $success .= ' A Microsoft 365 account request and welcome email have been queued.';
                } else {
                    $success .= ' A calendar access email has been queued.';
                }

                $posted = [];
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $errors[] = $e->getMessage() ?: 'The request could not be completed.';
    }
}

if (!$districtEmailSuggestion && !empty($posted['first_name']) && !empty($posted['last_name']) && !isset($posted['use_calendar_link_only'])) {
    $districtEmailSuggestion = (string) ($posted['requested_district_email'] ?? gm_district_email_candidate((string) $posted['first_name'], (string) $posted['last_name']));
}

$pageTitle = 'Add person | ' . $appName;
$heroTitle = 'Add person';
$heroText = 'Add a leader to ' . (string) $selectedGroup['group_name'] . ' and start the right access route.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/group-manager.php?group_id=' . $selectedGroupId . '">Group Manager</a> / Add person';
$districtEmailDomain = gm_default_district_email_domain();

include __DIR__ . '/header.php';
?>

<style>
    .gm-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 992px) {
        .gm-grid-2 {
            grid-template-columns: minmax(0, 2fr) minmax(300px, 1fr);
        }
    }

    .gm-subnav {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        margin-bottom: 1rem;
    }

    .gm-flow-step {
        border-left: .45rem solid var(--iv-purple);
        padding: 1rem;
        background: #fff;
        margin-bottom: 1rem;
        box-shadow: 0 1px 0 rgba(0, 0, 0, .08);
    }

    .gm-flow-step h3 {
        margin-top: 0;
    }

    .gm-suggested-email {
        font-size: 1.15rem;
        font-weight: 900;
        color: var(--iv-purple);
        word-break: break-word;
    }

    .gm-muted {
        color: #555;
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
    <div class="gm-subnav">
        <a class="btn btn-secondary lt-btn" href="/group-manager.php?group_id=<?= (int) $selectedGroupId ?>">Back to Group Manager</a>
        <a class="btn btn-secondary lt-btn" href="/group-manager-inactive.php?group_id=<?= (int) $selectedGroupId ?>">Inactive people</a>
        <a class="btn btn-secondary lt-btn" href="/group-manager-access.php?group_id=<?= (int) $selectedGroupId ?>">Calendar access</a>
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
        <div class="alert alert-success">
            <?= e($success) ?>
            <?php if ($createdPersonId > 0): ?>
                <div class="mt-2">
                    <a class="btn btn-primary btn-sm" href="/group-manager-person.php?group_id=<?= (int) $selectedGroupId ?>&person_id=<?= (int) $createdPersonId ?>">Manage this person</a>
                    <a class="btn btn-secondary btn-sm" href="/group-manager-add-person.php?group_id=<?= (int) $selectedGroupId ?>">Add another person</a>
                </div>
            <?php endif; ?>
        </div>
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
            <h2 class="lt-section-title">Add a person to <?= e($selectedGroup['group_name']) ?></h2>
            <p class="lt-lede">
                Microsoft sign-in is the preferred route. Use calendar-link-only access when the person cannot use a District Microsoft 365 account yet.
            </p>

            <form method="post" id="gm-add-person-form">
                <input type="hidden" name="action" value="add_person" id="gm-action">
                <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">

                <div class="gm-flow-step">
                    <h3 class="h5 font-weight-bold">1. Person details</h3>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="first_name">First name</label>
                            <input class="form-control" type="text" id="first_name" name="first_name" value="<?= e($posted['first_name'] ?? '') ?>" required>
                        </div>

                        <div class="form-group col-md-6">
                            <label for="last_name">Last name</label>
                            <input class="form-control" type="text" id="last_name" name="last_name" value="<?= e($posted['last_name'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-8">
                            <label for="personal_email">Personal or Scouting email</label>
                            <input class="form-control" type="email" id="personal_email" name="personal_email" value="<?= e($posted['personal_email'] ?? '') ?>" required>
                            <small class="form-text text-muted">Used for account instructions and contact details.</small>
                        </div>

                        <div class="form-group col-md-4">
                            <label for="phone">Contact number</label>
                            <input class="form-control" type="text" id="phone" name="phone" value="<?= e($posted['phone'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="gm-flow-step">
                    <h3 class="h5 font-weight-bold">2. Role in the Group</h3>

                    <div class="form-group mb-0">
                        <label for="membership_role">Role</label>
                        <select class="form-control" id="membership_role" name="membership_role" required>
                            <?php $selectedRole = (string) ($posted['membership_role'] ?? 'section_leader'); ?>
                            <?php foreach ($roleOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $selectedRole === $value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="gm-flow-step">
                    <h3 class="h5 font-weight-bold">3. Access route</h3>
                    <p>Preferred route: request a District Microsoft 365 account so the person can sign in with Microsoft.</p>

                    <label class="lt-check mb-3">
                        <input type="checkbox" name="use_calendar_link_only" id="use_calendar_link_only" value="1" <?= isset($posted['use_calendar_link_only']) ? 'checked' : '' ?>>
                        <span>Use calendar link only for now</span>
                    </label>

                    <div id="district-email-block">
                        <p class="mb-1">Suggested District email:</p>
                        <p class="gm-suggested-email" id="gm-email-preview">
                            <?= e($districtEmailSuggestion ?: 'Enter first and last name to generate an address') ?>
                        </p>

                        <input type="hidden" id="requested_district_email" name="requested_district_email" value="<?= e($districtEmailSuggestion ?: ($posted['requested_district_email'] ?? '')) ?>">

                        <button class="btn btn-secondary lt-btn" type="submit" formnovalidate onclick="document.getElementById('gm-action').value='suggest_email';">
                            Check availability
                        </button>

                        <p class="gm-muted mt-2 mb-0">
                            After the account request is processed, they should use Microsoft sign-in.
                        </p>
                    </div>

                    <div id="personal-link-block" class="alert alert-warning mt-3" style="display:none;">
                        Calendar-link-only access should be temporary. Microsoft sign-in gives better access control.
                    </div>
                </div>

                <div class="gm-flow-step">
                    <h3 class="h5 font-weight-bold">4. Directory and notes</h3>

                    <label class="lt-check">
                        <input type="checkbox" name="visible_in_directory" value="1" <?= array_key_exists('visible_in_directory', $posted) || !$posted ? 'checked' : '' ?>>
                        <span>Show this person in the District Directory</span>
                    </label>

                    <label class="lt-check mt-2">
                        <input type="checkbox" name="share_phone" value="1" <?= isset($posted['share_phone']) ? 'checked' : '' ?>>
                        <span>Show their phone number in the Directory</span>
                    </label>

                    <div class="form-group mt-3">
                        <label for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"><?= e($posted['notes'] ?? '') ?></textarea>
                    </div>

                    <button class="btn btn-primary lt-btn" type="submit" onclick="document.getElementById('gm-action').value='add_person';">
                        Add person and start access setup
                    </button>
                </div>
            </form>
        </section>

        <aside class="lt-panel-grey">
            <h2 class="lt-section-title">What this does</h2>
            <ol class="pl-3 font-weight-bold">
                <li>Creates or finds the person by email.</li>
                <li>Adds them to <?= e($selectedGroup['group_name']) ?>.</li>
                <li>Updates their Directory profile.</li>
                <li>Queues access instructions by email.</li>
            </ol>
            <p class="mb-0">
                If they already exist in the app, this links their existing person record to this Group.
            </p>
        </aside>
    </div>
</main>

<script>
(function () {
    function slugPart(value) {
        return (value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '.')
            .replace(/^\.+|\.+$/g, '');
    }

    var first = document.getElementById('first_name');
    var last = document.getElementById('last_name');
    var preview = document.getElementById('gm-email-preview');
    var hidden = document.getElementById('requested_district_email');
    var linkOnly = document.getElementById('use_calendar_link_only');
    var districtBlock = document.getElementById('district-email-block');
    var linkBlock = document.getElementById('personal-link-block');
    var domain = <?= json_encode($districtEmailDomain) ?>;

    function updatePreview() {
        if (!first || !last || !preview || !hidden) {
            return;
        }

        if (linkOnly && linkOnly.checked) {
            return;
        }

        var local = [slugPart(first.value), slugPart(last.value)].filter(Boolean).join('.');

        if (!local) {
            preview.textContent = 'Enter first and last name to generate an address';
            hidden.value = '';
            return;
        }

        var email = local + '@' + domain;
        preview.textContent = email;
        hidden.value = email;
    }

    function toggleAccessChoice() {
        var disabled = linkOnly && linkOnly.checked;

        if (districtBlock) {
            districtBlock.style.display = disabled ? 'none' : '';
        }

        if (linkBlock) {
            linkBlock.style.display = disabled ? '' : 'none';
        }

        if (!disabled) {
            updatePreview();
        }
    }

    if (first) {
        first.addEventListener('input', updatePreview);
    }

    if (last) {
        last.addEventListener('input', updatePreview);
    }

    if (linkOnly) {
        linkOnly.addEventListener('change', toggleAccessChoice);
    }

    toggleAccessChoice();

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