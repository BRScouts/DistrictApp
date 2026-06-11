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
$heroTitle = 'Leader Tool';
$heroText = 'Open District tools, update your profile and manage the tasks connected to your Group.';
$breadcrumb = '<a href="/index.php">Home</a>';

$memberships = user_group_memberships((int) $user['id']);

function home_membership_role_label(string $role): string
{
    return match ($role) {
        'group_lead_volunteer' => 'Group Lead Volunteer',
        'section_leader' => 'Section Leader',
        'assistant_section_leader' => 'Assistant Section Leader',
        'section_assistant' => 'Section Assistant',
        'trustee' => 'Trustee',
        'district_volunteer' => 'District Volunteer',
        'administrator' => 'Administrator',
        'other' => 'Other',
        default => $role !== '' ? ucwords(str_replace('_', ' ', $role)) : 'Member',
    };
}

function home_access_level_label(string $accessLevel): string
{
    return match ($accessLevel) {
        'system_admin' => 'System Admin',
        'district_admin' => 'District Admin',
        'district_reviewer' => 'District Reviewer',
        'group_admin' => 'Group Admin',
        default => 'Member',
    };
}

$accessLevels = [(string) ($user['highest_access_level'] ?? $user['role'] ?? 'member')];
$membershipRoles = [];
$groupCards = [];

foreach ($memberships as $membership) {
    if (($membership['status'] ?? 'active') !== 'active') {
        continue;
    }

    $groupName = trim((string) ($membership['group_name'] ?? ''));

    if ($groupName === '') {
        continue;
    }

    $groupId = (int) ($membership['group_id'] ?? 0);
    $membershipRole = (string) ($membership['membership_role'] ?? '');
    $accessLevel = (string) ($membership['access_level'] ?? 'member');

    $accessLevels[] = $accessLevel;
    $membershipRoles[] = $membershipRole;

    $key = $groupId > 0 ? 'group_' . $groupId : 'group_' . strtolower($groupName);

    if (!isset($groupCards[$key])) {
        $groupCards[$key] = [
            'group_id' => $groupId,
            'group_name' => $groupName,
            'roles' => [],
            'access_levels' => [],
            'can_manage' => false,
        ];
    }

    $roleLabel = home_membership_role_label($membershipRole);

    if (!in_array($roleLabel, $groupCards[$key]['roles'], true)) {
        $groupCards[$key]['roles'][] = $roleLabel;
    }

    if ($accessLevel !== 'member' && !in_array($accessLevel, $groupCards[$key]['access_levels'], true)) {
        $groupCards[$key]['access_levels'][] = $accessLevel;
    }

    if (
        $membershipRole === 'group_lead_volunteer'
        || $accessLevel === 'group_admin'
        || in_array($accessLevel, ['district_admin', 'system_admin'], true)
    ) {
        $groupCards[$key]['can_manage'] = true;
    }
}

$groupCards = array_values($groupCards);
$accessLevels = array_values(array_unique($accessLevels));
$membershipRoles = array_values(array_unique(array_filter($membershipRoles)));

$isSystemAdmin = in_array('system_admin', $accessLevels, true);
$isDistrictAdmin = $isSystemAdmin || in_array('district_admin', $accessLevels, true);
$isGroupAdmin = $isDistrictAdmin
    || in_array('group_admin', $accessLevels, true)
    || in_array('group_lead_volunteer', $membershipRoles, true);

if ($isDistrictAdmin) {
    foreach ($groupCards as &$groupCard) {
        $groupCard['can_manage'] = true;
    }
    unset($groupCard);
}

