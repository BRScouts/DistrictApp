<?php
$user = current_user();

$displayName = trim((string) ($user['preferred_name'] ?? $user['full_name'] ?? $user['email'] ?? 'User'));
$initials = strtoupper(substr($displayName, 0, 1));

$pageTitle = $pageTitle ?? $appName ?? app_config('APP_NAME', 'Irwell Valley Leader Tool');
$heroTitle = $heroTitle ?? null;
$heroText = $heroText ?? null;
$breadcrumb = $breadcrumb ?? null;

$roleLabel = str_replace('_', ' ', (string) ($user['highest_access_level'] ?? 'member'));
$roleLabel = ucwords($roleLabel);

$profilePhotoUrl = $user ? '/auth/profile-photo.php' : null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" type="image/png" href="/assets/img/favicon.png">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css" integrity="sha384-5Kguc7IDQdynmm22yUyn9psYyP8LQhAWCCKJT/RrZJAWqdUAw5eADwc25JoYsXH6" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/leader-tool.css">

    <style>
        .lt-brand > span {
            display: none;
        }

        .lt-brand img {
            height: 72px;
            width: auto;
            max-width: 260px;
            object-fit: contain;
        }

        .lt-header-inner {
            min-height: 92px;
        }

        .lt-account-menu {
            position: relative;
            display: inline-block;
        }

        .lt-account-menu summary {
            list-style: none;
            cursor: pointer;
        }

        .lt-account-menu summary::-webkit-details-marker {
            display: none;
        }

        .lt-account-trigger {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border: 0;
            background: transparent;
            color: inherit;
            padding: 0;
        }

        .lt-account-trigger:hover,
        .lt-account-trigger:focus {
            outline: 3px solid #ffcd00;
            outline-offset: 5px;
        }

        .lt-account-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            line-height: 1.15;
            min-width: 0;
        }

        .lt-account-name {
            font-weight: 900;
            color: inherit;
            max-width: 240px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .lt-account-role {
            margin-top: 0.1rem;
            font-size: 0.85rem;
            font-weight: 700;
            opacity: 0.85;
            max-width: 240px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .lt-avatar {
            overflow: hidden;
            flex: 0 0 auto;
        }

        .lt-avatar img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .lt-account-chevron {
            width: 0.55rem;
            height: 0.55rem;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(45deg) translateY(-2px);
            margin-left: -0.15rem;
            opacity: 0.8;
        }

        .lt-account-menu[open] .lt-account-chevron {
            transform: rotate(225deg) translateY(-1px);
        }

        .lt-account-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 0.75rem);
            width: 230px;
            background: #ffffff;
            border: 1px solid #e6e6e6;
            border-radius: 0.5rem;
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.14);
            z-index: 1000;
            overflow: hidden;
        }

        .lt-account-dropdown-header {
            padding: 0.85rem 1rem;
            background: #f7f5fb;
            border-bottom: 1px solid #e6e6e6;
        }

        .lt-account-dropdown-name {
            display: block;
            color: #1d1d1b;
            font-weight: 900;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }

        .lt-account-dropdown-role {
            display: block;
            margin-top: 0.2rem;
            color: #555555;
            font-size: 0.85rem;
            font-weight: 700;
        }

        .lt-account-dropdown-links {
            padding: 0.35rem;
        }

        .lt-account-dropdown-links a {
            display: block;
            padding: 0.75rem 0.85rem;
            border-radius: 0.35rem;
            color: #1d1d1b;
            font-weight: 900;
            text-decoration: none;
        }

        .lt-account-dropdown-links a:hover,
        .lt-account-dropdown-links a:focus {
            background: #f7f5fb;
            color: #4d2177;
            text-decoration: none;
        }

        .lt-account-dropdown-links .lt-sign-out-link {
            color: #b10e1e;
        }

        .lt-account-dropdown-links .lt-sign-out-link:hover,
        .lt-account-dropdown-links .lt-sign-out-link:focus {
            color: #b10e1e;
        }

        @media (min-width: 992px) {
            .lt-brand img {
                height: 86px;
                max-width: 310px;
            }

            .lt-header-inner {
                min-height: 106px;
            }
        }

        @media (max-width: 575.98px) {
            .lt-brand img {
                height: 58px;
                max-width: 190px;
            }

            .lt-header-inner {
                min-height: 76px;
            }

            .lt-account-meta {
                display: none;
            }

            .lt-account-chevron {
                display: none;
            }

            .lt-account-dropdown {
                right: 0;
                width: min(260px, calc(100vw - 1rem));
            }
        }

        /* ===== PRIMARY NAV (header menu items) ===== */
        .lt-primary-nav {
            display: none;
            align-items: center;
            gap: 0.15rem;
            flex: 1;
            justify-content: flex-end;
            margin-right: 1.25rem;
        }

        .lt-primary-nav a {
            padding: 0.5rem 0.75rem;
            color: var(--iv-black, #111);
            font-weight: 900;
            font-size: 0.88rem;
            text-decoration: none;
            border-radius: 0;
            transition: background 0.12s, color 0.12s;
        }

        .lt-primary-nav a:hover {
            background: #f3eef9;
            color: #4d2177;
            text-decoration: none;
        }

        .lt-primary-nav a:focus {
            outline: 3px solid #ffcd00;
            outline-offset: 2px;
        }

        .lt-primary-nav a[aria-current="page"] {
            background: #4d2177;
            color: #fff;
        }

        @media (min-width: 768px) {
            .lt-primary-nav {
                display: flex;
            }
        }

        /* ===== GLOBAL SEARCH ===== */
        .lt-search {
            position: relative;
            display: inline-flex;
            align-items: center;
            margin-right: 0.5rem;
        }

        .lt-search-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border: 2px solid var(--iv-grey-300, #d8d8d8);
            background: transparent;
            color: var(--iv-black, #111);
            cursor: pointer;
            border-radius: 0;
        }

        .lt-search-toggle:hover {
            border-color: #4d2177;
            color: #4d2177;
        }

        .lt-search-toggle:focus {
            outline: 3px solid #ffcd00;
            outline-offset: 2px;
        }

        .lt-search-panel {
            position: absolute;
            right: 0;
            top: calc(100% + 0.6rem);
            width: min(420px, calc(100vw - 1.5rem));
            background: #fff;
            border: 1px solid #e6e6e6;
            border-radius: 0.5rem;
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.16);
            z-index: 1100;
            overflow: hidden;
        }

        .lt-search-panel[hidden] {
            display: none;
        }

        .lt-search-form {
            margin: 0;
            padding: 0.75rem;
            border-bottom: 1px solid #eee;
        }

        .lt-search-input {
            width: 100%;
            padding: 0.6rem 0.7rem;
            border: 2px solid var(--iv-grey-300, #d8d8d8);
            font-weight: 700;
            font-size: 0.95rem;
            border-radius: 0.35rem;
        }

        .lt-search-input:focus {
            outline: none;
            border-color: #4d2177;
        }

        .lt-search-results {
            max-height: 60vh;
            overflow-y: auto;
        }

        .lt-search-group-label {
            padding: 0.55rem 0.85rem 0.25rem;
            font-size: 0.72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #777;
        }

        .lt-search-item {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            padding: 0.6rem 0.85rem;
            color: #1d1d1b;
            text-decoration: none;
            border-top: 1px solid #f2f2f2;
        }

        .lt-search-item:hover,
        .lt-search-item:focus {
            background: #f7f5fb;
            text-decoration: none;
            color: #1d1d1b;
        }

        .lt-search-item-body {
            min-width: 0;
            flex: 1;
        }

        .lt-search-item-title {
            display: block;
            font-weight: 900;
            line-height: 1.2;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .lt-search-item-sub {
            display: block;
            margin-top: 0.1rem;
            font-size: 0.82rem;
            font-weight: 700;
            color: #666;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .lt-search-state {
            padding: 1rem 0.85rem;
            font-weight: 700;
            color: #777;
            font-size: 0.9rem;
        }

        .lt-search-all {
            display: block;
            padding: 0.7rem 0.85rem;
            background: #f7f5fb;
            border-top: 1px solid #e6e6e6;
            font-weight: 900;
            color: #4d2177;
            text-decoration: none;
        }

        .lt-search-all[hidden] {
            display: none;
        }

        .lt-search-all:hover,
        .lt-search-all:focus {
            background: #efe7f8;
            color: #4d2177;
            text-decoration: none;
        }

        /* Shared source badges (match /search-results.php) */
        .search-badge {
            display: inline-block;
            flex: 0 0 auto;
            padding: 0.2rem 0.5rem;
            font-size: 0.68rem;
            font-weight: 900;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            border: 1px solid transparent;
            white-space: nowrap;
            border-radius: 0.25rem;
            line-height: 1.3;
        }

        .search-badge--module { background: #ede7f6; color: #4d2177; border-color: #d6c7ec; }
        .search-badge--calendar { background: #e3f0fb; color: #11508a; border-color: #c3dcf2; }
        .search-badge--district_event { background: #f1e6fb; color: #4d0b93; border-color: #d9c2f2; }
        .search-badge--risk { background: #fff3e0; color: #9a5600; border-color: #f3dcb8; }
        .search-badge--person { background: #e6f4ea; color: #1e6b36; border-color: #c7e6d1; }
        .search-badge--help { background: #e0f2f1; color: #00695c; border-color: #b2dfdb; }

        @media (max-width: 575.98px) {
            .lt-search-panel {
                position: fixed;
                left: 0.5rem;
                right: 0.5rem;
                top: 72px;
                width: auto;
            }
        }

        /* Mobile hamburger toggle */
        .lt-mobile-nav-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border: 2px solid var(--iv-grey-300, #d8d8d8);
            background: transparent;
            cursor: pointer;
            margin-right: 0.5rem;
        }

        .lt-mobile-nav-toggle span {
            display: block;
            width: 20px;
            height: 2px;
            background: #111;
            position: relative;
        }

        .lt-mobile-nav-toggle span::before,
        .lt-mobile-nav-toggle span::after {
            content: '';
            position: absolute;
            left: 0;
            width: 100%;
            height: 2px;
            background: #111;
        }

        .lt-mobile-nav-toggle span::before { top: -6px; }
        .lt-mobile-nav-toggle span::after { top: 6px; }

        @media (min-width: 768px) {
            .lt-mobile-nav-toggle {
                display: none;
            }
        }

        .lt-mobile-nav {
            display: none;
            background: #fff;
            border-bottom: 2px solid #4d2177;
            padding: 0.5rem 1rem;
        }

        .lt-mobile-nav.is-open {
            display: block;
        }

        .lt-mobile-nav a {
            display: block;
            padding: 0.75rem 0.5rem;
            color: #111;
            font-weight: 900;
            font-size: 0.95rem;
            text-decoration: none;
            border-bottom: 1px solid #e6e6e6;
        }

        .lt-mobile-nav a:last-child {
            border-bottom: 0;
        }

        .lt-mobile-nav a:hover,
        .lt-mobile-nav a:focus {
            color: #4d2177;
            background: #f7f5fb;
        }
    </style>
</head>
<body>
<header class="lt-header">
    <div class="lt-header-inner">
        <a class="lt-brand" href="/index.php" aria-label="Irwell Valley District Scouts">
            <img src="/assets/img/black-ir-logo.png" alt="Irwell Valley District Scouts" data-hide-on-error>
        </a>

        <?php if ($user): ?>
            <?php
                $userAccessLevel = (string) ($user['highest_access_level'] ?? $user['role'] ?? 'member');
                $isSystemAdmin = $userAccessLevel === 'system_admin';
                $isDistrictAdmin = in_array($userAccessLevel, ['system_admin', 'district_admin'], true);
                // highest_access_level ranks group_admin above member, so anyone
                // who manages a group (or is a district/system admin) lands here.
                $isGroupAdmin = in_array($userAccessLevel, ['system_admin', 'district_admin', 'group_admin'], true);
            ?>
            <nav class="lt-primary-nav" aria-label="Main navigation">
                <a href="/index.php">Home</a>
                <a href="/dc/">Calendar</a>
                <a href="/directory.php">Directory</a>
                <?php if ($isGroupAdmin): ?>
                    <a href="/group-manager.php">Group Admin</a>
                <?php endif; ?>
                <?php if ($isDistrictAdmin): ?>
                    <a href="/district-admin.php">District Admin</a>
                <?php endif; ?>
                <?php if ($isSystemAdmin): ?>
                    <a href="/system-admin-dashboard.php">System Admin</a>
                <?php endif; ?>
            </nav>

            <div class="lt-search" id="lt-search">
                <button
                    type="button"
                    class="lt-search-toggle"
                    id="lt-search-toggle"
                    aria-label="Search the District App"
                    aria-expanded="false"
                    aria-controls="lt-search-panel"
                >
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
                        <circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2"></circle>
                        <line x1="15.5" y1="15.5" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"></line>
                    </svg>
                </button>

                <div class="lt-search-panel" id="lt-search-panel" role="dialog" aria-label="Search" hidden>
                    <form class="lt-search-form" action="/search-results.php" method="get" role="search">
                        <input
                            type="search"
                            class="lt-search-input"
                            id="lt-search-input"
                            name="q"
                            placeholder="Search modules, events, risk assessments, people…"
                            autocomplete="off"
                            aria-controls="lt-search-results"
                            aria-expanded="false"
                        >
                    </form>

                    <div class="lt-search-results" id="lt-search-results" role="listbox" aria-label="Top results"></div>

                    <a class="lt-search-all" id="lt-search-all" href="/search-results.php" hidden>
                        See all results &rarr;
                    </a>
                </div>
            </div>

            <button type="button" class="lt-mobile-nav-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="lt-mobile-nav">
                <span aria-hidden="true"></span>
            </button>

            <nav class="lt-nav" aria-label="Account navigation">
                <details class="lt-account-menu">
                    <summary class="lt-account-trigger" aria-label="Open account menu">
                        <span class="lt-account-meta">
                            <span class="lt-account-name"><?= e($displayName) ?></span>
                            <span class="lt-account-role"><?= e($roleLabel) ?></span>
                        </span>

                        <span class="lt-avatar" aria-hidden="true">
                            <?php if ($profilePhotoUrl): ?>
                                <img
                                    src="<?= e($profilePhotoUrl) ?>"
                                    alt=""
                                    data-fallback-sibling
                                >
                                <span style="display: none;" aria-hidden="true"><?= e($initials) ?></span>
                            <?php else: ?>
                                <?= e($initials) ?>
                            <?php endif; ?>
                        </span>

                        <span class="lt-account-chevron" aria-hidden="true"></span>
                    </summary>

                    <div class="lt-account-dropdown" role="menu">
                        <div class="lt-account-dropdown-header">
                            <span class="lt-account-dropdown-name"><?= e($displayName) ?></span>
                            <span class="lt-account-dropdown-role"><?= e($roleLabel) ?></span>
                        </div>

                        <div class="lt-account-dropdown-links">
                            <a href="/profile.php" role="menuitem">My profile</a>
                            <a class="lt-sign-out-link" href="/logout.php" role="menuitem">Sign out</a>
                        </div>
                    </div>
                </details>
            </nav>
        <?php endif; ?>
    </div>

    <?php if ($user): ?>
        <div class="lt-mobile-nav" id="lt-mobile-nav" aria-label="Mobile navigation">
            <a href="/index.php">Home</a>
            <a href="/dc/">Calendar</a>
            <a href="/directory.php">Directory</a>
            <?php if (!empty($isGroupAdmin)): ?>
                <a href="/group-manager.php">Group Admin</a>
            <?php endif; ?>
            <?php if (!empty($isDistrictAdmin)): ?>
                <a href="/district-admin.php">District Admin</a>
            <?php endif; ?>
            <?php if (!empty($isSystemAdmin)): ?>
                <a href="/system-admin-dashboard.php">System Admin</a>
            <?php endif; ?>
            <a href="/profile.php">My Profile</a>
            <a href="/logout.php">Sign out</a>
        </div>
    <?php endif; ?>
</header>

<script <?= csp_nonce_attr() ?>>
(function() {
    var toggle = document.querySelector('.lt-mobile-nav-toggle');
    var nav = document.getElementById('lt-mobile-nav');
    if (!toggle || !nav) return;
    toggle.addEventListener('click', function() {
        var open = nav.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
})();
</script>

<script <?= csp_nonce_attr() ?>>
(function () {
    var wrap = document.getElementById('lt-search');
    if (!wrap) return;

    var toggle = document.getElementById('lt-search-toggle');
    var panel = document.getElementById('lt-search-panel');
    var form = panel.querySelector('.lt-search-form');
    var input = document.getElementById('lt-search-input');
    var resultsBox = document.getElementById('lt-search-results');
    var seeAll = document.getElementById('lt-search-all');

    var CATEGORY_ORDER = ['module', 'calendar', 'risk', 'person', 'help'];
    var CATEGORY_LABELS = {
        module: 'Modules',
        calendar: 'Calendar',
        risk: 'Risk Assessments',
        person: 'People',
        help: 'Help'
    };

    var debounceTimer = null;
    var lastQuery = '';
    var activeController = null;

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function openPanel() {
        panel.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        input.setAttribute('aria-expanded', 'true');
        window.setTimeout(function () { input.focus(); }, 0);
    }

    function closePanel() {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-expanded', 'false');
    }

    function setSeeAllHref(query) {
        if (query) {
            seeAll.href = '/search-results.php?q=' + encodeURIComponent(query);
            seeAll.hidden = false;
        } else {
            seeAll.hidden = true;
        }
    }

    function renderState(message) {
        resultsBox.innerHTML = '<div class="lt-search-state">' + escapeHtml(message) + '</div>';
    }

    function renderResults(payload) {
        var results = (payload && payload.results) || [];

        if (!results.length) {
            renderState('No matches. Press Enter for the full search.');
            return;
        }

        // Group results by category, preserving the defined order.
        var grouped = {};
        results.forEach(function (item) {
            (grouped[item.category] = grouped[item.category] || []).push(item);
        });

        var html = '';
        CATEGORY_ORDER.forEach(function (cat) {
            var items = grouped[cat];
            if (!items || !items.length) return;

            html += '<div class="lt-search-group-label">' + escapeHtml(CATEGORY_LABELS[cat] || cat) + '</div>';

            items.forEach(function (item) {
                var target = item.external ? ' target="_blank" rel="noopener noreferrer"' : '';
                var sub = item.subtitle
                    ? '<span class="lt-search-item-sub">' + escapeHtml(item.subtitle) + '</span>'
                    : '';

                html += '<a class="lt-search-item" role="option" href="' + escapeHtml(item.url) + '"' + target + '>'
                    + '<span class="search-badge search-badge--' + escapeHtml(item.category) + '">' + escapeHtml(item.badge) + '</span>'
                    + '<span class="lt-search-item-body">'
                    + '<span class="lt-search-item-title">' + escapeHtml(item.title) + '</span>'
                    + sub
                    + '</span>'
                    + '</a>';
            });
        });

        resultsBox.innerHTML = html;
    }

    function runSearch(query) {
        setSeeAllHref(query);

        if (!query) {
            resultsBox.innerHTML = '';
            return;
        }

        renderState('Searching…');

        if (activeController && typeof activeController.abort === 'function') {
            activeController.abort();
        }

        var options = {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        };

        if (typeof AbortController !== 'undefined') {
            activeController = new AbortController();
            options.signal = activeController.signal;
        }

        fetch('/search.php?q=' + encodeURIComponent(query), options)
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                // Ignore stale responses.
                if ((payload && payload.query) && payload.query !== lastQuery) return;
                renderResults(payload);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                renderState('Search is unavailable right now.');
            });
    }

    toggle.addEventListener('click', function () {
        if (panel.hidden) {
            openPanel();
        } else {
            closePanel();
        }
    });

    input.addEventListener('input', function () {
        var query = input.value.trim();
        lastQuery = query;
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(function () {
            runSearch(query);
        }, 220);
    });

    form.addEventListener('submit', function () {
        // Let the form navigate to /search-results.php?q=… natively.
    });

    // Close when clicking outside the widget.
    document.addEventListener('click', function (event) {
        if (!panel.hidden && !wrap.contains(event.target)) {
            closePanel();
        }
    });

    // Close on Escape.
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !panel.hidden) {
            closePanel();
            toggle.focus();
        }
    });
})();
</script>

<?php if ($heroTitle): ?>
    <section class="lt-hero">
        <div class="lt-hero-inner">
            <h1><?= e($heroTitle) ?></h1>
            <?php if ($heroText): ?><p><?= e($heroText) ?></p><?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($breadcrumb): ?>
    <div class="lt-breadcrumb">
        <div class="lt-breadcrumb-inner"><?= $breadcrumb ?></div>
    </div>
<?php endif; ?>