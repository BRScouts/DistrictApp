<?php
$user = current_user();

$displayName = trim((string) ($user['preferred_name'] ?? $user['full_name'] ?? $user['email'] ?? 'User'));
$initials = strtoupper(substr($displayName, 0, 1));

$pageTitle = $pageTitle ?? $appName ?? app_config('APP_NAME', 'Irwell Valley Leader Tool');
$heroTitle = $heroTitle ?? null;
$heroText = $heroText ?? null;
$breadcrumb = $breadcrumb ?? null;

$userRole = trim(str_replace('_', ' ', (string) ($user['highest_access_level'] ?? 'member')));

$userGroupName = '';
if ($user && function_exists('user_group_memberships')) {
    try {
        $memberships = user_group_memberships((int) ($user['id'] ?? 0), false);

        foreach ($memberships as $membership) {
            if (($membership['status'] ?? 'active') !== 'active') {
                continue;
            }

            $groupName = trim((string) ($membership['group_name'] ?? $membership['name'] ?? ''));

            if ($groupName !== '') {
                $userGroupName = $groupName;
                break;
            }
        }
    } catch (Throwable $e) {
        $userGroupName = '';
    }
}

$profilePhotoUrl = '';
foreach ([
    'profile_photo_url',
    'photo_url',
    'avatar_url',
    'picture_url',
    'microsoft_photo_url',
    'ms_photo_url',
] as $photoField) {
    if (!empty($user[$photoField])) {
        $profilePhotoUrl = (string) $user[$photoField];
        break;
    }
}

if ($profilePhotoUrl === '' && !empty($user['id'])) {
    $localPhotoPath = '/uploads/profile-photos/' . (int) $user['id'] . '.jpg';
    $localPhotoFile = __DIR__ . $localPhotoPath;

    if (is_file($localPhotoFile)) {
        $profilePhotoUrl = $localPhotoPath;
    }
}

