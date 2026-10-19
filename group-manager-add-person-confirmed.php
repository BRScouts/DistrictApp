<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/group-manager-helpers.php';

require_login();

if (function_exists('user_needs_group_onboarding') && user_needs_group_onboarding()) {
    redirect('/onboarding.php');
}

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

// One-shot flash set by group-manager-add-person.php after a successful add.
$confirmation = $_SESSION['gm_add_person_confirmation'] ?? null;
unset($_SESSION['gm_add_person_confirmation']);

if (!is_array($confirmation)) {
    // Nothing to confirm (e.g. page opened directly or after a refresh).
    redirect('/group-manager-add-person.php');
}

$groupId = (int) ($confirmation['group_id'] ?? 0);
$personId = (int) ($confirmation['person_id'] ?? 0);
$groupName = (string) ($confirmation['group_name'] ?? '');
$fullName = (string) ($confirmation['full_name'] ?? '');
$roleTitle = (string) ($confirmation['role_title'] ?? '');
$accessRoute = (string) ($confirmation['access_route'] ?? '');
$notifyEmail = (string) ($confirmation['notify_email'] ?? '');
$districtEmail = (string) ($confirmation['district_email'] ?? '');
$inviteUrl = (string) ($confirmation['invite_url'] ?? '');
$existingPerson = (bool) ($confirmation['existing_person'] ?? false);

/*
 * Work out, in plain English, what this person will receive. The two main
 * outcomes the Group Lead Volunteer cares about are:
 *   - a calendar link, or
 *   - a username and (temporary) password for a Microsoft 365 account.
 */
$outcome = match ($accessRoute) {
    'calendar_link_only' => [
        'heading' => 'They will receive a calendar link',
        'summary' => 'We have emailed ' . $fullName . ' a personal link to view the District Calendar.',
        'kind' => 'calendar',
    ],
    'district_account_requested' => [
        'heading' => 'They will receive a username and password',
        'summary' => 'A District Microsoft 365 account is being created for ' . $fullName . '. They will be emailed their username and a temporary password once it is ready (usually within a few minutes).',
        'kind' => 'account',
    ],
    'existing_district_email' => [
        'heading' => 'They will receive sign-in instructions',
        'summary' => $fullName . ' already has a District Microsoft 365 account, so no new account was created. They have been emailed instructions to sign in with Microsoft.',
        'kind' => 'signin',
    ],
    'b2b_sso' => [
        'heading' => 'They will receive sign-in instructions',
        'summary' => 'This Group has its own Office 365, so no District email was created. ' . $fullName . ' has been emailed instructions to sign in with their existing group email via Single Sign-On.',
        'kind' => 'signin',
    ],
    default => [
        'heading' => 'They have been added',
        'summary' => $fullName . ' has been added and an email has been queued.',
        'kind' => 'signin',
    ],
};

$pageTitle = 'Person added | ' . $appName;
$heroTitle = 'Person added';
$heroText = $fullName !== ''
    ? $fullName . ' has been added to ' . $groupName . '.'
    : 'The person has been added.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/group-manager.php?group_id=' . $groupId . '">Group Manager</a> / Person added';

$gmNavCurrent = 'add';

include __DIR__ . '/header.php';
include __DIR__ . '/app/group-manager-nav.php';
?>

<style>
    .gm-confirm-card {
        border: 2px solid #e6e6e6;
        border-left: .45rem solid #00a794;
        background: #eefaf7;
        padding: 1.5rem;
        margin-bottom: 1.25rem;
    }

    .gm-confirm-card h2 {
        margin-top: 0;
        color: var(--iv-purple);
        font-weight: 900;
    }

    .gm-confirm-tick {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.25rem;
        height: 2.25rem;
        border-radius: 50%;
        background: #00a794;
        color: #fff;
        font-weight: 900;
        font-size: 1.25rem;
        margin-right: .6rem;
    }

    .gm-confirm-meta {
        display: grid;
        gap: .4rem;
        margin: 1rem 0 0;
        padding: 0;
        list-style: none;
        font-weight: 700;
    }

    .gm-confirm-meta span {
        color: #555;
        font-weight: 700;
        display: inline-block;
        min-width: 7rem;
    }

    .gm-next-card {
        border: 2px solid #e6e6e6;
        background: #fff;
        padding: 1.25rem;
        margin-bottom: 1.25rem;
    }

    .gm-next-card h3 {
        margin-top: 0;
        color: var(--iv-purple);
        font-weight: 900;
    }

    .gm-link-box {
        display: grid;
        gap: .5rem;
        margin-top: .5rem;
    }

    @media (min-width: 768px) {
        .gm-link-box {
            grid-template-columns: minmax(0, 1fr) auto;
        }
    }

    .gm-confirm-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        margin-top: 1rem;
    }
