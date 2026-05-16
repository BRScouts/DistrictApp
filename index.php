<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

if (user_needs_group_onboarding()) {
    redirect('/onboarding.php');
}

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley District Scouts');

$displayName = $user['full_name'] ?: $user['email'];
$initials = strtoupper(substr($displayName, 0, 1));

$modules = [
    [
        'title' => 'District Calendar',
        'description' => 'View District events, group activity, risk assessments and GLV information.',
        'url' => '/dc/',
        'status' => 'available',
        'icon' => 'calendar',
    ],
    [
        'title' => 'My Profile',
        'description' => 'View your account details, role and Microsoft sign-in information.',
        'url' => '/profile.php',
        'status' => 'available',
        'icon' => 'user',
    ],
    [
    'title' => 'District Directory',
    'description' => 'Browse District contacts, roles, teams and group leadership information.',
    'url' => '/directory.php',
    'status' => 'available',
    'icon' => 'directory',
    ],
    [
        'title' => 'Group Resources',
        'description' => 'Book equipment, shared kit and other District resources.',
        'url' => '#',
        'status' => 'coming_soon',
        'icon' => 'resources',
    ],
    [
        'title' => 'Edit District Website',
        'description' => 'Manage selected District website content and page updates.',
        'url' => '#',
        'status' => 'coming_soon',
        'icon' => 'website',
    ],
    [
        'title' => 'IT Tickets',
        'description' => 'Submit IT requests, report issues and track support tickets.',
        'url' => '#',
        'status' => 'coming_soon',
        'icon' => 'tickets',
    ],
];

