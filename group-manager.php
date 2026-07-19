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
$groupLinks = gm_fetch_group_links($selectedGroupId);

$pageTitle = 'Group Manager | ' . $appName;
$heroTitle = 'Group Manager';
$heroText = 'Manage people, access, and Group details for ' . (string) $selectedGroup['group_name'] . '.';
$breadcrumb = '<a href="/index.php">Home</a> / Group Manager';

include __DIR__ . '/header.php';
?>

<style>
    .gm-group-selector {
        margin-bottom: 2rem;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid var(--iv-grey-300);
    }

    .gm-group-selector label {
        display: block;
        font-weight: 900;
        margin-bottom: .5rem;
    }

    .gm-selector-row {
        display: flex;
        gap: .75rem;
        align-items: flex-end;
        flex-wrap: wrap;
    }

    .gm-selector-row select {
        flex: 1;
        min-width: 200px;
    }

    .gm-primary-action {
        display: inline-block;
        background: var(--iv-purple);
        color: var(--iv-white);
        font-weight: 900;
        font-size: 1.05rem;
        padding: .85rem 1.5rem;
        text-decoration: none;
        margin-bottom: 2rem;
    }

    .gm-primary-action:hover {
        background: var(--iv-purple-dark);
        color: var(--iv-white);
        text-decoration: none;
    }

    .gm-task-list {
        list-style: none;
        padding: 0;
        margin: 0 0 2.5rem;
    }

    .gm-task-list li {
        border-top: 1px solid var(--iv-grey-300);
        padding: 1rem 0;
    }

    .gm-task-list li:last-child {
        border-bottom: 1px solid var(--iv-grey-300);
    }

    .gm-task-list a {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        text-decoration: none;
        gap: 1rem;
    }

    .gm-task-list a:hover .gm-task-name,
    .gm-task-list a:focus .gm-task-name {
        text-decoration: underline;
    }

    .gm-task-name {
        font-weight: 900;
        font-size: 1.1rem;
        color: var(--iv-blue);
    }

    .gm-task-hint {
        display: block;
        margin-top: .25rem;
        font-size: .92rem;
        font-weight: 700;
        color: var(--iv-grey-700);
    }

    .gm-task-count {
        font-size: .88rem;
        font-weight: 900;
        color: var(--iv-grey-700);
        white-space: nowrap;
    }

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
    <?php if (count($manageableGroups) > 1): ?>
        <form class="gm-group-selector" method="get" aria-label="Select a Group to manage">
            <label for="group_id">Managing</label>
            <div class="gm-selector-row">
                <select class="form-control" id="group_id" name="group_id">
                    <?php foreach ($manageableGroups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>" <?= (int) $group['id'] === $selectedGroupId ? 'selected' : '' ?>>
                            <?= e($group['group_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary lt-btn" type="submit">Switch Group</button>
            </div>
        </form>
    <?php endif; ?>

    <a class="gm-primary-action" href="/group-manager-add-person.php?group_id=<?= (int) $selectedGroupId ?>">
        Add a person
    </a>

    <nav aria-label="Group Manager tasks">
        <ul class="gm-task-list">
            <li>
                <a href="/group-manager-inactive.php?group_id=<?= (int) $selectedGroupId ?>">
                    <div>
                        <span class="gm-task-name">Inactive people</span>
                        <span class="gm-task-hint">Review or reactivate people who have been removed.</span>
                    </div>
                    <?php if ($inactivePeople): ?>
                        <span class="gm-task-count"><?= count($inactivePeople) ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="/group-manager-access.php?group_id=<?= (int) $selectedGroupId ?>">
                    <div>
                        <span class="gm-task-name">Calendar access links</span>
                        <span class="gm-task-hint">Manage fallback calendar links for this Group.</span>
                    </div>
                    <?php if ($groupLinks): ?>
                        <span class="gm-task-count"><?= count($groupLinks) ?> active</span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="/group-manager-website.php?group_id=<?= (int) $selectedGroupId ?>">
                    <div>
                        <span class="gm-task-name">Group details</span>
                        <span class="gm-task-hint">Update public website, meeting place, and contact information.</span>
                    </div>
                </a>
            </li>
        </ul>
    </nav>

    <section>
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
                            <td><?= e(gm_role_title_from_membership_role((string) $leader['membership_role'])) ?></td>
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
    </section>

    <?php if ($inactivePeople && !$search): ?>
        <div class="gm-inactive-notice">
            <p><strong><?= count($inactivePeople) ?> inactive <?= count($inactivePeople) === 1 ? 'person' : 'people' ?></strong> linked to this Group.</p>
            <p><a href="/group-manager-inactive.php?group_id=<?= (int) $selectedGroupId ?>">Review inactive people</a></p>
        </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
