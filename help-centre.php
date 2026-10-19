<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

require_once __DIR__ . '/app/help-helpers.php';
require_once __DIR__ . '/app/search-helpers.php';

$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

// Routing: no slug => index mode; slug present => viewer mode.
$slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';

// Shared page chrome. The header renders the ONLY <h1> from $heroTitle, so in
// viewer mode we override $heroTitle with the article title (Req 6.1).
$pageTitle  = 'Help Centre | ' . $appName;
$heroTitle  = 'Help Centre';
$heroText   = 'Guides and step-by-step help for common tasks in the District App.';
$breadcrumb = '<a href="/index.php">Home</a> / Help Centre';

$isIndexMode = ($slug === '');

if ($isIndexMode) {
    // On-page Help search (Req 7): a `q` term filters the index via the
    // shared Search_Engine helper; empty `q` shows the topic-grouped listing.
    $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
    $isSearchMode  = ($q !== '');
    $searchResults = $isSearchMode ? search_help_articles($q, 50) : [];

    if (!$isSearchMode) {
        // Index mode (Req 2.4-2.9): list published articles grouped by topic.
        $articles = help_published_articles();
        $grouped  = help_group_by_topic($articles);
    }
} else {
    // Viewer mode (Req 3.x): a single published article, or null => not found.
    $article = help_find_published_article($slug);

    if ($article !== null) {
        // The article title becomes the single page <h1> (Req 6.1); header
        // escapes it via e().
        $heroTitle  = (string) ($article['title'] ?? 'Help article');
        $pageTitle  = $heroTitle . ' | ' . $appName;
        $heroText   = (string) ($article['summary'] ?? '');
        $breadcrumb = '<a href="/index.php">Home</a> / <a href="/help-centre.php">Help Centre</a> / ' . e($heroTitle);
    } else {
        // Not-found state (Req 3.7, 3.8).
        $heroTitle  = 'Help article not found';
        $pageTitle  = 'Help article not found | ' . $appName;
        $heroText   = 'We could not find the help article you asked for.';
        $breadcrumb = '<a href="/index.php">Home</a> / <a href="/help-centre.php">Help Centre</a> / Not found';
    }
}

?>
<?php include __DIR__ . '/header.php'; ?>

<main class="lt-main">
    <?php if ($isIndexMode): ?>
        <?php // On-page Help search (Req 7.1): GET form submitting `q` back here. ?>
        <form class="lt-help-search" method="get" action="/help-centre.php" role="search">
            <label class="lt-help-search-label" for="lt-help-q">Search the Help Centre</label>
            <div class="lt-help-search-row">
                <input
                    type="search"
                    id="lt-help-q"
                    name="q"
                    class="lt-help-search-input"
                    value="<?= e($q) ?>"
                    placeholder="Search help articles, e.g. add an event, shared mailbox…"
                    autocomplete="off"
                >
                <button type="submit" class="lt-help-action lt-help-action--primary">Search</button>
            </div>
        </form>

        <?php if ($isSearchMode): ?>
            <?php // Search results (Req 7.2-7.4) or support fallback (Req 7.6, 7.7). ?>
            <section class="lt-help-index lt-help-results">
                <?php if (empty($searchResults)): ?>
                    <?php
                    // Support_Fallback: pre-fill the support details box with a short
                    // sentence that includes the search term (Req 7.7, 7.8).
                    $prefill = 'I was looking for help with "' . $q . '" in the Help Centre but could not find an answer.';
                    $supportHref = '/technical-support.php?details=' . rawurlencode($prefill);
                    ?>
                    <div class="lt-help-nofind">
                        <h2 class="lt-help-nofind-title">No help articles matched &ldquo;<?= e($q) ?>&rdquo;</h2>
                        <p>We could not find a help article for that. You can try a different word, or ask our team for help &mdash; we will pre-fill your search so you do not have to type it again.</p>
                        <p class="lt-help-nofind-actions">
                            <a class="lt-help-action lt-help-action--primary" href="<?= e($supportHref) ?>">Get technical support</a>
                            <a class="lt-help-action lt-help-action--secondary" href="/help-centre.php">Back to all help topics</a>
                        </p>
                    </div>
                <?php else: ?>
                    <p class="lt-help-results-count">
                        <?= (int) count($searchResults) ?> result<?= count($searchResults) === 1 ? '' : 's' ?> for &ldquo;<?= e($q) ?>&rdquo;
                        &middot; <a href="/help-centre.php">clear search</a>
                    </p>
                    <ul class="lt-help-article-list">
                        <?php foreach ($searchResults as $result): ?>
                            <li class="lt-help-article">
                                <h2 class="lt-help-article-title">
                                    <a class="lt-help-article-link" href="<?= e((string) ($result['url'] ?? '#')) ?>">
                                        <?= e((string) ($result['title'] ?? '')) ?>
                                    </a>
                                </h2>
                                <?php if (!empty($result['subtitle'])): ?>
                                    <p class="lt-help-article-summary"><?= e((string) $result['subtitle']) ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php else: ?>
        <?php // Index mode body (Req 2.4-2.9): published articles grouped by topic. ?>
        <section class="lt-help-index">
            <?php if (empty($grouped)): ?>
                <?php // Req 2.9: no published articles to show. ?>
                <p class="lt-help-empty">No help articles are available at the moment. Please check back later.</p>
            <?php else: ?>
                <?php foreach ($grouped as $topic => $topicArticles): ?>
                    <section class="lt-help-topic">
                        <h2 class="lt-help-topic-title"><?= e($topic !== '' ? (string) $topic : 'General') ?></h2>
                        <ul class="lt-help-article-list">
                            <?php foreach ($topicArticles as $article): ?>
                                <?php
                                $articleSlug    = (string) ($article['slug'] ?? '');
                                $articleTitle   = (string) ($article['title'] ?? '');
                                $articleSummary = (string) ($article['summary'] ?? '');
                                ?>
                                <li class="lt-help-article">
                                    <h3 class="lt-help-article-title">
                                        <a class="lt-help-article-link" href="/help-centre.php?slug=<?= e(rawurlencode($articleSlug)) ?>">
                                            <?= e($articleTitle) ?>
                                        </a>
                                    </h3>
                                    <?php if ($articleSummary !== ''): ?>
                                        <p class="lt-help-article-summary"><?= e($articleSummary) ?></p>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    <?php elseif (isset($article) && $article !== null): ?>
        <?php
        // Viewer mode body (Req 3.1, 3.2, 6.1). The article title is already the
        // page <h1> via $heroTitle, so we render the Rich_Body only. The renderer
        // returns safe, pre-escaped HTML, so it is echoed raw (do NOT re-escape).
        $body = is_array($article['body'] ?? null) ? $article['body'] : [];
        ?>
        <article class="lt-help-article">
            <div class="lt-help-article-body">
                <?= help_render_body($body) ?>
            </div>
            <p class="lt-help-back">
                <a href="/help-centre.php">&larr; Back to the Help Centre</a>
            </p>
        </article>
    <?php else: ?>
        <?php // Not-found state (Req 3.7, 3.8) — rendered inside the normal layout. ?>
        <section class="lt-help-notfound">
            <p>We could not find the help article you were looking for. It may have been moved, removed, or is not currently available.</p>
            <p class="lt-help-back">
                <a href="/help-centre.php">&larr; Back to the Help Centre</a>
            </p>
        </section>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
