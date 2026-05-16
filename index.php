<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

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
        'url' => '#',
        'status' => 'coming_soon',
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

    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap"
          rel="stylesheet">

    <style>
        body {
            background: #f5f5f5;
        }

        .navbar-profile {
            display: flex;
            align-items: center;
            color: #ffffff;
            text-decoration: none;
        }

        .navbar-profile:hover {
            color: #ffffff;
            text-decoration: none;
        }

        .avatar-sm {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            background: #ffffff;
            color: #7413dc;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            margin-left: 0.75rem;
        }

        .dashboard-hero {
            background: #7413dc;
            color: #ffffff;
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .dashboard-card {
            border: 0;
            border-radius: 1rem;
            box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.06);
            height: 100%;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .dashboard-card.available:hover {
            transform: translateY(-3px);
            box-shadow: 0 1.25rem 2.5rem rgba(0, 0, 0, 0.1);
        }

        .dashboard-icon {
            width: 56px;
            height: 56px;
            border-radius: 1rem;
            background: #7413dc;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .dashboard-icon svg {
            width: 30px;
            height: 30px;
            fill: currentColor;
        }

        .dashboard-card.coming-soon {
            opacity: 0.7;
        }

        .dashboard-card.coming-soon .dashboard-icon {
            background: #707070;
        }

        .card-link-overlay {
            color: inherit;
            text-decoration: none;
        }

        .card-link-overlay:hover {
            color: inherit;
            text-decoration: none;
        }

        .status-badge {
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
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

<main class="container py-5">
    <section class="dashboard-hero">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <h1 class="h2 mb-2">Welcome, <?= e($displayName) ?></h1>
                <p class="mb-0">
                    Choose a District tool below.
                </p>
            </div>

            <div class="col-lg-4 text-lg-right mt-3 mt-lg-0">
                <span class="badge badge-light">
                    <?= e($user['role']) ?>
                </span>
            </div>
        </div>
    </section>

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

                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h2 class="h5 mb-0"><?= e($module['title']) ?></h2>

                            <?php if ($module['status'] === 'coming_soon'): ?>
                                <span class="badge badge-secondary status-badge">Coming soon</span>
                            <?php else: ?>
                                <span class="badge badge-success status-badge">Open</span>
                            <?php endif; ?>
                        </div>

                        <p class="text-muted mb-0">
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