<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css"
          crossorigin="anonymous">

    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700;800;900&display=swap"
          rel="stylesheet">

    <style>
        :root {
            --scout-purple: #7413dc;
            --scout-purple-dark: #4d0099;
            --scout-purple-deep: #330072;
            --scout-teal: #00a794;
            --scout-blue: #006ddf;
            --scout-red: #e22b1a;

            --text-dark: #111111;
            --text-muted: #686868;
            --border-light: #e5e5e5;
            --page-bg: #f7f7f7;
            --white: #ffffff;
        }

        html {
            min-height: 100%;
        }

        body {
            margin: 0;
            font-family: 'Nunito Sans', sans-serif;
            background: var(--page-bg);
            color: var(--text-dark);
            font-size: 16px;
        }

        /*
        |--------------------------------------------------------------------------
        | Top Scouts brand strip
        |--------------------------------------------------------------------------
        */

        .scout-brand-strip {
            background: var(--scout-purple);
            color: var(--white);
            height: 8px;
        }

        /*
        |--------------------------------------------------------------------------
        | Header
        |--------------------------------------------------------------------------
        */

        .top-header {
            background: var(--white);
            border-bottom: 1px solid var(--border-light);
            min-height: 88px;
            display: flex;
            align-items: center;
            box-shadow: 0 0.25rem 1.25rem rgba(0, 0, 0, 0.04);
        }

        .top-header-inner {
            width: 100%;
            max-width: 1240px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.5rem;
        }

        .brand-area {
            display: flex;
            align-items: center;
            min-width: 0;
            color: var(--text-dark);
            text-decoration: none;
        }

        .brand-area:hover {
            color: var(--text-dark);
            text-decoration: none;
        }

        /*
         * Replace /assets/img/logo.jpg with your uploaded JPG logo path.
         * Recommended image size: around 240px wide x 80px high.
         */
        .brand-logo {
            width: 72px;
            height: 56px;
            margin-right: 1rem;
            border-radius: 0.45rem;
            background: #f1e8ff;
            border: 1px solid #e3d3ff;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex: 0 0 72px;
        }

        .brand-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .brand-logo-fallback {
            color: var(--scout-purple);
            font-weight: 900;
            font-size: 0.8rem;
            text-align: center;
            line-height: 1.1;
            padding: 0.35rem;
        }

        .brand-copy {
            min-width: 0;
        }

        .brand-title {
            display: block;
            font-size: 1.18rem;
            font-weight: 900;
            letter-spacing: -0.035em;
            line-height: 1.05;
        }

        .brand-subtitle {
            display: block;
            margin-top: 0.2rem;
            font-size: 0.82rem;
            color: var(--text-muted);
            font-weight: 800;
        }

        .brand-subtitle::before {
            content: "";
            display: inline-block;
            width: 0.55rem;
            height: 0.55rem;
            margin-right: 0.35rem;
            border-radius: 50%;
            background: var(--scout-teal);
            vertical-align: middle;
            transform: translateY(-1px);
        }

        /*
        |--------------------------------------------------------------------------
        | Header actions
        |--------------------------------------------------------------------------
        */

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            flex-shrink: 0;
        }

        .dashboard-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.55rem 0.95rem;
            background: #f1e8ff;
            color: var(--scout-purple-dark);
            font-weight: 900;
            font-size: 0.88rem;
            text-decoration: none;
            border: 1px solid #e3d3ff;
        }

        .dashboard-link:hover {
            background: var(--scout-purple);
            color: var(--white);
            text-decoration: none;
        }

        .header-profile {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--text-dark);
            padding: 0.25rem;
            border-radius: 999px;
        }

        .header-profile:hover {
            color: var(--text-dark);
            text-decoration: none;
            background: #f5f5f5;
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
            max-width: 210px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .profile-role {
            display: block;
            margin-top: 0.15rem;
            font-size: 0.76rem;
            color: var(--text-muted);
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .profile-avatar {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--scout-purple);
            color: var(--white);
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid #f1e8ff;
            flex: 0 0 46px;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 767.98px) {
            .top-header {
                min-height: 76px;
            }

            .top-header-inner {
                padding-left: 1.25rem;
                padding-right: 1.25rem;
                gap: 1rem;
            }

            .brand-logo {
                width: 54px;
                height: 46px;
                flex-basis: 54px;
                margin-right: 0.75rem;
            }

            .brand-title {
                font-size: 1rem;
            }

            .brand-subtitle {
                display: none;
            }

            .dashboard-link {
                display: none;
            }

            .profile-meta {
                display: none;
            }

            .profile-avatar {
                width: 42px;
                height: 42px;
                flex-basis: 42px;
            }
        }
    </style>
</head>

<body>

<div class="scout-brand-strip"></div>

<header class="top-header">
    <div class="top-header-inner">

        <a href="/index.php" class="brand-area" aria-label="Return to District Dashboard">
            <div class="brand-logo">
                <img src="/assets/img/logo.jpg"
                     alt="Irwell Valley District Scouts logo"
                     onerror="this.style.display='none'; this.parentNode.innerHTML='<span class=&quot;brand-logo-fallback&quot;>Logo<br>here</span>';">
            </div>

            <div class="brand-copy">
                <span class="brand-title">Irwell Valley District Scouts</span>
                <span class="brand-subtitle">District Dashboard</span>
            </div>
        </a>

        <div class="header-actions">
            <a href="/index.php" class="dashboard-link">
                Dashboard
            </a>

            <a href="/profile.php" class="header-profile" aria-label="View my profile">
                <div class="profile-meta">
                    <span class="profile-name"><?= e($displayName) ?></span>
                    <span class="profile-role"><?= e($user['role'] ?? 'Member') ?></span>
                </div>

                <div class="profile-avatar">
                    <img src="/auth/profile-photo.php"
                         alt="<?= e($displayName) ?>"
                         onerror="this.style.display='none'; this.parentNode.innerHTML='<?= e($initials) ?>';">
                </div>
            </a>
        </div>

    </div>
</header>