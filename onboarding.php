<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley District Scouts');

$pdo = db();

$error = null;

$email = trim((string) ($user['email'] ?? ''));
$displayName = trim((string) ($user['full_name'] ?? $email));

/*
|--------------------------------------------------------------------------
| Role options
|--------------------------------------------------------------------------
*/

$roleOptions = [
    'Assistant Group Scout Leader – Group Leadership Team Member',
    'Assistant Section Leader – Section Team Member',
    'Chair - Chair',
    'Chaplain - Volunteering Development Team Member',
    'County/Area/Region(Scotland) Commissioner – County/Area/Region(Scotland) Lead Volunteer',
    'Deputy Chair - Trustee',
    'Deputy Group Scout Leader – Group Leadership Team Member',
    'District Commissioner – District Lead Volunteer',
    'District Explorer Scout Administrator - 14-24 Team Member',
    'District/County/Area/Region(Scotland) Skills Instructor – Programme Team Member',
    'Early Years Section Leader - Section Team Member (of the Squirrels Team)',
    'Executive Committee Member - Trustee',
    'Explorer Scout Administrator – 14-24 Team Member',
    'Group Scout Leader – Group Lead Volunteer',
    'Group Skills Instructor - Group Leadership Team Member',
    'Secretary - Trustee',
    'Section Assistant – Section Team Member',
    'Section Leader – Section Team Leader',
    'Treasurer - Treasurer',
    'Youth Commissioner – Youth Lead',
];

/*
|--------------------------------------------------------------------------
| Accreditation / permit options
|--------------------------------------------------------------------------
|
| Stored as JSON in group_contacts.accreditations.
|
*/

$accreditationOptions = [
    'Nights Away' => [
        'Nights Away Permit Holder',
        'Nights Away Adviser',
        'Greenfield Nights Away',
        'Lightweight Expedition Nights Away',
        'Indoor Nights Away',
        'Campsite Nights Away',
    ],

    'Activity Permits' => [
        'Archery Permit',
        'Air Rifle Shooting Permit',
        'Tomahawk Throwing Permit',
        'Climbing Permit',
        'Abseiling Permit',
        'Bouldering Permit',
        'Caving Permit',
        'Hillwalking Permit',
        'Mountain Biking Permit',
        'Canoeing Permit',
        'Kayaking Permit',
        'Stand Up Paddleboarding Permit',
        'Rafting Permit',
        'Sailing Permit',
        'Windsurfing Permit',
        'Powerboating Permit',
        'Pulling / Rowing Permit',
        'Bell Boating Permit',
    ],

    'Training / Support' => [
        'First Response Trainer',
        'First Response Assessor',
        'Safeguarding Trainer',
        'Safety Trainer',
        'Learning Assessor',
        'Training Adviser',
        'Skills Instructor',
        'Activity Assessor',
        'Permit Assessor',
    ],

    'Other' => [
        'Minibus Driver',
        'D1 Driver',
        'Trailer Towing',
        'Food Hygiene',
        'Event First Aid',
        'Mental Health First Aid',
    ],
];

function onboarding_flatten_accreditation_options(array $options): array
{
    $flat = [];

    foreach ($options as $items) {
        foreach ($items as $item) {
            $flat[] = $item;
        }
    }

    return $flat;
}

