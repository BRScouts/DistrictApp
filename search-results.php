<?php

declare(strict_types=1);

/**
 * Full global search results page with advanced filtering.
 *
 * Reuses the shared search engine (app/search-helpers.php) so results
 * and permission scoping exactly match the header dropdown.
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/search-helpers.php';

require_login();

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
$pageTitle = 'Search | ' . $appName;
$heroTitle = null;
$heroText = null;
$breadcrumb = null;

$query = trim((string) ($_GET['q'] ?? ''));
if (function_exists('mb_substr')) {
    $query = mb_substr($query, 0, 200);
} else {
    $query = substr($query, 0, 200);
}

$categoryLabels = search_categories();

// Selected category filter: "all" or one of the category keys.
$selectedCategory = (string) ($_GET['category'] ?? 'all');
if ($selectedCategory !== 'all' && !array_key_exists($selectedCategory, $categoryLabels)) {
    $selectedCategory = 'all';
}

$activeCategories = $selectedCategory === 'all' ? [] : [$selectedCategory];

$search = $query !== '' ? search_run($query, $activeCategories, 50) : ['results' => [], 'counts' => []];
$results = $search['results'];
$counts = $search['counts'];
$totalResults = count($results);

include __DIR__ . '/header.php';
?>

<style>
    .search-page {
        width: min(1180px, calc(100% - 1rem));
        margin: 1.75rem auto 0;
    }

    .search-head h1 {
        font-size: 1.9rem;
        font-weight: 900;
        letter-spacing: -.02em;
        margin: 0 0 .25rem;
    }

    .search-head p {
        color: var(--iv-grey-700);
        font-weight: 700;
        margin: 0 0 1.25rem;
    }

    .search-form {
        display: grid;
        gap: .75rem;
        background: var(--iv-grey-100);
        border: 1px solid var(--iv-grey-300);
        border-left: .4rem solid var(--iv-purple);
        padding: 1rem;
        margin-bottom: 1.5rem;
    }

    @media (min-width: 768px) {
        .search-form {
            grid-template-columns: minmax(0, 1fr) auto auto;
            align-items: end;
        }
    }

    .search-form .form-group {
        margin: 0;
    }

    .search-form label {
        display: block;
        font-weight: 900;
        font-size: .85rem;
        margin-bottom: .3rem;
    }

    .search-filters {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
        margin-bottom: 1.5rem;
    }

    .search-filter-chip {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .3rem .7rem;
        background: #fff;
        border: 1px solid var(--iv-grey-300);
        font-weight: 800;
        font-size: .85rem;
        color: var(--iv-grey-700);
        text-decoration: none;
    }

    .search-filter-chip:hover,
    .search-filter-chip:focus {
        border-color: var(--iv-purple);
        color: var(--iv-purple);
        text-decoration: none;
    }

    .search-filter-chip[aria-current="true"] {
        background: var(--iv-purple);
        border-color: var(--iv-purple);
        color: #fff;
    }

    .search-filter-chip .chip-count {
        font-size: .75rem;
        opacity: .85;
    }

    .search-results-list {
        display: grid;
        gap: .75rem;
    }

    .search-result {
        display: flex;
        align-items: flex-start;
        gap: .9rem;
        padding: 1rem;
        background: #fff;
        border: 1px solid var(--iv-grey-300);
        text-decoration: none;
        color: inherit;
        transition: border-color .12s, box-shadow .12s;
    }

    .search-result:hover,
    .search-result:focus {
        border-color: var(--iv-purple);
        box-shadow: 0 4px 16px rgba(77, 0, 153, .08);
        text-decoration: none;
        color: inherit;
    }

    .search-result-body {
        min-width: 0;
        flex: 1;
    }

    .search-result-title {
        font-weight: 900;
        font-size: 1.05rem;
        line-height: 1.2;
        margin: 0 0 .2rem;
    }

    .search-result-sub {
        color: var(--iv-grey-700);
        font-weight: 700;
        font-size: .9rem;
        margin: 0;
        overflow-wrap: anywhere;
    }

    .search-result-arrow {
        align-self: center;
        font-weight: 900;
        color: var(--iv-blue);
        flex: 0 0 auto;
    }

    .search-empty {
        padding: 2rem 1rem;
        text-align: center;
        background: var(--iv-grey-100);
        border: 1px dashed var(--iv-grey-300);
        color: var(--iv-grey-700);
        font-weight: 700;
    }

    /* ===== SOURCE BADGES (consistent across dropdown + page) ===== */
    .search-badge {
        display: inline-block;
        flex: 0 0 auto;
        padding: .25rem .55rem;
        font-size: .72rem;
        font-weight: 900;
        letter-spacing: .02em;
        text-transform: uppercase;
        border: 1px solid transparent;
        white-space: nowrap;
        border-radius: .25rem;
    }

    .search-badge--module {
        background: #ede7f6;
        color: #4d2177;
        border-color: #d6c7ec;
    }

    .search-badge--calendar {
        background: #e3f0fb;
        color: #11508a;
        border-color: #c3dcf2;
    }

    .search-badge--risk {
        background: #fff3e0;
        color: #9a5600;
        border-color: #f3dcb8;
    }

    .search-badge--person {
        background: #e6f4ea;
        color: #1e6b36;
        border-color: #c7e6d1;
    }
