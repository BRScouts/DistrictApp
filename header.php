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
            --iv-purple: #7413dc;
            --iv-purple-dark: #4d0b93;
            --iv-yellow: #ffb81c;
            --iv-black: #1d1d1b;
            --iv-white: #ffffff;
            --iv-grey: #f3f2f1;
            --iv-border: #b1b4b6;
        }

        body {
            margin: 0;
            color: var(--iv-black);
            background: #ffffff;
        }

        a {
            font-weight: 800;
        }

        a:focus,
        button:focus,
        summary:focus {
            outline: 4px solid var(--iv-yellow);
            outline-offset: 2px;
        }

        .lt-header {
            background: var(--iv-purple);
            color: var(--iv-white);
            border-bottom: 8px solid var(--iv-yellow);
        }

        .lt-header-inner {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            min-height: 82px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: .75rem 0;
        }

        .lt-brand {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            color: var(--iv-white);
            min-width: 0;
        }

        .lt-brand:hover {
            color: var(--iv-white);
            text-decoration: none;
        }

        .lt-brand-logo-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            padding: .45rem .6rem;
            min-width: 130px;
        }

        .lt-brand-logo-box img {
            display: block;
            height: 56px;
            width: auto;
            max-width: 180px;
            object-fit: contain;
        }

        .lt-brand-text {
            display: none;
            margin-left: 1rem;
            line-height: 1.05;
        }

        .lt-brand-text strong {
            display: block;
            font-size: 1.2rem;
            font-weight: 900;
            letter-spacing: -.01em;
        }

        .lt-brand-text span {
            display: block;
            margin-top: .15rem;
            font-size: .9rem;
            font-weight: 800;
            opacity: .95;
        }

        .lt-header-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: .5rem;
        }

        .lt-home-link {
            display: none;
            color: #ffffff;
            text-decoration: underline;
            text-decoration-thickness: 3px;
            text-underline-offset: 4px;
            font-weight: 900;
        }

        .lt-home-link:hover {
            color: #ffffff;
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
            gap: .6rem;
            min-height: 52px;
            border: 3px solid #ffffff;
            background: var(--iv-purple-dark);
            color: #ffffff;
            padding: .25rem .35rem .25rem .75rem;
            font-weight: 900;
        }

        .lt-profile-trigger:hover {
            background: #2f075c;
        }

        .lt-profile-trigger-text {
            display: none;
            min-width: 0;
            text-align: right;
            line-height: 1.1;
        }

        .lt-profile-name {
            display: block;
            max-width: 190px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: .95rem;
            font-weight: 900;
        }

        .lt-profile-group {
            display: block;
            max-width: 190px;
            margin-top: .1rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: .8rem;
            font-weight: 800;
        }

        .lt-avatar {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 50%;
            background: var(--iv-yellow);
            color: var(--iv-black);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            overflow: hidden;
            border: 2px solid #ffffff;
        }

        .lt-avatar img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .lt-profile-caret {
            width: 0;
            height: 0;
            border-left: .35rem solid transparent;
            border-right: .35rem solid transparent;
            border-top: .45rem solid #ffffff;
            margin-right: .25rem;
        }

        .lt-profile-menu[open] .lt-profile-caret {
            border-top: 0;
            border-bottom: .45rem solid #ffffff;
        }

        .lt-profile-dropdown {
            position: absolute;
            top: calc(100% + .5rem);
            right: 0;
            width: min(320px, calc(100vw - 1rem));
            background: #ffffff;
            color: var(--iv-black);
            border: 3px solid var(--iv-black);
            z-index: 100;
        }

        .lt-profile-dropdown-header {
            display: grid;
            grid-template-columns: 54px minmax(0, 1fr);
            gap: .75rem;
            align-items: center;
            padding: 1rem;
            background: var(--iv-grey);
            border-bottom: 3px solid var(--iv-black);
        }

        .lt-dropdown-avatar {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: var(--iv-purple);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
            font-weight: 900;
            font-size: 1.05rem;
            line-height: 1.1;
            overflow-wrap: anywhere;
        }

        .lt-dropdown-group,
        .lt-dropdown-role {
            display: block;
            margin-top: .2rem;
            font-weight: 800;
            font-size: .85rem;
            color: #505a5f;
        }

        .lt-profile-dropdown-links {
            padding: .5rem;
        }

        .lt-profile-dropdown-links a {
            display: block;
            color: var(--iv-black);
            padding: .85rem;
            text-decoration: underline;
            text-decoration-thickness: 2px;
            text-underline-offset: 3px;
            font-weight: 900;
        }

        .lt-profile-dropdown-links a:hover {
            background: var(--iv-grey);
            color: var(--iv-purple-dark);
        }

        .lt-profile-dropdown-links .lt-signout-link {
            color: #b10e1e;
        }

        .lt-hero {
            background: var(--iv-grey);
            border-bottom: 1px solid var(--iv-border);
        }

        .lt-hero-inner {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            padding: 1.5rem 0;
        }

        .lt-hero h1 {
            margin: 0;
            color: var(--iv-black);
            font-size: clamp(2rem, 7vw, 3.4rem);
            line-height: 1;
            font-weight: 900;
        }

        .lt-hero p {
            margin: .75rem 0 0;
            max-width: 720px;
            color: var(--iv-black);
            font-size: 1.1rem;
            line-height: 1.4;
            font-weight: 700;
        }

        .lt-breadcrumb {
            background: #ffffff;
            border-bottom: 1px solid var(--iv-border);
        }

        .lt-breadcrumb-inner {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            padding: .75rem 0;
            font-weight: 800;
            font-size: .95rem;
        }

        .lt-breadcrumb-inner a {
            color: var(--iv-purple-dark);
            text-decoration: underline;
            text-decoration-thickness: 2px;
            text-underline-offset: 3px;
        }

        @media (min-width: 600px) {
            .lt-brand-logo-box {
                min-width: 160px;
            }

            .lt-brand-logo-box img {
                height: 68px;
                max-width: 220px;
            }

            .lt-brand-text {
                display: block;
            }

            .lt-profile-trigger-text {
                display: block;
            }
        }

        @media (min-width: 900px) {
            .lt-header-inner {
                min-height: 104px;
            }

            .lt-brand-logo-box {
                min-width: 190px;
                padding: .55rem .75rem;
            }

            .lt-brand-logo-box img {
                height: 78px;
                max-width: 250px;
            }

            .lt-brand-text strong {
                font-size: 1.35rem;
            }

            .lt-home-link {
                display: inline-block;
            }
        }

        @media (max-width: 420px) {
            .lt-header-inner {
                width: min(1180px, calc(100% - .75rem));
            }

            .lt-brand-logo-box {
                min-width: 108px;
                padding: .35rem .45rem;
            }

            .lt-brand-logo-box img {
                height: 48px;
                max-width: 135px;
            }

            .lt-profile-trigger {
                padding: .25rem;
                min-height: 48px;
            }

            .lt-profile-caret {
                display: none;
            }

            .lt-profile-dropdown {
                right: -.25rem;
            }
        }
    </style>
