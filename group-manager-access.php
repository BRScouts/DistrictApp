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

    $pageTitle = 'Calendar access | ' . $appName;
    $heroTitle = 'Calendar access';
    $heroText = 'This area is for Group Lead Volunteers and District administrators.';
    $breadcrumb = '<a href="/index.php">Home</a> / <a href="/group-manager.php">Group Manager</a> / Calendar access';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors[] = 'Group calendar links cannot be changed from Group Manager. To rotate or disable a link, please raise a request in Technical Support.';
}

$activeLinks = gm_fetch_group_links($selectedGroupId, true);
$leaders = gm_fetch_people($selectedGroupId, 'active');
$leadersWithSso = count(array_filter($leaders, static fn(array $leader): bool => (int) ($leader['has_microsoft_account'] ?? 0) > 0));
$leadersWithoutSso = count($leaders) - $leadersWithSso;

$pageTitle = 'Calendar access | ' . $appName;
$heroTitle = 'Calendar access';
$heroText = 'View active calendar access links for ' . (string) $selectedGroup['group_name'] . '.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/group-manager.php?group_id=' . $selectedGroupId . '">Group Manager</a> / Calendar access';

include __DIR__ . '/header.php';
?>

<style>
    .gm-subnav {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        margin-bottom: 1rem;
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

    .gm-link-box {
        display: grid;
        gap: .5rem;
    }

    @media (min-width: 768px) {
        .gm-link-box {
            grid-template-columns: minmax(0, 1fr) auto;
        }
    }

    .gm-table-wrap {
        overflow-x: auto;
    }

    .gm-badge {
        display: inline-block;
        padding: .2rem .45rem;
        font-weight: 900;
        font-size: .78rem;
    }

    .gm-badge-active {
        background: #d1e7dd;
        color: #0f5132;
    }

    .gm-muted {
        color: #555;
    }

    .gm-notice {
        background: #fff8d6;
        border: 2px solid #e6e6e6;
        border-left: 8px solid #ffdd00;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .gm-notice h2 {
        margin-top: 0;
        color: var(--iv-purple);
        font-size: 1.25rem;
        font-weight: 900;
    }

    .gm-notice p:last-child {
        margin-bottom: 0;
    }

    .gm-link-readonly-note {
        background: #f7f5fb;
        border: 2px solid #e6e6e6;
        border-left: 8px solid var(--iv-purple);
        padding: 1rem;
    }

    .gm-link-readonly-note h2 {
        margin-top: 0;
        color: var(--iv-purple);
        font-size: 1.25rem;
        font-weight: 900;
    }

    .gm-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        align-items: center;
    }

    @media (max-width: 575.98px) {
        .gm-actions .btn {
            width: 100%;
        }
    }
</style>

<main class="lt-main">
    <div class="gm-subnav">
        <a class="btn btn-secondary lt-btn" href="/group-manager.php?group_id=<?= (int) $selectedGroupId ?>">Back to Group Manager</a>
        <a class="btn btn-secondary lt-btn" href="/group-manager-add-person.php?group_id=<?= (int) $selectedGroupId ?>">Add person</a>
        <a class="btn btn-secondary lt-btn" href="/group-manager-inactive.php?group_id=<?= (int) $selectedGroupId ?>">Inactive people</a>
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
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

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
            <strong><?= count($activeLinks) ?></strong>
            <span>active Group links</span>
        </div>
    </div>

    <div class="gm-grid gm-grid-2">
        <section class="lt-panel">
            <h2 class="lt-section-title">Preferred access: Microsoft sign-in</h2>
            <p class="lt-lede">
                Leaders should use Microsoft sign-in wherever possible. It gives clearer accountability and avoids relying on shared links.
            </p>

            <p>
                <?= (int) $leadersWithSso ?> of <?= count($leaders) ?> active people in this Group have signed in with Microsoft.
            </p>

            <a class="btn btn-primary lt-btn" href="/group-manager.php?group_id=<?= (int) $selectedGroupId ?>">Review people</a>
        </section>

        <aside class="lt-panel-grey">
            <h2 class="lt-section-title">Fallback access: Group link</h2>
            <p>
                Active Group calendar links are shown below for reference and copying.
            </p>
            <p class="mb-0">
                Group Managers cannot create, rotate or disable calendar links from this page.
            </p>
        </aside>
    </div>

    <section class="gm-notice mt-4">
        <h2>Need the Group link rotated?</h2>
        <p>
            If a Group calendar link has been shared too widely, has reached the wrong person, or needs replacing,
            please create a request in Technical Support.
        </p>

        <div class="gm-actions mt-3">
            <a class="btn btn-primary lt-btn" href="/technical-support.php">
                Create Technical Support request
            </a>
        </div>
    </section>

    <section class="lt-panel mt-4">
        <h2 class="lt-section-title">Active Group calendar links</h2>

        <?php if ($activeLinks): ?>
            <div class="gm-table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Status</th>
                            <th>Link</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activeLinks as $link): ?>
                        <?php $url = gm_group_link_url($link); ?>
                        <tr>
                            <td><?= e($link['label'] ?? 'Group calendar link') ?></td>
                            <td>
                                <span class="gm-badge gm-badge-active">Active</span>
                            </td>
                            <td>
                                <?php if ($url): ?>
                                    <div class="gm-link-box">
                                        <input class="form-control" type="text" value="<?= e($url) ?>" readonly>
                                        <button class="btn btn-secondary btn-sm gm-copy" type="button" data-copy="<?= e($url) ?>">Copy</button>
                                    </div>
                                <?php else: ?>
                                    <div class="gm-link-readonly-note">
                                        <h2>Link cannot be displayed</h2>
                                        <p class="mb-0">
                                            An active link exists, but the visible token is not available.
                                            Please create a Technical Support request if this Group needs a usable or rotated link.
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($link['created_at'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-warning mb-0">
                No active Group calendar links are currently available for this Group.
                If this Group needs a link, please create a request in Technical Support.
            </div>
        <?php endif; ?>
    </section>
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