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
$heroText = 'Tools for Irwell Valley Scout District volunteers.';
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
        'description' => 'Add activities away from the hut, review events and share risk assessments.',
        'url' => '/dc/',
        'status' => 'available',
        'visibility' => true,
        'image' => '/assets/img/dashboard/calendar.jpg',
        'label' => 'Open calendar',
        'colour' => 'purple',
    ],
    [
        'title' => 'My profile',
        'description' => 'Update your contact details, directory preferences and accreditations.',
        'url' => '/profile.php',
        'status' => 'available',
        'visibility' => true,
        'image' => '/assets/img/dashboard/profile.jpg',
        'label' => 'Update profile',
        'colour' => 'yellow',
    ],
    [
        'title' => 'District Directory',
        'description' => 'Find volunteers by Group, role, section or accreditation.',
        'url' => '/directory.php',
        'status' => 'available',
        'visibility' => true,
        'image' => '/assets/img/dashboard/directory.jpg',
        'label' => 'Open directory',
        'colour' => 'purple',
    ],
    [
        'title' => 'Group Admin',
        'description' => 'Add leaders to your Group and request District Microsoft 365 accounts.',
        'url' => '/group-manager.php',
        'status' => 'available',
        'visibility' => $isGroupAdmin,
        'image' => '/assets/img/dashboard/group-admin.jpg',
        'label' => 'Manage Group',
        'colour' => 'yellow',
    ],
    [
        'title' => 'District Admin',
        'description' => 'Create Groups, assign GLVs, rotate calendar links and manage permissions.',
        'url' => '/district-admin.php',
        'status' => 'available',
        'visibility' => $isDistrictAdmin,
        'image' => '/assets/img/dashboard/district-admin.jpg',
        'label' => 'Open admin',
        'colour' => 'purple',
    ],
    [
        'title' => 'Comms Tool',
        'description' => 'Prepare District messages and targeted volunteer communications.',
        'url' => '/comms-tool.php',
        'status' => 'soon',
        'visibility' => $isDistrictAdmin,
        'image' => '/assets/img/dashboard/comms.jpg',
        'label' => 'Coming soon',
        'colour' => 'yellow',
    ],
    [
        'title' => 'Technical Support',
        'description' => 'Report a problem, request access help or ask for a dashboard change.',
        'url' => '/technical-support.php',
        'status' => 'soon',
        'visibility' => true,
        'image' => '/assets/img/dashboard/support.jpg',
        'label' => 'Coming soon',
        'colour' => 'purple',
    ],
];

$visibleModules = array_values(array_filter(
    $modules,
    static fn(array $module): bool => (bool) ($module['visibility'] ?? false)
));

$externalLinks = [
    [
        'title' => 'My Scout Membership',
        'description' => 'Membership record, learning and personal details.',
        'url' => 'https://membership.scouts.org.uk',
        'label' => 'Open My Scout Membership',
    ],
    [
        'title' => 'Online Scout Manager',
        'description' => 'Programme planning, section administration and parent communications.',
        'url' => 'https://www.onlinescoutmanager.co.uk/login.php',
        'label' => 'Open OSM',
    ],
];

?>
<?php include __DIR__ . '/header.php'; ?>