</head>
<body>
<header class="lt-header">
    <div class="lt-header-inner">
        <a class="lt-brand" href="/index.php" aria-label="Irwell Valley Scout District dashboard">
            <span class="lt-brand-logo-box">
                <img src="/assets/img/black-ir-logo.png" alt="Irwell Valley District Scouts" onerror="this.style.display='none';">
            </span>

            <span class="lt-brand-text">
                <strong>Irwell Valley Scout District</strong>
                <span>District Dashboard</span>
            </span>
        </a>

        <?php if ($user): ?>
            <div class="lt-header-actions">
                <a class="lt-home-link" href="/index.php">Dashboard</a>

                <details class="lt-profile-menu">
                    <summary class="lt-profile-trigger" aria-label="Open account menu">
                        <span class="lt-profile-trigger-text">
                            <span class="lt-profile-name"><?= e($displayName) ?></span>
                            <span class="lt-profile-group">
                                <?= e($userGroupName !== '' ? $userGroupName : ucwords($userRole)) ?>
                            </span>
                        </span>

                        <span class="lt-avatar" aria-hidden="true">
                            <?php if ($profilePhotoUrl !== ''): ?>
                                <img src="<?= e($profilePhotoUrl) ?>" alt="" onerror="this.remove(); this.parentElement.textContent='<?= e($initials) ?>';">
                            <?php else: ?>
                                <?= e($initials) ?>
                            <?php endif; ?>
                        </span>

                        <span class="lt-profile-caret" aria-hidden="true"></span>
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