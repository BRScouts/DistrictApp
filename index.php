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
$heroText = 'Open the tools connected to your Group, District Calendar, Directory and local administration.';
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
$isDistrictReviewer = $isDistrictAdmin || in_array('district_reviewer', $accessLevels, true);
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
        'fallback' => 'calendar',
        'tag' => 'Calendar',
    ],
    [
        'title' => 'My profile',
        'description' => 'Update your details, directory visibility, role information and accreditations.',
        'url' => '/profile.php',
        'status' => 'available',
        'visibility' => true,
        'image' => '/assets/img/dashboard/profile.jpg',
        'fallback' => 'profile',
        'tag' => 'Your details',
    ],
    [
        'title' => 'District Directory',
        'description' => 'Find leaders and volunteers by name, Group, role, section or accreditation.',
        'url' => '/directory.php',
        'status' => 'available',
        'visibility' => true,
        'image' => '/assets/img/dashboard/directory.jpg',
        'fallback' => 'directory',
        'tag' => 'People',
    ],
    [
        'title' => 'Group Admin',
        'description' => 'Add leaders to your Group, request District Microsoft 365 access and manage active Group people.',
        'url' => '/group-manager.php',
        'status' => 'available',
        'visibility' => $isGroupAdmin,
        'image' => '/assets/img/dashboard/group-admin.jpg',
        'fallback' => 'group',
        'tag' => 'GLV',
    ],
    [
        'title' => 'District Admin',
        'description' => 'Create Groups, assign GLVs, rotate Group links and manage reviewer or admin permissions.',
        'url' => '/district-admin.php',
        'status' => 'available',
        'visibility' => $isDistrictAdmin,
        'image' => '/assets/img/dashboard/district-admin.jpg',
        'fallback' => 'admin',
        'tag' => 'Admin',
    ],
    [
        'title' => 'Comms Tool',
        'description' => 'Prepare District communications, targeted messages and admin announcements.',
        'url' => '/comms-tool.php',
        'status' => 'soon',
        'visibility' => $isDistrictAdmin,
        'image' => '/assets/img/dashboard/comms.jpg',
        'fallback' => 'comms',
        'tag' => 'Comms',
    ],
    [
        'title' => 'Technical Support',
        'description' => 'Report a problem, request help with access or ask for a change to the District Dashboard.',
        'url' => '/technical-support.php',
        'status' => 'soon',
        'visibility' => true,
        'image' => '/assets/img/dashboard/support.jpg',
        'fallback' => 'support',
        'tag' => 'Help',
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

    .dashboard-welcome-card {
        background: #ffffff;
        border: 1px solid #e8e4f1;
        border-left: .45rem solid var(--iv-purple, #7413dc);
        border-radius: .85rem;
        padding: 1.25rem;
        box-shadow: 0 2px 14px rgba(0, 0, 0, .06);
    }

    .dashboard-welcome-card h2 {
        margin: 0;
        color: var(--iv-purple-dark, #4d0b93);
        font-size: clamp(1.6rem, 5vw, 2.4rem);
        line-height: 1.05;
        font-weight: 900;
    }

    .dashboard-welcome-card p {
        margin: .75rem 0 0;
        max-width: 760px;
        color: #333;
        font-size: 1.05rem;
        font-weight: 700;
        line-height: 1.45;
    }

    .dashboard-group-card {
        background: #f5f3ff;
        border: 1px solid #e3d8ff;
        border-radius: .85rem;
        padding: 1.25rem;
        box-shadow: 0 2px 14px rgba(0, 0, 0, .05);
    }

    .dashboard-group-card h3 {
        margin: 0 0 .75rem;
        color: var(--iv-purple-dark, #4d0b93);
        font-weight: 900;
    }

    .dashboard-group-card ul {
        margin: 0;
        padding-left: 1.25rem;
        font-weight: 800;
    }

    .dashboard-group-card li + li {
        margin-top: .25rem;
    }

    .dashboard-section-header {
        display: flex;
        flex-direction: column;
        gap: .35rem;
        margin: 2rem 0 1rem;
    }

    .dashboard-section-header h2 {
        margin: 0;
        color: var(--iv-purple-dark, #4d0b93);
        font-weight: 900;
        font-size: 1.55rem;
    }

    .dashboard-section-header p {
        margin: 0;
        color: #555;
        font-weight: 700;
    }

    .dashboard-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 620px) {
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

    .dashboard-card-link:hover,
    .dashboard-card-link:focus {
        color: inherit;
        text-decoration: none;
    }

    .dashboard-card {
        position: relative;
        display: flex;
        flex-direction: column;
        min-height: 100%;
        overflow: hidden;
        background: #ffffff;
        border: 1px solid #e6e6e6;
        border-radius: 1rem;
        box-shadow: 0 4px 18px rgba(0, 0, 0, .08);
        transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
    }

    .dashboard-card-link:hover .dashboard-card,
    .dashboard-card-link:focus .dashboard-card {
        transform: translateY(-2px);
        border-color: var(--iv-purple, #7413dc);
        box-shadow: 0 10px 30px rgba(0, 0, 0, .13);
    }

    .dashboard-card-link:focus {
        outline: 4px solid rgba(255, 184, 28, .75);
        outline-offset: 4px;
        border-radius: 1rem;
    }

    .dashboard-card-media {
        position: relative;
        min-height: 132px;
        background:
            radial-gradient(circle at top left, rgba(255,255,255,.35), transparent 35%),
            linear-gradient(135deg, #7413dc, #4d0b93);
        overflow: hidden;
    }

    .dashboard-card-media img {
        display: block;
        width: 100%;
        height: 160px;
        object-fit: cover;
    }

    .dashboard-card-media::after {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(0,0,0,.05), rgba(0,0,0,.38));
        pointer-events: none;
    }

    .dashboard-card-icon {
        position: absolute;
        left: 1rem;
        bottom: 1rem;
        z-index: 2;
        width: 54px;
        height: 54px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #ffb81c;
        color: #1d1d1b;
        font-weight: 900;
        font-size: 1.35rem;
        box-shadow: 0 8px 18px rgba(0,0,0,.2);
    }

    .dashboard-card-tag {
        position: absolute;
        right: 1rem;
        top: 1rem;
        z-index: 2;
        display: inline-block;
        padding: .25rem .55rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, .94);
        color: var(--iv-purple-dark, #4d0b93);
        font-size: .78rem;
        font-weight: 900;
    }

    .dashboard-card-body {
        display: flex;
        flex: 1;
        flex-direction: column;
        padding: 1.1rem;
    }

    .dashboard-card h3 {
        margin: 0;
        color: var(--iv-purple-dark, #4d0b93);
        font-size: 1.25rem;
        line-height: 1.15;
        font-weight: 900;
    }

    .dashboard-card p {
        margin: .65rem 0 1rem;
        color: #333;
        font-weight: 700;
        line-height: 1.42;
    }

    .dashboard-card-action {
        margin-top: auto;
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        color: var(--iv-purple-dark, #4d0b93);
        font-weight: 900;
    }

    .dashboard-card-action::after {
        content: "›";
        font-size: 1.4rem;
        line-height: 1;
    }

    .dashboard-badge-soon {
        display: inline-block;
        align-self: flex-start;
        margin-bottom: .75rem;
        padding: .25rem .55rem;
        border-radius: .35rem;
        background: #fff3cd;
        color: #664d03;
        font-size: .78rem;
        font-weight: 900;
    }

    .dashboard-card-soon {
        opacity: .82;
    }

    .dashboard-card-soon .dashboard-card-action {
        color: #555;
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
        border-radius: .85rem;
        padding: 1.25rem;
        box-shadow: 0 2px 14px rgba(0, 0, 0, .05);
    }

    .dashboard-external-card h3 {
        margin: 0;
        color: var(--iv-purple-dark, #4d0b93);
        font-weight: 900;
    }

    .dashboard-external-card p {
        margin: .5rem 0 .85rem;
        color: #333;
        font-weight: 700;
    }

    .dashboard-external-card a {
        font-weight: 900;
    }

    @media (prefers-reduced-motion: reduce) {
        .dashboard-card {
            transition: none;
        }

        .dashboard-card-link:hover .dashboard-card,
        .dashboard-card-link:focus .dashboard-card {
            transform: none;
        }
    }
</style>

<main class="lt-main">
    <section class="dashboard-welcome" aria-label="Welcome">
        <div class="dashboard-welcome-card">
            <h2>Welcome, <?= e($displayName) ?></h2>
            <p>
                Your dashboard shows the tools available to you based on your active Group memberships and District permissions.
                Use the tiles below to open the District Calendar, update your profile, manage your Group or access admin tools.
            </p>
        </div>

        <aside class="dashboard-group-card">
            <h3>Your Groups</h3>
            <?php if ($groupNames): ?>
                <ul>
                    <?php foreach ($groupNames as $groupName): ?>
                        <li><?= e($groupName) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="mb-0">No Groups linked yet.</p>
            <?php endif; ?>
        </aside>
    </section>

    <section aria-labelledby="tasks-heading">
        <div class="dashboard-section-header">
            <h2 id="tasks-heading">Things you can do</h2>
            <p>Choose a tool to get started.</p>
        </div>

        <div class="dashboard-grid">
            <?php foreach ($visibleModules as $module): ?>
                <?php
                $isAvailable = $module['status'] === 'available';
                $fallbackLetter = strtoupper(substr((string) $module['title'], 0, 1));
                ?>

                <?php if ($isAvailable): ?>
                    <a href="<?= e($module['url']) ?>" class="dashboard-card-link">
                <?php endif; ?>

                <article class="dashboard-card <?= $isAvailable ? '' : 'dashboard-card-soon' ?>">
                    <div class="dashboard-card-media dashboard-card-media-<?= e($module['fallback']) ?>">
                        <img
                            src="<?= e($module['image']) ?>"
                            alt=""
                            loading="lazy"
                            onerror="this.style.display='none';"
                        >
                        <span class="dashboard-card-tag"><?= e($module['tag']) ?></span>
                        <span class="dashboard-card-icon" aria-hidden="true"><?= e($fallbackLetter) ?></span>
                    </div>

                    <div class="dashboard-card-body">
                        <?php if (!$isAvailable): ?>
                            <span class="dashboard-badge-soon">Coming soon</span>
                        <?php endif; ?>

                        <h3><?= e($module['title']) ?></h3>
                        <p><?= e($module['description']) ?></p>

                        <span class="dashboard-card-action">
                            <?= $isAvailable ? 'Open' : 'Not available yet' ?>
                        </span>
                    </div>
                </article>

                <?php if ($isAvailable): ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="mt-4" aria-labelledby="external-heading">
        <div class="dashboard-section-header">
            <h2 id="external-heading">Useful external links</h2>
            <p>Common external systems used by Scout volunteers.</p>
        </div>

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