<style>
    :root {
        --iv-purple: #7413dc;
        --iv-purple-dark: #4d0b93;
        --iv-yellow: #ffb81c;
        --iv-black: #1d1d1b;
        --iv-grey: #f3f2f1;
        --iv-border: #b1b4b6;
    }

    .dashboard-intro {
        display: grid;
        gap: 1rem;
        margin-bottom: 2rem;
    }

    @media (min-width: 900px) {
        .dashboard-intro {
            grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
            align-items: stretch;
        }
    }

    .dashboard-welcome {
        background: var(--iv-purple);
        color: #ffffff;
        padding: 1.25rem;
        border-bottom: 8px solid var(--iv-yellow);
    }

    .dashboard-welcome h2 {
        margin: 0;
        color: #ffffff;
        font-size: clamp(1.8rem, 6vw, 3rem);
        line-height: 1;
        font-weight: 900;
    }

    .dashboard-welcome p {
        margin: .85rem 0 0;
        max-width: 720px;
        color: #ffffff;
        font-size: 1.1rem;
        line-height: 1.4;
        font-weight: 700;
    }

    .dashboard-groups {
        background: var(--iv-grey);
        border: 3px solid var(--iv-black);
        padding: 1rem;
    }

    .dashboard-groups h2 {
        margin: 0 0 .75rem;
        color: var(--iv-black);
        font-size: 1.2rem;
        font-weight: 900;
    }

    .dashboard-groups ul {
        margin: 0;
        padding-left: 1.25rem;
        font-weight: 900;
    }

    .dashboard-groups li + li {
        margin-top: .25rem;
    }

    .dashboard-section-heading {
        margin: 0 0 1rem;
        padding-bottom: .45rem;
        border-bottom: 5px solid var(--iv-yellow);
        color: var(--iv-black);
        font-size: 1.6rem;
        line-height: 1.1;
        font-weight: 900;
    }

    .dashboard-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 700px) {
        .dashboard-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (min-width: 1100px) {
        .dashboard-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    .dashboard-card-link {
        display: block;
        color: inherit;
        text-decoration: none;
        height: 100%;
    }

    .dashboard-card-link:hover {
        color: inherit;
        text-decoration: none;
    }

    .dashboard-card-link:focus {
        outline: 4px solid var(--iv-yellow);
        outline-offset: 4px;
    }

    .dashboard-card {
        display: flex;
        flex-direction: column;
        min-height: 100%;
        background: #ffffff;
        border: 3px solid var(--iv-black);
    }

    .dashboard-card-media {
        position: relative;
        min-height: 132px;
        background: var(--iv-purple);
        border-bottom: 3px solid var(--iv-black);
    }

    .dashboard-card-media img {
        display: block;
        width: 100%;
        height: 150px;
        object-fit: cover;
    }

    .dashboard-card-media::after {
        content: "";
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, .18);
    }

    .dashboard-card-colour-yellow .dashboard-card-media {
        background: var(--iv-yellow);
    }

    .dashboard-card-colour-yellow .dashboard-card-media::after {
        background: rgba(0, 0, 0, .05);
    }

    .dashboard-card-title-strip {
        display: inline-block;
        position: absolute;
        left: 1rem;
        bottom: 1rem;
        z-index: 2;
        background: #ffffff;
        color: var(--iv-black);
        padding: .35rem .55rem;
        font-size: .9rem;
        font-weight: 900;
        border: 3px solid var(--iv-black);
    }

    .dashboard-card-body {
        display: flex;
        flex-direction: column;
        flex: 1;
        padding: 1rem;
    }

    .dashboard-card h3 {
        margin: 0;
        color: var(--iv-black);
        font-size: 1.35rem;
        line-height: 1.1;
        font-weight: 900;
    }

    .dashboard-card p {
        margin: .75rem 0 1rem;
        color: var(--iv-black);
        font-size: 1rem;
        line-height: 1.4;
        font-weight: 700;
    }

    .dashboard-action {
        display: inline-block;
        align-self: flex-start;
        margin-top: auto;
        background: var(--iv-purple);
        color: #ffffff;
        padding: .65rem .9rem;
        font-weight: 900;
        text-decoration: none;
        border: 3px solid var(--iv-purple-dark);
    }

    .dashboard-card-link:hover .dashboard-action {
        background: var(--iv-purple-dark);
    }

    .dashboard-card-colour-yellow .dashboard-action {
        background: var(--iv-yellow);
        color: var(--iv-black);
        border-color: var(--iv-black);
    }

    .dashboard-card-soon {
        background: var(--iv-grey);
    }

    .dashboard-card-soon .dashboard-action {
        background: #505a5f;
        border-color: #505a5f;
        color: #ffffff;
    }

    .dashboard-soon-badge {
        display: inline-block;
        align-self: flex-start;
        margin-bottom: .75rem;
        padding: .25rem .5rem;
        background: var(--iv-yellow);
        color: var(--iv-black);
        border: 2px solid var(--iv-black);
        font-size: .8rem;
        font-weight: 900;
    }

    .dashboard-external {
        margin-top: 2rem;
    }

    .dashboard-external-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 760px) {
        .dashboard-external-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .dashboard-external-card {
        background: #ffffff;
        border: 3px solid var(--iv-black);
        padding: 1rem;
    }

    .dashboard-external-card h3 {
        margin: 0;
        color: var(--iv-black);
        font-size: 1.25rem;
        font-weight: 900;
    }

    .dashboard-external-card p {
        margin: .65rem 0 .85rem;
        color: var(--iv-black);
        font-weight: 700;
        line-height: 1.4;
    }

    .dashboard-external-card a {
        color: var(--iv-purple-dark);
        font-weight: 900;
        text-decoration: underline;
        text-decoration-thickness: 3px;
        text-underline-offset: 4px;
    }

    @media (max-width: 520px) {
        .dashboard-welcome,
        .dashboard-groups,
        .dashboard-card-body,
        .dashboard-external-card {
            padding: .9rem;
        }

        .dashboard-card-media img {
            height: 125px;
        }
    }
</style>

<main class="lt-main">
    <section class="dashboard-intro" aria-label="Dashboard summary">
        <div class="dashboard-welcome">
            <h2>Welcome, <?= e($displayName) ?></h2>
            <p>
                Use this dashboard to open the District Calendar, update your profile,
                find volunteers and manage the tools available to your role.
            </p>
        </div>

        <aside class="dashboard-groups">
            <h2>Your Group<?= count($groupNames) === 1 ? '' : 's' ?></h2>

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
        <h2 id="tasks-heading" class="dashboard-section-heading">Things you can do</h2>

        <div class="dashboard-grid">
            <?php foreach ($visibleModules as $module): ?>
                <?php $isAvailable = $module['status'] === 'available'; ?>

                <?php if ($isAvailable): ?>
                    <a href="<?= e($module['url']) ?>" class="dashboard-card-link">
                <?php endif; ?>

                <article class="dashboard-card dashboard-card-colour-<?= e($module['colour']) ?> <?= $isAvailable ? '' : 'dashboard-card-soon' ?>">
                    <div class="dashboard-card-media">
                        <img
                            src="<?= e($module['image']) ?>"
                            alt=""
                            loading="lazy"
                            onerror="this.style.display='none';"
                        >
                        <span class="dashboard-card-title-strip"><?= e($module['title']) ?></span>
                    </div>

                    <div class="dashboard-card-body">
                        <?php if (!$isAvailable): ?>
                            <span class="dashboard-soon-badge">Coming soon</span>
                        <?php endif; ?>

                        <h3><?= e($module['title']) ?></h3>
                        <p><?= e($module['description']) ?></p>

                        <span class="dashboard-action">
                            <?= e($module['label']) ?>
                        </span>
                    </div>
                </article>

                <?php if ($isAvailable): ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="dashboard-external" aria-labelledby="external-heading">
        <h2 id="external-heading" class="dashboard-section-heading">Useful external links</h2>

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