/*
|--------------------------------------------------------------------------
| Load active groups
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT id, group_name
    FROM groups
    WHERE is_active = 1
    ORDER BY group_name ASC
");

$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Existing contact record, if one exists
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM group_contacts
    WHERE LOWER(email) = LOWER(:email)
    LIMIT 1
");

$stmt->execute([
    'email' => $email,
]);

$existingContact = $stmt->fetch(PDO::FETCH_ASSOC);

$existingGroupId = (int) ($existingContact['group_id'] ?? 0);
$groupIsLocked = $existingGroupId > 0;

/*
|--------------------------------------------------------------------------
| Handle save
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
    $scoutRole = trim((string) ($_POST['scout_role'] ?? ''));
    $aboutMe = trim((string) ($_POST['about_me'] ?? ''));
    $shareContactNumber = isset($_POST['share_contact_number']) ? 1 : 0;

    if ($groupIsLocked) {
        $groupId = $existingGroupId;
    } else {
        $groupId = (int) ($_POST['group_id'] ?? 0);
    }

    $postedAccreditations = $_POST['accreditations'] ?? [];

    if (!is_array($postedAccreditations)) {
        $postedAccreditations = [];
    }

    $allowedAccreditations = onboarding_flatten_accreditation_options($accreditationOptions);

    $cleanAccreditations = array_values(array_intersect(
        array_map('strval', $postedAccreditations),
        $allowedAccreditations
    ));

    $accreditationsJson = json_encode($cleanAccreditations, JSON_UNESCAPED_UNICODE);

    if ($accreditationsJson === false) {
        $accreditationsJson = '[]';
    }

    if ($fullName === '') {
        $error = 'Please enter your name.';
    } elseif ($groupId <= 0) {
        $error = 'Please choose your group.';
    } elseif ($scoutRole === '') {
        $error = 'Please choose your Scout role.';
    } elseif (!in_array($scoutRole, $roleOptions, true)) {
        $error = 'Please choose a valid Scout role.';
    } else {
        $stmt = $pdo->prepare("
            SELECT id
            FROM groups
            WHERE id = :id
              AND is_active = 1
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $groupId,
        ]);

        if (!$stmt->fetchColumn()) {
            $error = 'Please choose a valid group.';
        }
    }

    if (!$error) {
        /*
         * Keep admin_users full_name in sync.
         */
        $stmt = $pdo->prepare("
            UPDATE admin_users
            SET full_name = :full_name
            WHERE id = :id
        ");

        $stmt->execute([
            'full_name' => $fullName,
            'id' => (int) $user['id'],
        ]);

        $_SESSION['portal_user']['full_name'] = $fullName;

        /*
         * Link user to group for portal/DC access.
         */
        $stmt = $pdo->prepare("
            INSERT INTO user_groups (
                user_id,
                group_id,
                source,
                status,
                is_primary,
                created_at
            ) VALUES (
                :user_id,
                :group_id,
                'self_selected',
                'pending',
                1,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                group_id = VALUES(group_id),
                is_primary = VALUES(is_primary),
                source = VALUES(source)
        ");

        $stmt->execute([
            'user_id' => (int) $user['id'],
            'group_id' => $groupId,
        ]);

        /*
         * Create/update directory contact profile matched by email.
         */
        if ($existingContact) {
            $stmt = $pdo->prepare("
                UPDATE group_contacts
                SET group_id = :group_id,
                    full_name = :full_name,
                    contact_number = :contact_number,
                    scout_role = :scout_role,
                    about_me = :about_me,
                    accreditations = :accreditations,
                    share_contact_number = :share_contact_number,
                    profile_updated_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                'group_id' => $groupId,
                'full_name' => $fullName,
                'contact_number' => $contactNumber,
                'scout_role' => $scoutRole,
                'about_me' => $aboutMe,
                'accreditations' => $accreditationsJson,
                'share_contact_number' => $shareContactNumber,
                'id' => (int) $existingContact['id'],
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO group_contacts (
                    group_id,
                    full_name,
                    email,
                    contact_number,
                    scout_role,
                    about_me,
                    accreditations,
                    share_contact_number,
                    profile_updated_at
                ) VALUES (
                    :group_id,
                    :full_name,
                    :email,
                    :contact_number,
                    :scout_role,
                    :about_me,
                    :accreditations,
                    :share_contact_number,
                    NOW()
                )
            ");

            $stmt->execute([
                'group_id' => $groupId,
                'full_name' => $fullName,
                'email' => $email,
                'contact_number' => $contactNumber,
                'scout_role' => $scoutRole,
                'about_me' => $aboutMe,
                'accreditations' => $accreditationsJson,
                'share_contact_number' => $shareContactNumber,
            ]);
        }

        redirect('/index.php');
    }
}

$formFullName = trim((string) ($_POST['full_name'] ?? ($existingContact['full_name'] ?? $displayName)));
$formGroupId = (int) ($_POST['group_id'] ?? ($existingContact['group_id'] ?? 0));
$formScoutRole = trim((string) ($_POST['scout_role'] ?? ($existingContact['scout_role'] ?? '')));
$formContactNumber = trim((string) ($_POST['contact_number'] ?? ($existingContact['contact_number'] ?? '')));
$formAboutMe = trim((string) ($_POST['about_me'] ?? ($existingContact['about_me'] ?? '')));
$formShareContact = isset($_POST['share_contact_number'])
    ? 1
    : (int) ($existingContact['share_contact_number'] ?? 0);

$formAccreditations = $_POST['accreditations'] ?? [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $existingAccreditations = trim((string) ($existingContact['accreditations'] ?? ''));
    $decoded = json_decode($existingAccreditations, true);
    $formAccreditations = is_array($decoded) ? $decoded : [];
}

if (!is_array($formAccreditations)) {
    $formAccreditations = [];
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Account setup | <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css"
          crossorigin="anonymous">

    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap"
          rel="stylesheet">

    <style>
        :root {
            --scout-purple: #7413dc;
            --scout-purple-dark: #4d0099;
            --scout-teal: #00a794;
            --scout-blue: #006ddf;
            --text-dark: #111111;
            --text-muted: #686868;
            --border-light: #e5e5e5;
            --page-bg: #f7f7f7;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            font-family: 'Nunito Sans', sans-serif;
            background:
                linear-gradient(
                    90deg,
                    rgba(25, 0, 55, 0.9) 0%,
                    rgba(77, 0, 153, 0.78) 48%,
                    rgba(0, 0, 0, 0.38) 100%
                ),
                url('/assets/img/cub-on-raft-jpg.jpg') center center / cover no-repeat fixed;
            color: var(--text-dark);
        }

        .setup-page {
            min-height: 100vh;
            padding: 2rem 0;
            display: flex;
            align-items: center;
        }

        .setup-shell {
            width: 100%;
        }

        .setup-card {
            border: 0;
            border-radius: 1.25rem;
            overflow: hidden;
            box-shadow: 0 1.5rem 4rem rgba(0, 0, 0, 0.28);
            background: #ffffff;
        }

        .brand-panel {
            background:
                radial-gradient(circle at top left, rgba(255,255,255,0.16), transparent 22rem),
                var(--scout-purple);
            color: #ffffff;
            position: relative;
            overflow: hidden;
        }

        .brand-panel::after {
            content: "Skills for life";
            position: absolute;
            right: -1.6rem;
            bottom: 1rem;
            color: rgba(255, 255, 255, 0.08);
            font-size: 3.8rem;
            font-weight: 900;
            line-height: 0.9;
            transform: rotate(-6deg);
            pointer-events: none;
        }

        .brand-logo-space {
            width: 128px;
            min-height: 58px;
            margin-bottom: 1.6rem;
            display: flex;
            align-items: center;
        }

        .brand-logo-space img {
            max-width: 100%;
            height: auto;
            display: block;
        }

        .brand-kicker {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.22);
            border-radius: 999px;
            padding: 0.35rem 0.75rem;
            font-size: 0.78rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 1rem;
        }

        .brand-kicker::before {
            content: "";
            width: 0.55rem;
            height: 0.55rem;
            border-radius: 999px;
            background: var(--scout-teal);
            margin-right: 0.5rem;
        }

        .brand-panel h1 {
            font-size: 2.4rem;
            font-weight: 900;
            letter-spacing: -0.05em;
            line-height: 1;
            margin-bottom: 1rem;
        }

        .brand-panel p {
            color: rgba(255,255,255,0.9);
            font-weight: 700;
            line-height: 1.5;
        }

        .setup-panel {
            background: rgba(255, 255, 255, 0.98);
        }

        .setup-heading {
            font-size: 1.8rem;
            font-weight: 900;
            letter-spacing: -0.045em;
            margin-bottom: 0.4rem;
        }

        .setup-subtitle {
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: 1.75rem;
        }

        .form-section {
            border-top: 1px solid var(--border-light);
            padding-top: 1.5rem;
            margin-top: 1.5rem;
        }

        .form-section:first-of-type {
            border-top: 0;
            padding-top: 0;
            margin-top: 0;
        }

        .section-title {
            font-size: 1.05rem;
            font-weight: 900;
            color: var(--scout-purple);
            margin-bottom: 0.4rem;
        }

        .section-help {
            color: var(--text-muted);
            font-size: 0.92rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        label {
            font-weight: 800;
            font-size: 0.92rem;
        }

        .form-control {
            min-height: 48px;
            border-radius: 0;
        }

        textarea.form-control {
            min-height: auto;
        }

        .btn-primary {
            background: var(--scout-purple);
            border-color: var(--scout-purple);
            border-radius: 0;
            font-weight: 900;
            padding: 0.85rem 1.3rem;
        }

        .btn-primary:hover {
            background: var(--scout-purple-dark);
            border-color: var(--scout-purple-dark);
        }

        .locked-field {
            background: #f7f7f7;
            border: 1px solid #dddddd;
            min-height: 48px;
            display: flex;
            align-items: center;
            padding: 0.65rem 0.75rem;
            font-weight: 800;
            color: #333333;
        }

        .locked-note {
            color: var(--text-muted);
            font-size: 0.85rem;
            font-weight: 700;
            margin-top: 0.45rem;
        }

        .accreditation-group {
            border: 1px solid var(--border-light);
            margin-bottom: 1rem;
            background: #ffffff;
        }

        .accreditation-group-heading {
            background: #f7f7f7;
            border-bottom: 1px solid var(--border-light);
            padding: 0.7rem 0.9rem;
            font-weight: 900;
            color: var(--scout-purple);
        }

        .accreditation-grid {
            padding: 0.9rem;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.6rem 0.9rem;
        }

        .accreditation-check {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            margin: 0;
            font-weight: 700;
            line-height: 1.3;
        }

        .accreditation-check input {
            margin-top: 0.15rem;
        }

        .small-note {
            color: var(--text-muted);
            font-weight: 700;
            font-size: 0.86rem;
        }

        @media (max-width: 991.98px) {
            .setup-page {
                align-items: flex-start;
                padding: 1rem 0;
            }

            .brand-panel::after {
                font-size: 2.5rem;
            }
        }

        @media (max-width: 767.98px) {
            body {
                background:
                    linear-gradient(
                        180deg,
                        rgba(25, 0, 55, 0.92) 0%,
                        rgba(77, 0, 153, 0.74) 58%,
                        rgba(0, 0, 0, 0.48) 100%
                    ),
                    url('/assets/img/cub-on-raft-jpg.jpg') center center / cover no-repeat fixed;
            }

            .brand-panel h1 {
                font-size: 2rem;
            }

            .accreditation-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<main class="setup-page">
    <div class="container setup-shell">
        <div class="row justify-content-center">
            <div class="col-xl-11">
                <div class="card setup-card">
                    <div class="row no-gutters">
                        <div class="col-lg-4 brand-panel p-4 p-md-5 d-flex flex-column justify-content-center">
                            <div class="brand-logo-space">
                                <img src="/assets/img/white-ir-logo.png"
                                     alt="Irwell Valley District Scouts"
                                     onerror="this.style.display='none';">
                            </div>

                            <div class="brand-kicker">Account setup</div>

                            <h1>Welcome to the District Dashboard</h1>

                            <p>
                                Tell us a few details so we can personalise your dashboard and start building the District Directory.
                            </p>

                            <p class="mb-0 small-note" style="color: rgba(255,255,255,0.82);">
                                Signed in as<br>
                                <strong><?= e($email) ?></strong>
                            </p>
                        </div>

                        <div class="col-lg-8 setup-panel p-4 p-md-5">
                            <h2 class="setup-heading">Complete your profile</h2>

                            <p class="setup-subtitle">
                                These details help link your Microsoft sign-in to your Group and directory profile.
                            </p>

                            <?php if ($error): ?>
                                <div class="alert alert-danger">
                                    <?= e($error) ?>
                                </div>
                            <?php endif; ?>

                            <form method="post">

                                <section class="form-section">
                                    <h3 class="section-title">Basic details</h3>
                                    <p class="section-help">
                                        Your name and email will be used to identify you in the dashboard.
                                    </p>

                                    <div class="form-group">
                                        <label for="full_name">Name</label>

                                        <input type="text"
                                               class="form-control"
                                               id="full_name"
                                               name="full_name"
                                               value="<?= e($formFullName) ?>"
                                               required>
                                    </div>

                                    <div class="form-group">
                                        <label for="email">Email address</label>

                                        <input type="email"
                                               class="form-control"
                                               id="email"
                                               value="<?= e($email) ?>"
                                               disabled>

                                        <small class="form-text text-muted">
                                            This comes from your Microsoft sign-in.
                                        </small>
                                    </div>
                                </section>

                                <section class="form-section">
                                    <h3 class="section-title">Group and role</h3>
                                    <p class="section-help">
                                        This controls which Group you are linked to and how you appear in the District Directory.
                                    </p>

                                    <div class="form-group">
                                        <label for="group_id">Group</label>

                                        <?php if ($groupIsLocked): ?>
                                            <?php
                                                $lockedGroupName = 'Selected group';

                                                foreach ($groups as $group) {
                                                    if ((int) $group['id'] === $existingGroupId) {
                                                        $lockedGroupName = (string) $group['group_name'];
                                                        break;
                                                    }
                                                }
                                            ?>

                                            <div class="locked-field">
                                                <?= e($lockedGroupName) ?>
                                            </div>

                                            <input type="hidden"
                                                   name="group_id"
                                                   value="<?= (int) $existingGroupId ?>">

                                            <div class="locked-note">
                                                Your group is already set. If this needs changing, please contact the District team.
                                            </div>
                                        <?php else: ?>
                                            <select class="form-control"
                                                    id="group_id"
                                                    name="group_id"
                                                    required>

                                                <option value="">Select your group</option>

                                                <?php foreach ($groups as $group): ?>
                                                    <option value="<?= (int) $group['id'] ?>"
                                                        <?= $formGroupId === (int) $group['id'] ? 'selected' : '' ?>>
                                                        <?= e($group['group_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </div>

                                    <div class="form-group">
                                        <label for="scout_role">Scout role</label>

                                        <select class="form-control"
                                                id="scout_role"
                                                name="scout_role"
                                                required>

                                            <option value="">Select your role</option>

                                            <?php foreach ($roleOptions as $roleOption): ?>
                                                <option value="<?= e($roleOption) ?>"
                                                    <?= $formScoutRole === $roleOption ? 'selected' : '' ?>>
                                                    <?= e($roleOption) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </section>

                                <section class="form-section">
                                    <h3 class="section-title">Directory details</h3>
                                    <p class="section-help">
                                        These details can appear in the District Directory.
                                    </p>

                                    <div class="form-group">
                                        <label for="contact_number">Contact number</label>

                                        <input type="text"
                                               class="form-control"
                                               id="contact_number"
                                               name="contact_number"
                                               value="<?= e($formContactNumber) ?>"
                                               placeholder="Optional">
                                    </div>

                                    <div class="form-group form-check">
                                        <input type="checkbox"
                                               class="form-check-input"
                                               id="share_contact_number"
                                               name="share_contact_number"
                                               value="1"
                                               <?= $formShareContact === 1 ? 'checked' : '' ?>>

                                        <label class="form-check-label"
                                               for="share_contact_number">
                                            Share my contact number in the District Directory
                                        </label>
                                    </div>

                                    <div class="form-group">
                                        <label for="about_me">About me</label>

                                        <textarea class="form-control"
                                                  id="about_me"
                                                  name="about_me"
                                                  rows="3"
                                                  placeholder="Optional short profile"><?= e($formAboutMe) ?></textarea>
                                    </div>
                                </section>

                                <section class="form-section">
                                    <h3 class="section-title">Permits and accreditations</h3>
                                    <p class="section-help">
                                        Tick any permits, adviser roles or accreditations you hold. You can update these later from My Profile.
                                    </p>

                                    <?php foreach ($accreditationOptions as $category => $items): ?>
                                        <div class="accreditation-group">
                                            <div class="accreditation-group-heading">
                                                <?= e($category) ?>
                                            </div>

                                            <div class="accreditation-grid">
                                                <?php foreach ($items as $item): ?>
                                                    <?php $id = 'accreditation_' . md5($item); ?>

                                                    <label class="accreditation-check" for="<?= e($id) ?>">
                                                        <input type="checkbox"
                                                               id="<?= e($id) ?>"
                                                               name="accreditations[]"
                                                               value="<?= e($item) ?>"
                                                               <?= in_array($item, $formAccreditations, true) ? 'checked' : '' ?>>

                                                        <span><?= e($item) ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </section>

                                <div class="d-flex align-items-center flex-wrap mt-4">
                                    <button type="submit"
                                            class="btn btn-primary btn-lg mr-3 mb-2">
                                        Complete setup
                                    </button>

                                    <span class="small-note mb-2">
                                        You can update most of these details later from My Profile.
                                    </span>
                                </div>

                            </form>
                        </div>
                    </div>
                </div>

                <p class="text-center small mt-4 mb-0" style="color: rgba(255,255,255,0.88); font-weight: 800;">
                    <?= e($appName) ?>
                </p>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"
        crossorigin="anonymous"></script>

<script src="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/js/bootstrap.min.js"
        crossorigin="anonymous"></script>

</body>
</html>