</style>

<main class="lt-main">

    <div class="gm-confirm-card">
        <h2><span class="gm-confirm-tick" aria-hidden="true">&#10003;</span>Confirmed</h2>
        <p class="lt-lede mb-0">
            <?php if ($existingPerson): ?>
                <?= e($fullName) ?> was found by email and linked to <strong><?= e($groupName) ?></strong><?= $roleTitle !== '' ? ' as ' . e($roleTitle) : '' ?>.
            <?php else: ?>
                <?= e($fullName) ?> has been added to <strong><?= e($groupName) ?></strong><?= $roleTitle !== '' ? ' as ' . e($roleTitle) : '' ?>.
            <?php endif; ?>
        </p>

        <ul class="gm-confirm-meta">
            <li><span>Name</span><?= e($fullName) ?></li>
            <?php if ($roleTitle !== ''): ?>
                <li><span>Role</span><?= e($roleTitle) ?></li>
            <?php endif; ?>
            <li><span>Group</span><?= e($groupName) ?></li>
        </ul>
    </div>

    <div class="gm-next-card">
        <h3><?= e($outcome['heading']) ?></h3>
        <p><?= e($outcome['summary']) ?></p>

        <?php if ($outcome['kind'] === 'account' && $districtEmail !== ''): ?>
            <p class="mb-1">Their new District email address will be:</p>
            <p style="font-size:1.1rem;font-weight:900;color:var(--iv-purple);word-break:break-word;">
                <?= e($districtEmail) ?>
            </p>
            <p class="text-muted mb-0" style="font-size:.92rem;">
                The welcome email was sent to <strong><?= e($notifyEmail) ?></strong>. The username and temporary password follow in a separate email once the account is created.
            </p>
        <?php elseif ($outcome['kind'] === 'calendar'): ?>
            <p class="text-muted mb-0" style="font-size:.92rem;">
                The calendar link was emailed to <strong><?= e($notifyEmail) ?></strong>.
            </p>
            <?php if ($inviteUrl !== ''): ?>
                <p class="mb-1 mt-3" style="font-weight:700;">You can also copy the link here:</p>
                <div class="gm-link-box">
                    <input class="form-control" type="text" value="<?= e($inviteUrl) ?>" readonly>
                    <button class="btn btn-secondary lt-btn gm-copy" type="button" data-copy="<?= e($inviteUrl) ?>">Copy</button>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-muted mb-0" style="font-size:.92rem;">
                Sign-in instructions were emailed to <strong><?= e($notifyEmail) ?></strong>.
            </p>
        <?php endif; ?>
    </div>

    <div class="gm-confirm-actions">
        <?php if ($personId > 0): ?>
            <a class="btn btn-primary lt-btn" href="/group-manager-person.php?group_id=<?= (int) $groupId ?>&person_id=<?= (int) $personId ?>">
                Manage this person
            </a>
        <?php endif; ?>
        <a class="btn btn-secondary lt-btn" href="/group-manager-add-person.php?group_id=<?= (int) $groupId ?>">
            Add another person
        </a>
        <a class="btn btn-secondary lt-btn" href="/group-manager.php?group_id=<?= (int) $groupId ?>">
            Back to Group Manager
        </a>
    </div>
</main>

<script <?= csp_nonce_attr() ?>>
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
