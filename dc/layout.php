<?php

declare(strict_types=1);

$ctx = dc_context(false);

$pageTitle = $pageTitle ?? 'District Calendar';
$heroTitle = $heroTitle ?? $pageTitle;
$heroText = $heroText ?? null;
$active = $active ?? '';

$groups = $ctx['groups'] ?? [];

$isSignedIn = (bool) ($ctx['is_signed_in'] ?? false);
$isGroupLink = !$isSignedIn && (($ctx['actor_type'] ?? '') === 'group_link');

$displayName = $ctx['name'] ?? 'Group access';

$groupName = $ctx['group_name'] ?? null;

if (!$groupName && count($groups) === 1) {
    $groupName = $groups[0]['group_name'] ?? $groups[0]['name'] ?? null;
}

if (!$groupName && $groups) {
    $groupName = 'Multiple groups';
}

$accessLevel = (string) ($ctx['access_level'] ?? '');
$membershipRole = (string) ($ctx['membership_role'] ?? '');
$role = (string) ($ctx['role'] ?? '');

$ctxAccessLevels = array_filter(array_map(
    'strval',
    (array) ($ctx['access_levels'] ?? [])
));

$ctxMembershipRoles = array_filter(array_map(
    'strval',
    (array) ($ctx['membership_roles'] ?? [])
));

if ($accessLevel !== '') {
    $ctxAccessLevels[] = $accessLevel;
}

if ($membershipRole !== '') {
    $ctxMembershipRoles[] = $membershipRole;
}

if ($role !== '') {
    $ctxMembershipRoles[] = $role;
}

$ctxAccessLevels = array_values(array_unique($ctxAccessLevels));
$ctxMembershipRoles = array_values(array_unique($ctxMembershipRoles));

$membershipSummaryParts = [];

foreach ($groups as $group) {
    $name = $group['group_name'] ?? $group['name'] ?? null;

    if (!$name) {
        continue;
    }

    $membershipSummaryParts[] = (string) $name;
}

$membershipSummaryParts = array_values(array_unique($membershipSummaryParts));

if ($membershipSummaryParts) {
    $membershipSummary = implode(', ', array_slice($membershipSummaryParts, 0, 2));

    if (count($membershipSummaryParts) > 2) {
        $membershipSummary .= ' +' . (count($membershipSummaryParts) - 2) . ' more';
    }
} elseif ($groupName) {
    $membershipSummary = $groupName;
} elseif ($isSignedIn) {
    $membershipSummary = 'No Group membership shown';
} else {
    $membershipSummary = 'Group access';
}

$isSystemAdmin = in_array('system_admin', $ctxAccessLevels, true);
$isDistrictAdmin = in_array('district_admin', $ctxAccessLevels, true);
$isDistrictReviewer = in_array('district_reviewer', $ctxAccessLevels, true);
$isGroupAdmin = in_array('group_admin', $ctxAccessLevels, true);

$isGlv = in_array('group_lead_volunteer', $ctxMembershipRoles, true)
    || in_array('group_lead_volunteer', $ctxAccessLevels, true)
    || $isGroupAdmin
    || $isDistrictAdmin
    || $isSystemAdmin;

$isReviewer = (bool) ($ctx['is_reviewer'] ?? false)
    || $isDistrictReviewer
    || $isDistrictAdmin
    || $isSystemAdmin;

$roleLabel = 'Leader';

if ($isSystemAdmin) {
    $roleLabel = 'System Admin';
} elseif ($isDistrictAdmin) {
    $roleLabel = 'District Admin';
} elseif ($isReviewer) {
    $roleLabel = 'Reviewer';
} elseif ($isGlv) {
    $roleLabel = 'Group Lead Volunteer';
} elseif ($accessLevel !== '') {
    $roleLabel = ucwords(str_replace('_', ' ', $accessLevel));
} elseif ($membershipRole !== '') {
    $roleLabel = ucwords(str_replace('_', ' ', $membershipRole));
} elseif ($role !== '') {
    $roleLabel = ucwords(str_replace('_', ' ', $role));
}

$profilePhotoUrl = null;

if ($isSignedIn) {
    $profilePhotoUrl = '/auth/profile-photo.php';
}

