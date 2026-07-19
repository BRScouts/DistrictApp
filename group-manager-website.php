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
$memberships = gm_current_memberships($user);
$isDistrictAdmin = gm_actor_is_district_admin($user, $memberships);
$manageableGroups = gm_manageable_groups((int) $user['id'], $isDistrictAdmin);

if (!$manageableGroups) {
    http_response_code(403);

    $pageTitle = 'Group details | ' . $appName;
    $heroTitle = 'Group details';
    $heroText = 'This area is for Group Lead Volunteers and District administrators.';
    $breadcrumb = '<a href="/index.php">Home</a> / <a href="/group-manager.php">Group Manager</a> / Group details';

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

$pageTitle = 'Group details | ' . $appName;
$heroTitle = 'Group details';
$heroText = 'Manage public details and website information for ' . (string) $selectedGroup['group_name'] . '.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/group-manager.php?group_id=' . $selectedGroupId . '">Group Manager</a> / Group details';

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

    .gm-detail-list {
        display: grid;
        gap: .75rem;
        margin: 0;
    }

    .gm-detail-list div {
        border-bottom: 1px solid #ddd;
        padding-bottom: .75rem;
    }

    .gm-detail-list dt {
        font-weight: 900;
    }

    .gm-detail-list dd {
        margin: .15rem 0 0;
    }

    .gm-muted {
        color: #555;
    }
</style>

<main class="lt-main">
    <a class="gm-back-link" href="/group-manager.php?group_id=<?= (int) $selectedGroupId ?>">Back to Group Manager</a>

    <div class="gm-grid gm-grid-2">
        <section class="lt-panel">
            <h2 class="lt-section-title">Current Group details</h2>
            <p class="lt-lede">
                These details are used across the app where available. Use the website admin page for the full website-editing workflow.
            </p>

            <dl class="gm-detail-list">
                <div>
                    <dt>Group name</dt>
                    <dd><?= e($selectedGroup['group_name'] ?? $selectedGroup['name'] ?? '—') ?></dd>
                </div>
                <div>
                    <dt>Public/contact email</dt>
                    <dd><?= e($selectedGroup['public_email'] ?? $selectedGroup['contact_email'] ?? '—') ?></dd>
                </div>
                <div>
                    <dt>Website URL</dt>
                    <dd><?= e($selectedGroup['website_url'] ?? '—') ?></dd>
                </div>
                <div>
                    <dt>Meeting place</dt>
                    <dd><?= e($selectedGroup['meeting_place'] ?? '—') ?></dd>
                </div>
                <div>
                    <dt>Postcode</dt>
                    <dd><?= e($selectedGroup['postcode'] ?? '—') ?></dd>
                </div>
            </dl>
        </section>

        <aside class="lt-panel-grey">
            <h2 class="lt-section-title">Edit website details</h2>
            <p>
                Website details are managed in the existing Group Website Admin area.
            </p>
            <a class="btn btn-primary lt-btn btn-block" href="/group_website_admin.php">Open website admin</a>

            <hr>

            <p class="gm-muted mb-0">
                District Admins can edit the core Group record from District Admin if the Group name, meeting place, or contact information needs correcting.
            </p>
        </aside>
    </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>