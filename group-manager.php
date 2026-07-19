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

    $pageTitle = 'Group Manager | ' . $appName;
    $heroTitle = 'Group Manager';
    $heroText = 'This area is for Group Lead Volunteers and District administrators.';
    $breadcrumb = '<a href="/index.php">Home</a> / Group Manager';

    include __DIR__ . '/header.php';
    echo '<main class="lt-main"><div class="alert alert-danger"><strong>Access denied:</strong> You do not currently manage any Groups.</div></main>';
    include __DIR__ . '/footer.php';
    exit;
}

$selectedGroupId = gm_selected_group_id($manageableGroups);

if (isset($_GET['tab'])) {
    $legacyTab = (string) $_GET['tab'];

    if ($legacyTab === 'add') {
        redirect('/group-manager-add-person.php?group_id=' . $selectedGroupId);
    }

    if ($legacyTab === 'links') {
        redirect('/group-manager-access.php?group_id=' . $selectedGroupId);
    }

    if ($legacyTab === 'website') {
        redirect('/group-manager-website.php?group_id=' . $selectedGroupId);
    }
}

$selectedGroup = gm_fetch_group($selectedGroupId);

if (!$selectedGroup) {
    http_response_code(404);
    include __DIR__ . '/header.php';
    echo '<main class="lt-main"><div class="alert alert-danger">Group not found.</div></main>';
    include __DIR__ . '/footer.php';
    exit;
}

$search = trim((string) ($_GET['search'] ?? ''));
$leaders = gm_fetch_people($selectedGroupId, 'active', $search);
$inactivePeople = gm_fetch_people($selectedGroupId, 'inactive');

$pageTitle = 'Group Manager | ' . $appName;
$heroTitle = 'Group Manager';
$heroText = 'Manage people, access, and Group details for ' . (string) $selectedGroup['group_name'] . '.';
$breadcrumb = '<a href="/index.php">Home</a> / Group Manager';

$gmNavCurrent = 'people';

include __DIR__ . '/header.php';
include __DIR__ . '/app/group-manager-nav.php';
?>

<style>
    .gm-table-wrap {
        overflow-x: auto;
    }

    .gm-table th {
        white-space: nowrap;
        background: var(--iv-grey-100);
        font-weight: 900;
        border-bottom: 2px solid var(--iv-grey-700);
    }

    .gm-table td {
        vertical-align: middle;
    }

    .gm-badge {
        display: inline-block;
        padding: .2rem .5rem;
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

    .gm-badge-reviewer {
        background: #e8e0f3;
        color: #4d0b93;
    }

    .gm-muted {
        color: var(--iv-grey-700);
    }

    .gm-person-link {
        font-weight: 900;
        text-decoration: none;
        color: var(--iv-blue);
    }

    .gm-person-link:hover {
        text-decoration: underline;
    }

    .gm-search-form {
        display: flex;
        gap: .75rem;
        flex-wrap: wrap;
        margin-bottom: 1.5rem;
    }

    .gm-search-form input[type="search"] {
        flex: 1;
        min-width: 200px;
    }

    .gm-inactive-notice {
        margin-top: 2rem;
        padding: 1rem 1.25rem;
        border-left: 4px solid var(--iv-grey-700);
        background: var(--iv-grey-100);
    }

    .gm-inactive-notice p {
        margin: 0 0 .5rem;
    }

    .gm-inactive-notice p:last-child {
        margin-bottom: 0;
    }
</style>

<main class="lt-main">
    <h2 class="lt-section-title">People in <?= e($selectedGroup['group_name']) ?></h2>
    <p class="lt-lede">
        Select a person to manage their details, role, or access.
    </p>

    <form method="get" class="gm-search-form" aria-label="Search people">
        <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
        <label for="search" class="sr-only">Search people</label>
        <input class="form-control" type="search" id="search" name="search" value="<?= e($search) ?>" placeholder="Search by name, email, or phone">
        <button class="btn btn-secondary lt-btn" type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn-secondary lt-btn" href="/group-manager.php?group_id=<?= (int) $selectedGroupId ?>">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($leaders): ?>
        <div class="gm-table-wrap">
            <table class="table gm-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Access</th>
                        <th>Events</th>
                        <th>Latest event</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($leaders as $leader): ?>
                    <?php
                    $accessLabel = gm_access_status_label($leader);
                    $accessClass = match ($accessLabel) {
                        'Microsoft SSO' => 'gm-badge-sso',
                        'Account requested' => 'gm-badge-pending',
                        default => 'gm-badge-link',
                    };
                    ?>
                    <tr>
                        <td>
                            <a class="gm-person-link" href="/group-manager-person.php?group_id=<?= (int) $selectedGroupId ?>&person_id=<?= (int) $leader['person_id'] ?>">
                                <?= e($leader['full_name']) ?>
                            </a><br>
                            <span class="gm-muted"><?= e($leader['primary_email']) ?></span>
                        </td>
                        <td>
                            <?= e(gm_role_title_from_membership_role((string) $leader['membership_role'])) ?>
                            <?php if ((int) ($leader['can_review_events'] ?? 0) === 1): ?>
                                <br><span class="gm-badge gm-badge-reviewer">Reviewer</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="gm-badge <?= e($accessClass) ?>"><?= e($accessLabel) ?></span></td>
                        <td><?= (int) $leader['total_events'] ?></td>
                        <td><?= $leader['latest_event_at'] ? e(date('d M Y', strtotime((string) $leader['latest_event_at']))) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>
            <?php if ($search !== ''): ?>
                No people match your search. <a href="/group-manager.php?group_id=<?= (int) $selectedGroupId ?>">Clear search</a>.
            <?php else: ?>
                No active people in this Group yet. <a href="/group-manager-add-person.php?group_id=<?= (int) $selectedGroupId ?>">Add the first person</a>.
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($inactivePeople && !$search): ?>
        <div class="gm-inactive-notice">
            <p><strong><?= count($inactivePeople) ?> inactive <?= count($inactivePeople) === 1 ? 'person' : 'people' ?></strong> linked to this Group.</p>
            <p><a href="/group-manager-inactive.php?group_id=<?= (int) $selectedGroupId ?>">Review inactive people</a></p>
        </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
