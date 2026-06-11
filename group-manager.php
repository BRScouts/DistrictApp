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

$leadersWithSso = count(array_filter($leaders, static fn(array $leader): bool => (int) ($leader['has_microsoft_account'] ?? 0) > 0));
$leadersWithoutSso = count($leaders) - $leadersWithSso;
$pendingAccountRequests = count(array_filter($leaders, static fn(array $leader): bool => gm_person_has_pending_account_request((int) $leader['person_id'], (string) $leader['primary_email'])));
$totalEvents = array_sum(array_map(static fn(array $leader): int => (int) ($leader['total_events'] ?? 0), $leaders));

$pageTitle = 'Group Manager | ' . $appName;
$heroTitle = 'Group Manager';
$heroText = 'Manage people, access, and Group details for ' . (string) $selectedGroup['group_name'] . '.';
$breadcrumb = '<a href="/index.php">Home</a> / Group Manager';

include __DIR__ . '/header.php';
?>

<style>
    .gm-switch-panel {
        margin-bottom: 1rem;
    }

    .gm-actions {
        display: grid;
        gap: .75rem;
        margin-bottom: 1rem;
    }

    @media (min-width: 768px) {
        .gm-actions {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
    }

    .gm-action-card {
        display: block;
        background: #fff;
        border: 2px solid var(--iv-purple);
        padding: 1rem;
        text-decoration: none;
        color: var(--iv-purple);
        font-weight: 900;
        min-height: 105px;
    }

    .gm-action-card:hover {
        color: var(--iv-purple-dark);
        text-decoration: none;
        border-color: var(--iv-purple-dark);
    }

    .gm-action-card span {
        display: block;
        color: #333;
        font-weight: 400;
        margin-top: .35rem;
    }

    .gm-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .gm-stat {
        background: #fff;
        border: 2px solid #e5e5e5;
        padding: 1rem;
    }

    .gm-stat strong {
        display: block;
        font-size: 2rem;
        line-height: 1;
        color: var(--iv-purple);
    }

    .gm-table-wrap {
        overflow-x: auto;
    }

    .gm-table th {
        white-space: nowrap;
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

    .gm-muted {
        color: #555;
    }

    .gm-row-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
    }
</style>

<main class="lt-main">
    <?php if (count($manageableGroups) > 1): ?>
        <form class="lt-panel gm-switch-panel" method="get">
            <div class="form-row align-items-end">
                <div class="form-group col-md-8 mb-md-0">
                    <label for="group_id"><strong>Managing Group</strong></label>
                    <select class="form-control" id="group_id" name="group_id">
                        <?php foreach ($manageableGroups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>" <?= (int) $group['id'] === $selectedGroupId ? 'selected' : '' ?>>
                                <?= e($group['group_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-4 mb-0">
                    <button class="btn btn-primary lt-btn btn-block" type="submit">Change Group</button>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <div class="gm-actions" aria-label="Group Manager actions">
        <a class="gm-action-card" href="/group-manager-add-person.php?group_id=<?= (int) $selectedGroupId ?>">
            Add person
            <span>Create or link a leader and start their access setup.</span>
        </a>

        <a class="gm-action-card" href="/group-manager-inactive.php?group_id=<?= (int) $selectedGroupId ?>">
            Inactive people
            <span>Reactivate someone or check who has been removed.</span>
        </a>

        <a class="gm-action-card" href="/group-manager-access.php?group_id=<?= (int) $selectedGroupId ?>">
            Calendar access
            <span>Copy, rotate, or disable this Group’s calendar link.</span>
        </a>

        <a class="gm-action-card" href="/group-manager-website.php?group_id=<?= (int) $selectedGroupId ?>">
            Group details
            <span>Manage public website, meeting, and contact details.</span>
        </a>
    </div>

    <div class="gm-stats">
        <div class="gm-stat">
            <strong><?= count($leaders) ?></strong>
            <span>active people</span>
        </div>

        <div class="gm-stat">
            <strong><?= $leadersWithSso ?></strong>
            <span>signed in with Microsoft</span>
        </div>

        <div class="gm-stat">
            <strong><?= $leadersWithoutSso ?></strong>
            <span>without Microsoft SSO</span>
        </div>

        <div class="gm-stat">
            <strong><?= count($groupLinks) ?></strong>
            <span>active calendar links</span>
        </div>

        <div class="gm-stat">
            <strong><?= $pendingAccountRequests ?></strong>
            <span>account requests pending</span>
        </div>

        <div class="gm-stat">
            <strong><?= (int) $totalEvents ?></strong>
            <span>linked calendar events</span>
        </div>
    </div>

    <section class="lt-panel">
        <h2 class="lt-section-title">People in <?= e($selectedGroup['group_name']) ?></h2>
        <p class="lt-lede">
            Use this page for quick checks. Open a person to edit their details, role, access instructions, or status.
        </p>

        <form method="get" class="form-row align-items-end mb-3">
            <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">

            <div class="form-group col-md-8">
                <label for="search">Search people</label>
                <input class="form-control" type="search" id="search" name="search" value="<?= e($search) ?>" placeholder="Name, email, or phone">
            </div>

            <div class="form-group col-md-4">
                <button class="btn btn-secondary lt-btn btn-block" type="submit">Search</button>
            </div>
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
                            <th>Action</th>
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
                                <strong><?= e($leader['full_name']) ?></strong><br>
                                <span class="gm-muted"><?= e($leader['primary_email']) ?></span>
                            </td>
                            <td><?= e(gm_role_title_from_membership_role((string) $leader['membership_role'])) ?></td>
                            <td><span class="gm-badge <?= e($accessClass) ?>"><?= e($accessLabel) ?></span></td>
                            <td>
                                <?= (int) $leader['total_events'] ?> total<br>
                                <span class="gm-muted"><?= (int) $leader['in_review_events'] ?> in review</span>
                            </td>
                            <td><?= $leader['latest_event_at'] ? e(date('d M Y', strtotime((string) $leader['latest_event_at']))) : '—' ?></td>
                            <td>
                                <div class="gm-row-actions">
                                    <a class="btn btn-sm btn-primary" href="/group-manager-person.php?group_id=<?= (int) $selectedGroupId ?>&person_id=<?= (int) $leader['person_id'] ?>">Manage</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">
                No active people match this view.
                <?php if ($search !== ''): ?>
                    <a href="/group-manager.php?group_id=<?= (int) $selectedGroupId ?>">Clear search</a>.
                <?php else: ?>
                    <a href="/group-manager-add-person.php?group_id=<?= (int) $selectedGroupId ?>">Add the first person</a>.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($inactivePeople): ?>
        <section class="lt-panel-grey mt-4">
            <h2 class="lt-section-title">Inactive people</h2>
            <p class="mb-3">
                <?= count($inactivePeople) ?> inactive <?= count($inactivePeople) === 1 ? 'person is' : 'people are' ?> linked to this Group.
            </p>
            <a class="btn btn-secondary lt-btn" href="/group-manager-inactive.php?group_id=<?= (int) $selectedGroupId ?>">Review inactive people</a>
        </section>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>