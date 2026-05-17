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

$stmt = $pdo->prepare("\n    SELECT p.*, dp.role_title, dp.about_me, dp.accreditations_json, dp.share_phone, dp.visible_in_directory\n    FROM people p\n    LEFT JOIN directory_profiles dp ON dp.person_id = p.id\n    WHERE p.id = :person_id\n    LIMIT 1\n");
$stmt->execute(['person_id' => $personId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    throw new RuntimeException('Profile not found.');
}

$memberships = user_group_memberships($personId, false);
$existingGroupIds = array_map(static fn(array $m): int => (int) $m['group_id'], $memberships);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $roleTitle = trim((string) ($_POST['role_title'] ?? ''));
    $aboutMe = trim((string) ($_POST['about_me'] ?? ''));
    $sharePhone = isset($_POST['share_phone']) ? 1 : 0;
    $visibleInDirectory = isset($_POST['visible_in_directory']) ? 1 : 0;

    $postedGroupIds = $_POST['group_ids'] ?? [];
    if (!is_array($postedGroupIds)) { $postedGroupIds = []; }
    $groupIds = array_values(array_unique(array_filter(array_map('intval', $postedGroupIds), static fn(int $id): bool => $id > 0)));

    $postedAccreditations = $_POST['accreditations'] ?? [];
    if (!is_array($postedAccreditations)) { $postedAccreditations = []; }
    $cleanAccreditations = array_values(array_intersect(array_map('strval', $postedAccreditations), $allowedAccreditations));
    $accreditationsJson = json_encode($cleanAccreditations, JSON_UNESCAPED_UNICODE) ?: '[]';

    if ($fullName === '') {
        $error = 'Enter your name.';
    } elseif ($roleTitle === '' || !in_array($roleTitle, $roleOptions, true)) {
        $error = 'Choose your main role.';
    } elseif (!$groupIds) {
        $error = 'Choose at least one Group.';
    }

    if (!$error) {
        $membershipRole = portal_membership_role_from_title($roleTitle);
        $accessLevel = portal_access_level_from_membership_role($membershipRole);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE people SET full_name = :full_name, phone = :phone, status = 'active' WHERE id = :person_id");
            $stmt->execute(['full_name' => $fullName, 'phone' => $phone !== '' ? $phone : null, 'person_id' => $personId]);

            $stmt = $pdo->prepare("\n                INSERT INTO directory_profiles (person_id, role_title, about_me, accreditations_json, visible_in_directory, share_phone, profile_updated_at)\n                VALUES (:person_id, :role_title, :about_me, :accreditations_json, :visible_in_directory, :share_phone, NOW())\n                ON DUPLICATE KEY UPDATE\n                    role_title = VALUES(role_title),\n                    about_me = VALUES(about_me),\n                    accreditations_json = VALUES(accreditations_json),\n                    visible_in_directory = VALUES(visible_in_directory),\n                    share_phone = VALUES(share_phone),\n                    profile_updated_at = NOW()\n            ");
            $stmt->execute([
                'person_id' => $personId,
                'role_title' => $roleTitle,
                'about_me' => $aboutMe !== '' ? $aboutMe : null,
                'accreditations_json' => $accreditationsJson,
                'visible_in_directory' => $visibleInDirectory,
                'share_phone' => $sharePhone,
            ]);

            $stmt = $pdo->prepare("\n                UPDATE group_memberships\n                SET status = 'inactive', is_primary = 0\n                WHERE person_id = :person_id\n            ");
            $stmt->execute(['person_id' => $personId]);

            foreach ($groupIds as $index => $groupId) {
                $stmt = $pdo->prepare("\n                    INSERT INTO group_memberships (person_id, group_id, membership_role, access_level, status, is_primary, approved_at)\n                    VALUES (:person_id, :group_id, :membership_role, :access_level, 'active', :is_primary, NOW())\n                    ON DUPLICATE KEY UPDATE\n                        access_level = VALUES(access_level),\n                        status = 'active',\n                        is_primary = VALUES(is_primary),\n                        approved_at = COALESCE(approved_at, NOW())\n                ");
                $stmt->execute([
                    'person_id' => $personId,
                    'group_id' => $groupId,
                    'membership_role' => $membershipRole,
                    'access_level' => $accessLevel,
                    'is_primary' => $index === 0 ? 1 : 0,
                ]);
            }

            $stmt = $pdo->prepare("\n                INSERT INTO audit_log (actor_type, actor_person_id, action, entity_type, entity_id, details_json)\n                VALUES ('person', :person_id, 'profile_updated', 'person', :person_id, :details_json)\n            ");
            $stmt->execute([
                'person_id' => $personId,
                'details_json' => json_encode(['group_ids' => $groupIds, 'role_title' => $roleTitle], JSON_UNESCAPED_UNICODE),
            ]);

            $pdo->commit();
            refresh_current_user_session();
            $success = 'Profile updated.';

            $stmt = $pdo->prepare("\n                SELECT p.*, dp.role_title, dp.about_me, dp.accreditations_json, dp.share_phone, dp.visible_in_directory\n                FROM people p\n                LEFT JOIN directory_profiles dp ON dp.person_id = p.id\n                WHERE p.id = :person_id\n                LIMIT 1\n            ");
            $stmt->execute(['person_id' => $personId]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: $profile;
            $memberships = user_group_memberships($personId, false);
            $existingGroupIds = array_map(static fn(array $m): int => (int) $m['group_id'], $memberships);
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
$formGroupIds = $_POST['group_ids'] ?? $existingGroupIds;
if (!is_array($formGroupIds)) { $formGroupIds = []; }
$formGroupIds = array_map('intval', $formGroupIds);
$formAccreditations = $_POST['accreditations'] ?? portal_decode_json_list($profile['accreditations_json'] ?? null);
if (!is_array($formAccreditations)) { $formAccreditations = []; }
$formSharePhone = isset($_POST['share_phone']) ? 1 : (int) ($profile['share_phone'] ?? 0);
$formVisible = isset($_POST['visible_in_directory']) ? 1 : (int) ($profile['visible_in_directory'] ?? 1);

$pageTitle = 'Profile | ' . $appName;
$heroTitle = 'My profile';
$heroText = 'Keep your directory details, Group access and accreditations up to date.';
$breadcrumb = '<a href="/index.php">Home</a> / Profile';
?>
<?php include __DIR__ . '/header.php'; ?>

<main class="lt-main">
    <?php if ($error): ?><div class="alert alert-danger"><strong>There is a problem:</strong> <?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <form method="post" class="lt-panel">
        <h2 class="lt-section-title">Account details</h2>
        <div class="form-group">
            <label for="full_name">Name</label>
            <input class="form-control" type="text" id="full_name" name="full_name" value="<?= e($formFullName) ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Microsoft account email</label>
            <input class="form-control" type="email" id="email" value="<?= e($user['email']) ?>" disabled>
        </div>
        <div class="form-group">
            <label for="phone">Contact number</label>
            <input class="form-control" type="text" id="phone" name="phone" value="<?= e($formPhone) ?>">
        </div>

        <div class="lt-divider"></div>

        <h2 class="lt-section-title">Groups</h2>
        <p class="lt-lede">Group selection controls your access. Section-level information is metadata only and can be expanded later.</p>
        <div class="lt-check-list mt-3 mb-4">
            <?php foreach ($groups as $group): ?>
                <?php $groupId = (int) $group['id']; ?>
                <label class="lt-check">
                    <input type="checkbox" name="group_ids[]" value="<?= $groupId ?>" <?= in_array($groupId, $formGroupIds, true) ? 'checked' : '' ?>>
                    <span><?= e($group['group_name']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <h2 class="lt-section-title">Directory</h2>
        <div class="form-group">
            <label for="role_title">Main role</label>
            <select class="form-control" id="role_title" name="role_title" required>
                <option value="">Choose your role</option>
                <?php foreach ($roleOptions as $roleOption): ?>
                    <option value="<?= e($roleOption) ?>" <?= $formRoleTitle === $roleOption ? 'selected' : '' ?>><?= e($roleOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" id="visible_in_directory" name="visible_in_directory" value="1" <?= $formVisible === 1 ? 'checked' : '' ?>>
            <label class="form-check-label" for="visible_in_directory">Show me in the District Directory</label>
        </div>
        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" id="share_phone" name="share_phone" value="1" <?= $formSharePhone === 1 ? 'checked' : '' ?>>
            <label class="form-check-label" for="share_phone">Share my contact number</label>
        </div>
        <div class="form-group">
            <label for="about_me">About me</label>
            <textarea class="form-control" id="about_me" name="about_me" rows="3"><?= e($formAboutMe) ?></textarea>
        </div>

        <div class="lt-divider"></div>

        <h2 class="lt-section-title">Permits and accreditations</h2>
        <?php foreach ($accreditationOptions as $category => $items): ?>
            <div class="lt-panel-grey mb-3">
                <h3 class="h6 font-weight-bold"><?= e($category) ?></h3>
                <div class="lt-check-list">
                    <?php foreach ($items as $item): ?>
                        <label class="lt-check">
                            <input type="checkbox" name="accreditations[]" value="<?= e($item) ?>" <?= in_array($item, $formAccreditations, true) ? 'checked' : '' ?>>
                            <span><?= e($item) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary btn-lg lt-btn">Save profile</button>
    </form>
</main>

<?php include __DIR__ . '/footer.php'; ?>
