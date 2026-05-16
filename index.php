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

$externalLinks = [
    [
        'title' => 'My Scout Membership',
        'description' => 'Access your Scouts membership record, personal details, learning and member information.',
        'url' => 'https://membership.scouts.org.uk',
        'label' => 'Open My Scout Membership',
        'icon' => 'membership',
    ],
    [
        'title' => 'Online Scout Manager',
        'description' => 'Open OSM to manage sections, programme planning, registers, badges and parent communications.',
        'url' => 'https://www.onlinescoutmanager.co.uk/login.php',
        'label' => 'Open Online Scout Manager',
        'icon' => 'osm',
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
        'membership' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7v2.5A5.5 5.5 0 0 0 10.5 17h.5v3H8a1 1 0 1 0 0 2h8a1 1 0 1 0 0-2h-3v-3h.5A5.5 5.5 0 0 0 19 11.5V9a7 7 0 0 0-7-7Zm0 2a5 5 0 0 1 5 5v2.5A3.5 3.5 0 0 1 13.5 15h-3A3.5 3.5 0 0 1 7 11.5V9a5 5 0 0 1 5-5Zm-2 5a1 1 0 1 0 0 2h4a1 1 0 1 0 0-2h-4Z"/></svg>',
        'osm' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 2v14h14V5H5Zm3 3h8a1 1 0 1 1 0 2H8a1 1 0 0 1 0-2Zm0 4h8a1 1 0 1 1 0 2H8a1 1 0 1 1 0-2Zm0 4h5a1 1 0 1 1 0 2H8a1 1 0 1 1 0-2Z"/></svg>',
        'external' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3a1 1 0 1 0 0 2h3.59l-8.3 8.29a1 1 0 1 0 1.42 1.42L19 6.41V10a1 1 0 1 0 2 0V4a1 1 0 0 0-1-1h-6ZM5 5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5a1 1 0 1 0-2 0v5H5V7h5a1 1 0 1 0 0-2H5Z"/></svg>',
    ];

    return $icons[$icon] ?? $icons['calendar'];
}

?>
<?php include __DIR__ . '/header.php'; ?>

<main class="page-container">
    <h1 class="page-title">Dashboard</h1>

    <section class="row">
        <?php foreach ($modules as $module): ?>
            <?php
                $isAvailable = $module['status'] === 'available';

                $moduleImages = [
                    'profile' => 'https://www.irvalscouts.org.uk/wp-content/uploads/2026/05/two-scouts-hiking-jpg-scaled-1.jpg',
                    'teams' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=900&q=80',
                    'calendar' => '/assets/img/female-leader-with-large-group-jpg.jpg',
                    'user' => '/assets/img/cubs-crate-stacking-jpg.jpg',
                    'resources' => '/assets/img/cub-on-raft-jpg.jpg',
                    'website' => '/assets/img/group-sitting-on-hill-jpg.jpg',
                    'tickets' => '/assets/img/scouts-around-campfire-jpg.jpg',
                    'directory' => '/assets/img/scouts-around-campfire-jpg.jpg',
                ];

                $imageUrl = $module['image'] ?? $moduleImages[$module['icon']] ?? 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=900&q=80';
                $linkLabel = $module['link_label'] ?? 'View ' . strtolower($module['title']);
            ?>

            <div class="col-md-6 col-xl-4 mb-4">
                <?php if ($isAvailable): ?>
                    <a class="card-link-overlay" href="<?= e($module['url']) ?>">
                <?php endif; ?>

                <article class="dashboard-card <?= e($isAvailable ? 'available' : 'coming-soon') ?>">
                    <img class="module-image"
                         src="<?= e($imageUrl) ?>"
                         alt="">

                    <div class="module-body">
                        <div class="module-heading">
                            <div class="module-title-wrap">
                                <span class="module-icon">
                                    <?= dashboard_icon($module['icon']) ?>
                                </span>

                                <h2 class="module-title">
                                    <?= e($module['title']) ?>
                                </h2>
                            </div>

                            <?php if (!empty($module['count'])): ?>
                                <span class="status-badge">
                                    <?= e($module['count']) ?>
                                </span>
                            <?php elseif ($module['status'] === 'coming_soon'): ?>
                                <span class="status-badge">
                                    Soon
                                </span>
                            <?php endif; ?>
                        </div>

                        <p class="module-description">
                            <?= e($module['description']) ?>
                        </p>

                        <?php if ($isAvailable): ?>
                            <span class="module-link">
                                <?= e($linkLabel) ?>
                                <span class="module-link-arrow" aria-hidden="true">›</span>
                            </span>
                        <?php else: ?>
                            <span class="module-link text-muted">
                                Coming soon
                            </span>
                        <?php endif; ?>
                    </div>
                </article>

                <?php if ($isAvailable): ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="external-links-section mt-5">
        <div class="section-heading-row mb-3">
            <div>
                <h2 class="external-links-title">Useful external links</h2>
                <p class="external-links-subtitle">
                    Quick access to common Scouts systems outside the District Dashboard.
                </p>
            </div>
        </div>

        <div class="row">
            <?php foreach ($externalLinks as $externalLink): ?>
                <div class="col-md-6 mb-4">
                    <a href="<?= e($externalLink['url']) ?>"
                       class="external-link-card"
                       target="_blank"
                       rel="noopener noreferrer">
                        <div class="external-link-icon">
                            <?= dashboard_icon($externalLink['icon']) ?>
                        </div>

                        <div class="external-link-content">
                            <h3 class="external-link-title">
                                <?= e($externalLink['title']) ?>
                            </h3>

                            <p class="external-link-description">
                                <?= e($externalLink['description']) ?>
                            </p>

                            <span class="external-link-action">
                                <?= e($externalLink['label']) ?>
                                <span aria-hidden="true">↗</span>
                            </span>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>