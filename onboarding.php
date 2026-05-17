<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
$pdo = db();
$error = null;

$personId = (int) $user['id'];
$email = trim((string) ($user['email'] ?? ''));
$displayName = trim((string) ($user['full_name'] ?? $email));

$roleOptions = portal_role_options();
$accreditationOptions = portal_accreditation_options();
$allowedAccreditations = portal_flatten_options($accreditationOptions);

$stmt = $pdo->query("\n    SELECT id, group_name\n    FROM groups\n    WHERE is_active = 1\n    ORDER BY group_name ASC\n");
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("\n    SELECT id, group_id, section_type, section_name\n    FROM group_sections\n    WHERE is_active = 1\n    ORDER BY group_id ASC, sort_order ASC, section_name ASC\n");
$sectionsByGroup = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $section) {
    $sectionsByGroup[(int) $section['group_id']][] = $section;
}

$stmt = $pdo->prepare("\n    SELECT p.*, dp.role_title, dp.about_me, dp.accreditations_json, dp.share_phone, dp.visible_in_directory\n    FROM people p\n    LEFT JOIN directory_profiles dp ON dp.person_id = p.id\n    WHERE p.id = :person_id\n    LIMIT 1\n");
$stmt->execute(['person_id' => $personId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$existingMemberships = user_group_memberships($personId, false);
$existingGroupIds = array_map(static fn(array $m): int => (int) $m['group_id'], $existingMemberships);

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
    $groupIds = array_values(array_unique(array_filter(array_map('intval', $postedGroupIds), static fn(int $id): bool => $id > 0)));

    $postedSectionIds = $_POST['section_ids'] ?? [];
    if (!is_array($postedSectionIds)) {
        $postedSectionIds = [];
    }
    $sectionIds = array_values(array_unique(array_filter(array_map('intval', $postedSectionIds), static fn(int $id): bool => $id > 0)));

    $postedAccreditations = $_POST['accreditations'] ?? [];
    if (!is_array($postedAccreditations)) {
        $postedAccreditations = [];
    }
    $cleanAccreditations = array_values(array_intersect(array_map('strval', $postedAccreditations), $allowedAccreditations));
    $accreditationsJson = json_encode($cleanAccreditations, JSON_UNESCAPED_UNICODE) ?: '[]';

    if ($fullName === '') {
        $error = 'Enter your name.';
    } elseif ($roleTitle === '' || !in_array($roleTitle, $roleOptions, true)) {
        $error = 'Choose your main role.';
    } elseif (!$groupIds) {
        $error = 'Choose at least one Group.';
    } else {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM groups WHERE is_active = 1 AND id IN ({$placeholders})");
        $stmt->execute($groupIds);
        if ((int) $stmt->fetchColumn() !== count($groupIds)) {
            $error = 'Choose valid active Groups.';
        }
    }

    if (!$error) {
        $membershipRole = portal_membership_role_from_title($roleTitle);
        $accessLevel = portal_access_level_from_membership_role($membershipRole);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("\n                UPDATE people\n                SET full_name = :full_name,\n                    phone = :phone,\n                    status = 'active'\n                WHERE id = :person_id\n            ");
            $stmt->execute([
                'full_name' => $fullName,
                'phone' => $phone !== '' ? $phone : null,
                'person_id' => $personId,
            ]);

            $stmt = $pdo->prepare("\n                INSERT INTO directory_profiles (person_id, role_title, about_me, accreditations_json, visible_in_directory, share_phone, profile_updated_at)\n                VALUES (:person_id, :role_title, :about_me, :accreditations_json, :visible_in_directory, :share_phone, NOW())\n                ON DUPLICATE KEY UPDATE\n                    role_title = VALUES(role_title),\n                    about_me = VALUES(about_me),\n                    accreditations_json = VALUES(accreditations_json),\n                    visible_in_directory = VALUES(visible_in_directory),\n                    share_phone = VALUES(share_phone),\n                    profile_updated_at = NOW()\n            ");
            $stmt->execute([
                'person_id' => $personId,
                'role_title' => $roleTitle,
                'about_me' => $aboutMe !== '' ? $aboutMe : null,
                'accreditations_json' => $accreditationsJson,
                'visible_in_directory' => $visibleInDirectory,
                'share_phone' => $sharePhone,
            ]);

            $membershipIdsByGroup = [];
            foreach ($groupIds as $index => $groupId) {
                $stmt = $pdo->prepare("\n                    INSERT INTO group_memberships (person_id, group_id, membership_role, access_level, status, is_primary, approved_at)\n                    VALUES (:person_id, :group_id, :membership_role, :access_level, 'active', :is_primary, NOW())\n                    ON DUPLICATE KEY UPDATE\n                        access_level = VALUES(access_level),\n                        status = 'active',\n                        is_primary = VALUES(is_primary),\n                        approved_at = COALESCE(approved_at, NOW())\n                ");
                $stmt->execute([
                    'person_id' => $personId,
                    'group_id' => $groupId,
                    'membership_role' => $membershipRole,
                    'access_level' => $accessLevel,
                    'is_primary' => $index === 0 ? 1 : 0,
                ]);

                $stmt = $pdo->prepare("\n                    SELECT id\n                    FROM group_memberships\n                    WHERE person_id = :person_id\n                      AND group_id = :group_id\n                      AND membership_role = :membership_role\n                    LIMIT 1\n                ");
                $stmt->execute([
                    'person_id' => $personId,
                    'group_id' => $groupId,
                    'membership_role' => $membershipRole,
                ]);
                $membershipIdsByGroup[$groupId] = (int) $stmt->fetchColumn();
            }

            if ($sectionIds) {
                $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
                $stmt = $pdo->prepare("SELECT id, group_id FROM group_sections WHERE id IN ({$placeholders})");
                $stmt->execute($sectionIds);
                $validSections = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($membershipIdsByGroup as $membershipId) {
                    $stmt = $pdo->prepare("DELETE FROM group_membership_sections WHERE group_membership_id = :membership_id");
                    $stmt->execute(['membership_id' => $membershipId]);
                }

                foreach ($validSections as $section) {
                    $groupId = (int) $section['group_id'];
                    if (!isset($membershipIdsByGroup[$groupId])) {
                        continue;
                    }

                    $stmt = $pdo->prepare("\n                        INSERT IGNORE INTO group_membership_sections (group_membership_id, group_section_id)\n                        VALUES (:membership_id, :section_id)\n                    ");
                    $stmt->execute([
                        'membership_id' => $membershipIdsByGroup[$groupId],
                        'section_id' => (int) $section['id'],
                    ]);
                }
            }

            $stmt = $pdo->prepare("\n                INSERT INTO audit_log (actor_type, actor_person_id, action, entity_type, entity_id, details_json)\n                VALUES ('person', :person_id, 'self_onboarding_completed', 'person', :person_id, :details_json)\n            ");
            $stmt->execute([
                'person_id' => $personId,
                'details_json' => json_encode(['group_ids' => $groupIds, 'role_title' => $roleTitle], JSON_UNESCAPED_UNICODE),
            ]);

            $pdo->commit();
            refresh_current_user_session();
            redirect('/index.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Your details could not be saved. Please try again.';
        }
    }
}

$formFullName = trim((string) ($_POST['full_name'] ?? ($profile['full_name'] ?? $displayName)));
$formPhone = trim((string) ($_POST['phone'] ?? ($profile['phone'] ?? '')));
$formRoleTitle = trim((string) ($_POST['role_title'] ?? ($profile['role_title'] ?? '')));
$formAboutMe = trim((string) ($_POST['about_me'] ?? ($profile['about_me'] ?? '')));
$formGroupIds = $_POST['group_ids'] ?? $existingGroupIds;
$formSectionIds = $_POST['section_ids'] ?? [];
$formAccreditations = $_POST['accreditations'] ?? portal_decode_json_list($profile['accreditations_json'] ?? null);
$formSharePhone = isset($_POST['share_phone']) ? 1 : (int) ($profile['share_phone'] ?? 0);
$formVisible = isset($_POST['visible_in_directory']) ? 1 : (int) ($profile['visible_in_directory'] ?? 1);

if (!is_array($formGroupIds)) { $formGroupIds = []; }
if (!is_array($formSectionIds)) { $formSectionIds = []; }
if (!is_array($formAccreditations)) { $formAccreditations = []; }
$formGroupIds = array_map('intval', $formGroupIds);
$formSectionIds = array_map('intval', $formSectionIds);

$pageTitle = 'Complete your profile | ' . $appName;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css" integrity="sha384-5Kguc7IDQdynmm22yUyn9psYyP8LQhAWCCKJT/RrZJAWqdUAw5eADwc25JoYsXH6" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/leader-tool.css">
</head>
<body>
<header class="lt-header">
    <div class="lt-header-inner">
        <a class="lt-brand" href="/index.php">
            <img src="/assets/img/black-ir-logo.png" alt="Irwell Valley District Scouts" onerror="this.style.display='none';">
            <span><span class="lt-brand-title">Leader Tool</span><span class="lt-brand-subtitle">Account setup</span></span>
        </a>
        <nav class="lt-nav"><a href="/logout.php">Sign out</a></nav>
    </div>
</header>
<section class="lt-hero">
    <div class="lt-hero-inner">
        <h1>Complete your profile</h1>
        <p>Tell us who you are and which Group or Groups you work with. This creates your access for the main Leader Tool.</p>
    </div>
</section>

<main class="lt-main">
    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><strong>There is a problem:</strong> <?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="lt-panel" novalidate>
        <section>
            <h2 class="lt-section-title">Your details</h2>
            <div class="form-group">
                <label for="full_name">Name</label>
                <input type="text" class="form-control" id="full_name" name="full_name" value="<?= e($formFullName) ?>" required>
            </div>
            <div class="form-group">
                <label for="email">Microsoft account email</label>
                <input type="email" class="form-control" id="email" value="<?= e($email) ?>" disabled>
                <small class="form-text text-muted">This comes from your Microsoft sign-in and cannot be changed here.</small>
            </div>
            <div class="form-group">
                <label for="phone">Contact number</label>
                <input type="text" class="form-control" id="phone" name="phone" value="<?= e($formPhone) ?>">
            </div>
        </section>

        <div class="lt-divider"></div>

        <section>
            <h2 class="lt-section-title">Group access</h2>
            <p class="lt-lede">Choose every Group you need access to. We trust tenant users to choose the right Group; this can be corrected later if needed.</p>
            <div class="lt-check-list mt-3">
                <?php foreach ($groups as $group): ?>
                    <?php $groupId = (int) $group['id']; ?>
                    <label class="lt-check">
                        <input type="checkbox" name="group_ids[]" value="<?= $groupId ?>" <?= in_array($groupId, $formGroupIds, true) ? 'checked' : '' ?>>
                        <span><?= e($group['group_name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="lt-divider"></div>

        <section>
            <h2 class="lt-section-title">Role and section information</h2>
            <p class="lt-lede">This helps with directory search and targeted emails. It does not limit your access within a Group.</p>
            <div class="form-group mt-3">
                <label for="role_title">Main role</label>
                <select class="form-control" id="role_title" name="role_title" required>
                    <option value="">Choose your role</option>
                    <?php foreach ($roleOptions as $roleOption): ?>
                        <option value="<?= e($roleOption) ?>" <?= $formRoleTitle === $roleOption ? 'selected' : '' ?>><?= e($roleOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($sectionsByGroup): ?>
                <fieldset class="mt-4">
                    <legend class="h5">Sections you work with</legend>
                    <p class="form-text text-muted">Optional. Used for targeted emails such as Cub leaders or Explorer volunteers.</p>
                    <?php foreach ($groups as $group): ?>
                        <?php $groupId = (int) $group['id']; ?>
                        <?php if (empty($sectionsByGroup[$groupId])) { continue; } ?>
                        <div class="lt-panel-grey mb-3">
                            <h3 class="h6 font-weight-bold"><?= e($group['group_name']) ?></h3>
                            <div class="lt-check-list">
                                <?php foreach ($sectionsByGroup[$groupId] as $section): ?>
                                    <?php $sectionId = (int) $section['id']; ?>
                                    <label class="lt-check">
                                        <input type="checkbox" name="section_ids[]" value="<?= $sectionId ?>" <?= in_array($sectionId, $formSectionIds, true) ? 'checked' : '' ?>>
                                        <span><?= e($section['section_name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>
        </section>

        <div class="lt-divider"></div>

        <section>
            <h2 class="lt-section-title">Directory details</h2>
            <div class="form-group form-check">
                <input type="checkbox" class="form-check-input" id="visible_in_directory" name="visible_in_directory" value="1" <?= $formVisible === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="visible_in_directory">Show me in the District Directory</label>
            </div>
            <div class="form-group form-check">
                <input type="checkbox" class="form-check-input" id="share_phone" name="share_phone" value="1" <?= $formSharePhone === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="share_phone">Share my contact number in the District Directory</label>
            </div>
            <div class="form-group">
                <label for="about_me">About me</label>
                <textarea class="form-control" id="about_me" name="about_me" rows="3"><?= e($formAboutMe) ?></textarea>
            </div>
        </section>

        <div class="lt-divider"></div>

        <section>
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
        </section>

        <div class="mt-4">
            <button type="submit" class="btn btn-primary btn-lg lt-btn">Save and continue</button>
        </div>
    </form>
</main>

<?php include __DIR__ . '/footer.php'; ?>
