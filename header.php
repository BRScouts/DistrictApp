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
            outline: 3px solid #ffdd00;
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
            color: #4d0b93;
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
    </style>
</head>
<body>
<header class="lt-header">
    <div class="lt-header-inner">
        <a class="lt-brand" href="/index.php" aria-label="Irwell Valley District Scouts">
            <img src="/assets/img/black-ir-logo.png" alt="Irwell Valley District Scouts" onerror="this.style.display='none';">
        </a>

        <?php if ($user): ?>
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
                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';"
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
</header>

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