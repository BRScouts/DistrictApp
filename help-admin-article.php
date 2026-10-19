<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/district-admin-helpers.php';
require_once __DIR__ . '/app/help-helpers.php';

require_login();

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
$actorPersonId = (int) $user['id'];

$memberships = da_current_memberships($actorPersonId);
$actorAccessLevel = da_current_access_level($user, $memberships);
$actorIsAdmin = da_is_district_admin($actorAccessLevel);

if (!$actorIsAdmin) {
    http_response_code(403);

    $pageTitle = 'Help Centre admin | ' . $appName;
    $heroTitle = 'Help Centre admin';
    $heroText = 'This area is for District administrators only.';
    $breadcrumb = '<a href="/index.php">Home</a> / <a href="/district-admin.php">District Admin</a> / Help article';

    include __DIR__ . '/header.php';

    echo '<main class="lt-main"><div class="alert alert-danger"><strong>Access denied:</strong> You must be a District Admin or System Admin to manage help articles.</div></main>';

    include __DIR__ . '/footer.php';
    exit;
}

$articleId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$article = $articleId > 0 ? help_find_article_by_id($articleId) : null;
$isNew = ($article === null);

$errors = [];
$success = null;

// The form field values. Seeded from the stored article (edit) or blank (new),
// then overwritten by POST so the user's input survives a validation error.
$form = [
    'title' => (string) ($article['title'] ?? ''),
    'slug' => (string) ($article['slug'] ?? ''),
    'summary' => (string) ($article['summary'] ?? ''),
    'keywords' => (string) ($article['keywords'] ?? ''),
    'topic' => (string) ($article['topic'] ?? ''),
    'status' => (string) ($article['status'] ?? 'draft'),
    'sort_order' => (string) ($article['sort_order'] ?? '0'),
    'body_source' => $article !== null ? help_body_to_source((array) ($article['body'] ?? [])) : '',
];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate();

        $action = (string) ($_POST['action'] ?? 'save');

        if ($action === 'delete') {
            if ($isNew) {
                throw new RuntimeException('There is no saved article to delete.');
            }

            help_delete_article($articleId);
            da_log_action($actorPersonId, 'help_article_deleted', 'help_article', $articleId, [
                'slug' => $article['slug'] ?? '',
            ]);

            redirect('/help-admin.php');
        }

        // Collect + trim input.
        $form['title'] = trim((string) ($_POST['title'] ?? ''));
        $form['slug'] = trim((string) ($_POST['slug'] ?? ''));
        $form['summary'] = trim((string) ($_POST['summary'] ?? ''));
        $form['keywords'] = trim((string) ($_POST['keywords'] ?? ''));
        $form['topic'] = trim((string) ($_POST['topic'] ?? ''));
        $form['status'] = (string) ($_POST['status'] ?? 'draft');
        $form['sort_order'] = trim((string) ($_POST['sort_order'] ?? '0'));
        $form['body_source'] = (string) ($_POST['body_source'] ?? '');

        // Validate.
        if ($form['title'] === '') {
            $errors[] = 'Enter a title for the article.';
        }

        if (!in_array($form['status'], ['draft', 'published'], true)) {
            $form['status'] = 'draft';
        }

        if ($form['sort_order'] !== '' && !preg_match('/^-?\d+$/', $form['sort_order'])) {
            $errors[] = 'Sort order must be a whole number.';
        }

        // Derive the slug from the title when the admin leaves it blank, then
        // make sure it is unique (ignoring this article when editing).
        $desiredSlug = $form['slug'] !== '' ? $form['slug'] : $form['title'];

        if (!$errors) {
            $slug = help_unique_slug($desiredSlug, $isNew ? 0 : $articleId);
            $form['slug'] = $slug;

            $blocks = help_parse_body_source($form['body_source']);

            $fields = [
                'slug' => $slug,
                'title' => $form['title'],
                'summary' => $form['summary'],
                'keywords' => $form['keywords'],
                'topic' => $form['topic'],
                'status' => $form['status'],
                'sort_order' => (int) $form['sort_order'],
                'body' => $blocks,
            ];

            if ($isNew) {
                $articleId = help_create_article($fields);
                $article = help_find_article_by_id($articleId);
                $isNew = false;

                da_log_action($actorPersonId, 'help_article_created', 'help_article', $articleId, [
                    'slug' => $slug,
                    'status' => $form['status'],
                ]);

                $success = 'Help article created.';
            } else {
                help_update_article($articleId, $fields);
                $article = help_find_article_by_id($articleId);

                da_log_action($actorPersonId, 'help_article_updated', 'help_article', $articleId, [
                    'slug' => $slug,
                    'status' => $form['status'],
                ]);

                $success = 'Help article saved.';
            }
        }
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage() ?: 'The article could not be saved.';
}

$pageTitle = ($isNew ? 'New help article' : 'Edit help article') . ' | ' . $appName;
$heroTitle = $isNew ? 'New help article' : 'Edit help article';
$heroText = $isNew
    ? 'Write a new help article for the Help Centre.'
    : 'Update this help article. Changes go live as soon as you save a published article.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/district-admin.php">District Admin</a> / <a href="/help-admin.php">Help Centre</a> / ' . ($isNew ? 'New article' : 'Edit article');

include __DIR__ . '/header.php';
?>