$modules = [
    [
        'title' => 'District Calendar',
        'description' => 'Submit away-from-hut notifications, view Group activity and share risk assessments.',
        'url' => '/dc/',
        'status' => 'soon',
        'image' => '/assets/img/explorer-campfire-2-jpg.jpg',
        'visible' => true,
    ],
    [
        'title' => 'My District Email / OneDrive',
        'description' => 'As a volunteer with Irwell Valley District, you are eligible for a free Microsoft 365 account with up to 1TB of storage. Access your email, OneDrive and other Microsoft apps here.',
        'url' => 'https://outlook.cloud.microsoft/',
        'status' => 'available',
        'image' => 'https://cdn-dynmedia-1.microsoft.com/is/image/microsoftcorp/527948-FeaturedNewsCard-416x178?resMode=sharp2&op_usm=1.5,0.65,15,0&wid=1000&hei=429&qlt=85&fit=constrain',
        'visible' => true,
    ],
    [
        'title' => 'District Directory',
        'description' => 'Find leaders and volunteers by name, Group, role, section or accreditation.',
        'url' => '/directory.php',
        'status' => 'available',
        'image' => '/assets/img/female-leader-with-large-group-jpg.jpg',
        'visible' => true,
    ],
    [
        'title' => 'Group Admin',
        'description' => 'Manage leaders in your Group and request District Microsoft 365 accounts.',
        'url' => '/group-manager.php',
        'status' => 'available',
        'image' => '/assets/img/cub-on-raft-jpg.jpg',
        'visible' => $isGroupAdmin,
    ],
    [
        'title' => 'District Admin',
        'description' => 'Create Groups, assign GLVs, rotate Group links and manage reviewer/admin permissions.',
        'url' => '/district-admin.php',
        'status' => 'available',
        'image' => '/assets/img/cub-climbing-jpg.jpg',
        'visible' => $isDistrictAdmin,
    ],
    [
        'title' => 'Technical Support',
        'description' => 'Report a problem, request help with access or ask for a dashboard change.',
        'url' => '/technical-support.php',
        'status' => 'soon',
        'image' => '/assets/img/cub-carrying-leaves-jpg.jpg',
        'visible' => true,
    ],
    [
        'title' => 'Comms Tool',
        'description' => 'Prepare District communications and targeted volunteer messages.',
        'url' => '/comms-tool.php',
        'status' => 'available',
        'image' => '/assets/img/db-20220915-00340-jpg.jpg',
        'visible' => $isDistrictAdmin,
    ],
];

$modules = array_values(array_filter(
    $modules,
    static fn(array $module): bool => (bool) ($module['visible'] ?? false)
));
?>
<?php include __DIR__ . '/header.php'; ?>

<style>
    /* Dashboard-specific compact header/hero. Keeps other pages unchanged. */
    .lt-header-inner {
        min-height: 72px;
        padding-top: .55rem;
        padding-bottom: .55rem;
    }

    .lt-brand img {
        height: 52px;
        max-width: 220px;
    }

    .lt-hero-inner {
        padding-top: 1rem;
        padding-bottom: 1rem;
    }

    .lt-hero h1 {
        font-size: 2rem;
        margin-bottom: .25rem;
    }

    .lt-hero p {
        font-size: .98rem;
        max-width: 720px;
    }

    .lt-breadcrumb-inner {
        padding-top: .45rem;
        padding-bottom: .45rem;
    }

    .lt-main {
        padding-top: 1.25rem;
    }

    @media (min-width: 992px) {
        .lt-header-inner {
            min-height: 78px;
        }

        .lt-brand img {
            height: 58px;
            max-width: 250px;
        }

        .lt-hero h1 {
            font-size: 2.35rem;
        }
    }

    @media (max-width: 575.98px) {
        .lt-header-inner {
            min-height: 64px;
        }

        .lt-brand img {
            height: 44px;
            max-width: 160px;
        }

        .lt-hero-inner {
            padding-top: .85rem;
            padding-bottom: .85rem;
        }

        .lt-hero h1 {
            font-size: 1.75rem;
        }
    }

    .lt-task-card {
        overflow: hidden;
    }

    .lt-task-card-image {
        height: 130px;
        margin: -1.25rem -1.25rem 1rem;
        background: #f3f2f1;
        border-bottom: 1px solid #e6e6e6;
        overflow: hidden;
    }

    .lt-task-card-image img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .lt-task-card-image img[style*="display: none"] + .lt-task-card-image-fallback {
        display: flex;
    }

    .lt-task-card-image-fallback {
        display: none;
        width: 100%;
        height: 100%;
        align-items: center;
        justify-content: center;
        background: #f7f5fb;
        color: #4d0b93;
        font-size: 2rem;
        font-weight: 900;
    }

    .lt-dashboard-top {
        align-items: stretch;
    }

    .lt-welcome-panel {
        height: 100%;
        display: flex;
        align-items: center;
    }

    .lt-welcome-panel .lt-page-title {
        margin-bottom: 0;
    }

    .lt-groups-panel {
        height: 100%;
        border-left: .45rem solid var(--iv-purple);
    }

    .lt-groups-heading {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: .75rem;
        margin-bottom: .75rem;
    }

    .lt-groups-heading h3 {
        margin-bottom: 0;
    }

    .lt-groups-count {
        color: var(--iv-purple);
        font-weight: 900;
        white-space: nowrap;
    }

    .lt-group-list {
        display: grid;
        gap: .75rem;
    }

    .lt-group-item {
        background: #fff;
        border: 2px solid #e5e5e5;
        padding: .75rem;
    }

    .lt-group-name {
        display: block;
        color: var(--iv-black);
        font-size: 1rem;
        font-weight: 900;
        line-height: 1.2;
        margin-bottom: .25rem;
    }

    .lt-group-role {
        display: block;
        color: #333;
        font-weight: 800;
        line-height: 1.25;
    }

    .lt-group-meta {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
        margin-top: .55rem;
    }

    .lt-group-badge {
        display: inline-block;
        padding: .2rem .45rem;
        background: #e7f1ff;
        color: #084298;
        font-size: .78rem;
        font-weight: 900;
    }

    .lt-group-link {
        display: inline-block;
        margin-top: .6rem;
        font-weight: 900;
    }

    .lt-no-groups {
        background: #fff;
        border: 2px solid #e5e5e5;
        padding: .75rem;
    }

    @media (max-width: 575.98px) {
        .lt-task-card-image {
            height: 115px;
        }
    }
