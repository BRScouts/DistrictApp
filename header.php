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

        .lt-account {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: inherit;
            text-decoration: none;
        }

        .lt-account:hover,
        .lt-account:focus {
            color: inherit;
            text-decoration: none;
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

        .lt-account-links {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 0.3rem;
            font-size: 0.9rem;
            font-weight: 900;
        }

        .lt-account-links a {
            color: inherit;
            text-decoration: underline;
            text-decoration-thickness: 2px;
            text-underline-offset: 0.15em;
        }

        .lt-account-links a:hover,
        .lt-account-links a:focus {
            text-decoration: none;
        }

        .lt-account-links .lt-sign-out-link {
            color: #b10e1e;
        }

        .lt-avatar {
            overflow: hidden;
        }

        .lt-avatar img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        @media (max-width: 575.98px) {
            .lt-account-meta {
                display: none;
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
                <div class="lt-account" aria-label="Current user">
                    <span class="lt-account-meta">
                        <span class="lt-account-name"><?= e($displayName) ?></span>
                        <span class="lt-account-role"><?= e($roleLabel) ?></span>

                        <span class="lt-account-links">
                            <a href="/profile.php">Profile</a>
                            <a class="lt-sign-out-link" href="/logout.php">Sign out</a>
                        </span>
                    </span>

                    <a class="lt-avatar" href="/profile.php" aria-label="Open profile">
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
                    </a>
                </div>
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