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
$pdo = db();

$error = null;
$success = null;
$personId = (int) $user['id'];

$roleOptions = array_values(array_filter(
    portal_role_options(),
    static function (string $role): bool {
        $normalised = strtolower(trim($role));

        if ($normalised === '') {
            return false;
        }

        if ($normalised === 'other') {
            return false;
        }

        if (str_contains($normalised, 'permit holder')) {
            return false;
        }

        if (str_contains($normalised, 'nights away')) {
            return false;
        }

        if (str_contains($normalised, 'skill instructor') || str_contains($normalised, 'skills instructor')) {
            return false;
        }

        return true;
    }
));

$accreditationOptions = portal_accreditation_options();
$allowedAccreditations = portal_flatten_options($accreditationOptions);

function profile_table_exists(string $table): bool
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

function profile_membership_role_label(?string $role): string
{
    if ($role === null || $role === '') {
        return 'Member';
    }

    if (function_exists('gm_role_title_from_membership_role')) {
        return gm_role_title_from_membership_role($role);
    }

    return ucwords(str_replace('_', ' ', $role));
}

function profile_access_level_label(?string $accessLevel): string
{
    return match ((string) $accessLevel) {
        'system_admin' => 'System Admin',
        'district_admin' => 'District Admin',
        'district_reviewer' => 'District Reviewer',
        'group_admin' => 'Group Admin',
        'member' => 'Member',
        default => ucwords(str_replace('_', ' ', (string) ($accessLevel ?: 'member'))),
    };
}

function profile_photo_url(array $user, array $profile): string
{
    foreach ([
        'profile_photo_url',
        'photo_url',
        'avatar_url',
        'picture_url',
        'microsoft_photo_url',
        'ms_photo_url',
    ] as $field) {
        if (!empty($user[$field])) {
            return (string) $user[$field];
        }

        if (!empty($profile[$field])) {
            return (string) $profile[$field];
        }
    }

    if (!empty($user['id'])) {
        $localPath = '/uploads/profile-photos/' . (int) $user['id'] . '.jpg';
        $localFile = __DIR__ . $localPath;

        if (is_file($localFile)) {
            return $localPath;
        }
    }

    return '';
}

