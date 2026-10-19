<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/district-admin-helpers.php';
require_once __DIR__ . '/app/help-helpers.php';

require_login();

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

$memberships = da_current_memberships((int) $user['id']);
$actorAccessLevel = da_current_access_level($user, $memberships);
$actorIsAdmin = da_is_district_admin($actorAccessLevel);

if (!$actorIsAdmin) {
    http_response_code(403);

    $pageTitle = 'Help Centre admin | ' . $appName;
    $heroTitle = 'Help Centre admin';
    $heroText = 'This area is for District administrators only.';
    $breadcrumb = '<a href="/index.php">Home</a> / Help Centre admin';

    include __DIR__ . '/header.php';

    echo '<main class="lt-main"><div class="alert alert-danger"><strong>Access denied:</strong> You must be a District Admin or System Admin to manage help articles.</div></main>';

    include __DIR__ . '/footer.php';
    exit;
}

$articles = help_all_articles();
$grouped = help_group_by_topic($articles);

$publishedCount = count(array_filter(
    $articles,
    static fn(array $a): bool => ($a['status'] ?? '') === 'published'
));
$draftCount = count($articles) - $publishedCount;

$pageTitle = 'Help Centre admin | ' . $appName;
$heroTitle = 'Help Centre admin';
$heroText = 'Create, edit and publish the help articles leaders see in the Help Centre.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/district-admin.php">District Admin</a> / Help Centre';

include __DIR__ . '/header.php';
?>

<style>
    .ha-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        margin-bottom: 1.25rem;
    }

    .ha-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .ha-stat {
        background: #fff;
        border: 2px solid #e5e5e5;
        padding: 1rem;
    }

    .ha-stat strong {
        display: block;
        font-size: 2rem;
        line-height: 1;
        color: var(--iv-purple);
    }

    .ha-table-wrap {
        overflow-x: auto;
    }

    .ha-badge {
        display: inline-block;
        padding: .2rem .45rem;
        font-weight: 900;
        font-size: .78rem;
    }

    .ha-badge-published {
        background: #d1e7dd;
        color: #0f5132;
    }

    .ha-badge-draft {
        background: #fff3cd;
        color: #664d03;
    }

    .ha-muted {
        color: #555;
    }

    .ha-topic-heading {
        margin: 1.5rem 0 .5rem;
        font-size: 1.1rem;
        font-weight: 900;
    }

    .ha-action-row {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
    }
</style>

<main class="lt-main">
    <div class="ha-actions">
        <a class="btn btn-primary lt-btn" href="/help-admin-article.php">New help article</a>
        <a class="btn btn-secondary lt-btn" href="/help-centre.php">View the Help Centre</a>
        <a class="btn btn-secondary lt-btn" href="/district-admin.php">Back to District Admin</a>
    </div>

    <div class="ha-stats">
        <div class="ha-stat">
            <strong><?= count($articles) ?></strong>
            <span>total articles</span>
        </div>

        <div class="ha-stat">
            <strong><?= $publishedCount ?></strong>
            <span>published</span>
        </div>

        <div class="ha-stat">
            <strong><?= $draftCount ?></strong>
            <span>drafts</span>
        </div>
    </div>

    <section class="lt-panel">
        <h2 class="lt-section-title">Help articles</h2>
        <p class="lt-lede">
            Articles are grouped by topic, in the order leaders see them. Published articles are live in the Help Centre; drafts are hidden.
        </p>

        <?php if ($articles): ?>
            <?php foreach ($grouped as $topic => $topicArticles): ?>
                <h3 class="ha-topic-heading"><?= e($topic !== '' ? (string) $topic : 'General') ?></h3>

                <div class="ha-table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Slug</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($topicArticles as $article): ?>
                            <tr>
                                <td>
                                    <strong><?= e((string) ($article['title'] ?? 'Untitled')) ?></strong><br>
                                    <span class="ha-muted"><?= e((string) ($article['summary'] ?? '')) ?></span>
                                </td>
                                <td><span class="ha-muted"><?= e((string) ($article['slug'] ?? '')) ?></span></td>
                                <td><?= (int) ($article['sort_order'] ?? 0) ?></td>
                                <td>
                                    <?= ($article['status'] ?? '') === 'published'
                                        ? '<span class="ha-badge ha-badge-published">Published</span>'
                                        : '<span class="ha-badge ha-badge-draft">Draft</span>' ?>
                                </td>
                                <td>
                                    <div class="ha-action-row">
                                        <a class="btn btn-sm btn-primary" href="/help-admin-article.php?id=<?= (int) $article['id'] ?>">Edit</a>
                                        <?php if (($article['status'] ?? '') === 'published'): ?>
                                            <a class="btn btn-sm btn-secondary" href="/help-centre.php?slug=<?= e(rawurlencode((string) $article['slug'])) ?>" target="_blank" rel="noopener">View</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-info mb-0">
                No help articles exist yet. Use <strong>New help article</strong> to create the first one.
            </div>
        <?php endif; ?>
    </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>