$isAdmin = in_array(
    (string) ($user['highest_access_level'] ?? 'member'),
    ['district_admin', 'system_admin'],
    true
);
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
        :root {
            --lt-header-purple: #7413dc;
            --lt-header-purple-dark: #4d0b93;
            --lt-header-yellow: #ffb81c;
            --lt-header-white: #ffffff;
            --lt-header-text: #1d1d1b;
            --lt-header-border: rgba(255, 255, 255, .22);
            --lt-header-shadow: 0 2px 16px rgba(0, 0, 0, .14);
        }

        body {
            margin: 0;
        }

        .lt-header {
            background: var(--lt-header-purple);
            color: var(--lt-header-white);
            box-shadow: var(--lt-header-shadow);
            position: relative;
            z-index: 50;
        }

        .lt-header-inner {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            min-height: 76px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            padding: .5rem 0;
        }

        .lt-brand {
            display: inline-flex;
            align-items: center;
            min-width: 0;
            text-decoration: none;
            color: var(--lt-header-white);
        }

        .lt-brand:hover,
        .lt-brand:focus {
            color: var(--lt-header-white);
            text-decoration: none;
        }

        .lt-brand-logo-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--lt-header-white);
            border-radius: .55rem;
            padding: .35rem .45rem;
            min-width: 112px;
            min-height: 56px;
        }

        .lt-brand img {
            display: block;
            width: auto;
            height: 48px;
            max-width: 150px;
            object-fit: contain;
        }

        .lt-brand-wordmark {
            display: none;
            margin-left: .8rem;
            line-height: 1.1;
        }

        .lt-brand-district {
            display: block;
            font-weight: 900;
            font-size: 1rem;
            letter-spacing: -.01em;
        }

        .lt-brand-subtitle {
            display: block;
            font-size: .82rem;
            font-weight: 700;
            opacity: .9;
        }

        .lt-header-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: .5rem;
            min-width: 0;
        }

        .lt-profile-menu {
            position: relative;
            display: inline-block;
        }

        .lt-profile-menu summary {
            list-style: none;
            cursor: pointer;
        }

        .lt-profile-menu summary::-webkit-details-marker {
            display: none;
        }

        .lt-profile-trigger {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: .6rem;
            border: 2px solid var(--lt-header-border);
            background: rgba(255, 255, 255, .1);
            color: var(--lt-header-white);
            border-radius: 999px;
            padding: .25rem .35rem .25rem .8rem;
            min-height: 52px;
            max-width: 210px;
        }

        .lt-profile-trigger:hover,
        .lt-profile-trigger:focus {
            background: rgba(255, 255, 255, .18);
            outline: 3px solid rgba(255, 184, 28, .55);
            outline-offset: 2px;
        }

        .lt-profile-trigger-text {
            display: none;
            min-width: 0;
            text-align: right;
            line-height: 1.1;
        }

        .lt-profile-name {
            display: block;
            font-weight: 900;
            font-size: .92rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .lt-profile-group {
            display: block;
            font-size: .76rem;
            font-weight: 700;
            opacity: .88;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .lt-avatar {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--lt-header-yellow);
            color: var(--lt-header-text);
            font-weight: 900;
            font-size: 1rem;
            overflow: hidden;
            border: 2px solid var(--lt-header-white);
        }

        .lt-avatar img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .lt-profile-chevron {
            width: .65rem;
            height: .65rem;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(45deg) translateY(-2px);
            opacity: .9;
            margin-right: .25rem;
        }

        .lt-profile-menu[open] .lt-profile-chevron {
            transform: rotate(225deg) translateY(-2px);
        }

        .lt-profile-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + .55rem);
            width: min(310px, calc(100vw - 1rem));
            background: #fff;
            color: var(--lt-header-text);
            border-radius: .7rem;
            box-shadow: 0 18px 48px rgba(0, 0, 0, .24);
            overflow: hidden;
            border: 1px solid #e6e6e6;
        }

        .lt-profile-dropdown-header {
            display: grid;
            grid-template-columns: 52px minmax(0, 1fr);
            gap: .75rem;
            align-items: center;
            padding: 1rem;
            background: #f5f3ff;
            border-bottom: 1px solid #e6e6e6;
        }

        .lt-dropdown-avatar {
            width: 52px;
            height: 52px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--lt-header-purple);
            color: #fff;
            font-weight: 900;
            overflow: hidden;
        }

        .lt-dropdown-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .lt-dropdown-name {
            display: block;
            font-size: 1rem;
            font-weight: 900;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }

        .lt-dropdown-group,
        .lt-dropdown-role {
            display: block;
            margin-top: .15rem;
            font-size: .84rem;
            font-weight: 700;
            color: #555;
        }

        .lt-profile-dropdown-links {
            padding: .35rem;
        }

        .lt-profile-dropdown-links a,
        .lt-profile-dropdown-links button {
            display: flex;
            align-items: center;
            width: 100%;
            gap: .5rem;
            border: 0;
            background: transparent;
            color: var(--lt-header-text);
            text-align: left;
            text-decoration: none;
            font-weight: 800;
            padding: .85rem .8rem;
            border-radius: .45rem;
        }

        .lt-profile-dropdown-links a:hover,
        .lt-profile-dropdown-links a:focus,
        .lt-profile-dropdown-links button:hover,
        .lt-profile-dropdown-links button:focus {
            background: #f1f1f1;
            color: var(--lt-header-purple-dark);
            text-decoration: none;
            outline: 3px solid rgba(116, 19, 220, .18);
        }

        .lt-profile-dropdown-links .lt-signout-link {
            color: #842029;
        }

        .lt-mobile-home {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--lt-header-border);
            background: rgba(255, 255, 255, .1);
            color: #fff;
            border-radius: 999px;
            font-weight: 900;
            text-decoration: none;
            min-height: 44px;
            padding: .45rem .8rem;
        }

        .lt-mobile-home:hover,
        .lt-mobile-home:focus {
            background: rgba(255, 255, 255, .18);
            color: #fff;
            text-decoration: none;
            outline: 3px solid rgba(255, 184, 28, .55);
            outline-offset: 2px;
        }

        .lt-hero {
            background: #f5f3ff;
            border-bottom: 1px solid #e7e2f4;
        }

        .lt-hero-inner {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            padding: 1.5rem 0;
        }

        .lt-hero h1 {
            margin: 0;
            color: var(--lt-header-purple-dark);
            font-size: clamp(1.8rem, 6vw, 3rem);
            font-weight: 900;
            line-height: 1.05;
        }

        .lt-hero p {
            margin: .65rem 0 0;
            max-width: 760px;
            font-size: 1.05rem;
            font-weight: 700;
            color: #333;
        }

        .lt-breadcrumb {
            background: #fff;
            border-bottom: 1px solid #e6e6e6;
        }

        .lt-breadcrumb-inner {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            padding: .75rem 0;
            font-size: .95rem;
            font-weight: 700;
        }

        .lt-breadcrumb-inner a {
            color: var(--lt-header-purple-dark);
            font-weight: 900;
        }

        @media (min-width: 480px) {
            .lt-header-inner {
                min-height: 84px;
            }

            .lt-brand-logo-wrap {
                min-width: 132px;
                min-height: 64px;
            }

            .lt-brand img {
                height: 56px;
                max-width: 170px;
            }

            .lt-profile-trigger {
                max-width: 260px;
            }

            .lt-profile-trigger-text {
                display: block;
            }
        }

        @media (min-width: 768px) {
            .lt-header-inner {
                min-height: 96px;
                padding: .7rem 0;
            }

            .lt-brand-logo-wrap {
                min-width: 160px;
                min-height: 76px;
                padding: .45rem .6rem;
            }

            .lt-brand img {
                height: 68px;
                max-width: 210px;
            }

            .lt-brand-wordmark {
                display: block;
            }

            .lt-brand-district {
                font-size: 1.15rem;
            }

            .lt-brand-subtitle {
                font-size: .9rem;
            }

            .lt-profile-trigger {
                padding-left: 1rem;
                max-width: 320px;
                min-height: 58px;
            }

            .lt-avatar {
                width: 48px;
                height: 48px;
                flex-basis: 48px;
            }

            .lt-mobile-home {
                display: none;
            }
        }

        @media (max-width: 420px) {
            .lt-header-inner {
                width: min(100% - .75rem, 1180px);
            }

            .lt-brand-logo-wrap {
                min-width: 96px;
                min-height: 52px;
            }

            .lt-brand img {
                height: 44px;
                max-width: 125px;
            }

            .lt-profile-trigger {
                padding: .2rem .25rem;
                min-height: 48px;
                max-width: 60px;
            }

            .lt-profile-chevron {
                display: none;
            }

            .lt-profile-dropdown {
                right: -.15rem;
            }
        }

        @media (prefers-reduced-motion: no-preference) {
            .lt-profile-trigger,
            .lt-profile-dropdown-links a,
            .lt-profile-dropdown-links button,
            .lt-mobile-home {
                transition: background-color .15s ease, color .15s ease, outline-color .15s ease;
            }
        }
    </style>
