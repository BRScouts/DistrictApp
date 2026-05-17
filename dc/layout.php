<?php

declare(strict_types=1);

$ctx = dc_context(false);

$pageTitle = $pageTitle ?? 'District Calendar';
$heroTitle = $heroTitle ?? $pageTitle;
$heroText = $heroText ?? null;
$active = $active ?? '';

$groups = $ctx['groups'] ?? [];
$isSignedIn = (bool) ($ctx['is_signed_in'] ?? false);
$isReviewer = (bool) ($ctx['is_reviewer'] ?? false);
$isGroupLink = !$isSignedIn && (($ctx['actor_type'] ?? '') === 'group_link');

$displayName = $ctx['name'] ?? 'Group access';

$groupName = $ctx['group_name'] ?? null;

if (!$groupName && count($groups) === 1) {
    $groupName = $groups[0]['group_name'] ?? $groups[0]['name'] ?? null;
}

if (!$groupName && $groups) {
    $groupName = 'Multiple groups';
}

$roleLabel = 'Leader';

if ($isReviewer) {
    $roleLabel = 'Reviewer';
} elseif (!empty($ctx['role'])) {
    $roleLabel = ucwords(str_replace('_', ' ', (string) $ctx['role']));
} elseif (!empty($ctx['access_level'])) {
    $roleLabel = ucwords(str_replace('_', ' ', (string) $ctx['access_level']));
} elseif (!empty($ctx['membership_role'])) {
    $roleLabel = ucwords(str_replace('_', ' ', (string) $ctx['membership_role']));
}

$profilePhotoUrl = null;

if ($isSignedIn) {
    $profilePhotoUrl = '/auth/profile-photo.php';
}

$returnUrl = $isSignedIn ? '/index.php' : '/login.php';
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
        href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="/assets/css/leader-tool.css">

    <style>
        .dc-return-bar {
            background: #7413dc;
            color: #ffffff;
            border-bottom: 4px solid #000000;
        }

        .dc-return-bar-inner {
            width: min(1120px, calc(100% - 2rem));
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            min-height: 48px;
            padding: 0.35rem 0;
        }

        .dc-return-link {
            color: #ffffff;
            font-weight: 800;
            text-decoration: underline;
            text-decoration-thickness: 2px;
            text-underline-offset: 0.18em;
        }

        .dc-return-link:hover,
        .dc-return-link:focus {
            color: #ffffff;
            outline: 3px solid #ffdd00;
            outline-offset: 3px;
            text-decoration: none;
        }

        .dc-user-context {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem;
            min-width: 0;
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
            font-weight: 800;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 220px;
        }

        .dc-user-role {
            display: block;
            font-size: 0.875rem;
            font-weight: 700;
            opacity: 0.95;
        }

        .dc-profile-photo {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            border: 2px solid #ffffff;
            background: #ffffff;
            object-fit: cover;
            flex: 0 0 auto;
        }

        .dc-profile-fallback {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            border: 2px solid #ffffff;
            background: #000000;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            flex: 0 0 auto;
        }

        .dc-group-link-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.65rem;
            background: #006ddf;
            color: #ffffff;
            border: 2px solid #ffffff;
            font-weight: 800;
            line-height: 1.1;
            max-width: 260px;
        }

        .dc-group-link-badge span {
            display: inline-block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dc-group-link-badge small {
            display: none;
            font-weight: 700;
            opacity: 0.95;
        }

        .dc-nav-context {
            display: none;
        }

        @media (min-width: 640px) {
            .dc-user-text {
                display: block;
            }

            .dc-group-link-badge {
                display: grid;
                grid-template-columns: 1fr;
            }

            .dc-group-link-badge small {
                display: block;
            }
        }

        @media (max-width: 767.98px) {
            .dc-return-bar-inner {
                width: min(100% - 1rem, 1120px);
            }

            .dc-return-link {
                font-size: 0.95rem;
            }

            .dc-profile-photo,
            .dc-profile-fallback {
                width: 36px;
                height: 36px;
            }

            .dc-group-link-badge {
                max-width: 190px;
                font-size: 0.875rem;
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
                <div class="dc-user-text">
                    <span class="dc-user-name"><?= e($displayName) ?></span>
                    <span class="dc-user-role"><?= e($roleLabel) ?></span>
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
            <a class="<?= $active === 'home' ? 'active' : '' ?>" href="/dc/">Calendar</a>
            <a class="<?= $active === 'add' ? 'active' : '' ?>" href="/dc/add-event.php">Add event</a>
            <a class="<?= $active === 'risk' ? 'active' : '' ?>" href="/dc/risk-assessments.php">Risk assessments</a>
            <a class="<?= $active === 'map' ? 'active' : '' ?>" href="/dc/map.php">Map</a>

            <?php if ($isReviewer): ?>
                <a class="<?= $active === 'review' ? 'active' : '' ?>" href="/dc/reviewer/">Review</a>
            <?php endif; ?>

            <?php if ($isSignedIn): ?>
                <a href="/index.php">Leader Tool</a>
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

        <span class="dc-context-label">
            <?php if ($isSignedIn): ?>
                <?= e($displayName) ?><?= $roleLabel ? ' · ' . e($roleLabel) : '' ?>
            <?php else: ?>
                <?= e($groupName ?: $displayName) ?> · Group link
            <?php endif; ?>
        </span>
    </div>
</div>

<main class="lt-main dc-main">