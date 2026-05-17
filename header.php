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
            --lt-purple: #7413dc;
            --lt-purple-dark: #4d0b93;
            --lt-yellow: #ffb81c;
            --lt-text: #1d1d1b;
            --lt-border: #e6e6e6;
            --lt-soft: #f7f5fb;
        }

        body {
            margin: 0;
        }

        .lt-header {
            background: #ffffff;
            border-bottom: 1px solid var(--lt-border);
        }

        .lt-header-inner {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            min-height: 82px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: .65rem 0;
        }

        .lt-brand {
            display: inline-flex;
            align-items: center;
            min-width: 0;
            text-decoration: none;
        }

        .lt-brand:hover,
        .lt-brand:focus {
            text-decoration: none;
        }

        .lt-brand img {
            display: block;
            height: 62px;
            width: auto;
            max-width: 210px;
            object-fit: contain;
        }

        .lt-header-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: .75rem;
            min-width: 0;
        }

        .lt-header-home {
            display: none;
            color: var(--lt-purple-dark);
            font-weight: 900;
            text-decoration: none;
        }

        .lt-header-home:hover,
        .lt-header-home:focus {
            color: var(--lt-purple-dark);
            text-decoration: underline;
            text-decoration-thickness: 2px;
            text-underline-offset: 3px;
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
            gap: .65rem;
            border: 1px solid var(--lt-border);
            background: #ffffff;
            color: var(--lt-text);
            border-radius: 999px;
            padding: .35rem .45rem .35rem .9rem;
            min-height: 54px;
            max-width: 260px;
        }

        .lt-profile-trigger:hover,
        .lt-profile-trigger:focus {
            border-color: var(--lt-purple);
            box-shadow: 0 0 0 3px rgba(116, 19, 220, .12);
            outline: none;
        }

        .lt-profile-trigger-text {
            display: none;
            min-width: 0;
            line-height: 1.15;
            text-align: right;
        }

        .lt-profile-name {
            display: block;
            max-width: 170px;
            color: var(--lt-text);
            font-size: .92rem;
            font-weight: 900;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .lt-profile-group {
            display: block;
            max-width: 170px;
            margin-top: .05rem;
            color: #555;
            font-size: .78rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .lt-avatar {
            width: 44px;
            height: 44px;
            flex: 0 0 44px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--lt-purple);
            color: #ffffff;
            font-weight: 900;
            overflow: hidden;
        }

        .lt-avatar img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .lt-profile-chevron {
            width: .55rem;
            height: .55rem;
            border-right: 2px solid #555;
            border-bottom: 2px solid #555;
            transform: rotate(45deg) translateY(-2px);
            margin-right: .25rem;
        }

        .lt-profile-menu[open] .lt-profile-chevron {
            transform: rotate(225deg) translateY(-1px);
        }

        .lt-profile-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + .55rem);
            width: min(310px, calc(100vw - 1rem));
            background: #ffffff;
            color: var(--lt-text);
            border: 1px solid var(--lt-border);
            border-radius: .5rem;
            box-shadow: 0 14px 34px rgba(0, 0, 0, .12);
            overflow: hidden;
            z-index: 100;
        }

        .lt-profile-dropdown-header {
            display: grid;
            grid-template-columns: 54px minmax(0, 1fr);
            gap: .75rem;
            align-items: center;
            padding: 1rem;
            background: var(--lt-soft);
            border-bottom: 1px solid var(--lt-border);
        }

        .lt-dropdown-avatar {
            width: 54px;
            height: 54px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--lt-purple);
            color: #ffffff;
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
            color: var(--lt-text);
            font-size: 1rem;
            font-weight: 900;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }

        .lt-dropdown-group,
        .lt-dropdown-role {
            display: block;
            margin-top: .2rem;
            color: #555;
            font-size: .84rem;
            font-weight: 700;
        }

        .lt-profile-dropdown-links {
            padding: .35rem;
        }

        .lt-profile-dropdown-links a {
            display: block;
            color: var(--lt-text);
            text-decoration: none;
            font-weight: 800;
            padding: .8rem .85rem;
            border-radius: .35rem;
        }

        .lt-profile-dropdown-links a:hover,
        .lt-profile-dropdown-links a:focus {
            background: var(--lt-soft);
            color: var(--lt-purple-dark);
            text-decoration: none;
        }

        .lt-profile-dropdown-links .lt-signout-link {
            color: #b10e1e;
        }

        .lt-hero {
            background: var(--lt-soft);
            border-bottom: 1px solid var(--lt-border);
        }

        .lt-hero-inner {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            padding: 1.6rem 0;
        }

        .lt-hero h1 {
            margin: 0;
            color: var(--lt-purple-dark);
            font-size: clamp(2rem, 6vw, 3.2rem);
            line-height: 1.05;
            font-weight: 900;
        }

        .lt-hero p {
            margin: .65rem 0 0;
            max-width: 760px;
            color: #333;
            font-size: 1.05rem;
            font-weight: 700;
            line-height: 1.45;
        }

        .lt-breadcrumb {
            background: #ffffff;
            border-bottom: 1px solid var(--lt-border);
        }

        .lt-breadcrumb-inner {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            padding: .75rem 0;
            font-size: .95rem;
            font-weight: 700;
        }

        .lt-breadcrumb-inner a {
            color: var(--lt-purple-dark);
            font-weight: 900;
        }

        @media (min-width: 520px) {
            .lt-brand img {
                height: 72px;
                max-width: 240px;
            }

            .lt-profile-trigger-text {
                display: block;
            }
        }

        @media (min-width: 900px) {
            .lt-header-inner {
                min-height: 96px;
            }

            .lt-brand img {
                height: 82px;
                max-width: 280px;
            }

            .lt-header-home {
                display: inline-block;
            }
        }

        @media (max-width: 420px) {
            .lt-header-inner {
                width: min(1180px, calc(100% - .75rem));
            }

            .lt-brand img {
                height: 54px;
                max-width: 160px;
            }

            .lt-profile-trigger {
                padding: .3rem;
                min-height: 50px;
            }

            .lt-profile-chevron {
                display: none;
            }

            .lt-profile-dropdown {
                right: -.15rem;
            }
        }
    </style>
</head>
<body>
<header class="lt-header">
    <div class="lt-header-inner">
        <a class="lt-brand" href="/index.php" aria-label="Irwell Valley Scout District home">
            <img src="/assets/img/black-ir-logo.png" alt="Irwell Valley District Scouts" onerror="this.style.display='none';">
        </a>

        <?php if ($user): ?>
            <div class="lt-header-actions">
                <a class="lt-header-home" href="/index.php">Dashboard</a>

                <details class="lt-profile-menu">
                    <summary class="lt-profile-trigger" aria-label="Open profile menu">
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