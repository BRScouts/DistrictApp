<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css"
          crossorigin="anonymous">

    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,400;0,600;0,700;0,800;0,900&display=swap"
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

        .brand-link {
            display: inline-flex;
            align-items: center;
            color: #000000;
            text-decoration: none;
            font-weight: 900;
            font-size: 1.55rem;
            letter-spacing: -0.04em;
        }

        .brand-link:hover {
            color: #000000;
            text-decoration: none;
        }

        .brand-mark {
            font-size: 1.35rem;
            margin-left: 0.45rem;
            line-height: 1;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.45rem;
            font-size: 0.92rem;
            font-weight: 800;
        }

        .header-actions a {
            color: #111111;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            white-space: nowrap;
        }

        .header-actions a:hover {
            color: var(--scout-purple);
            text-decoration: none;
        }

        .header-icon {
            width: 18px;
            height: 18px;
            display: inline-block;
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

        .breadcrumb-inner a:hover {
            text-decoration: underline;
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

        @media (max-width: 991.98px) {
            .header-actions {
                gap: 0.85rem;
                font-size: 0.85rem;
            }

            .header-actions .optional-action {
                display: none;
            }

            .member-hero h1 {
                font-size: 2.15rem;
            }

            .member-service {
                font-size: 1.65rem;
            }
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

            .brand-link {
                font-size: 1.35rem;
            }

            .header-actions {
                gap: 0.75rem;
            }

            .header-actions span.action-label {
                display: none;
            }

            .member-hero-inner {
                padding-top: 1.65rem;
                padding-bottom: 2rem;
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
        <a href="/index.php" class="brand-link">
            Scouts <span class="brand-mark">⚜</span>
        </a>

        <nav class="header-actions" aria-label="Account navigation">
            <a href="/profile.php">
                <span class="action-label"><?= e($displayName) ?></span>
                <span aria-hidden="true">♙</span>
            </a>

            <a href="#" class="optional-action">
                <span>English</span>
                <span aria-hidden="true">◎</span>
            </a>

            <a href="#" class="optional-action">
                <span>Notifications</span>
                <span aria-hidden="true">♢</span>
            </a>

            <a href="#" class="optional-action">
                <span>System updates</span>
                <span aria-hidden="true">↗</span>
            </a>

            <a href="/logout.php">
                <span class="action-label">Sign out</span>
                <span aria-hidden="true">▢</span>
            </a>

            <a href="#" aria-label="Search">
                <span aria-hidden="true">⌕</span>
            </a>
        </nav>
    </div>
</header>

<section class="member-hero">
    <div class="member-hero-inner">
        <h1>Welcome, <?= e($displayName) ?></h1>

        <?php if (!empty($user['member_number'])): ?>
            <div class="member-number">
                Member <?= e($user['member_number']) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($user['service_length'])): ?>
            <div class="member-service">
                <?= e($user['service_length']) ?> service
            </div>
        <?php else: ?>
            <div class="member-service">
                District tools
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="breadcrumb-strip">
    <div class="breadcrumb-inner">
        <a href="/index.php">Home</a>
        <span class="mx-2">›</span>
        <strong>Dashboard</strong>
    </div>
</div>