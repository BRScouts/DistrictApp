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
$districtEmailDomain = gm_default_district_email_domain();

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

function gm_add_normalise_email(string $email): string
{
    return strtolower(trim($email));
}

function gm_add_email_domain(string $email): string
{
    $email = gm_add_normalise_email($email);

    if (!str_contains($email, '@')) {
        return '';
    }

    $parts = explode('@', $email);

    return strtolower(trim((string) end($parts)));
}

function gm_add_is_district_email(string $email, string $districtDomain): bool
{
    $domain = strtolower(ltrim(trim($districtDomain), '@'));

    if ($domain === '') {
        return false;
    }

    return gm_add_email_domain($email) === $domain;
}

function gm_add_access_route_label(string $route): string
{
    return match ($route) {
        'calendar_link_only' => 'Calendar link only',
        'existing_district_email' => 'Existing District Microsoft 365 account',
        'district_account_requested' => 'District Microsoft 365 account requested',
        default => 'Microsoft 365 sign-in',
    };
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
            $personalEmail = gm_add_normalise_email((string) ($_POST['personal_email'] ?? ''));

            if ($personalEmail !== '' && filter_var($personalEmail, FILTER_VALIDATE_EMAIL) && gm_add_is_district_email($personalEmail, $districtEmailDomain)) {
                $districtEmailSuggestion = $personalEmail;
                $posted['requested_district_email'] = $districtEmailSuggestion;
                $posted['access_route'] = 'microsoft';

                $success = 'Existing District email detected. No new Microsoft 365 account request will be created for this person.';
            } elseif ($firstName === '' || $lastName === '') {
                $errors[] = 'Enter the person\'s first and last name before checking the District email.';
            } else {
                $suggestion = gm_available_district_email($firstName, $lastName);
                $districtEmailSuggestion = $suggestion['email'];
                $graphChecked = (bool) $suggestion['checked_graph'];
                $posted['requested_district_email'] = $districtEmailSuggestion;
                $posted['access_route'] = 'microsoft';

                $success = $graphChecked
                    ? 'District email checked and suggested.'
                    : 'District email suggested from local records.';
            }
        } elseif ($action === 'add_person') {
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $fullName = trim($firstName . ' ' . $lastName);
            $personalEmail = gm_add_normalise_email((string) ($_POST['personal_email'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $membershipRole = (string) ($_POST['membership_role'] ?? 'section_leader');
            $sharePhone = isset($_POST['share_phone']) ? 1 : 0;
            $notes = trim((string) ($_POST['notes'] ?? ''));

            $accessRoute = (string) ($_POST['access_route'] ?? 'microsoft');

            if (!in_array($accessRoute, ['microsoft', 'calendar_link_only'], true)) {
                $accessRoute = 'microsoft';
            }

            $useCalendarLinkOnly = $accessRoute === 'calendar_link_only';
            $personalEmailIsDistrict = $personalEmail !== ''
                && filter_var($personalEmail, FILTER_VALIDATE_EMAIL)
                && gm_add_is_district_email($personalEmail, $districtEmailDomain);

            $requestedDistrictEmail = gm_add_normalise_email((string) ($_POST['requested_district_email'] ?? ''));

            /*
             * Directory visibility is not user-controlled here.
             * Active people can appear in the Directory, but calendar-link-only users cannot sign in
             * to use the Directory or other Dashboard features.
             */
            $visibleInDirectory = 1;

            if ($firstName === '') {
                $errors[] = 'Enter the person\'s first name.';
            }

            if ($lastName === '') {
                $errors[] = 'Enter the person\'s last name.';
            }

            if ($personalEmail === '' || !filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid current email address.';
            }

            if (!array_key_exists($membershipRole, $roleOptions)) {
                $errors[] = 'Choose a valid role.';
            }

            if (!$useCalendarLinkOnly) {
                if ($personalEmailIsDistrict) {
                    /*
                     * They already have a District email, so do not create another request.
                     */
                    $requestedDistrictEmail = $personalEmail;
                    $posted['requested_district_email'] = $requestedDistrictEmail;
                } else {
                    if ($requestedDistrictEmail === '') {
                        $suggestion = gm_available_district_email($firstName, $lastName);
                        $requestedDistrictEmail = gm_add_normalise_email((string) $suggestion['email']);
                        $posted['requested_district_email'] = $requestedDistrictEmail;
                    }

                    if (!filter_var($requestedDistrictEmail, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = 'The suggested District email address is not valid.';
                    } elseif (!gm_add_is_district_email($requestedDistrictEmail, $districtEmailDomain)) {
                        $errors[] = 'The requested District email must use @' . $districtEmailDomain . '.';
                    }
                }
            }

            if (!$errors) {
                $existingPerson = gm_find_person_by_email($personalEmail);

                if (!$existingPerson && !$useCalendarLinkOnly && $requestedDistrictEmail !== '' && $requestedDistrictEmail !== $personalEmail) {
                    $existingPerson = gm_find_person_by_email($requestedDistrictEmail);
                }

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
                $finalAccessRoute = 'district_account_requested';

                $ssoUrl = gm_absolute_url('/auth/microsoft-start.php');
                $dashboardUrl = gm_absolute_url('/index.php');
                $calendarUrl = gm_absolute_url('/dc/');

                if ($useCalendarLinkOnly) {
                    $finalAccessRoute = 'calendar_link_only';
                    $createdInviteUrl = gm_create_unique_invite($actorPersonId, $personId, $selectedGroupId);

                    gm_queue_email_and_log(
                        $personId,
                        $personalEmail,
                        $fullName,
                        'Your Irwell Valley District Calendar access',
                        "Hello {$firstName},\n\n"
                        . "Your Group Lead Volunteer has added you to the District Calendar using calendar-link-only access.\n\n"
                        . "Access the calendar here:\n{$createdInviteUrl}\n\n"
                        . "This link gives access to the District Calendar only. It does not give access to the District Dashboard, Directory, Microsoft 365 email, OneDrive or other signed-in features.\n\n"
                        . "If you later receive a District Microsoft 365 account, please use the Microsoft sign-in button instead.\n\n"
                        . "Irwell Valley Scout District",
                        'group_calendar_invite'
                    );
                } elseif ($personalEmailIsDistrict) {
                    $finalAccessRoute = 'existing_district_email';

                    gm_queue_email_and_log(
                        $personId,
                        $personalEmail,
                        $fullName,
                        'You have been added to the Irwell Valley District App',
                        "Hello {$firstName},\n\n"
                        . "Your Group Lead Volunteer has added you to {$selectedGroup['group_name']} in the Irwell Valley District App.\n\n"
                        . "We have used your existing District Microsoft 365 address:\n{$personalEmail}\n\n"
                        . "No new District email account has been requested because this already appears to be a District account.\n\n"
                        . "Please use the Microsoft sign-in button to access the District Dashboard and District Calendar.\n\n"
                        . "Sign in with Microsoft:\n{$ssoUrl}\n\n"
                        . "Dashboard:\n{$dashboardUrl}\n\n"
                        . "District Calendar:\n{$calendarUrl}\n\n"
                        . "Irwell Valley Scout District",
                        'district_account_existing'
                    );
                } else {
                    $finalAccessRoute = 'district_account_requested';

                    $requestRecorded = gm_create_district_email_request(
                        $actorPersonId,
                        $personId,
                        $selectedGroupId,
                        $requestedDistrictEmail,
                        $personalEmail,
                        $notes
                    );

                    gm_queue_email_and_log(
                        $personId,
                        $personalEmail,
                        $fullName,
                        'Your Irwell Valley District Microsoft 365 account request',
                        "Hello {$firstName},\n\n"
                        . "Your Group Lead Volunteer has requested a District Microsoft 365 account for you.\n\n"
                        . "Requested address: {$requestedDistrictEmail}\n\n"
                        . "Most volunteers should use a District Microsoft 365 account because it gives access to the District Dashboard, Directory, District Calendar, Scout email and other District services.\n\n"
                        . "Once the account is created, use the Microsoft sign-in button to access the District Dashboard and District Calendar.\n\n"
                        . "Sign in with Microsoft:\n{$ssoUrl}\n\n"
                        . "Dashboard:\n{$dashboardUrl}\n\n"
                        . "District Calendar:\n{$calendarUrl}\n\n"
                        . "Irwell Valley Scout District",
                        'district_account_requested'
                    );
                }

                gm_log_action($actorPersonId, $existingPerson ? 'group_person_linked' : 'group_person_created', 'person', $personId, [
                    'group_id' => $selectedGroupId,
                    'membership_role' => $membershipRole,
                    'access_route' => $finalAccessRoute,
                    'district_account_requested' => $finalAccessRoute === 'district_account_requested',
                    'existing_district_email_detected' => $personalEmailIsDistrict,
                    'requested_district_email' => $requestedDistrictEmail,
                    'request_recorded' => $requestRecorded,
                    'calendar_link_only' => $useCalendarLinkOnly,
                ]);

                $pdo->commit();

                $createdPersonId = $personId;
                $success = $existingPerson
                    ? 'Existing person found by email and linked to this Group.'
                    : 'Person added to this Group.';

                if ($finalAccessRoute === 'district_account_requested') {
                    $success .= ' A Microsoft 365 account request and welcome email have been queued.';
                } elseif ($finalAccessRoute === 'existing_district_email') {
                    $success .= ' Existing District email detected, so no new Microsoft 365 account request was created. Sign-in instructions have been queued.';
                } else {
                    $success .= ' A calendar-link-only access email has been queued.';
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

if (
    !$districtEmailSuggestion
    && !empty($posted['first_name'])
    && !empty($posted['last_name'])
    && (string) ($posted['access_route'] ?? 'microsoft') !== 'calendar_link_only'
) {
    $postedEmail = gm_add_normalise_email((string) ($posted['personal_email'] ?? ''));

    if ($postedEmail !== '' && filter_var($postedEmail, FILTER_VALIDATE_EMAIL) && gm_add_is_district_email($postedEmail, $districtEmailDomain)) {
        $districtEmailSuggestion = $postedEmail;
    } else {
        $districtEmailSuggestion = (string) ($posted['requested_district_email'] ?? gm_district_email_candidate((string) $posted['first_name'], (string) $posted['last_name']));
    }
}

$pageTitle = 'Add person | ' . $appName;
$heroTitle = 'Add person';
$heroText = 'Add a leader to ' . (string) $selectedGroup['group_name'] . ' and choose the right access route.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/group-manager.php?group_id=' . $selectedGroupId . '">Group Manager</a> / Add person';

$selectedAccessRoute = (string) ($posted['access_route'] ?? 'microsoft');

if (isset($posted['use_calendar_link_only'])) {
    $selectedAccessRoute = 'calendar_link_only';
}

if (!in_array($selectedAccessRoute, ['microsoft', 'calendar_link_only'], true)) {
    $selectedAccessRoute = 'microsoft';
}

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
        border: 2px solid #e6e6e6;
        border-left: .45rem solid var(--iv-purple);
        padding: 1rem;
        background: #fff;
        margin-bottom: 1rem;
        box-shadow: none;
    }

    .gm-flow-step h3 {
        margin-top: 0;
        color: var(--iv-purple);
        font-weight: 900;
    }

    .gm-step-kicker {
        display: inline-block;
        margin-bottom: .4rem;
        background: #f7f5fb;
        color: var(--iv-purple);
        padding: .2rem .5rem;
        font-size: .8rem;
        font-weight: 900;
    }

    .gm-route-grid {
        display: grid;
        gap: .75rem;
    }

    @media (min-width: 760px) {
        .gm-route-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .gm-route-card {
        display: block;
        border: 2px solid #d8d8d8;
        background: #fff;
        padding: 1rem;
        cursor: pointer;
    }

    .gm-route-card:has(input:checked) {
        border-color: var(--iv-purple);
        background: #f7f5fb;
    }

    .gm-route-card input {
        margin-right: .4rem;
    }

    .gm-route-card strong {
        color: var(--iv-purple);
        font-weight: 900;
    }

    .gm-route-card span {
        display: block;
        margin-top: .35rem;
        color: #333;
        font-weight: 700;
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

    .gm-access-panel {
        border: 2px solid #e6e6e6;
        background: #ffffff;
        padding: 1rem;
        margin-top: 1rem;
    }

    .gm-access-panel h4 {
        margin-top: 0;
        color: var(--iv-purple);
        font-weight: 900;
    }

    .gm-warning-panel {
        border-left: 6px solid #ffdd00;
        background: #fff8d6;
    }

    .gm-good-panel {
        border-left: 6px solid #00a794;
        background: #eefaf7;
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
                Most volunteers should use a District Microsoft 365 account. Calendar-link-only access is available for people who do not want, or cannot use, a District email account.
            </p>

            <form method="post" id="gm-add-person-form">
                <input type="hidden" name="action" value="add_person" id="gm-action">
                <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">

                <div class="gm-flow-step">
                    <span class="gm-step-kicker">Step 1</span>
                    <h3 class="h5 font-weight-bold">Person details</h3>

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
                            <label for="personal_email">Current email address</label>
                            <input class="form-control" type="email" id="personal_email" name="personal_email" value="<?= e($posted['personal_email'] ?? '') ?>" required>
                            <small class="form-text text-muted">
                                Use their personal or Scouting email. If this is already an @<?= e($districtEmailDomain) ?> address, no new District account request will be created.
                            </small>
                        </div>

                        <div class="form-group col-md-4">
                            <label for="phone">Contact number</label>
                            <input class="form-control" type="text" id="phone" name="phone" value="<?= e($posted['phone'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="gm-flow-step">
                    <span class="gm-step-kicker">Step 2</span>
                    <h3 class="h5 font-weight-bold">Choose access route</h3>

                    <div class="gm-route-grid">
                        <label class="gm-route-card">
                            <input
                                type="radio"
                                name="access_route"
                                value="microsoft"
                                <?= $selectedAccessRoute === 'microsoft' ? 'checked' : '' ?>
                                data-access-route
                            >
                            <strong>District Microsoft 365 account</strong>
                            <span>
                                Recommended. Gives Microsoft sign-in, District Dashboard, Directory, Calendar, Scout email and other District services.
                            </span>
                        </label>

                        <label class="gm-route-card">
                            <input
                                type="radio"
                                name="access_route"
                                value="calendar_link_only"
                                <?= $selectedAccessRoute === 'calendar_link_only' ? 'checked' : '' ?>
                                data-access-route
                            >
                            <strong>Calendar link only</strong>
                            <span>
                                For people who do not want a District email. They will only receive a Calendar link and will not be able to sign in to other features.
                            </span>
                        </label>
                    </div>

                    <div id="district-email-block" class="gm-access-panel gm-good-panel">
                        <h4>District Microsoft 365 account</h4>

                        <div id="existing-district-email-block" style="display:none;">
                            <p class="mb-1">
                                Existing District email detected:
                            </p>
                            <p class="gm-suggested-email" id="existing-district-email-preview"></p>
                            <p class="mb-0 gm-muted">
                                No new account request will be created. The person will be sent Microsoft sign-in instructions.
                            </p>
                        </div>

                        <div id="suggested-district-email-block">
                            <p class="mb-1">Suggested District email:</p>
                            <p class="gm-suggested-email" id="gm-email-preview">
                                <?= e($districtEmailSuggestion ?: 'Enter first and last name to generate an address') ?>
                            </p>

                            <input type="hidden" id="requested_district_email" name="requested_district_email" value="<?= e($districtEmailSuggestion ?: ($posted['requested_district_email'] ?? '')) ?>">

                            <button class="btn btn-secondary lt-btn" type="submit" formnovalidate onclick="document.getElementById('gm-action').value='suggest_email';">
                                Check availability
                            </button>

                            <p class="gm-muted mt-2 mb-0">
                                We'll create a job for the account to be created, please note that this could take upto 5 mins and the member will recieve thier password to sign in.
                            </p>
                        </div>
                    </div>

                    <div id="personal-link-block" class="gm-access-panel gm-warning-panel" style="display:none;">
                        <h4>Calendar-link-only access</h4>
                        <p class="mb-0">
                            The person will receive a unique Calendar link after saving. They will not be able to use Microsoft sign-in, the Dashboard, Directory, Scout email, OneDrive or other signed-in features.
                        </p>
                    </div>
                </div>

                <div class="gm-flow-step">
                    <span class="gm-step-kicker">Step 3</span>
                    <h3 class="h5 font-weight-bold">Role in the Group</h3>

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
                    <span class="gm-step-kicker">Step 4</span>
                    <h3 class="h5 font-weight-bold">Directory and notes</h3>

                    <p class="gm-muted">
                        The person will be linked as an active volunteer. Calendar-link-only users cannot sign in to view the Directory or other Dashboard features.
                    </p>

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
            <h2 class="lt-section-title">What happens next</h2>

            <ol class="pl-3 font-weight-bold">
                <li>The person is created or found by email.</li>
                <li>They are linked to <?= e($selectedGroup['group_name']) ?>.</li>
                <li>Their Directory profile is updated.</li>
                <li>The right access email is queued.</li>
            </ol>

            <hr>

            <h3 class="h5 font-weight-bold">Microsoft 365 route</h3>
            <p>
                Use this for most volunteers. It gives proper Microsoft sign-in and access to District tools.
            </p>

            <h3 class="h5 font-weight-bold">Calendar-link-only route</h3>
            <p class="mb-0">
                Use this only where the person does not want a District email. They receive a Calendar link only.
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

    function normaliseEmail(value) {
        return String(value || '').trim().toLowerCase();
    }

    function emailDomain(value) {
        var email = normaliseEmail(value);
        var at = email.lastIndexOf('@');

        if (at === -1) {
            return '';
        }

        return email.slice(at + 1);
    }

    var first = document.getElementById('first_name');
    var last = document.getElementById('last_name');
    var personalEmail = document.getElementById('personal_email');
    var preview = document.getElementById('gm-email-preview');
    var existingPreview = document.getElementById('existing-district-email-preview');
    var hidden = document.getElementById('requested_district_email');
    var districtBlock = document.getElementById('district-email-block');
    var suggestedDistrictBlock = document.getElementById('suggested-district-email-block');
    var existingDistrictBlock = document.getElementById('existing-district-email-block');
    var linkBlock = document.getElementById('personal-link-block');
    var domain = <?= json_encode($districtEmailDomain) ?>;

    var serverSuggested = hidden ? hidden.value : '';
    var serverSuggestedNameKey = getNameKey();

    function getSelectedAccessRoute() {
        var selected = document.querySelector('[data-access-route]:checked');
        return selected ? selected.value : 'microsoft';
    }

    function getNameKey() {
        return [
            first ? first.value.trim().toLowerCase() : '',
            last ? last.value.trim().toLowerCase() : ''
        ].join('|');
    }

    function isDistrictEmail(value) {
        return emailDomain(value) === String(domain || '').toLowerCase();
    }

    function generatedDistrictEmail() {
        var local = [
            slugPart(first ? first.value : ''),
            slugPart(last ? last.value : '')
        ].filter(Boolean).join('.');

        if (!local) {
            return '';
        }

        return local + '@' + domain;
    }

    function clearServerSuggestionIfNameChanged() {
        if (getNameKey() !== serverSuggestedNameKey) {
            serverSuggested = '';
        }
    }

    function updatePreview() {
        if (!preview || !hidden) {
            return;
        }

        clearServerSuggestionIfNameChanged();

        var route = getSelectedAccessRoute();
        var currentEmail = normaliseEmail(personalEmail ? personalEmail.value : '');
        var currentEmailIsDistrict = isDistrictEmail(currentEmail);

        if (route === 'calendar_link_only') {
            if (districtBlock) {
                districtBlock.style.display = 'none';
            }

            if (linkBlock) {
                linkBlock.style.display = '';
            }

            hidden.value = '';
            return;
        }

        if (districtBlock) {
            districtBlock.style.display = '';
        }

        if (linkBlock) {
            linkBlock.style.display = 'none';
        }

        if (currentEmailIsDistrict) {
            if (existingDistrictBlock) {
                existingDistrictBlock.style.display = '';
            }

            if (suggestedDistrictBlock) {
                suggestedDistrictBlock.style.display = 'none';
            }

            if (existingPreview) {
                existingPreview.textContent = currentEmail;
            }

            hidden.value = currentEmail;
            return;
        }

        if (existingDistrictBlock) {
            existingDistrictBlock.style.display = 'none';
        }

        if (suggestedDistrictBlock) {
            suggestedDistrictBlock.style.display = '';
        }

        var email = serverSuggested || generatedDistrictEmail();

        if (!email) {
            preview.textContent = 'Enter first and last name to generate an address';
            hidden.value = '';
            return;
        }

        preview.textContent = email;
        hidden.value = email;
    }

    if (first) {
        first.addEventListener('input', updatePreview);
    }

    if (last) {
        last.addEventListener('input', updatePreview);
    }

    if (personalEmail) {
        personalEmail.addEventListener('input', updatePreview);
    }

    document.querySelectorAll('[data-access-route]').forEach(function (radio) {
        radio.addEventListener('change', updatePreview);
    });

    updatePreview();

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