<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

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

$roleOptions = portal_role_options();
$accreditationOptions = portal_accreditation_options();
$allowedAccreditations = portal_flatten_options($accreditationOptions);

$stmt = $pdo->query("SELECT id, group_name FROM groups WHERE is_active = 1 ORDER BY group_name ASC");
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
    LEFT JOIN directory_profiles dp ON dp.person_id = p.id
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

function profile_membership_role_label(string $role): string
{
    return match ($role) {
        'group_lead_volunteer' => 'Group Lead Volunteer',
        'section_leader' => 'Section Leader',
        'assistant_section_leader' => 'Assistant Section Leader',
        'section_assistant' => 'Section Assistant',
        'trustee' => 'Trustee',
        'district_volunteer' => 'District Volunteer',
        'administrator' => 'Administrator',
        default => ucwords(str_replace('_', ' ', $role ?: 'Member')),
    };
}

function profile_access_label(string $access): string
{
    return ucwords(str_replace('_', ' ', $access ?: 'member'));
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $roleTitle = trim((string) ($_POST['role_title'] ?? ''));
    $aboutMe = trim((string) ($_POST['about_me'] ?? ''));
    $sharePhone = isset($_POST['share_phone']) ? 1 : 0;
    $visibleInDirectory = isset($_POST['visible_in_directory']) ? 1 : 0;

    $postedGroupIds = $_POST['group_ids'] ?? [];
    if (!is_array($postedGroupIds)) {
        $postedGroupIds = [];
    }

    $groupIds = array_values(array_unique(array_filter(
        array_map('intval', $postedGroupIds),
        static fn(int $id): bool => $id > 0
    )));

    $postedAccreditations = $_POST['accreditations'] ?? [];
    if (!is_array($postedAccreditations)) {
        $postedAccreditations = [];
    }

    $cleanAccreditations = array_values(array_intersect(
        array_map('strval', $postedAccreditations),
        $allowedAccreditations
    ));

    $accreditationsJson = json_encode($cleanAccreditations, JSON_UNESCAPED_UNICODE) ?: '[]';

    if ($fullName === '') {
        $error = 'Enter your name.';
    } elseif ($roleTitle === '' || !in_array($roleTitle, $roleOptions, true)) {
        $error = 'Choose your main role.';
    } elseif (!$hasActiveGroup && !$groupIds) {
        $error = 'Choose your Group.';
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

            /*
             * Group selection is only allowed when the person has no active Group.
             * Once a Group exists, GLVs/admins manage changes so users cannot move
             * themselves between Groups or grant themselves access.
             */
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

            $stmt = $pdo->prepare("
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
                'details_json' => json_encode([
                    'group_ids' => !$hasActiveGroup ? $groupIds : $existingGroupIds,
                    'role_title' => $roleTitle,
                    'group_change_allowed' => !$hasActiveGroup,
                ], JSON_UNESCAPED_UNICODE),
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
                LEFT JOIN directory_profiles dp ON dp.person_id = p.id
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

if (!$hasActiveGroup) {
    $formGroupIds = $_POST['group_ids'] ?? $existingGroupIds;
} else {
    $formGroupIds = $existingGroupIds;
}

if (!is_array($formGroupIds)) {
    $formGroupIds = [];
}

$formGroupIds = array_map('intval', $formGroupIds);

$formAccreditations = $_POST['accreditations'] ?? portal_decode_json_list($profile['accreditations_json'] ?? null);
if (!is_array($formAccreditations)) {
    $formAccreditations = [];
}

$formSharePhone = isset($_POST['share_phone']) ? 1 : (int) ($profile['share_phone'] ?? 0);
$formVisible = isset($_POST['visible_in_directory']) ? 1 : (int) ($profile['visible_in_directory'] ?? 1);

$displayName = trim((string) ($profile['full_name'] ?? $user['full_name'] ?? $user['email'] ?? 'User'));
$initials = strtoupper(substr($displayName, 0, 1));
$photoUrl = profile_photo_url($user, $profile);
$microsoftProfileUrl = 'https://myaccount.microsoft.com/';

$pageTitle = 'Profile | ' . $appName;
$heroTitle = 'My profile';
$heroText = 'Keep your contact details, directory information and accreditations up to date.';
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
            grid-template-columns: minmax(280px, 340px) minmax(0, 1fr);
            align-items: start;
        }
    }

    .profile-summary-card {
        background: #ffffff;
        border: 1px solid #e6e6e6;
        border-radius: 1rem;
        padding: 1.25rem;
        box-shadow: 0 4px 18px rgba(0, 0, 0, .08);
    }

    .profile-photo-wrap {
        display: flex;
        justify-content: center;
        margin-bottom: 1rem;
    }

    .profile-photo {
        width: 132px;
        height: 132px;
        border-radius: 999px;
        overflow: hidden;
        background: var(--iv-purple, #7413dc);
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 3rem;
        font-weight: 900;
        border: 5px solid #f5f3ff;
        box-shadow: 0 8px 24px rgba(0, 0, 0, .18);
    }

    .profile-photo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .profile-summary-card h2 {
        margin: 0;
        color: var(--iv-purple-dark, #4d0b93);
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

    .profile-summary-list {
        display: grid;
        gap: .75rem;
        margin: 1rem 0;
    }

    .profile-summary-item {
        border-top: 1px solid #eeeeee;
        padding-top: .75rem;
    }

    .profile-summary-item span {
        display: block;
        color: #555;
        font-size: .85rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .02em;
    }

    .profile-summary-item strong {
        display: block;
        color: #1d1d1b;
        font-size: 1rem;
        font-weight: 900;
        margin-top: .1rem;
    }

    .profile-photo-note {
        margin: 1rem 0 0;
        padding: 1rem;
        border-radius: .75rem;
        background: #f5f3ff;
        color: #2f075c;
        font-weight: 700;
        line-height: 1.42;
    }

    .profile-photo-note a {
        font-weight: 900;
    }

    .profile-panel {
        background: #ffffff;
        border: 1px solid #e6e6e6;
        border-radius: 1rem;
        padding: 1.25rem;
        box-shadow: 0 4px 18px rgba(0, 0, 0, .06);
        margin-bottom: 1rem;
    }

    .profile-panel h2 {
        margin: 0 0 .85rem;
        color: var(--iv-purple-dark, #4d0b93);
        font-size: 1.35rem;
        font-weight: 900;
    }

    .profile-panel-intro {
        margin-top: -.35rem;
        color: #555;
        font-weight: 700;
    }

    .profile-group-list {
        display: grid;
        gap: .75rem;
        margin-top: 1rem;
    }

    .profile-group-card {
        border: 1px solid #e6e6e6;
        border-left: .35rem solid var(--iv-purple, #7413dc);
        border-radius: .65rem;
        padding: .9rem;
        background: #ffffff;
    }

    .profile-group-card h3 {
        margin: 0;
        color: var(--iv-purple-dark, #4d0b93);
        font-size: 1.05rem;
        font-weight: 900;
    }

    .profile-group-meta {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
        margin-top: .55rem;
    }

    .profile-pill {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        background: #f1f1f1;
        color: #333;
        padding: .25rem .55rem;
        font-size: .82rem;
        font-weight: 900;
    }

    .profile-pill-purple {
        background: #f5f3ff;
        color: var(--iv-purple-dark, #4d0b93);
    }

    .profile-warning {
        background: #fff3cd;
        color: #664d03;
        border: 1px solid #ffecb5;
        border-radius: .75rem;
        padding: 1rem;
        font-weight: 700;
        margin-top: 1rem;
    }

    .profile-check-grid {
        display: grid;
        gap: .6rem;
    }

    @media (min-width: 700px) {
        .profile-check-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .profile-save-bar {
        position: sticky;
        bottom: 0;
        z-index: 20;
        background: rgba(255, 255, 255, .96);
        border-top: 1px solid #e6e6e6;
        padding: 1rem 0 0;
        margin-top: 1rem;
    }

    .profile-save-bar .btn {
        width: 100%;
    }

    @media (min-width: 768px) {
        .profile-save-bar .btn {
            width: auto;
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
        <aside class="profile-summary-card">
            <div class="profile-photo-wrap">
                <span class="profile-photo" aria-hidden="true">
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
            </div>

            <h2><?= e($displayName) ?></h2>
            <p class="profile-email"><?= e($user['email'] ?? $profile['primary_email'] ?? '') ?></p>

            <div class="profile-summary-list">
                <div class="profile-summary-item">
                    <span>Main role</span>
                    <strong><?= e($formRoleTitle !== '' ? $formRoleTitle : 'Not set') ?></strong>
                </div>

                <div class="profile-summary-item">
                    <span>Directory visibility</span>
                    <strong><?= $formVisible === 1 ? 'Visible' : 'Hidden' ?></strong>
                </div>

                <div class="profile-summary-item">
                    <span>Phone sharing</span>
                    <strong><?= $formSharePhone === 1 ? 'Shared' : 'Not shared' ?></strong>
                </div>
            </div>

            <div class="profile-photo-note">
                <strong>Profile photo</strong><br>
                Photos are managed through your Microsoft 365 work or school account.
                <a href="<?= e($microsoftProfileUrl) ?>" target="_blank" rel="noopener noreferrer">
                    Change your photo in Microsoft 365
                </a>.
                It can take up to 24 hours for a new photo to appear across Microsoft 365 and this dashboard.
            </div>
        </aside>

        <section>
            <form method="post">
                <div class="profile-panel">
                    <h2>Account details</h2>
                    <p class="profile-panel-intro">Keep your basic contact details accurate so District and Group teams can contact you when needed.</p>

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
                        <small class="form-text text-muted">
                            This comes from your sign-in account. Contact your Group Lead Volunteer if it looks wrong.
                        </small>
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
                </div>

                <div class="profile-panel">
                    <h2>Group and access</h2>

                    <?php if ($hasActiveGroup): ?>
                        <p class="profile-panel-intro">
                            Your Group access is managed by your Group Lead Volunteer or a District Admin.
                        </p>

                        <div class="profile-group-list">
                            <?php foreach ($activeMemberships as $membership): ?>
                                <article class="profile-group-card">
                                    <h3><?= e($membership['group_name'] ?? 'Unknown Group') ?></h3>
                                    <div class="profile-group-meta">
                                        <span class="profile-pill profile-pill-purple">
                                            <?= e(profile_membership_role_label((string) ($membership['membership_role'] ?? 'member'))) ?>
                                        </span>
                                        <span class="profile-pill">
                                            <?= e(profile_access_label((string) ($membership['access_level'] ?? 'member'))) ?>
                                        </span>
                                        <?php if ((int) ($membership['is_primary'] ?? 0) === 1): ?>
                                            <span class="profile-pill">Primary Group</span>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <div class="profile-warning">
                            If your Group, role or access level is wrong, contact your Group Lead Volunteer.
                            You cannot change existing Group membership from this page.
                        </div>
                    <?php else: ?>
                        <p class="profile-panel-intro">
                            Choose your Group to complete your setup. Once set, changes must be made by your Group Lead Volunteer.
                        </p>

                        <div class="profile-check-grid">
                            <?php foreach ($groups as $group): ?>
                                <?php $groupId = (int) $group['id']; ?>
                                <label class="lt-check">
                                    <input
                                        type="checkbox"
                                        name="group_ids[]"
                                        value="<?= $groupId ?>"
                                        <?= in_array($groupId, $formGroupIds, true) ? 'checked' : '' ?>
                                    >
                                    <span><?= e($group['group_name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="profile-panel">
                    <h2>Directory</h2>
                    <p class="profile-panel-intro">
                        These details help other District volunteers find the right contact.
                    </p>

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
                        <small class="form-text text-muted">
                            This is your directory role label. It does not grant app permissions.
                        </small>
                    </div>

                    <label class="lt-check mb-2">
                        <input
                            type="checkbox"
                            id="visible_in_directory"
                            name="visible_in_directory"
                            value="1"
                            <?= $formVisible === 1 ? 'checked' : '' ?>
                        >
                        <span>Show me in the District Directory</span>
                    </label>

                    <label class="lt-check mb-3">
                        <input
                            type="checkbox"
                            id="share_phone"
                            name="share_phone"
                            value="1"
                            <?= $formSharePhone === 1 ? 'checked' : '' ?>
                        >
                        <span>Share my contact number in the Directory</span>
                    </label>

                    <div class="form-group mb-0">
                        <label for="about_me">About me</label>
                        <textarea
                            class="form-control"
                            id="about_me"
                            name="about_me"
                            rows="4"
                        ><?= e($formAboutMe) ?></textarea>
                    </div>
                </div>

                <div class="profile-panel">
                    <h2>Permits and accreditations</h2>
                    <p class="profile-panel-intro">
                        Select relevant permits, skills and accreditations so other volunteers can find support across the District.
                    </p>

                    <?php foreach ($accreditationOptions as $category => $items): ?>
                        <div class="lt-panel-grey mb-3">
                            <h3 class="h6 font-weight-bold"><?= e($category) ?></h3>
                            <div class="profile-check-grid">
                                <?php foreach ($items as $item): ?>
                                    <label class="lt-check">
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
                    <?php endforeach; ?>
                </div>

                <div class="profile-save-bar">
                    <button type="submit" class="btn btn-primary btn-lg lt-btn">Save profile</button>
                </div>
            </form>
        </section>
    </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>