</head>
<body>
<header class="lt-header">
    <div class="lt-header-inner">
        <a class="lt-brand" href="/index.php" aria-label="Irwell Valley Scout District home">
            <span class="lt-brand-logo-wrap">
                <img src="/assets/img/black-ir-logo.png" alt="Irwell Valley District Scouts" onerror="this.style.display='none';">
            </span>
            <span class="lt-brand-wordmark">
                <span class="lt-brand-district">Irwell Valley Scout District</span>
                <span class="lt-brand-subtitle">Volunteer tools and District Calendar</span>
            </span>
        </a>

        <?php if ($user): ?>
            <div class="lt-header-actions">
                <a class="lt-mobile-home" href="/index.php">Home</a>

                <details class="lt-profile-menu">
                    <summary class="lt-profile-trigger" aria-label="Open profile menu">
                        <span class="lt-profile-trigger-text">
                            <span class="lt-profile-name"><?= e($displayName) ?></span>
                            <?php if ($userGroupName !== ''): ?>
                                <span class="lt-profile-group"><?= e($userGroupName) ?></span>
                            <?php else: ?>
                                <span class="lt-profile-group"><?= e(ucwords($userRole)) ?></span>
                            <?php endif; ?>
                        </span>

                        <span class="lt-avatar" aria-hidden="true">
                            <?php if ($profilePhotoUrl !== ''): ?>
                                <img src="<?= e($profilePhotoUrl) ?>" alt="" onerror="this.remove(); this.parentElement.textContent='<?= e($initials) ?>';">
                            <?php else: ?>
                                <?= e($initials) ?>
                            <?php endif; ?>
                        </span>

                        <span class="lt-profile-chevron" aria-hidden="true"></span>
                    </summary>

                    <div class="lt-profile-dropdown" role="menu">
                        <div class="lt-profile-dropdown-header">
                            <span class="lt-dropdown-avatar" aria-hidden="true">
                                <?php if ($profilePhotoUrl !== ''): ?>
                                    <img src="<?= e($profilePhotoUrl) ?>" alt="" onerror="this.remove(); this.parentElement.textContent='<?= e($initials) ?>';">
                                <?php else: ?>
                                    <?= e($initials) ?>
                                <?php endif; ?>
                            </span>

                            <span>
                                <span class="lt-dropdown-name"><?= e($displayName) ?></span>

                                <?php if ($userGroupName !== ''): ?>
                                    <span class="lt-dropdown-group"><?= e($userGroupName) ?></span>
                                <?php endif; ?>

                                <span class="lt-dropdown-role"><?= e(ucwords($userRole)) ?></span>
                            </span>
                        </div>

                        <div class="lt-profile-dropdown-links">
                            <a href="/index.php" role="menuitem">Home</a>
                            <a href="/profile.php" role="menuitem">My profile</a>

                            <?php if ($isAdmin): ?>
                                <a href="/district-admin.php" role="menuitem">District Admin</a>
                            <?php endif; ?>

                            <a class="lt-signout-link" href="/logout.php" role="menuitem">Sign out</a>
                        </div>
                    </div>
                </details>
            </div>
        <?php endif; ?>
    </div>
</header>

<?php if ($heroTitle): ?>
    <section class="lt-hero">
        <div class="lt-hero-inner">
            <h1><?= e($heroTitle) ?></h1>
            <?php if ($heroText): ?>
                <p><?= e($heroText) ?></p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($breadcrumb): ?>
    <div class="lt-breadcrumb">
        <div class="lt-breadcrumb-inner"><?= $breadcrumb ?></div>
    </div>
<?php endif; ?>