<?php
$user = current_user();
$displayName = trim((string) ($user['preferred_name'] ?? $user['full_name'] ?? $user['email'] ?? 'User'));
$initials = strtoupper(substr($displayName, 0, 1));
$pageTitle = $pageTitle ?? $appName ?? app_config('APP_NAME', 'Irwell Valley Leader Tool');
$heroTitle = $heroTitle ?? null;
$heroText = $heroText ?? null;
$breadcrumb = $breadcrumb ?? null;
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
</head>
<body>
<header class="lt-header">
    <div class="lt-header-inner">
        <a class="lt-brand" href="/index.php">
            <img src="/assets/img/black-ir-logo.png" alt="Irwell Valley District Scouts" onerror="this.style.display='none';">
            <span>
                <span class="lt-brand-title">Leader Tool</span>
                <span class="lt-brand-subtitle">Irwell Valley Scout District</span>
            </span>
        </a>

        <?php if ($user): ?>
            <nav class="lt-nav" aria-label="Main navigation">
                <a href="/index.php">Home</a>
                <a href="/directory.php">Directory</a>
                <a href="/profile.php">Profile</a>
                <a href="/logout.php">Sign out</a>
                <a class="lt-user" href="/profile.php" aria-label="Open profile">
                    <span class="lt-user-meta">
                        <span class="lt-user-name"><?= e($displayName) ?></span>
                        <span class="lt-user-role"><?= e(str_replace('_', ' ', (string) ($user['highest_access_level'] ?? 'member'))) ?></span>
                    </span>
                    <span class="lt-avatar" aria-hidden="true"><?= e($initials) ?></span>
                </a>
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
