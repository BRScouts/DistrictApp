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
<?php include __DIR__ . '/header.php'; ?>

<style>
    .profile-page {
        padding-top: 2.5rem;
        padding-bottom: 5rem;
    }

    /*
    |--------------------------------------------------------------------------
    | Hero
    |--------------------------------------------------------------------------
    */

    .profile-hero {
        position: relative;
        overflow: hidden;
        border-radius: 0;
        margin-bottom: 2rem;

        background:
            linear-gradient(
                90deg,
                rgba(59, 0, 120, 0.92) 0%,
                rgba(77, 0, 153, 0.86) 45%,
                rgba(77, 0, 153, 0.68) 100%
            ),
            url('https://images.unsplash.com/photo-1529156069898-49953e39b3ac?auto=format&fit=crop&w=1800&q=80');

        background-size: cover;
        background-position: center;
        color: #ffffff;

        box-shadow:
            0 1rem 2rem rgba(0, 0, 0, 0.12);
    }

    .profile-hero::after {
        content: "⚜";
        position: absolute;
        right: 4%;
        top: -1rem;
        font-size: 14rem;
        line-height: 1;
        color: rgba(255, 255, 255, 0.08);
        font-family: serif;
    }

    .profile-hero-inner {
        position: relative;
        z-index: 1;

        padding:
            2.75rem
            3rem;
    }

    .profile-avatar-xl {
        width: 96px;
        height: 96px;
        border-radius: 50%;
        overflow: hidden;
        flex-shrink: 0;

        background: #ffffff;
        color: var(--scout-purple);

        display: flex;
        align-items: center;
        justify-content: center;

        font-size: 2rem;
        font-weight: 900;

        border: 4px solid rgba(255, 255, 255, 0.18);

        box-shadow:
            0 0.75rem 2rem rgba(0, 0, 0, 0.18);
    }

    .profile-avatar-xl img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .profile-hero h1 {
        font-size: 2.45rem;
        font-weight: 900;
        letter-spacing: -0.05em;
        margin-bottom: 0.35rem;
    }

    .profile-hero p {
        margin-bottom: 0;
        color: rgba(255, 255, 255, 0.88);
        font-size: 1rem;
        font-weight: 600;
    }

    /*
    |--------------------------------------------------------------------------
    | Cards
    |--------------------------------------------------------------------------
    */

    .profile-card {
        border: 1px solid var(--border-light);
        border-radius: 0;
        background: #ffffff;
        box-shadow:
            0 0.45rem 1.25rem rgba(0, 0, 0, 0.04);
    }

    .section-title {
        font-weight: 900;
        font-size: 1.15rem;
        letter-spacing: -0.02em;
        margin-bottom: 1.5rem;
        color: var(--scout-purple);
    }

    .form-control {
        border-radius: 0;
        min-height: 48px;
    }

    textarea.form-control {
        min-height: auto;
    }

    label {
        font-weight: 800;
        font-size: 0.92rem;
    }

    .btn-primary {
        background: var(--scout-purple);
        border-color: var(--scout-purple);
        font-weight: 800;
        border-radius: 0;
        padding-left: 1.5rem;
        padding-right: 1.5rem;
    }

    .btn-primary:hover {
        background: var(--scout-purple-dark);
        border-color: var(--scout-purple-dark);
    }

    @media (max-width: 767.98px) {

        .profile-page {
            padding-top: 1.5rem;
        }

        .profile-hero-inner {
            padding: 2rem 1.5rem;
        }

        .profile-hero h1 {
            font-size: 2rem;
        }

        .profile-avatar-xl {
            width: 78px;
            height: 78px;
            font-size: 1.6rem;
        }

        .profile-hero::after {
            font-size: 8rem;
            top: 1rem;
            right: -0.5rem;
        }
    }
</style>

<main class="page-container profile-page">

    <section class="profile-hero">
        <div class="profile-hero-inner">

            <div class="d-flex align-items-center flex-column flex-md-row">

                <div class="profile-avatar-xl mb-4 mb-md-0 mr-md-4">

                    <img src="/auth/profile-photo.php"
                         alt="<?= e($displayName) ?>"
                         onerror="this.style.display='none'; this.parentNode.innerHTML='<?= e($initials) ?>';">

                </div>

                <div>
                    <h1>My Profile</h1>

                    <p>
                        <?= e($email) ?>
                    </p>
                </div>

            </div>

        </div>
    </section>

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

    <div class="row justify-content-center">
        <div class="col-xl-9">

            <div class="card profile-card">
                <div class="card-body p-4 p-md-5">

                    <form method="post">

                        <h2 class="section-title">
                            Directory details
                        </h2>

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

                            <select class="form-control"
                                    id="group_id"
                                    name="group_id"
                                    required>

                                <option value="">
                                    Select your group
                                </option>

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
                                   placeholder="e.g. Group Lead Volunteer, Section Team Leader"
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
                                      rows="4"
                                      placeholder="Optional short profile"><?= e($contact['about_me'] ?? '') ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="accreditations">Accreditations</label>

                            <textarea class="form-control"
                                      id="accreditations"
                                      name="accreditations"
                                      rows="3"
                                      placeholder="e.g. Nights Away Adviser"><?= e($contact['accreditations'] ?? '') ?></textarea>

                            <small class="form-text text-muted">
                                Free text for now. We can structure this later.
                            </small>
                        </div>

                        <div class="d-flex align-items-center flex-wrap mt-4">

                            <button type="submit"
                                    class="btn btn-primary btn-lg mr-3 mb-2">

                                Save profile

                            </button>

                            <a href="/index.php"
                               class="btn btn-link mb-2">

                                Back to dashboard

                            </a>

                        </div>

                    </form>

                </div>
            </div>

        </div>
    </div>

</main>

<?php include __DIR__ . '/footer.php'; ?>