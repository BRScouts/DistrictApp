<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

if (user_needs_group_onboarding()) {
    redirect('/onboarding.php');
}

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

$pageTitle = 'Home | ' . $appName;
$heroTitle = 'District Dashboard';
$heroText = 'Complete common District tasks, update your profile and open the tools connected to your Group.';
$breadcrumb = '<a href="/index.php">Home</a>';

$memberships = user_group_memberships((int) $user['id']);

$groupNames = array_values(array_unique(array_filter(array_map(
    static fn(array $membership): string => (string) ($membership['group_name'] ?? ''),
    $memberships
))));

$accessLevels = [(string) ($user['highest_access_level'] ?? $user['role'] ?? 'member')];
$membershipRoles = [];

foreach ($memberships as $membership) {
    if (($membership['status'] ?? 'active') !== 'active') {
        continue;
    }

    $accessLevels[] = (string) ($membership['access_level'] ?? 'member');
    $membershipRoles[] = (string) ($membership['membership_role'] ?? '');
}

$accessLevels = array_values(array_unique($accessLevels));
$membershipRoles = array_values(array_unique(array_filter($membershipRoles)));

$isSystemAdmin = in_array('system_admin', $accessLevels, true);
$isDistrictAdmin = $isSystemAdmin || in_array('district_admin', $accessLevels, true);
$isGroupAdmin = $isDistrictAdmin
    || in_array('group_admin', $accessLevels, true)
    || in_array('group_lead_volunteer', $membershipRoles, true);

$displayName = trim((string) ($user['preferred_name'] ?? $user['full_name'] ?? $user['email'] ?? 'there'));

$modules = [
    [
        'title' => 'District Calendar',
        'description' => 'Submit away-from-hut notifications, view Group activity and share risk assessments.',
        'url' => '/dc/',
        'status' => 'available',
        'visibility' => true,
        'image' => '/assets/img/dashboard/calendar.jpg',
        'button' => 'Open calendar',
    ],
    [
        'title' => 'My profile',
        'description' => 'Update your details, directory visibility, role information and accreditations.',
        'url' => '/profile.php',
        'status' => 'available',
        'visibility' => true,
        'image' => '/assets/img/dashboard/profile.jpg',
        'button' => 'Update profile',
    ],
    [
        'title' => 'District Directory',
        'description' => 'Find leaders and volunteers by name, Group, role, section or accreditation.',
        'url' => '/directory.php',
        'status' => 'available',
        'visibility' => true,
        'image' => '/assets/img/dashboard/directory.jpg',
        'button' => 'Open directory',
    ],
    [
        'title' => 'Group Admin',
        'description' => 'Manage leaders in your Group and request District Microsoft 365 accounts.',
        'url' => '/group-manager.php',
        'status' => 'available',
        'visibility' => $isGroupAdmin,
        'image' => '/assets/img/dashboard/group-admin.jpg',
        'button' => 'Manage Group',
    ],
    [
        'title' => 'District Admin',
        'description' => 'Create Groups, assign GLVs, rotate Group links and manage permissions.',
        'url' => '/district-admin.php',
        'status' => 'available',
        'visibility' => $isDistrictAdmin,
        'image' => '/assets/img/dashboard/district-admin.jpg',
        'button' => 'Open admin',
    ],
    [
        'title' => 'Comms Tool',
        'description' => 'Prepare District communications and targeted volunteer messages.',
        'url' => '/comms-tool.php',
        'status' => 'soon',
        'visibility' => $isDistrictAdmin,
        'image' => '/assets/img/dashboard/comms.jpg',
        'button' => 'Coming soon',
    ],
    [
        'title' => 'Technical Support',
        'description' => 'Report a problem, request help with access or ask for a dashboard change.',
        'url' => '/technical-support.php',
        'status' => 'soon',
        'visibility' => true,
        'image' => '/assets/img/dashboard/support.jpg',
        'button' => 'Coming soon',
    ],
];

$visibleModules = array_values(array_filter(
    $modules,
    static fn(array $module): bool => (bool) ($module['visibility'] ?? false)
));

$externalLinks = [
    [
        'title' => 'My Scout Membership',
        'description' => 'Open your Scouts membership record, learning and personal details.',
        'url' => 'https://membership.scouts.org.uk',
        'label' => 'Open My Scout Membership',
    ],
    [
        'title' => 'Online Scout Manager',
        'description' => 'Open OSM for programme planning, section administration and parent communications.',
        'url' => 'https://www.onlinescoutmanager.co.uk/login.php',
        'label' => 'Open Online Scout Manager',
    ],
];

?>
<?php include __DIR__ . '/header.php'; ?>

