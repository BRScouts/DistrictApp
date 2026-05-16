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
            --scout-purple: #4d0099;
            --scout-purple-dark: #3b0078;
            --scout-red: #e22b1a;
            --scout-blue: #006ddf;
            --text-dark: #111111;
            --text-muted: #686868;
            --border-light: #dddddd;
            --page-bg: #ffffff;
        }

        body {
            margin: 0;
            font-family: 'Nunito Sans', sans-serif;
            background: var(--page-bg);
            color: var(--text-dark);
            font-size: 16px;
        }

        .top-header {
            background: #ffffff;
            border-bottom: 1px solid #eeeeee;
            min-height: 82px;
            display: flex;
            align-items: center;
        }

        .top-header-inner {
            width: 100%;
            max-width: 1240px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand-area {
            display: flex;
            align-items: center;
            color: #000000;
            text-decoration: none;
        }

        .brand-area:hover {
            color: #000000;
            text-decoration: none;
        }

        .brand-logo-placeholder {
            width: 52px;
            height: 52px;
            border-radius: 8px;
            background: var(--scout-purple);
            color: #ffffff;
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.85rem;
        }

        .brand-title {
            display: block;
            font-size: 1.1rem;
            font-weight: 900;
            letter-spacing: -0.03em;
            line-height: 1.1;
        }

        .brand-subtitle {
            display: block;
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 700;
        }

        .header-profile {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: #111111;
        }

        .header-profile:hover {
            color: #111111;
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
            background: var(--scout-purple);
            color: #ffffff;
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .member-hero {
            background: var(--scout-purple);
            color: #ffffff;
            position: relative;
            overflow: hidden;
        }

        .member-hero::after {
            content: "⚜";
            position: absolute;
            right: 6%;
            top: -2rem;
            font-size: 15rem;
            line-height: 1;
            color: rgba(255, 255, 255, 0.08);
            font-family: serif;
            pointer-events: none;
        }

        .member-hero-inner {
            max-width: 1240px;
            margin: 0 auto;
            padding: 2rem 2rem 2.75rem;
            position: relative;
            z-index: 1;
        }

        .member-hero h1 {
            font-size: 2.55rem;
            font-weight: 900;
            margin: 0 0 0.4rem;
            letter-spacing: -0.04em;
        }

        .member-number {
            font-weight: 700;
            margin-bottom: 2.2rem;
        }

        .member-service {
            font-size: 2rem;
            font-weight: 900;
            letter-spacing: -0.03em;
        }

        .breadcrumb-strip {
            background: #eeeeee;
            border-bottom: 1px solid #e2e2e2;
        }

        .breadcrumb-inner {
            max-width: 1240px;
            margin: 0 auto;
            padding: 0.75rem 2rem;
            font-size: 0.85rem;
            font-weight: 700;
            color: #555555;
        }

        .breadcrumb-inner a {
            color: #555555;
            text-decoration: none;
        }

        .page-container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 2.5rem 2rem 6rem;
        }

        .page-title {
            font-size: 2.35rem;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 2rem;
        }

        .dashboard-card {
            border: 1px solid var(--border-light);
            border-radius: 0;
            background: #ffffff;
            height: 100%;
            box-shadow: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .dashboard-card.available:hover {
            border-color: #bfbfbf;
            box-shadow: 0 0.35rem 1.25rem rgba(0, 0, 0, 0.08);
        }

        .dashboard-card.coming-soon {
            opacity: 0.72;
        }

        .module-image {
            width: 100%;
            height: 172px;
            object-fit: cover;
            display: block;
            border-bottom: 1px solid var(--border-light);
            background: #f2f2f2;
        }

        .module-body {
            padding: 0.85rem 0.95rem 1.05rem;
        }

        .module-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.35rem;
        }

.external-links-section {
    border-top: 1px solid var(--border-light);
    padding-top: 2rem;
}

.external-links-title {
    font-size: 1.55rem;
    font-weight: 900;
    letter-spacing: -0.035em;
    margin: 0 0 0.25rem;
}

.external-links-subtitle {
    color: var(--text-muted);
    font-weight: 700;
    margin: 0;
}

.external-link-card {
    display: flex;
    gap: 1rem;
    height: 100%;
    padding: 1.25rem;
    background: #ffffff;
    border: 1px solid var(--border-light);
    color: var(--text-dark);
    text-decoration: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}

.external-link-card:hover {
    color: var(--text-dark);
    text-decoration: none;
    border-color: #bfbfbf;
    box-shadow: 0 0.35rem 1.25rem rgba(0, 0, 0, 0.08);
    transform: translateY(-2px);
}

.external-link-icon {
    width: 48px;
    height: 48px;
    border-radius: 999px;
    background: #f1e8ff;
    color: var(--scout-purple);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 48px;
}

.external-link-icon svg {
    width: 26px;
    height: 26px;
    fill: currentColor;
}

.external-link-title {
    font-size: 1.05rem;
    font-weight: 900;
    letter-spacing: -0.02em;
    margin: 0 0 0.35rem;
}

.external-link-description {
    color: var(--text-muted);
    font-size: 0.92rem;
    line-height: 1.35;
    margin: 0 0 0.75rem;
}

.external-link-action {
    color: var(--scout-blue);
    font-weight: 900;
}

        .module-title-wrap {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            min-width: 0;
        }

        .module-icon {
            display: inline-flex;
            width: 20px;
            height: 20px;
            flex: 0 0 20px;
            color: #111111;
        }

        .module-icon svg {
            width: 20px;
            height: 20px;
            fill: currentColor;
        }

        .module-title {
            font-size: 1.05rem;
            font-weight: 900;
            margin: 0;
            letter-spacing: -0.02em;
        }

        .module-description {
            color: var(--text-muted);
            font-size: 0.92rem;
            line-height: 1.35;
            margin-bottom: 0.8rem;
        }

        .module-link {
            color: var(--scout-blue);
            font-weight: 900;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
        }

        .module-link:hover {
            color: #004f9f;
            text-decoration: none;
        }

        .module-link-arrow {
            font-size: 1.35rem;
            line-height: 1;
            transform: translateY(-1px);
        }

        .status-badge {
            background: var(--scout-red);
            color: #ffffff;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 900;
            padding: 0.18rem 0.55rem;
            line-height: 1.2;
        }

        .card-link-overlay {
            color: inherit;
            text-decoration: none;
            display: block;
            height: 100%;
        }

        .card-link-overlay:hover {
            color: inherit;
            text-decoration: none;
        }

        @media (max-width: 767.98px) {
            .top-header-inner,
            .member-hero-inner,
            .breadcrumb-inner,
            .page-container {
                padding-left: 1.25rem;
                padding-right: 1.25rem;
            }

            .top-header {
                min-height: 72px;
            }

            .brand-logo-placeholder {
                width: 46px;
                height: 46px;
            }

            .brand-title {
                font-size: 1rem;
            }

            .brand-subtitle,
            .profile-meta {
                display: none;
            }

            .member-hero h1 {
                font-size: 2rem;
            }

            .member-service {
                font-size: 1.55rem;
            }

            .member-hero::after {
                font-size: 9rem;
                right: -1rem;
                top: 0.25rem;
            }

            .page-title {
                font-size: 2rem;
            }
        }
    </style>
</head>

<body>

<header class="top-header">
    <div class="top-header-inner">
        <a href="/index.php" class="brand-area">
            <div class="brand-logo">
    <img src="/assets/img/black-ir-logo.png" alt="Logo">
</div>

<style>
    .brand-logo {
        width: 240px;   /* Controls overall logo width */
        height: 60px;   /* Controls overall logo height */

        display: flex;
        align-items: center;
    }

    .brand-logo img {
        max-width: 100%;
        max-height: 100%;

        width: auto;
        height: auto;

        object-fit: contain;
        display: block;
    }
</style>


        </a>

        <a href="/profile.php" class="header-profile">
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
</header>

