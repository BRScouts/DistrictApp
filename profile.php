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

function decode_accreditations(?string $value): array
{
    $value = trim((string) $value);

    if ($value === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    if (is_array($decoded)) {
        return array_values(array_filter(array_map('strval', $decoded)));
    }

    /*
     * Backwards compatibility for old free-text entries.
     */
    $lines = preg_split('/\r\n|\r|\n|,/', $value);

    if (!$lines) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $lines)));
}

function flatten_accreditation_options(array $options): array
{
    $flat = [];

    foreach ($options as $groupItems) {
        foreach ($groupItems as $item) {
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

$existingGroupId = (int) ($contact['group_id'] ?? 0);
$groupIsLocked = $existingGroupId > 0;
$selectedAccreditations = decode_accreditations($contact['accreditations'] ?? '');

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

    /*
     * If a group is already set, do not allow the user to change it.
     */
    if ($groupIsLocked) {
        $groupId = $existingGroupId;
    } else {
        $groupId = (int) ($_POST['group_id'] ?? 0);
    }

    $postedAccreditations = $_POST['accreditations'] ?? [];

    if (!is_array($postedAccreditations)) {
        $postedAccreditations = [];
    }

    $allowedAccreditations = flatten_accreditation_options($accreditationOptions);

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
                'accreditations' => $accreditationsJson,
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
                'accreditations' => $accreditationsJson,
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

        $existingGroupId = (int) ($contact['group_id'] ?? 0);
        $groupIsLocked = $existingGroupId > 0;
        $selectedAccreditations = decode_accreditations($contact['accreditations'] ?? '');
    }
}

?>
<?php include __DIR__ . '/header.php'; ?>

<style>
    .profile-page {
        padding-top: 2rem;
        padding-bottom: 5rem;
    }

    /*
    |--------------------------------------------------------------------------
    | Compact profile banner
    |--------------------------------------------------------------------------
    */

    .profile-banner {
        display: flex;
        align-items: center;
        gap: 1.25rem;
        margin-bottom: 1.75rem;
        padding: 1.25rem;
        border: 1px solid var(--border-light);
        background: #ffffff;
        box-shadow: 0 0.45rem 1.25rem rgba(0, 0, 0, 0.04);
    }

    .profile-banner-image {
        width: 112px;
        height: 112px;
        flex: 0 0 112px;
        border-radius: 999px;
        overflow: hidden;
        border: 5px solid #f1e8ff;
        background: var(--scout-purple);
        box-shadow: 0 0.75rem 1.75rem rgba(0, 0, 0, 0.12);
    }

    .profile-banner-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .profile-banner-copy {
        min-width: 0;
    }

    .profile-eyebrow {
        display: inline-flex;
        align-items: center;
        margin-bottom: 0.45rem;
        padding: 0.25rem 0.65rem;
        border-radius: 999px;
        background: #f1e8ff;
        color: var(--scout-purple);
        font-size: 0.75rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .profile-banner h1 {
        font-size: 2rem;
        font-weight: 900;
        letter-spacing: -0.045em;
        margin: 0 0 0.25rem;
    }

    .profile-banner p {
        color: var(--text-muted);
        font-weight: 700;
        margin: 0;
        word-break: break-word;
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
        box-shadow: 0 0.45rem 1.25rem rgba(0, 0, 0, 0.04);
    }

    .section-title {
        font-weight: 900;
        font-size: 1.15rem;
        letter-spacing: -0.02em;
        margin-bottom: 1.5rem;
        color: var(--scout-purple);
    }

    .section-intro {
        margin-top: -0.85rem;
        margin-bottom: 1.5rem;
        color: var(--text-muted);
        font-weight: 700;
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

    /*
    |--------------------------------------------------------------------------
    | Accreditations
    |--------------------------------------------------------------------------
    */

    .accreditation-group {
        border: 1px solid var(--border-light);
        background: #ffffff;
        margin-bottom: 1rem;
    }

    .accreditation-group-heading {
        background: #f7f7f7;
        border-bottom: 1px solid var(--border-light);
        padding: 0.75rem 1rem;
        font-weight: 900;
        color: var(--scout-purple);
    }

    .accreditation-grid {
        padding: 1rem;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.65rem 1rem;
    }

    .accreditation-check {
        display: flex;
        align-items: flex-start;
        gap: 0.55rem;
        margin: 0;
        font-weight: 700;
        color: #222222;
        line-height: 1.3;
    }

    .accreditation-check input {
        margin-top: 0.15rem;
    }

    @media (max-width: 767.98px) {
        .profile-page {
            padding-top: 1.25rem;
        }

        .profile-banner {
            display: block;
            text-align: center;
        }

        .profile-banner-image {
            margin: 0 auto 1rem;
            width: 96px;
            height: 96px;
            flex-basis: 96px;
        }

        .profile-banner h1 {
            font-size: 1.75rem;
        }

        .accreditation-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<main class="page-container profile-page">

    <section class="profile-banner">
        <div class="profile-banner-image">
            <img src="/assets/img/cub-on-raft-jpg.jpg"
                 alt="">
        </div>

        <div class="profile-banner-copy">
            <span class="profile-eyebrow">My Profile</span>

            <h1><?= e($displayName ?: 'My Profile') ?></h1>

            <p>
                <?= e($email) ?>
            </p>
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
        <div class="col-xl-10">

            <div class="card profile-card">
                <div class="card-body p-4 p-md-5">

                    <form method="post">

                        <h2 class="section-title">
                            Directory details
                        </h2>

                        <p class="section-intro">
                            These details will be used in the District Directory. You can choose whether your contact number is shown publicly.
                        </p>

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
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="scout_role">Scout role</label>

                            <select class="form-control"
                                    id="scout_role"
                                    name="scout_role"
                                    required>

                                <option value="">
                                    Select your role
                                </option>

                                <?php foreach ($roleOptions as $roleOption): ?>
                                    <option value="<?= e($roleOption) ?>"
                                        <?= (($contact['scout_role'] ?? '') === $roleOption) ? 'selected' : '' ?>>

                                        <?= e($roleOption) ?>

                                    </option>
                                <?php endforeach; ?>

                            </select>
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

                        <hr class="my-4">

                        <h2 class="section-title">
                            Permits and accreditations
                        </h2>

                        <p class="section-intro">
                            Tick the permits, accreditations or adviser roles you hold. These will be saved against your directory profile.
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
                                                   <?= in_array($item, $selectedAccreditations, true) ? 'checked' : '' ?>>

                                            <span><?= e($item) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

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