<style>
    .dashboard-welcome {
        display: grid;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    @media (min-width: 992px) {
        .dashboard-welcome {
            grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
            align-items: stretch;
        }
    }

    .dashboard-welcome-main,
    .dashboard-groups {
        background: #ffffff;
        border: 1px solid #e6e6e6;
        border-radius: .75rem;
        padding: 1.25rem;
        box-shadow: 0 2px 12px rgba(0, 0, 0, .05);
    }

    .dashboard-welcome-main h2 {
        margin: 0;
        color: #4d0b93;
        font-size: clamp(1.7rem, 5vw, 2.35rem);
        font-weight: 900;
        line-height: 1.08;
    }

    .dashboard-welcome-main p {
        margin: .75rem 0 0;
        max-width: 760px;
        color: #333333;
        font-size: 1.05rem;
        font-weight: 700;
        line-height: 1.45;
    }

    .dashboard-groups {
        background: #f7f5fb;
    }

    .dashboard-groups h3 {
        margin: 0 0 .65rem;
        color: #4d0b93;
        font-size: 1.15rem;
        font-weight: 900;
    }

    .dashboard-groups ul {
        margin: 0;
        padding-left: 1.25rem;
        font-weight: 800;
    }

    .dashboard-section-title {
        margin: 2rem 0 1rem;
        color: #4d0b93;
        font-size: 1.45rem;
        font-weight: 900;
    }

    .dashboard-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 680px) {
        .dashboard-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (min-width: 1120px) {
        .dashboard-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    .dashboard-card {
        display: flex;
        flex-direction: column;
        min-height: 100%;
        background: #ffffff;
        border: 1px solid #e6e6e6;
        border-radius: .75rem;
        overflow: hidden;
        box-shadow: 0 2px 12px rgba(0, 0, 0, .05);
    }

    .dashboard-card-image {
        height: 132px;
        background: #f7f5fb;
        border-bottom: 1px solid #e6e6e6;
        overflow: hidden;
    }

    .dashboard-card-image img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .dashboard-card-body {
        display: flex;
        flex-direction: column;
        flex: 1;
        padding: 1.1rem;
    }

    .dashboard-card h3 {
        margin: 0;
        color: #4d0b93;
        font-size: 1.25rem;
        line-height: 1.15;
        font-weight: 900;
    }

    .dashboard-card p {
        margin: .65rem 0 1rem;
        color: #333333;
        font-weight: 700;
        line-height: 1.42;
    }

    .dashboard-card-actions {
        margin-top: auto;
    }

    .dashboard-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 42px;
        padding: .55rem .9rem;
        border-radius: .35rem;
        background: #7413dc;
        color: #ffffff;
        font-weight: 900;
        text-decoration: none;
        border: 2px solid #7413dc;
    }

    .dashboard-button:hover,
    .dashboard-button:focus {
        background: #4d0b93;
        border-color: #4d0b93;
        color: #ffffff;
        text-decoration: none;
    }

    .dashboard-button-muted {
        background: #ffffff;
        color: #555555;
        border-color: #cccccc;
        cursor: default;
    }

    .dashboard-button-muted:hover,
    .dashboard-button-muted:focus {
        background: #ffffff;
        color: #555555;
        border-color: #cccccc;
    }

    .dashboard-badge {
        display: inline-block;
        margin-bottom: .7rem;
        padding: .25rem .5rem;
        border-radius: .35rem;
        background: #fff3cd;
        color: #664d03;
        font-size: .8rem;
        font-weight: 900;
    }

    .dashboard-card-soon {
        opacity: .85;
    }

    .dashboard-external-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 768px) {
        .dashboard-external-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .dashboard-external-card {
        background: #ffffff;
        border: 1px solid #e6e6e6;
        border-radius: .75rem;
        padding: 1.1rem;
        box-shadow: 0 2px 12px rgba(0, 0, 0, .05);
    }

    .dashboard-external-card h3 {
        margin: 0;
        color: #4d0b93;
        font-size: 1.15rem;
        font-weight: 900;
    }

    .dashboard-external-card p {
        margin: .55rem 0 .85rem;
        color: #333333;
        font-weight: 700;
    }

    .dashboard-external-card a {
        color: #4d0b93;
        font-weight: 900;
    }

    @media (max-width: 520px) {
        .dashboard-card-image {
            height: 115px;
        }

        .dashboard-welcome-main,
        .dashboard-groups,
        .dashboard-card-body,
        .dashboard-external-card {
            padding: 1rem;
        }

        .dashboard-button {
            width: 100%;
        }
    }
</style>

<main class="lt-main">
    <section class="dashboard-welcome">
        <div class="dashboard-welcome-main">
            <h2>Welcome, <?= e($displayName) ?></h2>
            <p>
                Your access is based on your active Group memberships.
                Use the cards below to open the tools available to you.
            </p>
        </div>

        <aside class="dashboard-groups">
            <h3>Your Groups</h3>

            <?php if ($groupNames): ?>
                <ul>
                    <?php foreach ($groupNames as $groupName): ?>
                        <li><?= e($groupName) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="mb-0 font-weight-bold">No Groups linked yet.</p>
            <?php endif; ?>
        </aside>
    </section>

    <section aria-labelledby="tasks-heading">
        <h2 id="tasks-heading" class="dashboard-section-title">Things you can do</h2>

        <div class="dashboard-grid">
            <?php foreach ($visibleModules as $module): ?>
                <?php $isAvailable = $module['status'] === 'available'; ?>

                <article class="dashboard-card <?= $isAvailable ? '' : 'dashboard-card-soon' ?>">
                    <div class="dashboard-card-image">
                        <img
                            src="<?= e($module['image']) ?>"
                            alt=""
                            loading="lazy"
                            onerror="this.style.display='none';"
                        >
                    </div>

                    <div class="dashboard-card-body">
                        <?php if (!$isAvailable): ?>
                            <span class="dashboard-badge">Coming soon</span>
                        <?php endif; ?>

                        <h3><?= e($module['title']) ?></h3>
                        <p><?= e($module['description']) ?></p>

                        <div class="dashboard-card-actions">
                            <?php if ($isAvailable): ?>
                                <a class="dashboard-button" href="<?= e($module['url']) ?>">
                                    <?= e($module['button']) ?>
                                </a>
                            <?php else: ?>
                                <span class="dashboard-button dashboard-button-muted">
                                    <?= e($module['button']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="mt-4" aria-labelledby="external-heading">
        <h2 id="external-heading" class="dashboard-section-title">Useful external links</h2>

        <div class="dashboard-external-grid">
            <?php foreach ($externalLinks as $link): ?>
                <article class="dashboard-external-card">
                    <h3><?= e($link['title']) ?></h3>
                    <p><?= e($link['description']) ?></p>
                    <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener noreferrer">
                        <?= e($link['label']) ?>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>