$returnUrl = $isSignedIn ? '/index.php' : '/login.php';
$profileUrl = '/profile.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($pageTitle) ?> | Irwell Valley Leader Tool</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css"
        integrity="sha384-5Kguc7IDQdynmm22yUyn9psYyP8LQhAWCCKJT/RrZJAWqdUAw5eADwc25JoYsXH6"
        crossorigin="anonymous"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,700&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="/assets/css/leader-tool.css">

    <style>
        :root {
            --dc-scouts-purple: #7413dc;
            --dc-scouts-purple-dark: #4d0b93;
            --dc-scouts-purple-deep: #2f005c;
            --dc-scouts-teal: #00a794;
            --dc-scouts-blue: #006ddf;
            --dc-focus: #ffdd00;
            --dc-ink: #101820;
            --dc-muted: #4b5563;
            --dc-border: #d8dde3;
            --dc-panel: #ffffff;
            --dc-canvas: #f5f6f8;
            --dc-radius: 0;
            --dc-width: 1120px;
        }

        html {
            background: var(--dc-canvas);
        }

        body {
            margin: 0;
            background: var(--dc-canvas);
            color: var(--dc-ink);
            font-family: "Nunito Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            text-rendering: optimizeLegibility;
        }

        a {
            color: var(--dc-scouts-purple-dark);
            text-decoration-thickness: 2px;
            text-underline-offset: 0.16em;
        }

        a:hover {
            color: var(--dc-scouts-purple-deep);
            text-decoration-thickness: 3px;
        }

        a:focus,
        button:focus,
        [tabindex]:focus {
            outline: 3px solid var(--dc-focus);
            outline-offset: 3px;
            box-shadow: 0 0 0 5px #000000;
        }

        .dc-shell-width {
            width: min(var(--dc-width), calc(100% - 2rem));
            margin-inline: auto;
        }

        /*
         * Top return/access bar
         * Clear, official, and service-oriented.
         */
        .dc-return-bar {
            background: var(--dc-scouts-purple);
            color: #ffffff;
            border-bottom: 4px solid #000000;
        }

        .dc-return-bar-inner {
            width: min(var(--dc-width), calc(100% - 2rem));
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            min-height: 56px;
            padding: 0.4rem 0;
        }

        .dc-return-link {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: #ffffff;
            font-weight: 900;
            line-height: 1.15;
            text-decoration: underline;
            text-decoration-thickness: 2px;
            text-underline-offset: 0.18em;
        }

        .dc-return-link:hover,
        .dc-return-link:focus {
            color: #ffffff;
            text-decoration: none;
        }

        .dc-user-context {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem;
            min-width: 0;
        }

        .dc-profile-link {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem;
            min-width: 0;
            color: #ffffff;
            text-decoration: none;
        }

        .dc-profile-link:hover,
        .dc-profile-link:focus {
            color: #ffffff;
            text-decoration: none;
        }

        .dc-user-text {
            display: none;
            color: #ffffff;
            text-align: right;
            line-height: 1.15;
            min-width: 0;
        }

        .dc-user-name {
            display: block;
            max-width: 280px;
            overflow: hidden;
            font-weight: 900;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dc-user-role {
            display: block;
            max-width: 280px;
            overflow: hidden;
            margin-top: 0.08rem;
            font-size: 0.875rem;
            font-weight: 800;
            opacity: 0.98;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dc-user-groups {
            display: block;
            max-width: 300px;
            overflow: hidden;
            margin-top: 0.08rem;
            font-size: 0.78rem;
            font-weight: 700;
            opacity: 0.92;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dc-profile-photo,
        .dc-profile-fallback {
            width: 44px;
            height: 44px;
            flex: 0 0 auto;
            border: 2px solid #ffffff;
            border-radius: 50%;
        }

        .dc-profile-photo {
            background: #ffffff;
            object-fit: cover;
        }

        .dc-profile-fallback {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #000000;
            color: #ffffff;
            font-weight: 900;
        }

        .dc-group-link-badge {
            display: inline-grid;
            grid-template-columns: 1fr;
            max-width: 280px;
            padding: 0.45rem 0.65rem;
            background: var(--dc-scouts-blue);
            color: #ffffff;
            border: 2px solid #ffffff;
            font-weight: 900;
            line-height: 1.1;
        }

        .dc-group-link-badge span {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dc-group-link-badge small {
            display: none;
            margin-top: 0.12rem;
            font-weight: 800;
            opacity: 0.95;
        }

        /*
         * Primary service header
         * Keeps existing lt-* classes for compatibility with current CSS/JS.
         */
        .lt-header {
            background: #ffffff;
            border-bottom: 1px solid var(--dc-border);
        }

        .lt-header-inner {
            width: min(var(--dc-width), calc(100% - 2rem));
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.25rem;
            padding: 1rem 0;
        }

        .lt-brand {
            display: inline-flex;
            align-items: center;
            min-width: 0;
            gap: 0.85rem;
            color: var(--dc-ink);
            text-decoration: none;
        }

        .lt-brand:hover {
            color: var(--dc-ink);
            text-decoration: none;
        }

        .lt-brand img {
            width: 52px;
            max-height: 52px;
            flex: 0 0 auto;
            object-fit: contain;
        }

        .lt-brand > span {
            display: block;
            min-width: 0;
        }

        .lt-brand-title {
            display: block;
            color: var(--dc-ink);
            font-size: 1.15rem;
            font-weight: 900;
            line-height: 1.05;
            letter-spacing: -0.02em;
        }

        .lt-brand-subtitle {
            display: block;
            margin-top: 0.12rem;
            color: var(--dc-muted);
            font-size: 0.9rem;
            font-weight: 800;
            line-height: 1.15;
        }

        .lt-menu-toggle {
            display: none;
            appearance: none;
            border: 2px solid var(--dc-ink);
            border-radius: var(--dc-radius);
            background: #ffffff;
            color: var(--dc-ink);
            cursor: pointer;
            font: inherit;
            font-weight: 900;
            line-height: 1;
            padding: 0.7rem 0.9rem;
        }

        .lt-menu-toggle:hover {
            background: var(--dc-ink);
            color: #ffffff;
        }

        .lt-nav,
        .dc-nav {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 0.25rem;
        }

        .lt-nav a,
        .dc-nav a {
            display: inline-flex;
            align-items: center;
            min-height: 44px;
            padding: 0.55rem 0.75rem;
            border-bottom: 4px solid transparent;
            color: var(--dc-ink);
            font-size: 0.95rem;
            font-weight: 900;
            line-height: 1.1;
            text-decoration: none;
        }

        .lt-nav a:hover,
        .dc-nav a:hover {
            background: #f0e7fb;
            color: var(--dc-scouts-purple-deep);
            border-bottom-color: var(--dc-scouts-purple);
            text-decoration: none;
        }

        .lt-nav a.active,
        .dc-nav a.active,
        .lt-nav a[aria-current="page"],
        .dc-nav a[aria-current="page"] {
            background: var(--dc-scouts-purple);
            color: #ffffff;
            border-bottom-color: #000000;
        }

        .lt-nav a.active:hover,
        .dc-nav a.active:hover,
        .lt-nav a[aria-current="page"]:hover,
        .dc-nav a[aria-current="page"]:hover {
            background: var(--dc-scouts-purple-dark);
            color: #ffffff;
        }

        /*
         * Hero
         * Bold, clear and content-first rather than decorative.
         */
        .lt-hero,
        .dc-hero {
            background:
                linear-gradient(90deg, var(--dc-scouts-purple-deep), var(--dc-scouts-purple));
            color: #ffffff;
            border-bottom: 6px solid var(--dc-scouts-teal);
        }

        .lt-hero-inner {
            width: min(var(--dc-width), calc(100% - 2rem));
            margin: 0 auto;
            padding: 2.25rem 0 2rem;
        }

        .lt-hero h1,
        .dc-hero h1 {
            max-width: 820px;
            margin: 0;
            color: #ffffff;
            font-size: clamp(2rem, 5vw, 3.75rem);
            font-weight: 900;
            line-height: 0.98;
            letter-spacing: -0.045em;
        }

        .lt-hero p,
        .dc-hero p {
            max-width: 720px;
            margin: 1rem 0 0;
            color: #ffffff;
            font-size: clamp(1.05rem, 2vw, 1.25rem);
            font-weight: 700;
            line-height: 1.4;
        }

        /*
         * Breadcrumb
         */
        .lt-breadcrumb {
            background: #ffffff;
            border-bottom: 1px solid var(--dc-border);
        }

        .lt-breadcrumb-inner {
            width: min(var(--dc-width), calc(100% - 2rem));
            margin: 0 auto;
            padding: 0.85rem 0;
            color: var(--dc-muted);
            font-size: 0.95rem;
            font-weight: 800;
            line-height: 1.3;
        }

        .lt-breadcrumb-inner a {
            color: var(--dc-scouts-purple-dark);
            font-weight: 900;
        }

        .lt-breadcrumb-inner span[aria-hidden="true"] {
            display: inline-block;
            margin: 0 0.45rem;
            color: var(--dc-muted);
            font-weight: 900;
        }

        /*
         * Main content wrapper
         */
        .lt-main,
        .dc-main {
            width: min(var(--dc-width), calc(100% - 2rem));
            margin: 0 auto;
            padding: 2rem 0 3rem;
        }

        /*
         * Useful defaults for content rendered inside the layout.
         * These are deliberately conservative so they should not break existing pages.
         */
        .dc-main h2,
        .dc-main h3 {
            color: var(--dc-ink);
            font-weight: 900;
            letter-spacing: -0.02em;
        }

        .dc-main h2 {
            margin-top: 0;
            font-size: clamp(1.6rem, 3vw, 2.25rem);
            line-height: 1.05;
        }

        .dc-main h3 {
            font-size: 1.3rem;
            line-height: 1.15;
        }

        .dc-main p,
        .dc-main li {
            color: var(--dc-ink);
            font-size: 1rem;
            line-height: 1.55;
        }

        .dc-main .btn,
        .dc-main button,
        .dc-main input[type="submit"] {
            border-radius: var(--dc-radius);
            font-weight: 900;
        }

        .dc-main table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
        }

        .dc-main th {
            background: var(--dc-ink);
            color: #ffffff;
            font-weight: 900;
        }

        .dc-main th,
        .dc-main td {
            padding: 0.85rem;
            border: 1px solid var(--dc-border);
            text-align: left;
            vertical-align: top;
        }

        .dc-main form {
            accent-color: var(--dc-scouts-purple);
        }

        .dc-main input,
        .dc-main select,
        .dc-main textarea {
            border-radius: var(--dc-radius);
        }

        /*
         * Responsive behaviour
         */
        @media (min-width: 640px) {
            .dc-user-text {
                display: block;
            }

            .dc-group-link-badge small {
                display: block;
            }
        }

        @media (max-width: 900px) {
            .lt-header-inner {
                align-items: flex-start;
                flex-wrap: wrap;
            }

            .lt-menu-toggle {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .lt-nav,
            .dc-nav {
                width: 100%;
                justify-content: flex-start;
                padding-top: 0.75rem;
                border-top: 1px solid var(--dc-border);
            }

            /*
             * If your existing JS toggles a class, this still leaves the nav usable.
             * If it toggles hidden/display elsewhere, these styles will not block it.
             */
            .lt-nav a,
            .dc-nav a {
                flex: 1 1 auto;
            }
        }

        @media (max-width: 767.98px) {
            .dc-return-bar-inner,
            .lt-header-inner,
            .lt-hero-inner,
            .lt-breadcrumb-inner,
            .lt-main,
            .dc-main {
                width: min(100% - 1rem, var(--dc-width));
            }

            .dc-return-bar-inner {
                min-height: 54px;
                gap: 0.75rem;
            }

            .dc-return-link {
                font-size: 0.95rem;
            }

            .dc-profile-photo,
            .dc-profile-fallback {
                width: 38px;
                height: 38px;
            }

            .dc-user-name,
            .dc-user-role,
            .dc-user-groups {
                max-width: 170px;
            }

            .dc-group-link-badge {
                max-width: 190px;
                font-size: 0.875rem;
            }

            .lt-brand img {
                width: 44px;
                max-height: 44px;
            }

            .lt-brand-title {
                font-size: 1rem;
            }

            .lt-brand-subtitle {
                font-size: 0.82rem;
            }

            .lt-hero-inner {
                padding: 1.75rem 0 1.6rem;
            }

            .lt-main,
            .dc-main {
                padding-top: 1.25rem;
            }

            .lt-nav,
            .dc-nav {
                display: grid;
                grid-template-columns: 1fr;
                gap: 0.35rem;
            }

            .lt-nav a,
            .dc-nav a {
                width: 100%;
                min-height: 46px;
                border: 2px solid var(--dc-border);
                border-left: 6px solid var(--dc-scouts-purple);
                background: #ffffff;
            }

            .lt-nav a.active,
            .dc-nav a.active,
            .lt-nav a[aria-current="page"],
            .dc-nav a[aria-current="page"] {
                border-color: #000000;
                border-left-color: #000000;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                transition-duration: 0.001ms !important;
                animation-duration: 0.001ms !important;
                animation-iteration-count: 1 !important;
            }
        }
    </style>
</head>

<body>
<header class="dc-return-bar">
    <div class="dc-return-bar-inner">
        <a class="dc-return-link" href="<?= e($returnUrl) ?>">
            ← Return to District App
        </a>

        <div class="dc-user-context" aria-label="Current access">
            <?php if ($isSignedIn): ?>
                <a class="dc-profile-link" href="<?= e($profileUrl) ?>" aria-label="Open my profile">
                    <div class="dc-user-text">
                        <span class="dc-user-name"><?= e($displayName) ?></span>
                        <span class="dc-user-role"><?= e($roleLabel) ?></span>
                        <span class="dc-user-groups"><?= e($membershipSummary) ?></span>
                    </div>

                    <?php if ($profilePhotoUrl): ?>
                        <img
                            class="dc-profile-photo"
                            src="<?= e($profilePhotoUrl) ?>"
                            alt=""
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';"
                        >
                        <span class="dc-profile-fallback" style="display: none;" aria-hidden="true">
                            <?= e(strtoupper(substr((string) $displayName, 0, 1))) ?>
                        </span>
                    <?php else: ?>
                        <span class="dc-profile-fallback" aria-hidden="true">
                            <?= e(strtoupper(substr((string) $displayName, 0, 1))) ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php else: ?>
                <div class="dc-group-link-badge">
                    <span><?= e($groupName ?: 'Group access') ?></span>
                    <small>Group link access</small>
                </div>
            <?php endif; ?>
        </div>
    </div>
</header>

<header class="lt-header">
    <div class="lt-header-inner">
        <a class="lt-brand" href="/dc/">
            <img
                src="/assets/img/black-ir-logo.png"
                alt="Irwell Valley District Scouts"
                onerror="this.style.display='none';"
            >
            <span>
                <span class="lt-brand-title">District Calendar</span>
                <span class="lt-brand-subtitle">Irwell Valley Leader Tool</span>
            </span>
        </a>

        <button
            class="lt-menu-toggle"
            type="button"
            data-menu-toggle
            aria-expanded="false"
            aria-controls="dc-main-nav"
        >
            Menu
        </button>

        <nav id="dc-main-nav" class="lt-nav dc-nav" aria-label="District Calendar navigation">
            <a
                class="<?= $active === 'home' ? 'active' : '' ?>"
                href="/dc/"
                <?= $active === 'home' ? 'aria-current="page"' : '' ?>
            >
                Calendar
            </a>

            <a
                class="<?= $active === 'add' ? 'active' : '' ?>"
                href="/dc/add-event.php"
                <?= $active === 'add' ? 'aria-current="page"' : '' ?>
            >
                Add event
            </a>

            <a
                class="<?= $active === 'risk' ? 'active' : '' ?>"
                href="/dc/risk-assessments.php"
                <?= $active === 'risk' ? 'aria-current="page"' : '' ?>
            >
                Risk assessments
            </a>

            <a
                class="<?= $active === 'map' ? 'active' : '' ?>"
                href="/dc/map.php"
                <?= $active === 'map' ? 'aria-current="page"' : '' ?>
            >
                Map
            </a>

            <?php if ($isGlv): ?>
                <a
                    class="<?= $active === 'glv' ? 'active' : '' ?>"
                    href="/dc/glv.php"
                    <?= $active === 'glv' ? 'aria-current="page"' : '' ?>
                >
                    GLV
                </a>
            <?php endif; ?>

            <?php if ($isReviewer): ?>
                <a
                    class="<?= $active === 'review' ? 'active' : '' ?>"
                    href="/dc/reviewer/"
                    <?= $active === 'review' ? 'aria-current="page"' : '' ?>
                >
                    Review
                </a>
            <?php endif; ?>

            <?php if ($isSignedIn): ?>
            <?php else: ?>
                <a href="/dc/logout.php">Leave group access</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<section class="lt-hero dc-hero">
    <div class="lt-hero-inner">
        <h1><?= e($heroTitle) ?></h1>

        <?php if ($heroText): ?>
            <p><?= e($heroText) ?></p>
        <?php endif; ?>
    </div>
</section>

<div class="lt-breadcrumb">
    <div class="lt-breadcrumb-inner">
        <a href="/dc/">District Calendar</a>

        <?php if ($pageTitle !== 'District Calendar'): ?>
            <span aria-hidden="true">›</span>
            <?= e($pageTitle) ?>
        <?php endif; ?>
    </div>
</div>

<main class="lt-main dc-main">