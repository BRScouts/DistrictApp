<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley District Scouts');

$email = trim((string) ($user['email'] ?? ''));
$displayName = trim((string) ($user['full_name'] ?? ''));
$initials = strtoupper(substr($displayName ?: $email, 0, 1));

$pdo = db();

$error = null;
$success = null;

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
| Load existing group contact by email
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

$contact = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contact) {
    $contact = [
        'id' => null,
        'group_id' => '',
        'full_name' => $displayName,
        'email' => $email,
        'contact_number' => '',
        'scout_role' => '',
        'about_me' => '',
        'accreditations' => '',
        'share_contact_number' => 0,
    ];
}

/*
|--------------------------------------------------------------------------
| Handle save
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
    $scoutRole = trim((string) ($_POST['scout_role'] ?? ''));
    $aboutMe = trim((string) ($_POST['about_me'] ?? ''));
    $accreditations = trim((string) ($_POST['accreditations'] ?? ''));
    $shareContactNumber = isset($_POST['share_contact_number']) ? 1 : 0;

    if ($fullName === '') {
        $error = 'Please enter your name.';
    } elseif ($groupId <= 0) {
        $error = 'Please choose your group.';
    } elseif ($scoutRole === '') {
        $error = 'Please enter your Scout role.';
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
         * Keep admin_users full_name in sync with profile name.
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
         * Update or create group_contacts row matched by email.
         */
        if (!empty($contact['id'])) {
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
                'accreditations' => $accreditations,
                'share_contact_number' => $shareContactNumber,
                'id' => (int) $contact['id'],
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
                'accreditations' => $accreditations,
                'share_contact_number' => $shareContactNumber,
            ]);
        }

        $success = 'Your profile has been updated.';

        $stmt = $pdo->prepare("
            SELECT *
            FROM group_contacts
            WHERE LOWER(email) = LOWER(:email)
            LIMIT 1
        ");

        $stmt->execute([
            'email' => $email,
        ]);

        $contact = $stmt->fetch(PDO::FETCH_ASSOC);
        $displayName = $fullName;
        $initials = strtoupper(substr($displayName ?: $email, 0, 1));
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>My Profile | <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css"
          crossorigin="anonymous">

    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap"
          rel="stylesheet">

    <style>
        body {
            background: #f5f5f5;
        }

        .profile-card {
            border: 0;
            border-radius: 1rem;
            box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.08);
        }

        .avatar-lg {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            object-fit: cover;
            background: #7413dc;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 800;
        }

        .section-title {
            font-weight: 800;
            color: #7413dc;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <a class="navbar-brand" href="/index.php"><?= e($appName) ?></a>

    <div class="ml-auto">
        <a class="btn btn-outline-light btn-sm mr-2" href="/index.php">Dashboard</a>
        <a class="btn btn-outline-light btn-sm" href="/logout.php">Sign out</a>
    </div>
</nav>

<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-9">

            <div class="card profile-card mb-4">
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex align-items-center">
                        <img src="/auth/profile-photo.php"
                             alt=""
                             class="avatar-lg mr-4"
                             onerror="this.style.display='none'; document.getElementById('fallbackAvatar').style.display='inline-flex';">

                        <div id="fallbackAvatar" class="avatar-lg mr-4" style="display:none;">
                            <?= e($initials) ?>
                        </div>

                        <div>
                            <h1 class="h3 mb-1">My Profile</h1>
                            <p class="text-muted mb-0"><?= e($email) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?= e($success) ?>
                </div>
            <?php endif; ?>

            <div class="card profile-card">
                <div class="card-body p-4 p-md-5">
                    <form method="post">
                        <h2 class="h5 section-title mb-4">Directory details</h2>

                        <div class="form-group">
                            <label for="full_name">Name</label>
                            <input type="text"
                                   class="form-control"
                                   id="full_name"
                                   name="full_name"
                                   value="<?= e($contact['full_name'] ?? $displayName) ?>"
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
                                Your email comes from your Microsoft sign-in and is used to link your directory record.
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="group_id">Group</label>
                            <select class="form-control" id="group_id" name="group_id" required>
                                <option value="">Select your group</option>

                                <?php foreach ($groups as $group): ?>
                                    <option value="<?= (int) $group['id'] ?>"
                                        <?= ((int) ($contact['group_id'] ?? 0) === (int) $group['id']) ? 'selected' : '' ?>>
                                        <?= e($group['group_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="scout_role">Scout role</label>
                            <input type="text"
                                   class="form-control"
                                   id="scout_role"
                                   name="scout_role"
                                   value="<?= e($contact['scout_role'] ?? '') ?>"
                                   placeholder="e.g. Group Lead Volunteer, Section Team Leader, Treasurer"
                                   required>
                        </div>

                        <div class="form-group">
                            <label for="contact_number">Contact number</label>
                            <input type="text"
                                   class="form-control"
                                   id="contact_number"
                                   name="contact_number"
                                   value="<?= e($contact['contact_number'] ?? '') ?>"
                                   placeholder="Optional">
                        </div>

                        <div class="form-group form-check">
                            <input type="checkbox"
                                   class="form-check-input"
                                   id="share_contact_number"
                                   name="share_contact_number"
                                   value="1"
                                   <?= !empty($contact['share_contact_number']) ? 'checked' : '' ?>>

                            <label class="form-check-label" for="share_contact_number">
                                Share my contact number in the District Directory
                            </label>
                        </div>

                        <div class="form-group">
                            <label for="about_me">About me</label>
                            <textarea class="form-control"
                                      id="about_me"
                                      name="about_me"
                                      rows="4"
                                      placeholder="Optional short profile for the District Directory"><?= e($contact['about_me'] ?? '') ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="accreditations">Accreditations</label>
                            <textarea class="form-control"
                                      id="accreditations"
                                      name="accreditations"
                                      rows="3"
                                      placeholder="e.g. Nights Away Adviser, First Aid Trainer, Archery Permit"><?= e($contact['accreditations'] ?? '') ?></textarea>

                            <small class="form-text text-muted">
                                Free text for now. We can make this structured later.
                            </small>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg">
                            Save profile
                        </button>

                        <a href="/index.php" class="btn btn-link">
                            Back to dashboard
                        </a>
                    </form>
                </div>
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