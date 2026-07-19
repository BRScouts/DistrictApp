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
$newLinkUrl = null;
$actorPersonId = (int) $user['id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');

        if (!gm_group_is_manageable($selectedGroupId, $manageableGroups)) {
            throw new RuntimeException('You do not have permission to manage that Group.');
        }

        if ($action === 'generate_group_link') {
            $label = trim((string) ($_POST['label'] ?? 'Main Group calendar link'));
            $disableExisting = isset($_POST['disable_existing']);

            $newLinkUrl = gm_generate_group_link(
                $selectedGroupId,
                $actorPersonId,
                $label,
                $disableExisting
            );

            $success = $disableExisting
                ? 'Group calendar link rotated. Existing active links were disabled.'
                : 'New Group calendar link generated.';
        } elseif ($action === 'disable_group_link') {
            $linkId = (int) ($_POST['link_id'] ?? 0);

            gm_disable_group_link($selectedGroupId, $linkId, $actorPersonId);
            $success = 'Group calendar link disabled.';
        }
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage() ?: 'The request could not be completed.';
}

$activeLinks = gm_fetch_group_links($selectedGroupId, true);
$allLinks = gm_fetch_group_links($selectedGroupId, false);
$leaders = gm_fetch_people($selectedGroupId, 'active');
$leadersWithSso = count(array_filter($leaders, static fn(array $leader): bool => (int) ($leader['has_microsoft_account'] ?? 0) > 0));
$leadersWithoutSso = count($leaders) - $leadersWithSso;

$pageTitle = 'Calendar access | ' . $appName;
$heroTitle = 'Calendar access';
$heroText = 'Manage Microsoft sign-in guidance and fallback calendar links for ' . (string) $selectedGroup['group_name'] . '.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/group-manager.php?group_id=' . $selectedGroupId . '">Group Manager</a> / Calendar access';

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

    .gm-badge-inactive {
        background: #f8d7da;
        color: #842029;
    }

    .gm-muted {
        color: #555;
    }
</style>

<main class="lt-main">
    <a class="gm-back-link" href="/group-manager.php?group_id=<?= (int) $selectedGroupId ?>">Back to Group Manager</a>

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

    <?php if ($newLinkUrl): ?>
        <div class="alert alert-info">
            <strong>New Group calendar link:</strong>
            <div class="gm-link-box mt-2">
                <input class="form-control" type="text" value="<?= e($newLinkUrl) ?>" readonly>
                <button class="btn btn-secondary lt-btn gm-copy" type="button" data-copy="<?= e($newLinkUrl) ?>">Copy</button>
            </div>
        </div>
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
                Leaders should use the Microsoft sign-in button wherever possible. It gives clearer accountability and avoids relying on shared links.
            </p>

            <p>
                <?= (int) $leadersWithSso ?> of <?= count($leaders) ?> active people in this Group have signed in with Microsoft.
            </p>

            <a class="btn btn-primary lt-btn" href="/group-manager.php?group_id=<?= (int) $selectedGroupId ?>">Review people</a>
        </section>

        <aside class="lt-panel-grey">
            <h2 class="lt-section-title">Fallback access: Group link</h2>
            <p>
                The Group calendar link is a fallback for onboarding and leaders who cannot use a District Microsoft 365 account yet.
            </p>
            <p class="mb-0">
                Rotate the link if it has been shared too widely or a leader leaves and still has the old link.
            </p>
        </aside>
    </div>

   
    <section class="lt-panel mt-4">
        <h2 class="lt-section-title">Existing links</h2>

        <?php if ($allLinks): ?>
            <div class="gm-table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Status</th>
                            <th>Link</th>
                            <th>Created</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allLinks as $link): ?>
                        <?php $url = gm_group_link_url($link); ?>
                        <tr>
                            <td><?= e($link['label'] ?? 'Group calendar link') ?></td>
                            <td>
                                <?php if (($link['status'] ?? '') === 'active'): ?>
                                    <span class="gm-badge gm-badge-active">Active</span>
                                <?php else: ?>
                                    <span class="gm-badge gm-badge-inactive"><?= e($link['status'] ?? 'Inactive') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($url && ($link['status'] ?? '') === 'active'): ?>
                                    <div class="gm-link-box">
                                        <input class="form-control" type="text" value="<?= e($url) ?>" readonly>
                                        <button class="btn btn-secondary btn-sm gm-copy" type="button" data-copy="<?= e($url) ?>">Copy</button>
                                    </div>
                                <?php elseif ($url): ?>
                                    <span class="gm-muted">Disabled link hidden from normal use.</span>
                                <?php else: ?>
                                    <span class="text-warning font-weight-bold">No visible token. Rotate this link.</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($link['created_at'] ?? '—') ?></td>
                            <td>
                                <?php if (($link['status'] ?? '') === 'active'): ?>
                                    <form method="post" onsubmit="return confirm('Disable this Group calendar link?');">
                                        <input type="hidden" name="action" value="disable_group_link">
                                        <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                                        <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-warning mb-0">
                No Group calendar links exist yet. Generate one above if this Group needs fallback access.
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