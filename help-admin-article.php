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
];

// The body is edited as a list of Rich_Body blocks via the GUI block editor.
// Seed from the stored article; POST overwrites it so input survives an error.
$blocks = $article !== null && is_array($article['body'] ?? null)
    ? $article['body']
    : [];

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

        // Build the body blocks from the GUI editor's structured POST data.
        $blocks = help_blocks_from_post($_POST['blocks'] ?? null);

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

            // Refresh the editor blocks from the canonical stored article so
            // the GUI reflects exactly what was saved.
            if (is_array($article['body'] ?? null)) {
                $blocks = $article['body'];
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

    /* ── GUI block editor ── */
    .ha-blocks {
        display: grid;
        gap: .85rem;
        margin: .5rem 0 1rem;
    }

    .ha-block {
        border: 1px solid var(--iv-grey-300);
        border-left: 4px solid var(--iv-purple);
        background: #fff;
        padding: .85rem;
    }

    .ha-block-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .5rem;
        margin-bottom: .6rem;
    }

    .ha-block-tag {
        font-weight: 900;
        font-size: .78rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: var(--iv-purple);
    }

    .ha-block-controls {
        display: flex;
        gap: .3rem;
    }

    .ha-block-controls .btn {
        padding: .15rem .5rem;
        line-height: 1.2;
    }

    .ha-field-row {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
    }

    .ha-field-row .form-control:first-child {
        flex: 1 1 200px;
    }

    .ha-level,
    .ha-style {
        flex: 0 0 auto;
        width: auto;
    }

    .ha-items,
    .ha-actions-list {
        display: grid;
        gap: .5rem;
        margin-bottom: .6rem;
    }

    .ha-item {
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .ha-item-num {
        flex: 0 0 auto;
        width: 1.6rem;
        font-weight: 900;
        color: var(--iv-grey-700);
        text-align: right;
    }

    .ha-item .form-control {
        flex: 1 1 auto;
    }

    .ha-action-item {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        align-items: center;
    }

    .ha-action-item .form-control[name*="[label]"] {
        flex: 1 1 160px;
    }

    .ha-action-item .form-control[name*="[url]"] {
        flex: 2 1 220px;
    }

    .ha-item-remove,
    .ha-action-remove {
        flex: 0 0 auto;
        padding: .15rem .55rem;
        font-weight: 900;
        line-height: 1.2;
    }

    .ha-add-block {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .4rem;
        padding-top: .5rem;
        border-top: 1px dashed var(--iv-grey-300);
    }

    .ha-add-label {
        font-weight: 900;
        font-size: .85rem;
        color: var(--iv-grey-700);
        margin-right: .25rem;
    }

    .ha-empty-hint {
        padding: .75rem;
        background: var(--iv-grey-100);
        font-weight: 700;
        color: var(--iv-grey-700);
        font-size: .9rem;
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
                    <label>Body</label>
                    <small class="form-text">Build the article from blocks. Add paragraphs, headings, step lists and buttons, and drag the arrows to reorder them.</small>

                    <div id="ha-blocks" class="ha-blocks" data-next-index="<?= count($blocks) ?>">
                        <?php
                        // Server-render each stored block as an editor card. The
                        // index N namespaces the fields as blocks[N][...]; the JS
                        // uses data-next-index to continue numbering new blocks.
                        foreach ($blocks as $i => $block):
                            if (!is_array($block)) {
                                continue;
                            }
                            $type = (string) ($block['type'] ?? '');
                        ?>
                            <?php if ($type === 'paragraph'): ?>
                                <div class="ha-block" data-block-type="paragraph">
                                    <div class="ha-block-head">
                                        <span class="ha-block-tag">Paragraph</span>
                                        <?php include __DIR__ . '/app/help-block-controls.php'; ?>
                                    </div>
                                    <input type="hidden" name="blocks[<?= $i ?>][type]" value="paragraph">
                                    <textarea class="form-control" name="blocks[<?= $i ?>][text]" rows="3" placeholder="Paragraph text"><?= e((string) ($block['text'] ?? '')) ?></textarea>
                                </div>

                            <?php elseif ($type === 'heading'): ?>
                                <?php $level = (int) ($block['level'] ?? 2); ?>
                                <div class="ha-block" data-block-type="heading">
                                    <div class="ha-block-head">
                                        <span class="ha-block-tag">Heading</span>
                                        <?php include __DIR__ . '/app/help-block-controls.php'; ?>
                                    </div>
                                    <input type="hidden" name="blocks[<?= $i ?>][type]" value="heading">
                                    <div class="ha-field-row">
                                        <input class="form-control" type="text" name="blocks[<?= $i ?>][text]" value="<?= e((string) ($block['text'] ?? '')) ?>" placeholder="Heading text">
                                        <select class="form-control ha-level" name="blocks[<?= $i ?>][level]" aria-label="Heading size">
                                            <option value="2" <?= $level === 2 ? 'selected' : '' ?>>Large</option>
                                            <option value="3" <?= $level === 3 ? 'selected' : '' ?>>Medium</option>
                                            <option value="4" <?= $level >= 4 ? 'selected' : '' ?>>Small</option>
                                        </select>
                                    </div>
                                </div>

                            <?php elseif ($type === 'steps'): ?>
                                <?php $items = is_array($block['items'] ?? null) ? $block['items'] : []; ?>
                                <div class="ha-block" data-block-type="steps">
                                    <div class="ha-block-head">
                                        <span class="ha-block-tag">Steps</span>
                                        <?php include __DIR__ . '/app/help-block-controls.php'; ?>
                                    </div>
                                    <input type="hidden" name="blocks[<?= $i ?>][type]" value="steps">
                                    <div class="ha-items" data-items>
                                        <?php foreach ($items as $item): ?>
                                            <div class="ha-item" data-item>
                                                <span class="ha-item-num" aria-hidden="true"></span>
                                                <input class="form-control" type="text" name="blocks[<?= $i ?>][items][]" value="<?= e((string) $item) ?>" placeholder="Step text">
                                                <button type="button" class="btn btn-outline-danger btn-sm ha-item-remove" aria-label="Remove step">&times;</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="btn btn-secondary btn-sm ha-add-item">Add step</button>
                                </div>

                            <?php elseif ($type === 'action'): ?>
                                <?php $actions = is_array($block['actions'] ?? null) ? $block['actions'] : []; ?>
                                <div class="ha-block" data-block-type="action">
                                    <div class="ha-block-head">
                                        <span class="ha-block-tag">Buttons</span>
                                        <?php include __DIR__ . '/app/help-block-controls.php'; ?>
                                    </div>
                                    <input type="hidden" name="blocks[<?= $i ?>][type]" value="action">
                                    <div class="ha-actions-list" data-actions>
                                        <?php foreach (array_values($actions) as $ai => $a): ?>
                                            <?php
                                            if (!is_array($a)) {
                                                continue;
                                            }
                                            $aStyle = ($a['style'] ?? '') === 'secondary' ? 'secondary' : 'primary';
                                            ?>
                                            <div class="ha-action-item" data-action>
                                                <input class="form-control" type="text" name="blocks[<?= $i ?>][actions][<?= $ai ?>][label]" value="<?= e((string) ($a['label'] ?? '')) ?>" placeholder="Button label">
                                                <input class="form-control" type="text" name="blocks[<?= $i ?>][actions][<?= $ai ?>][url]" value="<?= e((string) ($a['url'] ?? '')) ?>" placeholder="/page.php or https://…">
                                                <select class="form-control ha-style" name="blocks[<?= $i ?>][actions][<?= $ai ?>][style]" aria-label="Button style">
                                                    <option value="primary" <?= $aStyle === 'primary' ? 'selected' : '' ?>>Primary</option>
                                                    <option value="secondary" <?= $aStyle === 'secondary' ? 'selected' : '' ?>>Secondary</option>
                                                </select>
                                                <button type="button" class="btn btn-outline-danger btn-sm ha-action-remove" aria-label="Remove button">&times;</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="btn btn-secondary btn-sm ha-add-action">Add button</button>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="ha-add-block">
                        <span class="ha-add-label">Add a block:</span>
                        <button type="button" class="btn btn-secondary btn-sm" data-add-block="paragraph">Paragraph</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-add-block="heading">Heading</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-add-block="steps">Steps</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-add-block="action">Buttons</button>
                    </div>
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
                    <h2 class="lt-section-title">Block guide</h2>
                    <ul>
                        <li><strong>Paragraph</strong> — a block of plain text.</li>
                        <li><strong>Heading</strong> — a sub-title. Large, Medium or Small.</li>
                        <li><strong>Steps</strong> — a numbered list; add one step per line.</li>
                        <li><strong>Buttons</strong> — one or more links. A full <code>https://</code> link opens in a new tab; a path like <code>/page.php</code> stays in the app.</li>
                    </ul>
                    <p class="ha-muted mt-2 mb-0">Use the arrows on each block to reorder, and &times; to remove. Empty blocks are dropped when you save.</p>
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

<!-- Block templates for the GUI editor. Kept out of the DOM until cloned.
     The index placeholder __I__ is replaced with the block's position, and
     all blocks are renumbered on every change and before submit so PHP always
     receives sequential blocks[0..n] in visible order. -->
<template id="ha-tpl-paragraph">
    <div class="ha-block" data-block-type="paragraph">
        <div class="ha-block-head">
            <span class="ha-block-tag">Paragraph</span>
            <div class="ha-block-controls">
                <button type="button" class="btn btn-outline-secondary btn-sm ha-move-up" aria-label="Move block up" title="Move up">&uarr;</button>
                <button type="button" class="btn btn-outline-secondary btn-sm ha-move-down" aria-label="Move block down" title="Move down">&darr;</button>
                <button type="button" class="btn btn-outline-danger btn-sm ha-block-remove" aria-label="Remove block" title="Remove block">&times;</button>
            </div>
        </div>
        <input type="hidden" name="blocks[__I__][type]" value="paragraph">
        <textarea class="form-control" name="blocks[__I__][text]" rows="3" placeholder="Paragraph text"></textarea>
    </div>
</template>

<template id="ha-tpl-heading">
    <div class="ha-block" data-block-type="heading">
        <div class="ha-block-head">
            <span class="ha-block-tag">Heading</span>
            <div class="ha-block-controls">
                <button type="button" class="btn btn-outline-secondary btn-sm ha-move-up" aria-label="Move block up" title="Move up">&uarr;</button>
                <button type="button" class="btn btn-outline-secondary btn-sm ha-move-down" aria-label="Move block down" title="Move down">&darr;</button>
                <button type="button" class="btn btn-outline-danger btn-sm ha-block-remove" aria-label="Remove block" title="Remove block">&times;</button>
            </div>
        </div>
        <input type="hidden" name="blocks[__I__][type]" value="heading">
        <div class="ha-field-row">
            <input class="form-control" type="text" name="blocks[__I__][text]" placeholder="Heading text">
            <select class="form-control ha-level" name="blocks[__I__][level]" aria-label="Heading size">
                <option value="2">Large</option>
                <option value="3">Medium</option>
                <option value="4">Small</option>
            </select>
        </div>
    </div>
</template>

<template id="ha-tpl-steps">
    <div class="ha-block" data-block-type="steps">
        <div class="ha-block-head">
            <span class="ha-block-tag">Steps</span>
            <div class="ha-block-controls">
                <button type="button" class="btn btn-outline-secondary btn-sm ha-move-up" aria-label="Move block up" title="Move up">&uarr;</button>
                <button type="button" class="btn btn-outline-secondary btn-sm ha-move-down" aria-label="Move block down" title="Move down">&darr;</button>
                <button type="button" class="btn btn-outline-danger btn-sm ha-block-remove" aria-label="Remove block" title="Remove block">&times;</button>
            </div>
        </div>
        <input type="hidden" name="blocks[__I__][type]" value="steps">
        <div class="ha-items" data-items></div>
        <button type="button" class="btn btn-secondary btn-sm ha-add-item">Add step</button>
    </div>
</template>

<template id="ha-tpl-step-item">
    <div class="ha-item" data-item>
        <span class="ha-item-num" aria-hidden="true"></span>
        <input class="form-control" type="text" name="__ITEMS__" placeholder="Step text">
        <button type="button" class="btn btn-outline-danger btn-sm ha-item-remove" aria-label="Remove step">&times;</button>
    </div>
</template>

<template id="ha-tpl-action">
    <div class="ha-block" data-block-type="action">
        <div class="ha-block-head">
            <span class="ha-block-tag">Buttons</span>
            <div class="ha-block-controls">
                <button type="button" class="btn btn-outline-secondary btn-sm ha-move-up" aria-label="Move block up" title="Move up">&uarr;</button>
                <button type="button" class="btn btn-outline-secondary btn-sm ha-move-down" aria-label="Move block down" title="Move down">&darr;</button>
                <button type="button" class="btn btn-outline-danger btn-sm ha-block-remove" aria-label="Remove block" title="Remove block">&times;</button>
            </div>
        </div>
        <input type="hidden" name="blocks[__I__][type]" value="action">
        <div class="ha-actions-list" data-actions></div>
        <button type="button" class="btn btn-secondary btn-sm ha-add-action">Add button</button>
    </div>
</template>

<template id="ha-tpl-action-item">
    <div class="ha-action-item" data-action>
        <input class="form-control" type="text" name="__LABEL__" placeholder="Button label">
        <input class="form-control" type="text" name="__URL__" placeholder="/page.php or https://…">
        <select class="form-control ha-style" name="__STYLE__" aria-label="Button style">
            <option value="primary">Primary</option>
            <option value="secondary">Secondary</option>
        </select>
        <button type="button" class="btn btn-outline-danger btn-sm ha-action-remove" aria-label="Remove button">&times;</button>
    </div>
</template>

<script <?= csp_nonce_attr() ?>>
(function () {
    var container = document.getElementById('ha-blocks');
    if (!container) return;

    var form = container.closest('form');

    function tpl(id) {
        var t = document.getElementById(id);
        return t && t.content ? t.content.firstElementChild.cloneNode(true) : null;
    }

    // Renumber every block to blocks[0..n] in DOM order, and rename the field
    // names inside each block (type/text/level, items[], action fields) so the
    // POST shape always matches what the server parser expects.
    function renumber() {
        var blocks = container.querySelectorAll(':scope > .ha-block');
        blocks.forEach(function (block, i) {
            // Give each action row an explicit, stable index so a button's
            // label/url/style land together in blocks[i][actions][k][...].
            // (A bare [] per field would split them across separate entries.)
            var actionRows = block.querySelectorAll('[data-action]');
            actionRows.forEach(function (row, k) {
                var label = row.querySelector('[name*="[label]"]');
                var url = row.querySelector('[name*="[url]"]');
                var style = row.querySelector('[name*="[style]"]');
                if (label) label.setAttribute('name', 'blocks[' + i + '][actions][' + k + '][label]');
                if (url) url.setAttribute('name', 'blocks[' + i + '][actions][' + k + '][url]');
                if (style) style.setAttribute('name', 'blocks[' + i + '][actions][' + k + '][style]');
            });

            // Rewrite the remaining (non-action) field names to this block's
            // index. Action fields are already fully set above, so skip them.
            block.querySelectorAll('[name]').forEach(function (field) {
                var name = field.getAttribute('name');
                if (name.indexOf('[actions]') !== -1) return;
                name = name.replace(/^blocks\[[^\]]*\]/, 'blocks[' + i + ']');
                field.setAttribute('name', name);
            });

            refreshStepNumbers(block);
            updateMoveButtons(block, i, blocks.length);
        });
    }

    function updateMoveButtons(block, i, total) {
        var up = block.querySelector('.ha-move-up');
        var down = block.querySelector('.ha-move-down');
        if (up) up.disabled = (i === 0);
        if (down) down.disabled = (i === total - 1);
    }

    function refreshStepNumbers(block) {
        var nums = block.querySelectorAll('[data-item] .ha-item-num');
        nums.forEach(function (el, idx) { el.textContent = (idx + 1) + '.'; });
    }

    function addStepItem(block, value) {
        var row = tpl('ha-tpl-step-item');
        if (!row) return;
        var input = row.querySelector('input');
        input.setAttribute('name', 'blocks[0][items][]'); // renumbered on submit
        if (value) input.value = value;
        block.querySelector('[data-items]').appendChild(row);
        renumber();
    }

    function addActionItem(block) {
        var row = tpl('ha-tpl-action-item');
        if (!row) return;
        // Placeholder names; renumber() rewrites the block index, but the inner
        // action field names must be set to the action sub-keys here.
        row.querySelector('[name="__LABEL__"]').setAttribute('name', 'blocks[0][actions][][label]');
        row.querySelector('[name="__URL__"]').setAttribute('name', 'blocks[0][actions][][url]');
        row.querySelector('[name="__STYLE__"]').setAttribute('name', 'blocks[0][actions][][style]');
        block.querySelector('[data-actions]').appendChild(row);
        renumber();
    }

    function addBlock(type) {
        var node = tpl('ha-tpl-' + type);
        if (!node) return;
        // Clear the __I__ placeholder; renumber() assigns the real index.
        node.querySelectorAll('[name]').forEach(function (field) {
            field.setAttribute('name', field.getAttribute('name').replace('__I__', '0'));
        });
        container.appendChild(node);

        // Seed one empty row for list-style blocks so they are not empty.
        if (type === 'steps') addStepItem(node, '');
        if (type === 'action') addActionItem(node);

        renumber();
        var firstInput = node.querySelector('input, textarea');
        if (firstInput) firstInput.focus();
    }

    // Event delegation for all the block controls.
    container.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var block = btn.closest('.ha-block');

        if (btn.classList.contains('ha-block-remove')) {
            block.remove();
            renumber();
        } else if (btn.classList.contains('ha-move-up')) {
            var prev = block.previousElementSibling;
            if (prev) block.parentNode.insertBefore(block, prev);
            renumber();
        } else if (btn.classList.contains('ha-move-down')) {
            var next = block.nextElementSibling;
            if (next) block.parentNode.insertBefore(next, block);
            renumber();
        } else if (btn.classList.contains('ha-add-item')) {
            addStepItem(block, '');
        } else if (btn.classList.contains('ha-item-remove')) {
            var item = btn.closest('[data-item]');
            if (item) item.remove();
            refreshStepNumbers(block);
        } else if (btn.classList.contains('ha-add-action')) {
            addActionItem(block);
        } else if (btn.classList.contains('ha-action-remove')) {
            var act = btn.closest('[data-action]');
            if (act) act.remove();
        }
    });

    // "Add a block" buttons below the list.
    document.querySelectorAll('[data-add-block]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            addBlock(btn.getAttribute('data-add-block'));
        });
    });

    // Final renumber right before submit so names are sequential and ordered.
    if (form) {
        form.addEventListener('submit', renumber);
    }

    // Initial pass over the server-rendered blocks.
    renumber();
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>