</style>

<main class="search-page">
    <div class="search-head">
        <h1>Search the District App</h1>
        <p>Find modules, events you can access, risk assessments and people. This does not search the calendar grid itself.</p>
    </div>

    <form class="search-form" method="get" action="/search-results.php" role="search">
        <div class="form-group">
            <label for="search-q">Search term</label>
            <input
                type="search"
                class="form-control"
                id="search-q"
                name="q"
                value="<?= e($query) ?>"
                placeholder="Event name, person, risk assessment, module…"
                autocomplete="off"
            >
        </div>

        <div class="form-group">
            <label for="search-category">Category</label>
            <select class="form-control" id="search-category" name="category">
                <option value="all"<?= $selectedCategory === 'all' ? ' selected' : '' ?>>All results</option>
                <?php foreach ($categoryLabels as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $selectedCategory === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <button type="submit" class="btn btn-primary lt-btn">Search</button>
        </div>
    </form>

    <?php if ($query !== ''): ?>
        <div class="search-filters" role="group" aria-label="Filter by source">
            <?php
                $allHref = '/search-results.php?q=' . rawurlencode($query) . '&category=all';
            ?>
            <a class="search-filter-chip" href="<?= e($allHref) ?>" aria-current="<?= $selectedCategory === 'all' ? 'true' : 'false' ?>">
                All
            </a>
            <?php foreach ($categoryLabels as $key => $label): ?>
                <?php $href = '/search-results.php?q=' . rawurlencode($query) . '&category=' . rawurlencode($key); ?>
                <a class="search-filter-chip" href="<?= e($href) ?>" aria-current="<?= $selectedCategory === $key ? 'true' : 'false' ?>">
                    <span class="search-badge search-badge--<?= e($key) ?>"><?= e($label) ?></span>
                    <?php if ($selectedCategory === $key || $selectedCategory === 'all'): ?>
                        <?php if (isset($counts[$key])): ?>
                            <span class="chip-count"><?= (int) $counts[$key] ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($totalResults === 0): ?>
            <div class="search-empty">
                No results for <strong><?= e($query) ?></strong>. Try a different word or a person's name.
            </div>
        <?php else: ?>
            <p class="mb-2" style="font-weight: 800; color: var(--iv-grey-700);">
                <?= (int) $totalResults ?> result<?= $totalResults === 1 ? '' : 's' ?> for <strong><?= e($query) ?></strong>
            </p>

            <div class="search-results-list">
                <?php foreach ($results as $result): ?>
                    <a
                        class="search-result"
                        href="<?= e($result['url']) ?>"
                        <?= !empty($result['external']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>
                    >
                        <span class="search-badge search-badge--<?= e($result['category']) ?>"><?= e($result['badge']) ?></span>

                        <span class="search-result-body">
                            <span class="search-result-title"><?= e($result['title']) ?></span>
                            <?php if (!empty($result['subtitle'])): ?>
                                <span class="search-result-sub"><?= e($result['subtitle']) ?></span>
                            <?php endif; ?>
                        </span>

                        <span class="search-result-arrow">&rarr;</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="search-empty">
            Enter a search term above to look across modules, events, risk assessments and people.
        </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
