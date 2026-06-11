<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/district-admin-helpers.php';

require_login();

$pdo = db();
$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

$memberships = da_current_memberships((int) $user['id']);
$actorAccessLevel = da_current_access_level($user, $memberships);
$actorIsAdmin = da_is_district_admin($actorAccessLevel);

if (!$actorIsAdmin) {
    http_response_code(403);

    $pageTitle = 'District Admin | ' . $appName;
    $heroTitle = 'District Admin';
    $heroText = 'This area is for District administrators only.';
    $breadcrumb = '<a href="/index.php">Home</a> / District Admin';

    include __DIR__ . '/header.php';

    echo '<main class="lt-main"><div class="alert alert-danger"><strong>Access denied:</strong> You must be a District Admin or System Admin to use this page.</div></main>';

    include __DIR__ . '/footer.php';
    exit;
}

$groups = da_fetch_groups();

$pageTitle = 'District Admin | ' . $appName;
$heroTitle = 'District Admin';
$heroText = 'Manage Groups, Group calendar links, and who can edit each Group.';
$breadcrumb = '<a href="/index.php">Home</a> / District Admin';

include __DIR__ . '/header.php';
?>

<style>
    .da-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        margin-bottom: 1.25rem;
    }

    .da-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 1rem;
        margin-bottom: 1.25rem;
    }

    .da-stat {
        background: #fff;
        border: 2px solid #e5e5e5;
        padding: 1rem;
    }

    .da-stat strong {
        display: block;
        font-size: 2rem;
        line-height: 1;
        color: var(--iv-purple);
    }

    .da-table-wrap {
        overflow-x: auto;
    }

    .da-badge {
        display: inline-block;
        padding: .2rem .45rem;
        font-weight: 900;
        font-size: .78rem;
    }

    .da-badge-active {
        background: #d1e7dd;
        color: #0f5132;
    }

    .da-badge-inactive {
        background: #f8d7da;
        color: #842029;
    }

    .da-muted {
        color: #555;
    }

    .da-action-row {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
    }
</style>

<main class="lt-main">
    <div class="da-actions">
        <a class="btn btn-primary lt-btn" href="/district-admin-group.php">Add Group</a>
        <a class="btn btn-secondary lt-btn" href="/group-manager.php">Open Group Manager</a>
        <a class="btn btn-secondary lt-btn" href="/directory.php">Open Directory</a>
    </div>

    <div class="da-stats">
        <div class="da-stat">
            <strong><?= count($groups) ?></strong>
            <span>total Groups</span>
        </div>

        <div class="da-stat">
            <strong><?= count(array_filter($groups, static fn(array $group): bool => (int) ($group['is_active'] ?? 0) === 1)) ?></strong>
            <span>active Groups</span>
        </div>

        <div class="da-stat">
            <strong><?= array_sum(array_map(static fn(array $group): int => (int) ($group['active_people_count'] ?? 0), $groups)) ?></strong>
            <span>active memberships</span>
        </div>
    </div>

    <section class="lt-panel">
        <h2 class="lt-section-title">Groups</h2>
        <p class="lt-lede">
            Pick a Group to edit its details, link access, and named people who are allowed to manage it.
        </p>

        <?php if ($groups): ?>
            <div class="da-table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Group</th>
                            <th>Can edit this Group</th>
                            <th>People</th>
                            <th>Calendar links</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($groups as $group): ?>
                        <tr>
                            <td>
                                <strong><?= e($group['group_name'] ?? $group['name'] ?? 'Unnamed Group') ?></strong><br>
                                <span class="da-muted">Slug: <?= e($group['slug'] ?? '') ?></span>
                            </td>

                            <td>
                                <?= e($group['group_editors'] ?: 'No Group editor assigned') ?>
                            </td>

                            <td>
                                <?= (int) ($group['active_people_count'] ?? 0) ?>
                            </td>

                            <td>
                                <?= (int) ($group['active_link_count'] ?? 0) ?> active
                            </td>

                            <td>
                                <?= (int) ($group['is_active'] ?? 0) === 1
                                    ? '<span class="da-badge da-badge-active">Active</span>'
                                    : '<span class="da-badge da-badge-inactive">Inactive</span>' ?>
                            </td>

                            <td>
                                <div class="da-action-row">
                                    <a class="btn btn-sm btn-primary" href="/district-admin-group.php?group_id=<?= (int) $group['id'] ?>">Edit Group</a>
                                    <a class="btn btn-sm btn-secondary" href="/group-manager.php?group_id=<?= (int) $group['id'] ?>">People</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">
                No Groups have been created yet.
            </div>
        <?php endif; ?>
    </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>