function profile_audit(int $personId, array $details): void
{
    if (!profile_table_exists('audit_log')) {
        return;
    }

    try {
        $stmt = db()->prepare("
            INSERT INTO audit_log (
                actor_type,
                actor_person_id,
                action,
                entity_type,
                entity_id,
                details_json
            )
            VALUES (
                'person',
                :person_id,
                'profile_updated',
                'person',
                :person_id,
                :details_json
            )
        ");

        $stmt->execute([
            'person_id' => $personId,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        // Do not fail profile saving because audit logging failed.
    }
}

$stmt = $pdo->query("
    SELECT id, group_name
    FROM groups
    WHERE is_active = 1
    ORDER BY group_name ASC
");
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT
        p.*,
        dp.role_title,
        dp.about_me,
        dp.accreditations_json,
        dp.share_phone,
        dp.visible_in_directory
    FROM people p
    LEFT JOIN directory_profiles dp
      ON dp.person_id = p.id
    WHERE p.id = :person_id
    LIMIT 1
");
$stmt->execute(['person_id' => $personId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    throw new RuntimeException('Profile not found.');
}

$memberships = user_group_memberships($personId, false);

$activeMemberships = array_values(array_filter(
    $memberships,
    static fn(array $membership): bool => ($membership['status'] ?? 'active') === 'active'
));

$hasActiveGroup = count($activeMemberships) > 0;
$existingGroupIds = array_map(static fn(array $m): int => (int) $m['group_id'], $activeMemberships);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $roleTitle = trim((string) ($_POST['role_title'] ?? ''));
    $aboutMe = trim((string) ($_POST['about_me'] ?? ''));
    $sharePhone = isset($_POST['share_phone']) ? 1 : 0;
    $visibleInDirectory = 1;

    $postedGroupId = (int) ($_POST['group_id'] ?? 0);
    $groupIds = $postedGroupId > 0 ? [$postedGroupId] : [];

    $postedAccreditations = $_POST['accreditations'] ?? [];

    if (!is_array($postedAccreditations)) {
        $postedAccreditations = [];
    }

    $cleanAccreditations = array_values(array_intersect(
        array_map('strval', $postedAccreditations),
        $allowedAccreditations
    ));

    sort($cleanAccreditations);

    $accreditationsJson = json_encode($cleanAccreditations, JSON_UNESCAPED_UNICODE) ?: '[]';

    if ($fullName === '') {
        $error = 'Enter your name.';
    } elseif ($roleTitle === '' || !in_array($roleTitle, $roleOptions, true)) {
        $error = 'Choose your main role.';
    } elseif (!$hasActiveGroup && !$groupIds) {
        $error = 'Choose your Group.';
    } elseif (!$hasActiveGroup && $groupIds) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM groups
            WHERE is_active = 1
              AND id = :group_id
        ");
        $stmt->execute(['group_id' => $postedGroupId]);

        if ((int) $stmt->fetchColumn() !== 1) {
            $error = 'Choose a valid active Group.';
        }
    }

    if (!$error) {
        $membershipRole = portal_membership_role_from_title($roleTitle);
        $accessLevel = portal_access_level_from_membership_role($membershipRole);

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
                UPDATE people
                SET full_name = :full_name,
                    phone = :phone,
                    status = 'active'
                WHERE id = :person_id
            ");
            $stmt->execute([
                'full_name' => $fullName,
                'phone' => $phone !== '' ? $phone : null,
                'person_id' => $personId,
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO directory_profiles (
                    person_id,
                    role_title,
                    about_me,
                    accreditations_json,
                    visible_in_directory,
                    share_phone,
                    profile_updated_at
                )
                VALUES (
                    :person_id,
                    :role_title,
                    :about_me,
                    :accreditations_json,
                    :visible_in_directory,
                    :share_phone,
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    role_title = VALUES(role_title),
                    about_me = VALUES(about_me),
                    accreditations_json = VALUES(accreditations_json),
                    visible_in_directory = VALUES(visible_in_directory),
                    share_phone = VALUES(share_phone),
                    profile_updated_at = NOW()
            ");
            $stmt->execute([
                'person_id' => $personId,
                'role_title' => $roleTitle,
                'about_me' => $aboutMe !== '' ? $aboutMe : null,
                'accreditations_json' => $accreditationsJson,
                'visible_in_directory' => $visibleInDirectory,
                'share_phone' => $sharePhone,
            ]);

            if (!$hasActiveGroup) {
                foreach ($groupIds as $index => $groupId) {
                    $stmt = $pdo->prepare("
                        INSERT INTO group_memberships (
                            person_id,
                            group_id,
                            membership_role,
                            access_level,
                            status,
                            is_primary,
                            approved_at
                        )
                        VALUES (
                            :person_id,
                            :group_id,
                            :membership_role,
                            :access_level,
                            'active',
                            :is_primary,
                            NOW()
                        )
                        ON DUPLICATE KEY UPDATE
                            membership_role = VALUES(membership_role),
                            access_level = VALUES(access_level),
                            status = 'active',
                            is_primary = VALUES(is_primary),
                            approved_at = COALESCE(approved_at, NOW())
                    ");
                    $stmt->execute([
                        'person_id' => $personId,
                        'group_id' => $groupId,
                        'membership_role' => $membershipRole,
                        'access_level' => $accessLevel,
                        'is_primary' => $index === 0 ? 1 : 0,
                    ]);
                }
            }

            profile_audit($personId, [
                'role_title' => $roleTitle,
                'group_change_allowed' => !$hasActiveGroup,
                'group_ids' => !$hasActiveGroup ? $groupIds : $existingGroupIds,
                'accreditation_count' => count($cleanAccreditations),
                'phone_shared' => $sharePhone === 1,
                'visible_in_directory' => true,
            ]);

            $pdo->commit();

            refresh_current_user_session();
            $success = 'Profile updated.';

            $stmt = $pdo->prepare("
                SELECT
                    p.*,
                    dp.role_title,
                    dp.about_me,
                    dp.accreditations_json,
                    dp.share_phone,
                    dp.visible_in_directory
                FROM people p
                LEFT JOIN directory_profiles dp
                  ON dp.person_id = p.id
                WHERE p.id = :person_id
                LIMIT 1
            ");
            $stmt->execute(['person_id' => $personId]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: $profile;

            $memberships = user_group_memberships($personId, false);
            $activeMemberships = array_values(array_filter(
                $memberships,
                static fn(array $membership): bool => ($membership['status'] ?? 'active') === 'active'
            ));
            $hasActiveGroup = count($activeMemberships) > 0;
            $existingGroupIds = array_map(static fn(array $m): int => (int) $m['group_id'], $activeMemberships);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Profile could not be saved.';
        }
    }
}

$formFullName = trim((string) ($_POST['full_name'] ?? ($profile['full_name'] ?? '')));
$formPhone = trim((string) ($_POST['phone'] ?? ($profile['phone'] ?? '')));
$formRoleTitle = trim((string) ($_POST['role_title'] ?? ($profile['role_title'] ?? '')));
$formAboutMe = trim((string) ($_POST['about_me'] ?? ($profile['about_me'] ?? '')));

if ($hasActiveGroup) {
    $formGroupId = (int) ($existingGroupIds[0] ?? 0);
} else {
    $formGroupId = (int) ($_POST['group_id'] ?? ($existingGroupIds[0] ?? 0));
}

$formAccreditations = $_POST['accreditations'] ?? portal_decode_json_list($profile['accreditations_json'] ?? null);

if (!is_array($formAccreditations)) {
    $formAccreditations = [];
}

$formAccreditations = array_values(array_intersect(
    array_map('strval', $formAccreditations),
    $allowedAccreditations
));

sort($formAccreditations);

$formSharePhone = isset($_POST['share_phone']) ? 1 : (int) ($profile['share_phone'] ?? 0);

$displayName = trim((string) ($profile['full_name'] ?? $user['full_name'] ?? $user['email'] ?? 'User'));
$initials = strtoupper(substr($displayName, 0, 1));
$photoUrl = profile_photo_url($user, $profile);
$microsoftProfileUrl = 'https://myaccount.microsoft.com/';

$pageTitle = 'Profile | ' . $appName;
$heroTitle = 'My profile';
$heroText = 'Update your contact details, role and accreditations.';
$breadcrumb = '<a href="/index.php">Home</a> / Profile';
?>
<?php include __DIR__ . '/header.php'; ?>

<style>
    .profile-layout {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 992px) {
        .profile-layout {
            grid-template-columns: minmax(250px, 320px) minmax(0, 1fr);
            align-items: start;
        }
    }

    .profile-side-card,
    .profile-panel,
    .profile-role-card,
    .profile-pill,
    .profile-selected-summary,
    .profile-selected-tag,
    .profile-accreditation-category {
        border-radius: 0;
    }

    .profile-side-card {
        background: #ffffff;
        border: 2px solid #e6e6e6;
        padding: 1.25rem;
        box-shadow: none;
    }

    @media (min-width: 992px) {
        .profile-side-card {
            position: sticky;
            top: 1rem;
        }
    }

    .profile-photo-link {
        position: relative;
        display: block;
        width: 124px;
        height: 124px;
        margin: 0 auto 1rem;
        overflow: hidden;
        background: #7413dc;
        color: #ffffff;
        text-decoration: none;
        box-shadow: none;
    }

    .profile-photo-link:hover,
    .profile-photo-link:focus {
        color: #ffffff;
        text-decoration: none;
        outline: 4px solid #ffdd00;
        outline-offset: 3px;
    }

    .profile-photo {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 3rem;
        font-weight: 900;
    }

    .profile-photo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .profile-photo-overlay {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: .75rem;
        text-align: center;
        background: rgba(0, 0, 0, .68);
        color: #ffffff;
        font-weight: 900;
        opacity: 0;
        transition: opacity .15s ease-in-out;
    }

    .profile-photo-link:hover .profile-photo-overlay,
    .profile-photo-link:focus .profile-photo-overlay {
        opacity: 1;
    }

    .profile-side-card h2 {
        margin: 0;
        color: #4d0b93;
        font-size: 1.35rem;
        font-weight: 900;
        text-align: center;
        line-height: 1.15;
    }

    .profile-email {
        margin: .35rem 0 1rem;
        color: #555;
        font-weight: 700;
        text-align: center;
        overflow-wrap: anywhere;
    }

    .profile-side-heading {
        margin: 1rem 0 .5rem;
        color: #4d0b93;
        font-size: 1rem;
        font-weight: 900;
    }

    .profile-role-list {
        display: grid;
        gap: .6rem;
    }

    .profile-role-card {
        background: #f7f5fb;
        border: 2px solid #e6e6e6;
        border-left: 8px solid #7413dc;
        padding: .75rem;
    }

    .profile-role-card strong {
        display: block;
        color: #4d0b93;
        font-weight: 900;
        line-height: 1.2;
    }

    .profile-role-card span {
        display: block;
        margin-top: .25rem;
        color: #333;
        font-weight: 800;
    }

    .profile-pill-row {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
        margin-top: .5rem;
    }

    .profile-pill {
        display: inline-flex;
        background: #ffffff;
        color: #333;
        padding: .2rem .45rem;
        font-size: .78rem;
        font-weight: 900;
        border: 2px solid #e0e0e0;
    }

    .profile-main {
        display: grid;
        gap: 1rem;
    }

    .profile-panel {
        background: #ffffff;
        border: 2px solid #e6e6e6;
        padding: 1.25rem;
        box-shadow: none;
    }

    .profile-panel h2 {
        margin: 0 0 .85rem;
        color: #4d0b93;
        font-size: 1.35rem;
        font-weight: 900;
    }

    .profile-check-grid {
        display: grid;
        gap: .55rem;
    }

    @media (min-width: 700px) {
        .profile-check-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .profile-accreditation-toolbar {
        display: grid;
        gap: .75rem;
        margin-bottom: 1rem;
    }

    @media (min-width: 768px) {
        .profile-accreditation-toolbar {
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: end;
        }
    }

    .profile-selected-summary {
        background: #f7f5fb;
        border: 2px solid #e6e6e6;
        padding: .75rem;
        margin-bottom: 1rem;
    }

    .profile-selected-summary strong {
        display: block;
        color: #4d0b93;
        font-weight: 900;
        margin-bottom: .35rem;
    }

    .profile-selected-tags {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
    }

    .profile-selected-tag {
        display: inline-flex;
        align-items: center;
        background: #ffffff;
        border: 2px solid #d8d8d8;
        color: #333;
        padding: .25rem .55rem;
        font-size: .82rem;
        font-weight: 800;
    }

    .profile-accreditation-category {
        border: 2px solid #e6e6e6;
        margin-bottom: .75rem;
        background: #ffffff;
        overflow: hidden;
    }

    .profile-accreditation-category summary {
        cursor: pointer;
        padding: .85rem 1rem;
        background: #f7f5fb;
        color: #4d0b93;
        font-weight: 900;
        list-style: none;
    }

    .profile-accreditation-category summary::-webkit-details-marker {
        display: none;
    }

    .profile-accreditation-category summary::after {
        content: "Show";
        float: right;
        color: #555;
        font-size: .9rem;
    }

    .profile-accreditation-category[open] summary::after {
        content: "Hide";
    }

    .profile-accreditation-list {
        padding: 1rem;
    }

    .profile-accreditation-item.is-hidden {
        display: none;
    }

    .profile-accreditation-empty {
        display: none;
        color: #555;
        font-weight: 700;
        margin: 0;
    }

    .profile-accreditation-category.no-results .profile-accreditation-empty {
        display: block;
    }

    .profile-save-row {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        align-items: center;
    }

    @media (max-width: 575.98px) {
        .profile-save-row .btn {
            width: 100%;
        }
    }
</style>

<main class="lt-main">
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <strong>There is a problem:</strong> <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <div class="profile-layout">
        <aside class="profile-side-card">
            <a
                class="profile-photo-link"
                href="<?= e($microsoftProfileUrl) ?>"
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Update your profile photo in Microsoft 365"
            >
                <span class="profile-photo">
                    <?php if ($photoUrl !== ''): ?>
                        <img
                            src="<?= e($photoUrl) ?>"
                            alt=""
                            onerror="this.remove(); this.parentElement.textContent='<?= e($initials) ?>';"
                        >
                    <?php else: ?>
                        <?= e($initials) ?>
                    <?php endif; ?>
                </span>
                <span class="profile-photo-overlay">Update in Microsoft 365</span>
            </a>

            <h2><?= e($displayName) ?></h2>
            <p class="profile-email"><?= e($user['email'] ?? $profile['primary_email'] ?? '') ?></p>

            <h3 class="profile-side-heading">Your role<?= count($activeMemberships) === 1 ? '' : 's' ?></h3>

            <?php if ($activeMemberships): ?>
                <div class="profile-role-list">
                    <?php foreach ($activeMemberships as $membership): ?>
                        <div class="profile-role-card">
                            <strong><?= e($membership['group_name'] ?? 'Unknown Group') ?></strong>
                            <span><?= e(profile_membership_role_label((string) ($membership['membership_role'] ?? 'member'))) ?></span>

                            <div class="profile-pill-row">
                                <span class="profile-pill">
                                    <?= e(profile_access_level_label((string) ($membership['access_level'] ?? 'member'))) ?>
                                </span>

                                <?php if ((int) ($membership['is_primary'] ?? 0) === 1): ?>
                                    <span class="profile-pill">Primary Group</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="profile-role-card">
                    <strong>No Group linked</strong>
                    <span>Choose your Group on this page.</span>
                </div>
            <?php endif; ?>
        </aside>

        <section class="profile-main">
            <form method="post">
                <?= csrf_field() ?>
                <section class="profile-panel">
                    <h2>Contact details</h2>

                    <div class="form-group">
                        <label for="full_name">Name</label>
                        <input
                            class="form-control"
                            type="text"
                            id="full_name"
                            name="full_name"
                            value="<?= e($formFullName) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="email">Microsoft account email</label>
                        <input
                            class="form-control"
                            type="email"
                            id="email"
                            value="<?= e($user['email'] ?? $profile['primary_email'] ?? '') ?>"
                            disabled
                        >
                    </div>

                    <div class="form-group mb-0">
                        <label for="phone">Contact number</label>
                        <input
                            class="form-control"
                            type="text"
                            id="phone"
                            name="phone"
                            value="<?= e($formPhone) ?>"
                        >
                    </div>
                </section>

                <?php if (!$hasActiveGroup): ?>
                    <section class="profile-panel">
                        <h2>Your Group</h2>

                        <div class="profile-check-grid">
                            <?php foreach ($groups as $group): ?>
                                <?php $groupId = (int) $group['id']; ?>
                                <label class="lt-check">
                                    <input
                                        type="radio"
                                        name="group_id"
                                        value="<?= $groupId ?>"
                                        <?= $groupId === $formGroupId ? 'checked' : '' ?>
                                        required
                                    >
                                    <span><?= e($group['group_name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="profile-panel">
                    <h2>Directory details</h2>

                    <div class="form-group">
                        <label for="role_title">Main role</label>
                        <select class="form-control" id="role_title" name="role_title" required>
                            <option value="">Choose your role</option>
                            <?php foreach ($roleOptions as $roleOption): ?>
                                <option value="<?= e($roleOption) ?>" <?= $formRoleTitle === $roleOption ? 'selected' : '' ?>>
                                    <?= e($roleOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <label class="lt-check mb-3">
                        <input
                            type="checkbox"
                            id="share_phone"
                            name="share_phone"
                            value="1"
                            <?= $formSharePhone === 1 ? 'checked' : '' ?>
                        >
                        <span>Show my phone number in the Directory</span>
                    </label>

                    <div class="form-group mb-0">
                        <label for="about_me">About me</label>
                        <textarea
                            class="form-control"
                            id="about_me"
                            name="about_me"
                            rows="3"
                        ><?= e($formAboutMe) ?></textarea>
                    </div>
                </section>

                <section class="profile-panel">
                    <h2>Permits and accreditations</h2>

                    <div class="profile-accreditation-toolbar">
                        <div class="form-group mb-md-0">
                            <label for="accreditation_search">Search</label>
                            <input
                                type="search"
                                id="accreditation_search"
                                class="form-control"
                                placeholder="Search permits, skills or accreditations"
                            >
                        </div>

                        <div>
                            <button type="button" class="btn lt-btn lt-btn-secondary" id="clear_accreditation_search">
                                Clear
                            </button>
                        </div>
                    </div>

                    <div class="profile-selected-summary">
                        <strong>
                            Selected:
                            <span id="selected_accreditation_count"><?= count($formAccreditations) ?></span>
                        </strong>

                        <div class="profile-selected-tags" id="selected_accreditation_tags">
                            <?php if ($formAccreditations): ?>
                                <?php foreach ($formAccreditations as $item): ?>
                                    <span class="profile-selected-tag"><?= e($item) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="profile-selected-tag">None selected</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php foreach ($accreditationOptions as $category => $items): ?>
                        <?php $selectedInCategory = count(array_intersect($items, $formAccreditations)); ?>

                        <details class="profile-accreditation-category" <?= $selectedInCategory > 0 ? 'open' : '' ?>>
                            <summary>
                                <?= e($category) ?>
                                <span>
                                    (<span data-category-count="<?= e($category) ?>"><?= (int) $selectedInCategory ?></span>)
                                </span>
                            </summary>

                            <div class="profile-accreditation-list">
                                <p class="profile-accreditation-empty">No matches in this category.</p>

                                <div class="profile-check-grid">
                                    <?php foreach ($items as $item): ?>
                                        <label
                                            class="lt-check profile-accreditation-item"
                                            data-accreditation-item="<?= e(strtolower($item . ' ' . $category)) ?>"
                                            data-accreditation-category="<?= e($category) ?>"
                                        >
                                            <input
                                                type="checkbox"
                                                name="accreditations[]"
                                                value="<?= e($item) ?>"
                                                <?= in_array($item, $formAccreditations, true) ? 'checked' : '' ?>
                                            >
                                            <span><?= e($item) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </section>

                <section class="profile-panel">
                    <div class="profile-save-row">
                        <button type="submit" class="btn btn-primary btn-lg lt-btn">
                            Save profile
                        </button>
                    </div>
                </section>
            </form>
        </section>
    </div>
</main>

<script <?= csp_nonce_attr() ?>>
(function () {
    var searchInput = document.getElementById('accreditation_search');
    var clearButton = document.getElementById('clear_accreditation_search');
    var selectedCount = document.getElementById('selected_accreditation_count');
    var selectedTags = document.getElementById('selected_accreditation_tags');

    function normalise(value) {
        return String(value || '').toLowerCase().trim();
    }

    function updateSearch() {
        var query = normalise(searchInput ? searchInput.value : '');

        document.querySelectorAll('.profile-accreditation-category').forEach(function (category) {
            var visibleCount = 0;

            category.querySelectorAll('.profile-accreditation-item').forEach(function (item) {
                var haystack = normalise(item.getAttribute('data-accreditation-item'));
                var visible = query === '' || haystack.indexOf(query) !== -1;

                item.classList.toggle('is-hidden', !visible);

                if (visible) {
                    visibleCount++;
                }
            });

            category.classList.toggle('no-results', visibleCount === 0);

            if (query !== '' && visibleCount > 0) {
                category.open = true;
            }
        });
    }

    function updateSelectedSummary() {
        var checked = Array.prototype.slice.call(
            document.querySelectorAll('input[name="accreditations[]"]:checked')
        );

        if (selectedCount) {
            selectedCount.textContent = String(checked.length);
        }

        if (selectedTags) {
            selectedTags.innerHTML = '';

            if (checked.length === 0) {
                var empty = document.createElement('span');
                empty.className = 'profile-selected-tag';
                empty.textContent = 'None selected';
                selectedTags.appendChild(empty);
            } else {
                checked.forEach(function (checkbox) {
                    var tag = document.createElement('span');
                    tag.className = 'profile-selected-tag';
                    tag.textContent = checkbox.value;
                    selectedTags.appendChild(tag);
                });
            }
        }

        var categoryCounts = {};

        document.querySelectorAll('.profile-accreditation-item').forEach(function (item) {
            var category = item.getAttribute('data-accreditation-category') || '';
            var checkbox = item.querySelector('input[type="checkbox"]');

            if (!categoryCounts[category]) {
                categoryCounts[category] = 0;
            }

            if (checkbox && checkbox.checked) {
                categoryCounts[category]++;
            }
        });

        document.querySelectorAll('[data-category-count]').forEach(function (countNode) {
            var category = countNode.getAttribute('data-category-count') || '';
            countNode.textContent = String(categoryCounts[category] || 0);
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', updateSearch);
    }

    if (clearButton && searchInput) {
        clearButton.addEventListener('click', function () {
            searchInput.value = '';
            updateSearch();
            searchInput.focus();
        });
    }

    document.querySelectorAll('input[name="accreditations[]"]').forEach(function (checkbox) {
        checkbox.addEventListener('change', updateSelectedSummary);
    });

    updateSearch();
    updateSelectedSummary();
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>