function dashboard_icon(string $icon): string
{
    $icons = [
        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h1V3a1 1 0 0 1 1-1Zm13 8H4v9a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-9ZM5 6a1 1 0 0 0-1 1v1h16V7a1 1 0 0 0-1-1H5Z"/></svg>',
        'user' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-4.42 0-8 2.24-8 5v1a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-1c0-2.76-3.58-5-8-5Z"/></svg>',
        'directory' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm3 4a3 3 0 1 0 6 0 3 3 0 0 0-6 0Zm-1 9h10c-.31-2.28-2.43-4-5-4s-4.69 1.72-5 4Z"/></svg>',
        'resources' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7a3 3 0 0 1 3-3h3.17a3 3 0 0 1 2.12.88L13.41 7H18a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V7Zm3-1a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-7a1 1 0 0 0-1-1h-5a1 1 0 0 1-.71-.29L9.88 6.29A1 1 0 0 0 9.17 6H6Z"/></svg>',
        'website' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-6v2h3a1 1 0 1 1 0 2H7a1 1 0 1 1 0-2h3v-2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm0 2v9h16V6H4Z"/></svg>',
        'tickets' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v4a3 3 0 0 0 0 6v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4a3 3 0 0 0 0-6V5Zm8 3a1 1 0 0 0-1 1v1a1 1 0 1 0 2 0V9a1 1 0 0 0-1-1Zm0 5a1 1 0 0 0-1 1v1a1 1 0 1 0 2 0v-1a1 1 0 0 0-1-1Z"/></svg>',
    ];

    return $icons[$icon] ?? $icons['calendar'];
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css"
          crossorigin="anonymous">

    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap"
          rel="stylesheet">

    <style>
        :root {
            --scout-purple: #7413dc;
            --scout-purple-dark: #4e0b9f;
            --scout-purple-soft: #f0e6ff;
            --scout-gold: #ffd23f;
            --page-bg: #f6f4f8;
            --text-dark: #24212a;
            --text-muted: #6f6878;
            --card-shadow: 0 1rem 2.5rem rgba(35, 21, 56, 0.08);
            --card-shadow-hover: 0 1.5rem 3rem rgba(35, 21, 56, 0.14);
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            font-family: 'Nunito Sans', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(116, 19, 220, 0.08), transparent 34rem),
                linear-gradient(180deg, #ffffff 0%, var(--page-bg) 42%, #ffffff 100%);
            color: var(--text-dark);
        }

        .navbar {
            box-shadow: 0 0.35rem 1.25rem rgba(35, 21, 56, 0.14);
        }

        .navbar-brand {
            font-weight: 900;
            letter-spacing: -0.03em;
        }

        .navbar-profile {
            display: flex;
            align-items: center;
            color: #ffffff;
            text-decoration: none;
            font-weight: 700;
        }

        .navbar-profile:hover,
        .navbar-profile:focus {
            color: #ffffff;
            text-decoration: none;
        }

        .avatar-sm {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            background: #ffffff;
            color: var(--scout-purple);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            margin-left: 0.75rem;
            border: 2px solid rgba(255, 255, 255, 0.75);
        }

        .page-shell {
            position: relative;
        }

        .dashboard-hero {
            position: relative;
            overflow: hidden;
            color: #ffffff;
            border-radius: 1.5rem;
            padding: 0;
            margin-bottom: 2rem;
            box-shadow: 0 1.5rem 3rem rgba(35, 21, 56, 0.16);
            background: var(--scout-purple);
        }

        .dashboard-hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(90deg, rgba(35, 21, 56, 0.92) 0%, rgba(116, 19, 220, 0.82) 48%, rgba(116, 19, 220, 0.35) 100%),
                url("https://www.irvalscouts.org.uk/wp-content/uploads/2026/05/two-scouts-hiking-jpg-scaled-1.jpg");
            background-size: cover;
            background-position: center;
            transform: scale(1.02);
        }

        .dashboard-hero::after {
            content: "";
            position: absolute;
            right: -6rem;
            bottom: -6rem;
            width: 18rem;
            height: 18rem;
            border-radius: 50%;
            background: rgba(255, 210, 63, 0.22);
        }

        .dashboard-hero-inner {
            position: relative;
            z-index: 1;
            padding: 3rem;
        }

        .hero-kicker {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.16);
            color: #ffffff;
            font-size: 0.78rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 1rem;
        }

        .hero-title {
            font-weight: 900;
            letter-spacing: -0.04em;
            line-height: 1.05;
        }

        .hero-copy {
            max-width: 42rem;
            color: rgba(255, 255, 255, 0.88);
            font-size: 1.05rem;
        }

        .role-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: #ffffff;
            color: var(--scout-purple-dark);
            font-weight: 900;
            padding: 0.55rem 0.9rem;
            box-shadow: 0 0.75rem 1.5rem rgba(35, 21, 56, 0.18);
        }

        .section-heading {
            margin-bottom: 1.25rem;
        }

        .section-heading h2 {
            font-weight: 900;
            letter-spacing: -0.03em;
        }

        .dashboard-card {
            position: relative;
            overflow: hidden;
            border: 0;
            border-radius: 1.25rem;
            box-shadow: var(--card-shadow);
            height: 100%;
            background: #ffffff;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        .dashboard-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 0.35rem;
            background: linear-gradient(90deg, var(--scout-purple), var(--scout-gold));
            opacity: 0;
            transition: opacity 0.18s ease;
        }

        .dashboard-card.available:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .dashboard-card.available:hover::before {
            opacity: 1;
        }

        .dashboard-card.coming-soon {
            opacity: 0.72;
            background: #fbfafc;
        }

        .dashboard-icon {
            width: 58px;
            height: 58px;
            border-radius: 1.1rem;
            background: var(--scout-purple-soft);
            color: var(--scout-purple);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.15rem;
        }

        .dashboard-icon svg {
            width: 30px;
            height: 30px;
            fill: currentColor;
        }

        .dashboard-card.coming-soon .dashboard-icon {
            background: #eeeeee;
            color: #707070;
        }

        .card-link-overlay {
            color: inherit;
            text-decoration: none;
            display: block;
            height: 100%;
        }

        .card-link-overlay:hover,
        .card-link-overlay:focus {
            color: inherit;
            text-decoration: none;
        }

        .module-title {
            font-weight: 900;
            letter-spacing: -0.02em;
        }

        .module-description {
            color: var(--text-muted);
            line-height: 1.55;
        }

        .status-badge {
            font-size: 0.68rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-radius: 999px;
            padding: 0.4rem 0.55rem;
        }

        .dashboard-footer-note {
            color: var(--text-muted);
            font-size: 0.92rem;
        }

        @media (max-width: 767.98px) {
            .dashboard-hero {
                border-radius: 1rem;
            }

            .dashboard-hero::before {
                background:
                    linear-gradient(180deg, rgba(35, 21, 56, 0.94) 0%, rgba(116, 19, 220, 0.84) 100%),
                    url("https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1200&q=80");
                background-size: cover;
                background-position: center;
            }

            .dashboard-hero-inner {
                padding: 2rem;
            }

            .hero-title {
                font-size: 2rem;
            }

            .navbar .btn {
                padding-left: 0.65rem;
                padding-right: 0.65rem;
            }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <a class="navbar-brand" href="/index.php"><?= e($appName) ?></a>

    <div class="ml-auto d-flex align-items-center">
        <a href="/profile.php" class="navbar-profile">
            <span class="d-none d-sm-inline"><?= e($displayName) ?></span>

            <img src="/auth/profile-photo.php"
                 alt=""
                 class="avatar-sm"
                 onerror="this.style.display='none'; document.getElementById('fallbackAvatarNav').style.display='inline-flex';">

            <span id="fallbackAvatarNav" class="avatar-sm" style="display:none;">
                <?= e($initials) ?>
            </span>
        </a>

        <a class="btn btn-outline-light btn-sm ml-3" href="/logout.php">Sign out</a>
    </div>
</nav>

<main class="container py-5 page-shell">
    <section class="dashboard-hero">
        <div class="dashboard-hero-inner">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <span class="hero-kicker">District dashboard</span>

                    <h1 class="hero-title display-4 mb-3">
                        Welcome, <?= e($displayName) ?>
                    </h1>

                    <p class="hero-copy mb-0">
                        Access your District tools, manage key workflows, and open available modules from one place.
                    </p>
                </div>

                <div class="col-lg-4 text-lg-right mt-4 mt-lg-0">
                    <span class="role-pill">
                        <?= e($user['role']) ?>
                    </span>
                </div>
            </div>
        </div>
    </section>

    <div class="section-heading d-flex flex-column flex-md-row align-items-md-end justify-content-md-between">
        <div>
            <h2 class="h4 mb-1">Available tools</h2>
            <p class="dashboard-footer-note mb-md-0">
                Select a module below to continue.
            </p>
        </div>
    </div>

    <section class="row">
        <?php foreach ($modules as $module): ?>
            <div class="col-md-6 col-xl-4 mb-4">
                <?php if ($module['status'] === 'available'): ?>
                    <a class="card-link-overlay" href="<?= e($module['url']) ?>">
                <?php endif; ?>

                <article class="card dashboard-card <?= e($module['status'] === 'available' ? 'available' : 'coming-soon') ?>">
                    <div class="card-body p-4">
                        <div class="dashboard-icon">
                            <?= dashboard_icon($module['icon']) ?>
                        </div>

                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h3 class="h5 module-title mb-0 pr-3">
                                <?= e($module['title']) ?>
                            </h3>

                            <?php if ($module['status'] === 'coming_soon'): ?>
                                <span class="badge badge-secondary status-badge">Coming soon</span>
                            <?php else: ?>
                                <span class="badge badge-success status-badge">Open</span>
                            <?php endif; ?>
                        </div>

                        <p class="module-description mb-0">
                            <?= e($module['description']) ?>
                        </p>
                    </div>
                </article>

                <?php if ($module['status'] === 'available'): ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"
        crossorigin="anonymous"></script>

<script src="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/js/bootstrap.min.js"
        crossorigin="anonymous"></script>

</body>
</html>