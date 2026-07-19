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
$duplicateWarnings = [];
$actorPersonId = (int) $user['id'];
$roleOptions = gm_membership_role_options($selectedGroupId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
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
                // Duplicate person detection: check by name and email.
                $confirmedDuplicate = isset($_POST['confirm_duplicate']) && $_POST['confirm_duplicate'] === '1';
                $potentialDuplicates = [];

                if (!$confirmedDuplicate) {
                    $potentialDuplicates = gm_find_potential_duplicates($fullName, $personalEmail, $selectedGroupId);
                }

                if (!$confirmedDuplicate && count($potentialDuplicates) > 0) {
                    // Show warning — don't create the person yet.
                    $duplicateWarnings = [];
                    foreach ($potentialDuplicates as $dup) {
                        $dupGroups = $dup['groups_list'] ?: 'No active group';
                        $dupStatus = $dup['person_status'] === 'active' ? 'active' : 'inactive';
                        $duplicateWarnings[] = e($dup['full_name']) . ' (' . e($dup['primary_email'] ?? 'no email') . ') — ' . e($dupGroups) . ' [' . $dupStatus . ']';
                    }
                    $errors[] = 'A person with a similar name or email already exists in another group. If this is a different person, tick the confirmation below and submit again.';
                } else {
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

                if ($useCalendarLinkOnly) {
                    $finalAccessRoute = 'calendar_link_only';
                    $createdInviteUrl = gm_create_unique_invite($actorPersonId, $personId, $selectedGroupId);

                    gm_queue_email_and_log(
                        $personId,
                        $personalEmail,
                        $fullName,
                        'Your Irwell Valley District Calendar link',
                        "Hello {$firstName},\n\n"
                        . "Welcome to Irwell Valley Scout District. Your Group Lead Volunteer has set up calendar access for you.\n\n"
                        . "\n"
                        . "YOUR CALENDAR LINK\n\n"
                        . "{$createdInviteUrl}\n\n"
                        . "Bookmark this link or save this email so you can find it again easily.\n\n"
                        . "\n"
                        . "WHAT YOU CAN ACCESS\n\n"
                        . "This link gives you access to the District Calendar where you can view upcoming events and activities.\n\n"
                        . "This link does not give access to the District Dashboard, Directory, Scout email, or OneDrive.\n\n"
                        . "\n"
                        . "IMPORTANT\n\n"
                        . "This link is personal to you. Please do not share it with others.\n\n"
                        . "If you later receive a District Microsoft 365 account, you should use the Microsoft sign-in button instead of this link.\n\n"
                        . "\n"
                        . "Irwell Valley Scout District",
                        'group_calendar_invite'
                    );
                } elseif ($personalEmailIsDistrict) {
                    $finalAccessRoute = 'existing_district_email';

                    gm_queue_email_and_log(
                        $personId,
                        $personalEmail,
                        $fullName,
                        'Welcome to the Irwell Valley District App',
                        "Hello {$firstName},\n\n"
                        . "Welcome to Irwell Valley Scout District. Your Group Lead Volunteer has added you to {$selectedGroup['group_name']}.\n\n"
                        . "\n"
                        . "YOUR ACCOUNT\n\n"
                        . "We have linked your existing District Microsoft 365 address:\n{$personalEmail}\n\n"
                        . "No new account has been created because you already have a District email.\n\n"
                        . "\n"
                        . "HOW TO SIGN IN\n\n"
                        . "Go to the link below and click \"Sign in with Microsoft\". Use your District email address and password.\n\n"
                        . "{$ssoUrl}\n\n"
                        . "\n"
                        . "WHAT YOU CAN ACCESS\n\n"
                        . "Once signed in you will have access to:\n\n"
                        . "- The District Dashboard\n"
                        . "- The District Directory\n"
                        . "- The District Calendar\n"
                        . "- Your Scout email and OneDrive\n\n"
                        . "\n"
                        . "NEED HELP?\n\n"
                        . "If you have trouble signing in, ask your Group Lead Volunteer.\n\n"
                        . "\n"
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
                        'Your Irwell Valley District account is being set up',
                        "Hello {$firstName},\n\n"
                        . "Welcome to Irwell Valley Scout District. Your Group Lead Volunteer has requested a District Microsoft 365 account for you.\n\n"
                        . "\n"
                        . "YOUR NEW ACCOUNT\n\n"
                        . "Your District email address will be:\n{$requestedDistrictEmail}\n\n"
                        . "The account is usually created within a few minutes. You will receive a separate email with your temporary password once it is ready.\n\n"
                        . "\n"
                        . "WHAT HAPPENS NEXT\n\n"
                        . "1. Your account is created automatically.\n"
                        . "2. You receive your temporary password by email.\n"
                        . "3. You sign in and set a new password.\n"
                        . "4. You can then access all District services.\n\n"
                        . "\n"
                        . "WHAT YOU WILL BE ABLE TO ACCESS\n\n"
                        . "Once your account is ready and you sign in, you will have access to:\n\n"
                        . "- The District Dashboard\n"
                        . "- The District Directory\n"
                        . "- The District Calendar\n"
                        . "- Your Scout email and OneDrive\n\n"
                        . "\n"
                        . "HOW TO SIGN IN (once your account is ready)\n\n"
                        . "Go to the link below and click \"Sign in with Microsoft\". Use your new District email address and password.\n\n"
                        . "{$ssoUrl}\n\n"
                        . "\n"
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
                } // end else (no duplicates or confirmed)
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

$gmNavCurrent = 'add';

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
            grid-template-columns: minmax(0, 2fr) minmax(300px, 1fr);
        }
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

    .gm-legacy-warning {
        margin-top: .75rem;
        padding: .85rem 1rem;
        background: #fef3cd;
        border: 2px solid #e6a817;
        border-left: 5px solid #e6a817;
        font-weight: 700;
        font-size: .92rem;
        color: #664d03;
    }

    .gm-legacy-warning strong {
        display: block;
        margin-bottom: .25rem;
    }

    .gm-good-panel {
        border-left: 6px solid #00a794;
        background: #eefaf7;
    }

    .gm-email-explainer {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid rgba(0, 167, 148, .25);
        font-size: .92rem;
    }

    .gm-email-explainer p {
        margin-bottom: .4rem;
    }

    .gm-email-explainer ol {
        margin: 0;
        padding-left: 1.25rem;
        color: var(--iv-grey-700);
        font-weight: 700;
    }

    .gm-email-explainer ol li {
        margin-bottom: .25rem;
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

    <?php if (!empty($duplicateWarnings)): ?>
        <div class="alert alert-warning">
            <strong>Possible duplicate detected:</strong>
            <ul class="mb-2 mt-2">
                <?php foreach ($duplicateWarnings as $warning): ?>
                    <li><?= $warning ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="mb-0">If you are sure this is a different person, tick the box below and submit again.</p>
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
                Add a volunteer to this Group. You can choose whether they need a District email account or just a calendar link.
            </p>

            <form method="post" id="gm-add-person-form">
                <?= csrf_field() ?>
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
                                Their personal email or existing Scouting email. If they already have an @<?= e($districtEmailDomain) ?> address, enter it here and we will link it automatically.
                            </small>
                            <div id="legacy-email-warning" class="gm-legacy-warning" style="display:none;" role="alert">
                                <strong>Legacy email detected.</strong>
                                This looks like a old District email address. All volunteers should now be migrated to their new @<?= e($districtEmailDomain) ?> Irwell Valley email. Please use their personal email here and we will create a new account for them.
                            </div>
                        </div>

                        <div class="form-group col-md-4">
                            <label for="phone">Contact number</label>
                            <input class="form-control" type="text" id="phone" name="phone" value="<?= e($posted['phone'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="gm-flow-step" id="step-district-email">
                    <span class="gm-step-kicker">Step 2</span>
                    <h3 class="h5 font-weight-bold">District email and access</h3>

                    <p class="gm-muted mb-3">
                        Does this person need a District Microsoft 365 account? This is optional. Not all leaders need one.
                    </p>

                    <fieldset>
                        <legend class="sr-only">Choose how this person will access District services</legend>

                        <div class="gm-route-grid">
                            <label class="gm-route-card">
                                <input
                                    type="radio"
                                    name="access_route"
                                    value="microsoft"
                                    <?= $selectedAccessRoute === 'microsoft' ? 'checked' : '' ?>
                                    data-access-route
                                >
                                <strong>Yes, set up a District email</strong>
                                <span>
                                    They will get an @<?= e($districtEmailDomain) ?> address, Microsoft sign-in, and access to the Dashboard, Directory, Calendar, and Scout email.
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
                                <strong>No, calendar link only</strong>
                                <span>
                                    They will receive a link to view the District Calendar. They will not get an email address or be able to sign in.
                                </span>
                            </label>
                        </div>
                    </fieldset>

                    <!-- Shown when "Yes, set up a District email" is selected -->
                    <div id="district-email-block" class="gm-access-panel gm-good-panel">

                        <!-- State A: Their personal email IS already a district email -->
                        <div id="existing-district-email-block" style="display:none;">
                            <h4>Already has a District email</h4>
                            <p class="mb-1">
                                The email address you entered is already an @<?= e($districtEmailDomain) ?> account:
                            </p>
                            <p class="gm-suggested-email" id="existing-district-email-preview"></p>
                            <p class="mb-0 gm-muted">
                                We will link this existing account. No new account will be created and they will receive sign-in instructions at this address.
                            </p>
                        </div>

                        <!-- State B: New district email will be created -->
                        <div id="suggested-district-email-block">
                            <h4>New District email</h4>
                            <p class="mb-1">
                                A new @<?= e($districtEmailDomain) ?> account will be requested for this person:
                            </p>
                            <p class="gm-suggested-email" id="gm-email-preview">
                                <?= e($districtEmailSuggestion ?: 'Enter first and last name to generate an address') ?>
                            </p>

                            <input type="hidden" id="requested_district_email" name="requested_district_email" value="<?= e($districtEmailSuggestion ?: ($posted['requested_district_email'] ?? '')) ?>">

                            <button class="btn btn-secondary lt-btn" type="submit" formnovalidate formaction="?group_id=<?= (int) $selectedGroupId ?>#step-district-email" onclick="document.getElementById('gm-action').value='suggest_email';">
                                Check availability
                            </button>

                            <div class="gm-email-explainer">
                                <p><strong>What happens:</strong></p>
                                <ol>
                                    <li>An account request is queued for processing.</li>
                                    <li>The account is usually created within 5 minutes.</li>
                                    <li>They receive their username and temporary password by email.</li>
                                    <li>They sign in and set a new password.</li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    <!-- Shown when "No, calendar link only" is selected -->
                    <div id="personal-link-block" class="gm-access-panel gm-warning-panel" style="display:none;">
                        <h4>Calendar link only</h4>
                        <p>
                            This person will receive a unique link to view the District Calendar. They will not be able to:
                        </p>
                        <ul class="mb-0">
                            <li>Sign in with Microsoft</li>
                            <li>Access the Dashboard or Directory</li>
                            <li>Use Scout email, OneDrive, or other District services</li>
                        </ul>
                        <p class="gm-muted mt-2 mb-0">
                            You can set up a District email for them later from their profile if needed.
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

                    <?php if (!empty($duplicateWarnings)): ?>
                        <div class="form-group mt-3" style="background:#fff3cd;border:2px solid #ffc107;padding:.75rem;border-radius:4px;">
                            <label class="lt-check mb-0">
                                <input type="checkbox" name="confirm_duplicate" value="1" required>
                                <span><strong>I confirm this is not a duplicate.</strong> I have checked and this is a different person to the match shown above.</span>
                            </label>
                        </div>
                    <?php endif; ?>

                    <button class="btn btn-primary lt-btn" type="submit" onclick="document.getElementById('gm-action').value='add_person';">
                        Add person and start access setup
                    </button>
                </div>
            </form>
        </section>

        <aside class="lt-panel-grey">
            <h2 class="lt-section-title">What happens next</h2>

            <ol class="pl-3 font-weight-bold">
                <li>The person is created or linked by email.</li>
                <li>They are added to <?= e($selectedGroup['group_name']) ?>.</li>
                <li>Their Directory profile is set up.</li>
                <li>They receive an email with their access details.</li>
            </ol>

            <hr>

            <h3 class="h5 font-weight-bold">When to use a District email</h3>
            <p>
                Section Leaders, Group Lead Volunteers, and anyone who needs to sign in to the Dashboard, submit events, or use Scout email.
            </p>

            <h3 class="h5 font-weight-bold">When to use calendar link only</h3>
            <p>
                Occasional helpers, new volunteers still completing their appointment, or anyone who prefers not to have a District account.
            </p>

            <hr>

            <h3 class="h5 font-weight-bold">Already has a District email?</h3>
            <p class="mb-0">
                If you enter their existing @<?= e($districtEmailDomain) ?> address as their current email, we will automatically link it. No duplicate account will be created.
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
    var legacyDomains = ['brscouts.org.uk', 'prawscouts.org.uk'];
    var legacyWarning = document.getElementById('legacy-email-warning');

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

    function isLegacyEmail(value) {
        var d = emailDomain(value);
        if (!d) return false;
        for (var i = 0; i < legacyDomains.length; i++) {
            if (d === legacyDomains[i]) return true;
        }
        return false;
    }

    function updateLegacyWarning() {
        if (!legacyWarning || !personalEmail) return;
        var email = normaliseEmail(personalEmail.value);
        if (email && isLegacyEmail(email)) {
            legacyWarning.style.display = '';
        } else {
            legacyWarning.style.display = 'none';
        }
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
        personalEmail.addEventListener('input', updateLegacyWarning);
    }

    document.querySelectorAll('[data-access-route]').forEach(function (radio) {
        radio.addEventListener('change', updatePreview);
    });

    updatePreview();
    updateLegacyWarning();

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

<?php if (($_POST['action'] ?? '') === 'suggest_email'): ?>
<script>
(function () {
    var target = document.getElementById('step-district-email');
    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}());
</script>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>