</style>

<main class="lt-main">
    <div class="row mb-4 lt-dashboard-top">
        <div class="col-lg-8">
            <div class="lt-welcome-panel">
                <h2 class="lt-page-title">Welcome, <?= e($user['preferred_name'] ?: $user['full_name'] ?: $user['email']) ?></h2>
            </div>
        </div>

        <div class="col-lg-4 mt-3 mt-lg-0">
            <div class="lt-panel-grey lt-groups-panel">
                <div class="lt-groups-heading">
                    <h3 class="h5 font-weight-bold">
                        <?= count($groupCards) === 1 ? 'Your Group' : 'Your Groups' ?>
                    </h3>

                    <?php if ($groupCards): ?>
                        <span class="lt-groups-count"><?= count($groupCards) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($groupCards): ?>
                    <div class="lt-group-list">
                        <?php foreach ($groupCards as $group): ?>
                            <div class="lt-group-item">
                                <span class="lt-group-name"><?= e($group['group_name']) ?></span>
                                <span class="lt-group-role"><?= e(implode(', ', $group['roles'] ?: ['Member'])) ?></span>

                                <?php if ($group['access_levels']): ?>
                                    <div class="lt-group-meta">
                                        <?php foreach ($group['access_levels'] as $accessLevel): ?>
                                            <span class="lt-group-badge">
                                                <?= e(home_access_level_label((string) $accessLevel)) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($group['can_manage']): ?>
                                    <a class="lt-group-link" href="/group-manager.php<?= (int) $group['group_id'] > 0 ? '?group_id=' . (int) $group['group_id'] : '' ?>">
                                        Manage Group
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="lt-no-groups">
                        <p class="mb-0">No Groups linked yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <section aria-labelledby="tasks-heading">
        <h2 id="tasks-heading" class="lt-section-title">Things you can do</h2>
        <div class="row">
            <?php foreach ($modules as $module): ?>
                <div class="col-md-6 col-xl-3 mb-4">
                    <?php if ($module['status'] === 'available'): ?>
                        <a href="<?= e($module['url']) ?>" class="lt-card-link">
                    <?php endif; ?>
                        <article class="lt-task-card">
                            <div class="lt-task-card-image">
                                <img
                                    src="<?= e($module['image']) ?>"
                                    alt=""
                                    loading="lazy"
                                    onerror="this.style.display='none';"
                                >
                                <div class="lt-task-card-image-fallback" aria-hidden="true">
                                    <?= e(strtoupper(substr((string) $module['title'], 0, 1))) ?>
                                </div>
                            </div>

                            <?php if ($module['status'] !== 'available'): ?>
                                <span class="lt-badge mb-3">Soon</span>
                            <?php endif; ?>

                            <h3><?= e($module['title']) ?></h3>
                            <p><?= e($module['description']) ?></p>
                            <span class="lt-action-link"><?= $module['status'] === 'available' ? 'Open' : 'Not available yet' ?></span>
                        </article>
                    <?php if ($module['status'] === 'available'): ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="mt-4" aria-labelledby="external-heading">
        <h2 id="external-heading" class="lt-section-title">Useful external links</h2>
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="lt-panel">
                    <h3 class="h5 font-weight-bold">My Scout Membership</h3>
                    <p>Open your Scouts membership record, learning and personal details.</p>
                    <a href="https://membership.scouts.org.uk" target="_blank" rel="noopener noreferrer">Open My Scout Membership</a>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="lt-panel">
                    <h3 class="h5 font-weight-bold">Online Scout Manager</h3>
                    <p>Open OSM for programme planning, section administration and parent communications.</p>
                    <a href="https://www.onlinescoutmanager.co.uk/login.php" target="_blank" rel="noopener noreferrer">Open Online Scout Manager</a>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>