<style>
    .ha-grid {
        display: grid;
        gap: 1.25rem;
    }

    @media (min-width: 992px) {
        .ha-grid-2 {
            grid-template-columns: minmax(0, 2fr) minmax(300px, 1fr);
            align-items: start;
        }
    }

    .ha-subnav {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        margin-bottom: 1rem;
    }

    .ha-body-input {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: .95rem;
        line-height: 1.5;
        min-height: 320px;
    }

    .ha-format-help {
        font-size: .9rem;
    }

    .ha-format-help code {
        background: var(--iv-grey-100);
        padding: .1rem .3rem;
        font-weight: 700;
    }

    .ha-format-help ul {
        padding-left: 1.1rem;
        margin: 0;
    }

    .ha-format-help li {
        margin-bottom: .4rem;
        font-weight: 700;
    }

    .ha-danger-panel {
        border: 2px solid #842029;
        background: #fff;
        padding: 1rem;
        margin-top: 1.5rem;
    }

    .ha-muted {
        color: #555;
        font-size: .85rem;
    }
</style>

<main class="lt-main">
    <div class="ha-subnav">
        <a class="btn btn-secondary lt-btn" href="/help-admin.php">Back to all articles</a>

        <?php if (!$isNew && ($article['status'] ?? '') === 'published'): ?>
            <a class="btn btn-secondary lt-btn" href="/help-centre.php?slug=<?= e(rawurlencode((string) $article['slug'])) ?>" target="_blank" rel="noopener">View live article</a>
        <?php endif; ?>
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

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) $articleId ?>">

        <div class="ha-grid ha-grid-2">
            <section class="lt-panel">
                <h2 class="lt-section-title">Article content</h2>

                <div class="form-group">
                    <label for="title">Title</label>
                    <input class="form-control" type="text" id="title" name="title" value="<?= e($form['title']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="summary">Summary</label>
                    <input class="form-control" type="text" id="summary" name="summary" value="<?= e($form['summary']) ?>" maxlength="500">
                    <small class="form-text">Shown under the title in the index and in search results.</small>
                </div>

                <div class="form-group">
                    <label for="body_source">Body</label>
                    <textarea class="form-control ha-body-input" id="body_source" name="body_source" spellcheck="true"><?= e($form['body_source']) ?></textarea>
                    <small class="form-text">Separate blocks with a blank line. See the formatting guide on the right.</small>
                </div>
            </section>

            <aside>
                <section class="lt-panel">
                    <h2 class="lt-section-title">Publishing</h2>

                    <div class="form-group">
                        <label for="status">Status</label>
                        <select class="form-control" id="status" name="status">
                            <option value="draft" <?= $form['status'] === 'draft' ? 'selected' : '' ?>>Draft (hidden)</option>
                            <option value="published" <?= $form['status'] === 'published' ? 'selected' : '' ?>>Published (live)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="topic">Topic</label>
                        <input class="form-control" type="text" id="topic" name="topic" value="<?= e($form['topic']) ?>" list="ha-topics" placeholder="e.g. Events">
                        <datalist id="ha-topics">
                            <option value="Events"></option>
                            <option value="People &amp; Access"></option>
                            <option value="Risk Assessments"></option>
                            <option value="Email &amp; Microsoft 365"></option>
                            <option value="Getting Help"></option>
                        </datalist>
                        <small class="form-text">Articles are grouped by topic on the index.</small>
                    </div>

                    <div class="form-group">
                        <label for="sort_order">Sort order</label>
                        <input class="form-control" type="number" id="sort_order" name="sort_order" value="<?= e($form['sort_order']) ?>" step="1">
                        <small class="form-text">Lower numbers appear first within the topic.</small>
                    </div>

                    <div class="form-group">
                        <label for="slug">Slug</label>
                        <input class="form-control" type="text" id="slug" name="slug" value="<?= e($form['slug']) ?>" placeholder="auto from title">
                        <small class="form-text">The URL for the article. Leave blank to generate from the title.</small>
                    </div>

                    <div class="form-group">
                        <label for="keywords">Search keywords</label>
                        <input class="form-control" type="text" id="keywords" name="keywords" value="<?= e($form['keywords']) ?>" maxlength="500">
                        <small class="form-text">Extra words that should match this article in search.</small>
                    </div>

                    <button class="btn btn-primary lt-btn btn-block" type="submit"><?= $isNew ? 'Create article' : 'Save article' ?></button>
                </section>

                <section class="lt-panel-grey mt-4 ha-format-help">
                    <h2 class="lt-section-title">Formatting guide</h2>
                    <ul>
                        <li><strong>Paragraph</strong>: just type a line of text.</li>
                        <li><strong>Heading</strong>: start the line with <code>##</code> (more hashes = smaller heading).</li>
                        <li><strong>Steps</strong>: start each line with <code>-</code> to make a numbered list.</li>
                        <li><strong>Button</strong>: write <code>[Label](/page.php)</code>. A full <code>https://</code> link opens in a new tab.</li>
                    </ul>
                    <p class="ha-muted mt-2 mb-0">Leave a blank line between each block.</p>
                </section>
            </aside>
        </div>
    </form>

    <?php if (!$isNew): ?>
        <div class="ha-danger-panel">
            <h2 class="lt-section-title">Delete this article</h2>
            <p class="ha-muted">Deleting removes the article permanently. This cannot be undone.</p>

            <form method="post" data-confirm="Delete this help article permanently? This cannot be undone.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $articleId ?>">
                <button class="btn btn-outline-danger lt-btn" type="submit">Delete article</button>
            </form>
        </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
