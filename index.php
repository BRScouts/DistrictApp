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

$modules = [
    [
        'title' => 'District Calendar',
        'description' => 'Submit away-from-hut notifications, view Group activity and share risk assessments.',
        'url' => '/dc/',
        'status' => 'available',
        'image' => '/assets/img/explorer-campfire-2-jpg.jpg',
        'visible' => true,
    ],
    [
        'title' => 'My profile',
        'description' => 'Update your details, directory visibility, role information and accreditations.',
        'url' => '/profile.php',
        'status' => 'available',
        'image' => 'assets/img/cub-high-ropes-1-jpg.jpg',
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
        'image' => 'assets/img/cub-climbing-jpg.jpg',
        'visible' => $isDistrictAdmin,
    ],
    [
        'title' => 'Technical Support',
        'description' => 'Report a problem, request help with access or ask for a dashboard change.',
        'url' => '/technical-support.php',
        'status' => 'soon',
        'image' => 'assets/img/cub-carrying-leaves-jpg.jpg',
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
     [
        'title' => 'My District Email / OneDrive',
        'description' => 'As a volunteer with Irwell Valley District, you are eligible for a free Microsoft 365 account with upto 1TB of storage. Access your email, OneDrive and other Microsoft apps here.',
        'url' => 'https://outlook.cloud.microsoft/',
        'status' => 'soon',
        'image' => 'https://cdn-dynmedia-1.microsoft.com/is/image/microsoftcorp/527948-FeaturedNewsCard-416x178?resMode=sharp2&op_usm=1.5,0.65,15,0&wid=1000&hei=429&qlt=85&fit=constrain',
        'visible' => true,
    ],
];

$modules = array_values(array_filter(
    $modules,
    static fn(array $module): bool => (bool) ($module['visible'] ?? false)
));
?>
<?php include __DIR__ . '/header.php'; ?>

<style>
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

    @media (max-width: 575.98px) {
        .lt-task-card-image {
            height: 115px;
        }
    }
</style>

<main class="lt-main">
    <div class="row mb-4">
        <div class="col-lg-8">
            <h2 class="lt-page-title">Welcome, <?= e($user['preferred_name'] ?: $user['full_name'] ?: $user['email']) ?></h2>
        </div>
        <div class="col-lg-4 mt-3 mt-lg-0">
            <div class="lt-panel-grey">
                <h3 class="h5 font-weight-bold">Your Groups</h3>
                <?php if ($groupNames): ?>
                    <ul class="mb-0 pl-3 font-weight-bold">
                        <?php foreach ($groupNames as $groupName): ?>
                            <li><?= e($groupName) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="mb-0">No Groups linked yet.</p>
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