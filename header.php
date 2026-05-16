<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($appName) ?></title>

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css"
          crossorigin="anonymous">

    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700;800;900&display=swap"
          rel="stylesheet">

    <style>
        :root {
            --district-purple: #5c1aa8;
            --district-purple-dark: #43127a;
            --district-bg: #f5f6f8;
            --text-dark: #161616;
            --text-muted: #6b6b6b;
            --border-light: #e5e5e5;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            background: var(--district-bg);
            color: var(--text-dark);
            font-family: 'Nunito Sans', sans-serif;
        }

        /*
        |--------------------------------------------------------------------------
        | Main Header
        |--------------------------------------------------------------------------
        */

        .site-header {
            background: #ffffff;
            border-bottom: 1px solid var(--border-light);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .site-header-inner {
            max-width: 1320px;
            margin: 0 auto;
            padding: 0 1.75rem;
            min-height: 82px;

            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /*
        |--------------------------------------------------------------------------
        | Logo Area
        |--------------------------------------------------------------------------
        */

        .brand-area {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--text-dark);
        }

        .brand-area:hover {
            color: var(--text-dark);
            text-decoration: none;
        }

        .brand-logo-placeholder {
            width: 52px;
            height: 52px;
            border-radius: 12px;

            background:
                linear-gradient(135deg,
                var(--district-purple),
                var(--district-purple-dark));

            display: flex;
            align-items: center;
            justify-content: center;

            color: #ffffff;
            font-size: 1.4rem;
            font-weight: 900;

            margin-right: 0.95rem;

            box-shadow:
                0 0.5rem 1.5rem rgba(92, 26, 168, 0.18);
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.1;
        }

        .brand-title {
            font-size: 1.1rem;
            font-weight: 900;
            letter-spacing: -0.03em;
        }

        .brand-subtitle {
            font-size: 0.82rem;
            color: var(--text-muted);
            font-weight: 700;
        }

        /*
        |--------------------------------------------------------------------------
        | Navigation
        |--------------------------------------------------------------------------
        */

        .main-nav {
            display: flex;
            align-items: center;
            gap: 1.75rem;
        }

        .main-nav a {
            color: #222222;
            text-decoration: none;
            font-weight: 800;
            font-size: 0.95rem;
            position: relative;
            transition: color 0.15s ease;
        }

        .main-nav a:hover {
            color: var(--district-purple);
            text-decoration: none;
        }

        .main-nav a.active {
            color: var(--district-purple);
        }

        .main-nav a.active::after {
            content: "";
            position: absolute;
            left: 0;
            bottom: -1.65rem;
            width: 100%;
            height: 3px;
            border-radius: 999px;
            background: var(--district-purple);
        }

        /*
        |--------------------------------------------------------------------------
        | Profile Section
        |--------------------------------------------------------------------------
        */

        .header-profile {
            display: flex;
            align-items: center;
            margin-left: 2rem;
            text-decoration: none;
        }

        .header-profile:hover {
            text-decoration: none;
        }

        .profile-meta {
            text-align: right;
            margin-right: 0.85rem;
            line-height: 1.15;
        }

        .profile-name {
            display: block;
            font-size: 0.92rem;
            font-weight: 900;
            color: #1b1b1b;
        }

        .profile-role {
            display: block;
            font-size: 0.78rem;
            color: var(--text-muted);
            font-weight: 700;
        }

        .profile-avatar {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;

            background:
                linear-gradient(135deg,
                var(--district-purple),
                var(--district-purple-dark));

            display: flex;
            align-items: center;
            justify-content: center;

            color: #ffffff;
            font-weight: 900;
            font-size: 0.95rem;

            border: 2px solid #ffffff;

            box-shadow:
                0 0.35rem 1rem rgba(0, 0, 0, 0.12);
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991.98px) {

            .main-nav {
                gap: 1rem;
            }

            .main-nav a {
                font-size: 0.88rem;
            }

            .profile-meta {
                display: none;
            }
        }

        @media (max-width: 767.98px) {

            .site-header-inner {
                padding: 0 1rem;
                min-height: 72px;
            }

            .brand-subtitle {
                display: none;
            }

            .main-nav {
                display: none;
            }

            .brand-logo-placeholder {
                width: 46px;
                height: 46px;
                margin-right: 0.75rem;
            }

            .brand-title {
                font-size: 1rem;
            }
        }
    </style>
</head>

<body>

<header class="site-header">
    <div class="site-header-inner">

        <!-- Left -->
        <a href="/index.php" class="brand-area">

            <!-- Replace this with your actual logo image later -->
            <div class="brand-logo-placeholder">
                IV
            </div>

            <div class="brand-text">
                <span class="brand-title">
                    Irwell Valley District Scouts
                </span>

                <span class="brand-subtitle">
                    District Platform
                </span>
            </div>
        </a>

        <!-- Centre Navigation -->
        <nav class="main-nav" aria-label="Main navigation">
            <a href="/dashboard.php" class="active">
                Dashboard
            </a>

            <a href="/events.php">
                Events
            </a>

            <a href="/reports.php">
                Reports
            </a>

            <a href="/leaders.php">
                Leaders
            </a>

            <a href="/settings.php">
                Settings
            </a>
        </nav>

        <!-- Right -->
        <a href="/profile.php" class="header-profile">

            <div class="profile-meta">
                <span class="profile-name">
                    <?= e($displayName) ?>
                </span>

                <span class="profile-role">
                    <?= e($user['role'] ?? 'Member') ?>
                </span>
            </div>

            <div class="profile-avatar">

                <img src="/auth/profile-photo.php"
                     alt="<?= e($displayName) ?>"
                     onerror="this.style.display='none'; this.parentNode.innerHTML='<?= e($initials) ?>';">

            </div>
        </a>

    </div>
</header>