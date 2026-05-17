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

function onboarding_table_exists(string $table): bool
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

function onboarding_profile_photo_url(array $user, array $profile): string
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

function onboarding_audit(int $personId, array $details): void
{
    if (!onboarding_table_exists('audit_log')) {
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
                'self_onboarding_completed',
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
        // Do not fail onboarding because audit logging failed.
    }
}

$stmt = $pdo->query("
    SELECT id, group_name
    FROM groups
    WHERE is_active = 1
    ORDER BY group_name ASC
");
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("
    SELECT id, group_id, section_type, section_name
    FROM group_sections
    WHERE is_active = 1
    ORDER BY group_id ASC, sort_order ASC, section_name ASC
");

$sectionsByGroup = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $section) {
    $sectionsByGroup[(int) $section['group_id']][] = $section;
}

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
$profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$existingMemberships = user_group_memberships($personId, false);

$activeExistingMemberships = array_values(array_filter(
    $existingMemberships,
    static fn(array $membership): bool => ($membership['status'] ?? 'active') === 'active'
));

$existingGroupIds = array_map(static fn(array $m): int => (int) $m['group_id'], $activeExistingMemberships);

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

    $postedSectionIds = $_POST['section_ids'] ?? [];

    if (!is_array($postedSectionIds)) {
        $postedSectionIds = [];
    }

    $sectionIds = array_values(array_unique(array_filter(
        array_map('intval', $postedSectionIds),
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

    sort($cleanAccreditations);

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
             * Onboarding is the initial self-service Group selection flow.
             * After this, profile.php displays Group access as read-only.
             */
            $membershipIdsByGroup = [];

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

                $stmt = $pdo->prepare("
                    SELECT id
                    FROM group_memberships
                    WHERE person_id = :person_id
                      AND group_id = :group_id
                    LIMIT 1
                ");
                $stmt->execute([
                    'person_id' => $personId,
                    'group_id' => $groupId,
                ]);

                $membershipId = (int) $stmt->fetchColumn();

                if ($membershipId > 0) {
                    $membershipIdsByGroup[$groupId] = $membershipId;
                }
            }

            foreach ($membershipIdsByGroup as $membershipId) {
                $stmt = $pdo->prepare("
                    DELETE FROM group_membership_sections
                    WHERE group_membership_id = :membership_id
                ");
                $stmt->execute(['membership_id' => $membershipId]);
            }

            if ($sectionIds) {
                $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
                $stmt = $pdo->prepare("
                    SELECT id, group_id
                    FROM group_sections
                    WHERE is_active = 1
                      AND id IN ({$placeholders})
                ");
                $stmt->execute($sectionIds);
                $validSections = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($validSections as $section) {
                    $groupId = (int) $section['group_id'];

                    if (!isset($membershipIdsByGroup[$groupId])) {
                        continue;
                    }

                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO group_membership_sections (
                            group_membership_id,
                            group_section_id
                        )
                        VALUES (
                            :membership_id,
                            :section_id
                        )
                    ");
                    $stmt->execute([
                        'membership_id' => $membershipIdsByGroup[$groupId],
                        'section_id' => (int) $section['id'],
                    ]);
                }
            }

            onboarding_audit($personId, [
                'group_ids' => $groupIds,
                'section_ids' => $sectionIds,
                'role_title' => $roleTitle,
                'accreditation_count' => count($cleanAccreditations),
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

if (!is_array($formGroupIds)) {
    $formGroupIds = [];
}

if (!is_array($formSectionIds)) {
    $formSectionIds = [];
}

if (!is_array($formAccreditations)) {
    $formAccreditations = [];
}

$formGroupIds = array_map('intval', $formGroupIds);
$formSectionIds = array_map('intval', $formSectionIds);

$formAccreditations = array_values(array_intersect(
    array_map('strval', $formAccreditations),
    $allowedAccreditations
));

sort($formAccreditations);

$initials = strtoupper(substr($displayName !== '' ? $displayName : 'U', 0, 1));
$photoUrl = onboarding_profile_photo_url($user, $profile);
$microsoftProfileUrl = 'https://myaccount.microsoft.com/';

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

    <style>
        .onboarding-header {
            background: #ffffff;
            border-bottom: 1px solid #e6e6e6;
        }

        .onboarding-header-inner {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            min-height: 82px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: .65rem 0;
        }

        .onboarding-brand {
            display: inline-flex;
            align-items: center;
            gap: .75rem;
            text-decoration: none;
            color: #1d1d1b;
        }

        .onboarding-brand:hover,
        .onboarding-brand:focus {
            color: #1d1d1b;
            text-decoration: none;
        }

        .onboarding-brand img {
            display: block;
            height: 68px;
            width: auto;
            max-width: 230px;
            object-fit: contain;
        }

        .onboarding-brand span {
            display: none;
            font-weight: 900;
        }

        .onboarding-signout {
            font-weight: 900;
            color: #4d0b93;
        }

        .onboarding-hero {
            background: #f7f5fb;
            border-bottom: 1px solid #e6e6e6;
        }

        .onboarding-hero-inner {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            padding: 1.6rem 0;
        }

        .onboarding-hero h1 {
            margin: 0;
            color: #4d0b93;
            font-size: clamp(2rem, 6vw, 3.2rem);
            line-height: 1.05;
            font-weight: 900;
        }

        .onboarding-hero p {
            margin: .65rem 0 0;
            max-width: 780px;
            color: #333;
            font-size: 1.05rem;
            font-weight: 700;
            line-height: 1.45;
        }

        .onboarding-layout {
            display: grid;
            gap: 1rem;
        }

        @media (min-width: 992px) {
            .onboarding-layout {
                grid-template-columns: minmax(260px, 340px) minmax(0, 1fr);
                align-items: start;
            }
        }

        .onboarding-side-card {
            background: #ffffff;
            border: 1px solid #e6e6e6;
            border-radius: .75rem;
            padding: 1.25rem;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .05);
        }

        @media (min-width: 992px) {
            .onboarding-side-card {
                position: sticky;
                top: 1rem;
            }
        }

        .onboarding-photo-link {
            position: relative;
            display: block;
            width: 132px;
            height: 132px;
            margin: 0 auto 1rem;
            border-radius: 999px;
            overflow: hidden;
            background: #7413dc;
            color: #ffffff;
            text-decoration: none;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .12);
        }

        .onboarding-photo-link:hover,
        .onboarding-photo-link:focus {
            color: #ffffff;
            text-decoration: none;
            outline: 4px solid #ffdd00;
            outline-offset: 3px;
        }

        .onboarding-photo {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 900;
        }

        .onboarding-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .onboarding-photo-overlay {
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

        .onboarding-photo-link:hover .onboarding-photo-overlay,
        .onboarding-photo-link:focus .onboarding-photo-overlay {
            opacity: 1;
        }

        .onboarding-side-card h2 {
            margin: 0;
            color: #4d0b93;
            font-size: 1.45rem;
            font-weight: 900;
            text-align: center;
            line-height: 1.15;
        }

        .onboarding-email {
            margin: .35rem 0 1rem;
            color: #555;
            font-weight: 700;
            text-align: center;
            overflow-wrap: anywhere;
        }

        .onboarding-steps {
            margin: 1rem 0 0;
            padding-left: 1.25rem;
            font-weight: 800;
        }

        .onboarding-steps li + li {
            margin-top: .35rem;
        }

        .onboarding-photo-note {
            background: #f7f5fb;
            border-left: 5px solid #7413dc;
            padding: .9rem;
            margin-top: 1rem;
            font-weight: 700;
        }

        .onboarding-main {
            display: grid;
            gap: 1rem;
        }

        .onboarding-panel {
            background: #ffffff;
            border: 1px solid #e6e6e6;
            border-radius: .75rem;
            padding: 1.25rem;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .05);
        }

        .onboarding-panel h2 {
            margin: 0 0 .85rem;
            color: #4d0b93;
            font-size: 1.35rem;
            font-weight: 900;
        }

        .onboarding-panel-intro {
            margin-top: -.35rem;
            color: #555;
            font-weight: 700;
        }

        .onboarding-check-grid {
            display: grid;
            gap: .55rem;
        }

        @media (min-width: 700px) {
            .onboarding-check-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        .onboarding-group-section {
            display: none;
        }

        .onboarding-group-section.is-visible {
            display: block;
        }

        .onboarding-accreditation-toolbar {
            display: grid;
            gap: .75rem;
            margin-bottom: 1rem;
        }

        @media (min-width: 768px) {
            .onboarding-accreditation-toolbar {
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: end;
            }
        }

        .onboarding-selected-summary {
            background: #f7f5fb;
            border: 1px solid #e6e6e6;
            border-radius: .5rem;
            padding: .75rem;
            margin-bottom: 1rem;
        }

        .onboarding-selected-summary strong {
            display: block;
            color: #4d0b93;
            font-weight: 900;
            margin-bottom: .35rem;
        }

        .onboarding-selected-tags {
            display: flex;
            flex-wrap: wrap;
            gap: .35rem;
        }

        .onboarding-selected-tag {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: #ffffff;
            border: 1px solid #d8d8d8;
            color: #333;
            padding: .25rem .55rem;
            font-size: .82rem;
            font-weight: 800;
        }

        .onboarding-accreditation-category {
            border: 1px solid #e6e6e6;
            border-radius: .5rem;
            margin-bottom: .75rem;
            background: #ffffff;
            overflow: hidden;
        }

        .onboarding-accreditation-category summary {
            cursor: pointer;
            padding: .85rem 1rem;
            background: #f7f5fb;
            color: #4d0b93;
            font-weight: 900;
            list-style: none;
        }

        .onboarding-accreditation-category summary::-webkit-details-marker {
            display: none;
        }

        .onboarding-accreditation-category summary::after {
            content: "Show";
            float: right;
            color: #555;
            font-size: .9rem;
        }

        .onboarding-accreditation-category[open] summary::after {
            content: "Hide";
        }

        .onboarding-accreditation-list {
            padding: 1rem;
        }

        .onboarding-accreditation-item.is-hidden {
            display: none;
        }

        .onboarding-accreditation-empty {
            display: none;
            color: #555;
            font-weight: 700;
            margin: 0;
        }

        .onboarding-accreditation-category.no-results .onboarding-accreditation-empty {
            display: block;
        }

        .onboarding-save-row {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            align-items: center;
        }

        @media (max-width: 575.98px) {
            .onboarding-save-row .btn {
                width: 100%;
            }
        }

        @media (max-width: 520px) {
            .onboarding-brand img {
                height: 54px;
                max-width: 170px;
            }
        }
    </style>
</head>
<body>
<header class="onboarding-header">
    <div class="onboarding-header-inner">
        <a class="onboarding-brand" href="/index.php">
            <img src="/assets/img/black-ir-logo.png" alt="Irwell Valley District Scouts" onerror="this.style.display='none';">
            <span>Account setup</span>
        </a>

        <a class="onboarding-signout" href="/logout.php">Sign out</a>
    </div>
</header>

<section class="onboarding-hero">
    <div class="onboarding-hero-inner">
        <h1>Complete your profile</h1>
        <p>
            Tell us who you are and which Group or Groups you work with.
            This sets up your District Dashboard access.
        </p>
    </div>
</section>

<main class="lt-main">
    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert">
            <strong>There is a problem:</strong> <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="onboarding-layout">
        <aside class="onboarding-side-card">
            <a
                class="onboarding-photo-link"
                href="<?= e($microsoftProfileUrl) ?>"
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Update your profile photo in Microsoft 365"
            >
                <span class="onboarding-photo">
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
                <span class="onboarding-photo-overlay">Update in Microsoft 365</span>
            </a>

            <h2><?= e($formFullName !== '' ? $formFullName : $displayName) ?></h2>
            <p class="onboarding-email"><?= e($email) ?></p>

            <ol class="onboarding-steps">
                <li>Check your contact details.</li>
                <li>Choose your Group access.</li>
                <li>Add directory and accreditation details.</li>
                <li>Save to open the dashboard.</li>
            </ol>

            <div class="onboarding-photo-note">
                Profile photos are managed through Microsoft 365.
                Changes can take up to 24 hours to appear fully across Microsoft services and this dashboard.
            </div>
        </aside>

        <section class="onboarding-main">
            <form method="post" novalidate>
                <section class="onboarding-panel">
                    <h2>Your details</h2>

                    <div class="form-group">
                        <label for="full_name">Name</label>
                        <input
                            type="text"
                            class="form-control"
                            id="full_name"
                            name="full_name"
                            value="<?= e($formFullName) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="email">Microsoft account email</label>
                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            value="<?= e($email) ?>"
                            disabled
                        >
                        <small class="form-text text-muted">
                            This comes from your Microsoft sign-in and cannot be changed here.
                        </small>
                    </div>

                    <div class="form-group mb-0">
                        <label for="phone">Contact number</label>
                        <input
                            type="text"
                            class="form-control"
                            id="phone"
                            name="phone"
                            value="<?= e($formPhone) ?>"
                        >
                    </div>
                </section>

                <section class="onboarding-panel">
                    <h2>Group access</h2>
                    <p class="onboarding-panel-intro">
                        Choose every Group you need access to. After setup, changes to Group access must be made by your Group Lead Volunteer.
                    </p>

                    <div class="onboarding-check-grid mt-3">
                        <?php foreach ($groups as $group): ?>
                            <?php $groupId = (int) $group['id']; ?>
                            <label class="lt-check">
                                <input
                                    type="checkbox"
                                    name="group_ids[]"
                                    value="<?= $groupId ?>"
                                    data-group-toggle="<?= $groupId ?>"
                                    <?= in_array($groupId, $formGroupIds, true) ? 'checked' : '' ?>
                                >
                                <span><?= e($group['group_name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="onboarding-panel">
                    <h2>Role and section information</h2>
                    <p class="onboarding-panel-intro">
                        This helps with directory search and targeted emails. It does not limit your access inside a Group.
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
                    </div>

                    <?php if ($sectionsByGroup): ?>
                        <fieldset class="mt-4">
                            <legend class="h5 font-weight-bold">Sections you work with</legend>
                            <p class="form-text text-muted">
                                Optional. Used for targeted emails such as Cub leaders or Explorer volunteers.
                            </p>

                            <?php foreach ($groups as $group): ?>
                                <?php $groupId = (int) $group['id']; ?>

                                <?php if (empty($sectionsByGroup[$groupId])) {
                                    continue;
                                } ?>

                                <div
                                    class="lt-panel-grey mb-3 onboarding-group-section"
                                    data-section-group="<?= $groupId ?>"
                                >
                                    <h3 class="h6 font-weight-bold"><?= e($group['group_name']) ?></h3>

                                    <div class="onboarding-check-grid">
                                        <?php foreach ($sectionsByGroup[$groupId] as $section): ?>
                                            <?php $sectionId = (int) $section['id']; ?>
                                            <label class="lt-check">
                                                <input
                                                    type="checkbox"
                                                    name="section_ids[]"
                                                    value="<?= $sectionId ?>"
                                                    <?= in_array($sectionId, $formSectionIds, true) ? 'checked' : '' ?>
                                                >
                                                <span><?= e($section['section_name']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endif; ?>
                </section>

                <section class="onboarding-panel">
                    <h2>Directory details</h2>

                    <div class="form-group form-check">
                        <input
                            type="checkbox"
                            class="form-check-input"
                            id="visible_in_directory"
                            name="visible_in_directory"
                            value="1"
                            <?= $formVisible === 1 ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="visible_in_directory">
                            Show me in the District Directory
                        </label>
                    </div>

                    <div class="form-group form-check">
                        <input
                            type="checkbox"
                            class="form-check-input"
                            id="share_phone"
                            name="share_phone"
                            value="1"
                            <?= $formSharePhone === 1 ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="share_phone">
                            Share my contact number in the District Directory
                        </label>
                    </div>

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

                <section class="onboarding-panel">
                    <h2>Permits and accreditations</h2>
                    <p class="onboarding-panel-intro">
                        Search or open a category to select the permits, skills and accreditations you want shown in the Directory.
                    </p>

                    <div class="onboarding-accreditation-toolbar">
                        <div class="form-group mb-md-0">
                            <label for="accreditation_search">Search accreditations</label>
                            <input
                                type="search"
                                id="accreditation_search"
                                class="form-control"
                                placeholder="Search permits, skills or accreditations"
                            >
                        </div>

                        <div>
                            <button type="button" class="btn lt-btn lt-btn-secondary" id="clear_accreditation_search">
                                Clear search
                            </button>
                        </div>
                    </div>

                    <div class="onboarding-selected-summary">
                        <strong>
                            Selected accreditations:
                            <span id="selected_accreditation_count"><?= count($formAccreditations) ?></span>
                        </strong>

                        <div class="onboarding-selected-tags" id="selected_accreditation_tags">
                            <?php if ($formAccreditations): ?>
                                <?php foreach ($formAccreditations as $item): ?>
                                    <span class="onboarding-selected-tag"><?= e($item) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="onboarding-selected-tag">No accreditations selected</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php foreach ($accreditationOptions as $category => $items): ?>
                        <?php $selectedInCategory = count(array_intersect($items, $formAccreditations)); ?>

                        <details class="onboarding-accreditation-category" <?= $selectedInCategory > 0 ? 'open' : '' ?>>
                            <summary>
                                <?= e($category) ?>
                                <span>
                                    (<span data-category-count="<?= e($category) ?>"><?= (int) $selectedInCategory ?></span> selected)
                                </span>
                            </summary>

                            <div class="onboarding-accreditation-list">
                                <p class="onboarding-accreditation-empty">
                                    No matching accreditations in this category.
                                </p>

                                <div class="onboarding-check-grid">
                                    <?php foreach ($items as $item): ?>
                                        <label
                                            class="lt-check onboarding-accreditation-item"
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

                <section class="onboarding-panel">
                    <div class="onboarding-save-row">
                        <button type="submit" class="btn btn-primary btn-lg lt-btn">
                            Save and continue
                        </button>

                        <span class="text-muted font-weight-bold">
                            You can update directory details later from My profile.
                        </span>
                    </div>
                </section>
            </form>
        </section>
    </div>
</main>

<script>
(function () {
    var searchInput = document.getElementById('accreditation_search');
    var clearButton = document.getElementById('clear_accreditation_search');
    var selectedCount = document.getElementById('selected_accreditation_count');
    var selectedTags = document.getElementById('selected_accreditation_tags');

    function normalise(value) {
        return String(value || '').toLowerCase().trim();
    }

    function updateSectionVisibility() {
        var selectedGroups = {};

        document.querySelectorAll('[data-group-toggle]').forEach(function (checkbox) {
            if (checkbox.checked) {
                selectedGroups[checkbox.getAttribute('data-group-toggle')] = true;
            }
        });

        document.querySelectorAll('[data-section-group]').forEach(function (panel) {
            var groupId = panel.getAttribute('data-section-group');
            var visible = !!selectedGroups[groupId];

            panel.classList.toggle('is-visible', visible);

            if (!visible) {
                panel.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
                    checkbox.checked = false;
                });
            }
        });
    }

    function updateSearch() {
        var query = normalise(searchInput ? searchInput.value : '');

        document.querySelectorAll('.onboarding-accreditation-category').forEach(function (category) {
            var visibleCount = 0;

            category.querySelectorAll('.onboarding-accreditation-item').forEach(function (item) {
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
                empty.className = 'onboarding-selected-tag';
                empty.textContent = 'No accreditations selected';
                selectedTags.appendChild(empty);
            } else {
                checked.forEach(function (checkbox) {
                    var tag = document.createElement('span');
                    tag.className = 'onboarding-selected-tag';
                    tag.textContent = checkbox.value;
                    selectedTags.appendChild(tag);
                });
            }
        }

        var categoryCounts = {};

        document.querySelectorAll('.onboarding-accreditation-item').forEach(function (item) {
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

    document.querySelectorAll('[data-group-toggle]').forEach(function (checkbox) {
        checkbox.addEventListener('change', updateSectionVisibility);
    });

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

    updateSectionVisibility();
    updateSearch();
    updateSelectedSummary();
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>