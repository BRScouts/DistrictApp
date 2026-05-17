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
$groupNames = array_map(static fn(array $membership): string => (string) $membership['group_name'], $memberships);

$modules = [
    [
        'title' => 'District Calendar',
        'description' => 'Submit away-from-hut notifications, view Group activity and share risk assessments.',
        'url' => '/dc/',
        'status' => 'available',
    ],
    [
        'title' => 'My profile',
        'description' => 'Update your details, directory visibility, role information and accreditations.',
        'url' => '/profile.php',
        'status' => 'available',
    ],
    [
        'title' => 'District Directory',
        'description' => 'Find leaders and volunteers by name, Group, role, section or accreditation.',
        'url' => '/directory.php',
        'status' => 'available',
    ],
    [
        'title' => 'Group admin',
        'description' => 'Manage leaders, Groups and Microsoft 365 account requests. Available to GLVs soon.',
        'url' => '#',
        'status' => 'soon',
    ],
];
?>
<?php include __DIR__ . '/header.php'; ?>

<main class="lt-main">
    <div class="row mb-4">
        <div class="col-lg-8">
            <h2 class="lt-page-title">Welcome, <?= e($user['preferred_name'] ?: $user['full_name'] ?: $user['email']) ?></h2>
            <p class="lt-lede">Your access is based on your active Group memberships. Section details are used for directory filtering and targeted emails, not to restrict access inside a Group.</p>
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
