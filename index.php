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

$personId = (int) $user['id'];
$memberships = user_group_memberships($personId);

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

function home_table_exists(string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute(['table_name' => $table]);

        return $cache[$table] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function home_normalise_shared_mailbox_email(string $email): string
{
    return strtolower(trim($email));
}

function home_valid_shared_mailbox_email(string $email): bool
{
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function home_fetch_shared_mailboxes(int $personId): array
{
    if (!home_table_exists('user_shared_mailboxes')) {
        return [];
    }

    try {
        $stmt = db()->prepare("
            SELECT
                id,
                person_id,
                mailbox_email,
                display_name,
                is_favourite,
                open_count,
                last_opened_at,
                created_at,
                updated_at
            FROM user_shared_mailboxes
            WHERE person_id = :person_id
              AND is_favourite = 1
            ORDER BY
                COALESCE(last_opened_at, updated_at, created_at) DESC,
                mailbox_email ASC
            LIMIT 20
        ");
        $stmt->execute(['person_id' => $personId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function home_save_shared_mailbox(int $personId, string $email): array
{
    if (!home_table_exists('user_shared_mailboxes')) {
        throw new RuntimeException('Shared mailbox favourites table has not been created yet.');
    }

    $email = home_normalise_shared_mailbox_email($email);

    if (!home_valid_shared_mailbox_email($email)) {
        throw new RuntimeException('Enter a valid shared mailbox email address.');
    }

    $stmt = db()->prepare("
        INSERT INTO user_shared_mailboxes (
            person_id,
            mailbox_email,
            display_name,
            is_favourite,
            open_count,
            last_opened_at,
            created_at,
            updated_at
        )
        VALUES (
            :person_id,
            :mailbox_email,
            :display_name,
            1,
            1,
            NOW(),
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            is_favourite = 1,
            open_count = open_count + 1,
            last_opened_at = NOW(),
            updated_at = NOW()
    ");
    $stmt->execute([
        'person_id' => $personId,
        'mailbox_email' => $email,
        'display_name' => $email,
    ]);

    $stmt = db()->prepare("
        SELECT
            id,
            person_id,
            mailbox_email,
            display_name,
            is_favourite,
            open_count,
            last_opened_at,
            created_at,
            updated_at
        FROM user_shared_mailboxes
        WHERE person_id = :person_id
          AND mailbox_email = :mailbox_email
        LIMIT 1
    ");
    $stmt->execute([
        'person_id' => $personId,
        'mailbox_email' => $email,
    ]);

    $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mailbox) {
        throw new RuntimeException('Shared mailbox could not be saved.');
    }

    return $mailbox;
}

function home_touch_shared_mailbox(int $personId, int $mailboxId): array
{
    if (!home_table_exists('user_shared_mailboxes')) {
        throw new RuntimeException('Shared mailbox favourites table has not been created yet.');
    }

    $stmt = db()->prepare("
        UPDATE user_shared_mailboxes
        SET open_count = open_count + 1,
            last_opened_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
          AND person_id = :person_id
          AND is_favourite = 1
        LIMIT 1
    ");
    $stmt->execute([
        'id' => $mailboxId,
        'person_id' => $personId,
    ]);

    $stmt = db()->prepare("
        SELECT
            id,
            person_id,
            mailbox_email,
            display_name,
            is_favourite,
            open_count,
            last_opened_at,
            created_at,
            updated_at
        FROM user_shared_mailboxes
        WHERE id = :id
          AND person_id = :person_id
          AND is_favourite = 1
        LIMIT 1
    ");
    $stmt->execute([
        'id' => $mailboxId,
        'person_id' => $personId,
    ]);

    $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mailbox) {
        throw new RuntimeException('Shared mailbox could not be found.');
    }

    return $mailbox;
}

function home_remove_shared_mailbox(int $personId, int $mailboxId): void
{
    if (!home_table_exists('user_shared_mailboxes')) {
        throw new RuntimeException('Shared mailbox favourites table has not been created yet.');
    }

    $stmt = db()->prepare("
        UPDATE user_shared_mailboxes
        SET is_favourite = 0,
            updated_at = NOW()
        WHERE id = :id
          AND person_id = :person_id
        LIMIT 1
    ");
    $stmt->execute([
        'id' => $mailboxId,
        'person_id' => $personId,
    ]);
}

function home_shared_mailbox_json_response(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shared_mailbox_action'])) {
    $action = (string) ($_POST['shared_mailbox_action'] ?? '');

    try {
        if ($action === 'save') {
            $mailbox = home_save_shared_mailbox($personId, (string) ($_POST['mailbox_email'] ?? ''));

            home_shared_mailbox_json_response([
                'ok' => true,
                'mailbox' => $mailbox,
                'mailboxes' => home_fetch_shared_mailboxes($personId),
            ]);
        }

        if ($action === 'touch') {
            $mailbox = home_touch_shared_mailbox($personId, (int) ($_POST['mailbox_id'] ?? 0));

            home_shared_mailbox_json_response([
                'ok' => true,
                'mailbox' => $mailbox,
                'mailboxes' => home_fetch_shared_mailboxes($personId),
            ]);
        }

        if ($action === 'remove') {
            home_remove_shared_mailbox($personId, (int) ($_POST['mailbox_id'] ?? 0));

            home_shared_mailbox_json_response([
                'ok' => true,
                'mailboxes' => home_fetch_shared_mailboxes($personId),
            ]);
        }

        throw new RuntimeException('Unknown shared mailbox action.');
    } catch (Throwable $e) {
        home_shared_mailbox_json_response([
            'ok' => false,
            'message' => $e->getMessage() ?: 'Shared mailbox action failed.',
            'mailboxes' => home_fetch_shared_mailboxes($personId),
        ]);
    }
}

$sharedMailboxTableReady = home_table_exists('user_shared_mailboxes');
$sharedMailboxes = home_fetch_shared_mailboxes($personId);

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
        'title' => 'Open shared mailbox',
        'description' => $sharedMailboxes
            ? 'Open a recently opened shared mailbox or add another mailbox.'
            : 'Open a shared Outlook mailbox and save it for next time.',
        'url' => '#',
        'status' => 'available',
        'image' => '/assets/img/db-20220915-00340-jpg.jpg',
        'visible' => true,
        'type' => 'shared_mailbox',
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

$sharedMailboxesJson = json_encode($sharedMailboxes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
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

    .lt-card-button {
        display: block;
        width: 100%;
        border: 0;
        padding: 0;
        background: transparent;
        text-align: left;
        color: inherit;
        cursor: pointer;
    }

    .lt-card-button:hover,
    .lt-card-button:focus {
        color: inherit;
    }

    .lt-card-button:focus {
        outline: 4px solid #ffdd00;
        outline-offset: 4px;
    }

    .lt-shared-mailbox-modal[hidden] {
        display: none;
    }

    .lt-shared-mailbox-modal {
        position: fixed;
        z-index: 3000;
        inset: 0;
    }

    .lt-shared-mailbox-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, .58);
    }

    .lt-shared-mailbox-dialog {
        position: relative;
        width: min(720px, calc(100% - 1rem));
        max-height: calc(100vh - 2rem);
        overflow: auto;
        margin: 1rem auto;
        background: #ffffff;
        border: 4px solid var(--iv-purple);
        box-shadow: none;
    }

    @media (min-width: 768px) {
        .lt-shared-mailbox-dialog {
            margin-top: 4rem;
        }
    }

    .lt-shared-mailbox-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        padding: 1rem;
        background: #f7f5fb;
        border-bottom: 2px solid #e6e6e6;
    }

    .lt-shared-mailbox-header h2 {
        margin: 0;
        color: var(--iv-purple);
        font-size: 1.45rem;
        font-weight: 900;
        line-height: 1.15;
    }

    .lt-shared-mailbox-close {
        border: 2px solid #1d1d1b;
        background: #ffffff;
        color: #1d1d1b;
        font-weight: 900;
        padding: .35rem .6rem;
        cursor: pointer;
    }

    .lt-shared-mailbox-body {
        padding: 1rem;
    }

    .lt-shared-mailbox-list {
        display: grid;
        gap: .6rem;
        margin-bottom: 1rem;
    }

    .lt-shared-mailbox-item {
        display: grid;
        gap: .6rem;
        background: #ffffff;
        border: 2px solid #e6e6e6;
        border-left: 8px solid var(--iv-purple);
        padding: .75rem;
    }

    @media (min-width: 700px) {
        .lt-shared-mailbox-item {
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
        }
    }

    .lt-shared-mailbox-email {
        display: block;
        color: #1d1d1b;
        font-weight: 900;
        overflow-wrap: anywhere;
    }

    .lt-shared-mailbox-meta {
        display: block;
        color: #555;
        font-size: .9rem;
        font-weight: 700;
        margin-top: .15rem;
    }

    .lt-shared-mailbox-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
    }

    .lt-shared-mailbox-form {
        background: #f7f5fb;
        border: 2px solid #e6e6e6;
        padding: 1rem;
    }

    .lt-shared-mailbox-status {
        display: none;
        margin-bottom: 1rem;
        padding: .75rem;
        border: 2px solid #e6e6e6;
        font-weight: 800;
    }

    .lt-shared-mailbox-status.is-visible {
        display: block;
    }

    .lt-shared-mailbox-status.is-error {
        border-left: 8px solid #d4351c;
        background: #fff4f4;
    }

    .lt-shared-mailbox-status.is-success {
        border-left: 8px solid #00703c;
        background: #f3fff7;
    }

    @media (max-width: 575.98px) {
        .lt-task-card-image {
            height: 115px;
        }

        .lt-shared-mailbox-actions .btn,
        .lt-shared-mailbox-form .btn {
            width: 100%;
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
                <?php $isSharedMailboxTile = (($module['type'] ?? '') === 'shared_mailbox'); ?>

                <div class="col-md-6 col-xl-3 mb-4">
                    <?php if ($isSharedMailboxTile): ?>
                        <button type="button" class="lt-card-button" id="open-shared-mailbox-tile">
                    <?php elseif ($module['status'] === 'available'): ?>
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
                            <span class="lt-action-link">
                                <?php if ($isSharedMailboxTile): ?>
                                    Open
                                <?php else: ?>
                                    <?= $module['status'] === 'available' ? 'Open' : 'Not available yet' ?>
                                <?php endif; ?>
                            </span>
                        </article>

                    <?php if ($isSharedMailboxTile): ?>
                        </button>
                    <?php elseif ($module['status'] === 'available'): ?>
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

<div
    class="lt-shared-mailbox-modal"
    id="shared-mailbox-modal"
    hidden
    aria-hidden="true"
>
    <div class="lt-shared-mailbox-backdrop" data-shared-mailbox-close></div>

    <div
        class="lt-shared-mailbox-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="shared-mailbox-title"
    >
        <div class="lt-shared-mailbox-header">
            <h2 id="shared-mailbox-title">
                <?= $sharedMailboxes ? 'Open a recently opened shared mailbox' : 'Open shared mailbox' ?>
            </h2>

            <button type="button" class="lt-shared-mailbox-close" data-shared-mailbox-close>
                Close
            </button>
        </div>

        <div class="lt-shared-mailbox-body">
            <div
                class="lt-shared-mailbox-status"
                id="shared-mailbox-status"
                role="status"
                aria-live="polite"
            ></div>

            <?php if (!$sharedMailboxTableReady): ?>
                <div class="lt-shared-mailbox-status is-visible is-error">
                    The shared mailbox favourites table has not been created yet. Run the SQL below first.
                </div>
            <?php endif; ?>

            <div id="shared-mailbox-list-wrap">
                <div class="lt-shared-mailbox-list" id="shared-mailbox-list"></div>
            </div>

            <form class="lt-shared-mailbox-form" id="shared-mailbox-form">
                <div class="form-group">
                    <label for="shared-mailbox-email">Shared mailbox email</label>
                    <input
                        type="email"
                        class="form-control"
                        id="shared-mailbox-email"
                        name="mailbox_email"
                        placeholder="example@irwellvalleyscouts.org.uk"
                        autocomplete="email"
                        required
                        <?= $sharedMailboxTableReady ? '' : 'disabled' ?>
                    >
                </div>

                <button type="submit" class="btn btn-primary lt-btn" <?= $sharedMailboxTableReady ? '' : 'disabled' ?>>
                    Save and open
                </button>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var sharedMailboxTableReady = <?= $sharedMailboxTableReady ? 'true' : 'false' ?>;
    var sharedMailboxes = <?= $sharedMailboxesJson ?>;

    var tile = document.getElementById('open-shared-mailbox-tile');
    var modal = document.getElementById('shared-mailbox-modal');
    var modalTitle = document.getElementById('shared-mailbox-title');
    var statusBox = document.getElementById('shared-mailbox-status');
    var list = document.getElementById('shared-mailbox-list');
    var form = document.getElementById('shared-mailbox-form');
    var emailInput = document.getElementById('shared-mailbox-email');

    function outlookSharedMailboxUrl(email) {
        return 'https://outlook.office.com/mail/' + encodeURIComponent(String(email || '').trim());
    }

    function setStatus(message, type) {
        if (!statusBox) {
            return;
        }

        statusBox.textContent = message || '';
        statusBox.classList.remove('is-error', 'is-success', 'is-visible');

        if (message) {
            statusBox.classList.add('is-visible');
            statusBox.classList.add(type === 'error' ? 'is-error' : 'is-success');
        }
    }

    function postSharedMailboxAction(action, data) {
        var body = new FormData();
        body.append('shared_mailbox_action', action);

        Object.keys(data || {}).forEach(function (key) {
            body.append(key, data[key]);
        });

        return fetch('/index.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        }).then(function (response) {
            return response.json();
        });
    }

    function openSharedMailbox(email) {
        var url = outlookSharedMailboxUrl(email);
        window.open(url, '_blank', 'noopener,noreferrer');
    }

    function mailboxLastOpenedText(mailbox) {
        if (!mailbox.last_opened_at) {
            return 'Saved mailbox';
        }

        return 'Last opened ' + mailbox.last_opened_at;
    }

    function renderMailboxes() {
        if (!list) {
            return;
        }

        list.innerHTML = '';

        if (modalTitle) {
            modalTitle.textContent = sharedMailboxes.length > 0
                ? 'Open a recently opened shared mailbox'
                : 'Open shared mailbox';
        }

        if (sharedMailboxes.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'lt-shared-mailbox-item';

            var emptyText = document.createElement('div');
            emptyText.innerHTML = '<span class="lt-shared-mailbox-email">No saved shared mailboxes yet</span><span class="lt-shared-mailbox-meta">Enter the shared mailbox email below.</span>';

            empty.appendChild(emptyText);
            list.appendChild(empty);
            return;
        }

        sharedMailboxes.forEach(function (mailbox) {
            var row = document.createElement('div');
            row.className = 'lt-shared-mailbox-item';

            var textWrap = document.createElement('div');

            var email = document.createElement('span');
            email.className = 'lt-shared-mailbox-email';
            email.textContent = mailbox.mailbox_email;

            var meta = document.createElement('span');
            meta.className = 'lt-shared-mailbox-meta';
            meta.textContent = mailboxLastOpenedText(mailbox);

            textWrap.appendChild(email);
            textWrap.appendChild(meta);

            var actions = document.createElement('div');
            actions.className = 'lt-shared-mailbox-actions';

            var openButton = document.createElement('button');
            openButton.type = 'button';
            openButton.className = 'btn btn-primary lt-btn';
            openButton.textContent = 'Open';
            openButton.addEventListener('click', function () {
                openSharedMailbox(mailbox.mailbox_email);

                postSharedMailboxAction('touch', {
                    mailbox_id: mailbox.id
                }).then(function (payload) {
                    if (payload && payload.ok && Array.isArray(payload.mailboxes)) {
                        sharedMailboxes = payload.mailboxes;
                        renderMailboxes();
                    }
                }).catch(function () {
                    // Do not block mailbox opening if the recent list cannot update.
                });
            });

            var removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'btn lt-btn lt-btn-secondary';
            removeButton.textContent = 'Remove';
            removeButton.addEventListener('click', function () {
                postSharedMailboxAction('remove', {
                    mailbox_id: mailbox.id
                }).then(function (payload) {
                    if (!payload || !payload.ok) {
                        setStatus(payload && payload.message ? payload.message : 'Could not remove mailbox.', 'error');
                        return;
                    }

                    sharedMailboxes = Array.isArray(payload.mailboxes) ? payload.mailboxes : [];
                    setStatus('Shared mailbox removed.', 'success');
                    renderMailboxes();
                }).catch(function () {
                    setStatus('Could not remove mailbox.', 'error');
                });
            });

            actions.appendChild(openButton);
            actions.appendChild(removeButton);

            row.appendChild(textWrap);
            row.appendChild(actions);
            list.appendChild(row);
        });
    }

    function openModal() {
        if (!modal) {
            return;
        }

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        setStatus('', 'success');
        renderMailboxes();

        window.setTimeout(function () {
            if (sharedMailboxes.length === 0 && emailInput) {
                emailInput.focus();
            } else {
                var firstOpenButton = modal.querySelector('.lt-shared-mailbox-actions .btn-primary');
                if (firstOpenButton) {
                    firstOpenButton.focus();
                }
            }
        }, 50);
    }

    function closeModal() {
        if (!modal) {
            return;
        }

        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');

        if (tile) {
            tile.focus();
        }
    }

    if (tile) {
        tile.addEventListener('click', function () {
            openModal();
        });
    }

    document.querySelectorAll('[data-shared-mailbox-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal();
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal && !modal.hidden) {
            closeModal();
        }
    });

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!sharedMailboxTableReady) {
                setStatus('Run the shared mailbox SQL table first.', 'error');
                return;
            }

            var email = emailInput ? emailInput.value.trim().toLowerCase() : '';

            if (!email) {
                setStatus('Enter the shared mailbox email address.', 'error');
                return;
            }

            postSharedMailboxAction('save', {
                mailbox_email: email
            }).then(function (payload) {
                if (!payload || !payload.ok) {
                    setStatus(payload && payload.message ? payload.message : 'Could not save shared mailbox.', 'error');
                    return;
                }

                sharedMailboxes = Array.isArray(payload.mailboxes) ? payload.mailboxes : [];

                if (emailInput) {
                    emailInput.value = '';
                }

                setStatus('Shared mailbox saved.', 'success');
                renderMailboxes();

                if (payload.mailbox && payload.mailbox.mailbox_email) {
                    openSharedMailbox(payload.mailbox.mailbox_email);
                }
            }).catch(function () {
                setStatus('Could not save shared mailbox.', 'error');
            });
        });
    }

    renderMailboxes();
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>