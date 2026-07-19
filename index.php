<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (is_file(__DIR__ . '/app/group-manager-helpers.php')) {
    require_once __DIR__ . '/app/group-manager-helpers.php';
}

require_login();

if (user_needs_group_onboarding()) {
    redirect('/onboarding.php');
}

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
$pageTitle = 'Home | ' . $appName;
$heroTitle = null;
$heroText = null;
$breadcrumb = null;

$personId = (int) $user['id'];
$memberships = user_group_memberships($personId);

function home_membership_role_label(string $role): string
{
    if ($role === '') {
        return 'Member';
    }

    if (function_exists('gm_role_title_from_membership_role')) {
        return gm_role_title_from_membership_role($role);
    }

    return ucwords(str_replace('_', ' ', $role));
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
    csrf_validate();
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
        'status' => 'available',
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
        'status' => 'available',
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
    [
        'title' => 'System Admin',
        'description' => 'Manage system settings, audit logs, cron jobs and platform-wide configuration.',
        'url' => '/system-admin-dashboard.php',
        'status' => 'available',
        'image' => '/assets/img/cubs-crate-stacking-jpg.jpg',
        'visible' => $isSystemAdmin,
    ],
    [
        'title' => 'Website Editor',
        'description' => 'Edit and manage the Irwell Valley District Scouts website content.',
        'url' => 'https://www.irvalscouts.org.uk/?sso_for_azure_ad=start',
        'status' => 'available',
        'image' => '/assets/img/white-ir-logo.png',
        'visible' => $isSystemAdmin,
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
    /* ===== DASHBOARD HERO BANNER ===== */
    .lt-dash-hero {
        position: relative;
        background: var(--iv-purple);
        color: #fff;
        overflow: hidden;
    }

    .lt-dash-hero-img {
        position: absolute;
        inset: 0;
        z-index: 0;
    }

    .lt-dash-hero-img img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
        opacity: .35;
    }

    .lt-dash-hero-inner {
        position: relative;
        z-index: 1;
        max-width: 1180px;
        margin: 0 auto;
        padding: 2.5rem 1rem 2rem;
    }

    .lt-dash-hero h1 {
        font-size: 2rem;
        font-weight: 900;
        letter-spacing: -.03em;
        line-height: 1.1;
        margin: 0 0 .35rem;
    }

    .lt-dash-hero p {
        font-size: 1rem;
        font-weight: 700;
        opacity: .9;
        margin: 0;
        max-width: 600px;
    }

    @media (min-width: 768px) {
        .lt-dash-hero-inner {
            padding: 3.5rem 1rem 2.5rem;
        }

        .lt-dash-hero h1 {
            font-size: 2.75rem;
        }

        .lt-dash-hero p {
            font-size: 1.1rem;
        }
    }

    @media (max-width: 575.98px) {
        .lt-dash-hero-inner {
            padding: 1.75rem 1rem 1.5rem;
        }

        .lt-dash-hero h1 {
            font-size: 1.65rem;
        }
    }

    /* ===== MAIN CONTENT ===== */
    .lt-main {
        padding-top: 1.75rem;
    }

    /* ===== GROUPS STRIP ===== */
    .lt-groups-strip {
        display: flex;
        flex-wrap: wrap;
        gap: .6rem;
        margin-bottom: 2rem;
        padding: 1rem;
        background: var(--iv-grey-100);
        border: 1px solid var(--iv-grey-300);
        border-left: .4rem solid var(--iv-purple);
    }

    .lt-groups-strip-label {
        font-weight: 900;
        color: var(--iv-purple);
        margin-right: .5rem;
        white-space: nowrap;
    }

    .lt-groups-strip-item {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        padding: .25rem .6rem;
        background: #fff;
        border: 1px solid var(--iv-grey-300);
        font-weight: 800;
        font-size: .88rem;
    }

    .lt-groups-strip-item .lt-group-role-tag {
        color: var(--iv-grey-700);
        font-weight: 700;
    }

    /* ===== MODULE CARDS (CLEANER GRID) ===== */
    .lt-modules-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    @media (min-width: 576px) {
        .lt-modules-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (min-width: 992px) {
        .lt-modules-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    .lt-mod-card {
        display: flex;
        flex-direction: column;
        background: #fff;
        border: 1px solid var(--iv-grey-300);
        overflow: hidden;
        transition: border-color .15s, box-shadow .15s;
    }

    .lt-mod-card:hover {
        border-color: var(--iv-purple);
        box-shadow: 0 4px 16px rgba(77, 0, 153, .08);
    }

    .lt-mod-card-img {
        height: 120px;
        background: var(--iv-grey-100);
        overflow: hidden;
        flex-shrink: 0;
    }

    .lt-mod-card-img img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .lt-mod-card-body {
        padding: 1rem;
        display: flex;
        flex-direction: column;
        flex: 1;
    }

    .lt-mod-card-body h3 {
        font-size: 1.1rem;
        font-weight: 900;
        margin: 0 0 .4rem;
        line-height: 1.2;
    }

    .lt-mod-card-body p {
        font-size: .9rem;
        color: var(--iv-grey-700);
        font-weight: 700;
        margin: 0 0 .75rem;
        flex: 1;
    }

    .lt-mod-card-action {
        font-weight: 900;
        color: var(--iv-blue);
        font-size: .9rem;
    }

    .lt-mod-card-badge {
        display: inline-block;
        margin-bottom: .5rem;
        padding: .2rem .5rem;
        background: var(--iv-grey-100);
        border: 1px solid var(--iv-grey-300);
        font-size: .75rem;
        font-weight: 900;
        color: var(--iv-grey-700);
    }

    .lt-card-link {
        display: block;
        color: inherit;
        text-decoration: none;
        height: 100%;
    }

    .lt-card-link:hover {
        color: inherit;
        text-decoration: none;
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
        height: 100%;
    }

    .lt-card-button:hover,
    .lt-card-button:focus {
        color: inherit;
    }

    .lt-card-button:focus {
        outline: 4px solid var(--iv-yellow);
        outline-offset: 4px;
    }

    /* ===== EXTERNAL LINKS ===== */
    .lt-ext-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
        margin-top: .5rem;
    }

    @media (min-width: 576px) {
        .lt-ext-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    .lt-ext-card {
        padding: 1.25rem;
        border: 1px solid var(--iv-grey-300);
        background: #fff;
    }

    .lt-ext-card h3 {
        font-size: 1.05rem;
        font-weight: 900;
        margin: 0 0 .4rem;
    }

    .lt-ext-card p {
        font-size: .9rem;
        color: var(--iv-grey-700);
        font-weight: 700;
        margin: 0 0 .6rem;
    }

    /* ===== SHARED MAILBOX MODAL ===== */
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
        .lt-shared-mailbox-actions .btn,
        .lt-shared-mailbox-form .btn {
            width: 100%;
        }
    }
</style>

<section class="lt-dash-hero" aria-label="Dashboard welcome">
    <div class="lt-dash-hero-img" aria-hidden="true">
        <img src="/assets/img/cub-high-ropes-1-jpg.jpg" alt="" loading="eager">
    </div>
    <div class="lt-dash-hero-inner">
        <h1>Welcome, <?= e($user['preferred_name'] ?: $user['full_name'] ?: $user['email']) ?></h1>
        <p>Your District tools, communications and Group management in one place.</p>
    </div>
</section>

<main class="lt-main">
    <?php if ($groupCards): ?>
        <div class="lt-groups-strip" aria-label="Your Groups">
            <span class="lt-groups-strip-label"><?= count($groupCards) === 1 ? 'Your Group:' : 'Your Groups:' ?></span>
            <?php foreach ($groupCards as $group): ?>
                <span class="lt-groups-strip-item">
                    <?= e($group['group_name']) ?>
                    <span class="lt-group-role-tag">(<?= e(implode(', ', $group['roles'] ?: ['Member'])) ?>)</span>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section aria-labelledby="tasks-heading">
        <h2 id="tasks-heading" class="lt-section-title">Things you can do</h2>

        <div class="lt-modules-grid">
            <?php foreach ($modules as $module): ?>
                <?php $isSharedMailboxTile = (($module['type'] ?? '') === 'shared_mailbox'); ?>

                <?php if ($isSharedMailboxTile): ?>
                    <button type="button" class="lt-card-button" id="open-shared-mailbox-tile">
                <?php elseif ($module['status'] === 'available'): ?>
                    <a href="<?= e($module['url']) ?>" class="lt-card-link">
                <?php endif; ?>

                    <article class="lt-mod-card">
                        <div class="lt-mod-card-img">
                            <img
                                src="<?= e($module['image']) ?>"
                                alt=""
                                loading="lazy"
                                onerror="this.style.display='none';"
                            >
                        </div>

                        <div class="lt-mod-card-body">
                            <?php if ($module['status'] !== 'available'): ?>
                                <span class="lt-mod-card-badge">Coming soon</span>
                            <?php endif; ?>

                            <h3><?= e($module['title']) ?></h3>
                            <p><?= e($module['description']) ?></p>
                            <span class="lt-mod-card-action">
                                <?php if ($isSharedMailboxTile): ?>
                                    Open
                                <?php else: ?>
                                    <?= $module['status'] === 'available' ? 'Open' : 'Not available yet' ?>
                                <?php endif; ?>
                                &rarr;
                            </span>
                        </div>
                    </article>

                <?php if ($isSharedMailboxTile): ?>
                    </button>
                <?php elseif ($module['status'] === 'available'): ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="mt-4" aria-labelledby="external-heading">
        <h2 id="external-heading" class="lt-section-title">Useful external links</h2>
        <div class="lt-ext-grid">
            <div class="lt-ext-card">
                <h3>My Scout Membership</h3>
                <p>Open your Scouts membership record, learning and personal details.</p>
                <a href="https://membership.scouts.org.uk" target="_blank" rel="noopener noreferrer">Open My Scout Membership &rarr;</a>
            </div>
            <div class="lt-ext-card">
                <h3>Online Scout Manager</h3>
                <p>Open OSM for programme planning, section administration and parent communications.</p>
                <a href="https://www.onlinescoutmanager.co.uk/login.php" target="_blank" rel="noopener noreferrer">Open Online Scout Manager &rarr;</a>
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