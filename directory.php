<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley District Scouts');

$search = trim((string) ($_GET['q'] ?? ''));
$groupId = (int) ($_GET['group_id'] ?? 0);
$role = trim((string) ($_GET['role'] ?? ''));
$accreditation = trim((string) ($_GET['accreditation'] ?? ''));

$pdo = db();

/*
|--------------------------------------------------------------------------
| Role options - must match profile.php
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
| Accreditation / permit options - must match profile.php
|--------------------------------------------------------------------------
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

function flatten_accreditation_options(array $options): array
{
    $flat = [];

    foreach ($options as $items) {
        foreach ($items as $item) {
            $flat[] = $item;
        }
    }

    return $flat;
}

$flatAccreditationOptions = flatten_accreditation_options($accreditationOptions);

/*
|--------------------------------------------------------------------------
| Load filter data
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
| Build directory query
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        gc.id,
        gc.group_id,
        gc.full_name,
        gc.email,
        gc.contact_number,
        gc.scout_role,
        gc.about_me,
        gc.accreditations,
        gc.share_contact_number,
        g.group_name,
        au.id AS linked_user_id,
        au.microsoft_oid
    FROM group_contacts gc
    LEFT JOIN groups g
        ON g.id = gc.group_id
    LEFT JOIN admin_users au
        ON LOWER(au.email) = LOWER(gc.email)
    WHERE 1 = 1
";

$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            gc.full_name LIKE :search
            OR gc.email LIKE :search
            OR gc.scout_role LIKE :search
            OR gc.about_me LIKE :search
            OR gc.accreditations LIKE :search
            OR g.group_name LIKE :search
        )
    ";

    $params['search'] = '%' . $search . '%';
}

if ($groupId > 0) {
    $sql .= " AND gc.group_id = :group_id";
    $params['group_id'] = $groupId;
}

if ($role !== '') {
    $sql .= " AND gc.scout_role = :role";
    $params['role'] = $role;
}

if ($accreditation !== '') {
    $sql .= " AND gc.accreditations LIKE :accreditation";
    $params['accreditation'] = '%' . $accreditation . '%';
}

$sql .= "
    ORDER BY
        g.group_name ASC,
        gc.full_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

function directory_value(?string $value, string $fallback = 'Not provided'): string
{
    $value = trim((string) $value);

    return $value !== '' ? $value : $fallback;
}

function directory_initials(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return '?';
    }

    $parts = preg_split('/\s+/', $name);

    if (!$parts) {
        return strtoupper(substr($name, 0, 1));
    }

    $first = substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? substr($parts[count($parts) - 1], 0, 1) : '';

    return strtoupper($first . $last);
}

function directory_photo_url(array $contact): ?string
{
    if (empty($contact['linked_user_id'])) {
        return null;
    }

    return '/auth/profile-photo.php?user_id=' . urlencode((string) $contact['linked_user_id']);
}

function decode_directory_accreditations(?string $value): array
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
     * Backwards compatibility for older free-text values.
     */
    $lines = preg_split('/\r\n|\r|\n|,/', $value);

    if (!$lines) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $lines)));
}

function short_role_label(string $role): string
{
    $parts = preg_split('/\s+[–-]\s+/', $role, 2);

    if (!$parts || trim($parts[0]) === '') {
        return $role;
    }

    return trim($parts[0]);
}

function directory_has_active_filters(string $search, int $groupId, string $role, string $accreditation): bool
{
    return $search !== '' || $groupId > 0 || $role !== '' || $accreditation !== '';
}

?>
<?php include __DIR__ . '/header.php'; ?>

<style>
    .directory-page {
        padding-top: 2rem;
        padding-bottom: 5rem;
    }

    /*
    |--------------------------------------------------------------------------
    | Compact directory header
    |--------------------------------------------------------------------------
    */

    .directory-banner {
        display: flex;
        align-items: center;
        gap: 1.25rem;
        margin-bottom: 1.75rem;
        padding: 1.25rem;
        border: 1px solid var(--border-light);
        background: #ffffff;
        box-shadow: 0 0.45rem 1.25rem rgba(0, 0, 0, 0.04);
    }

    .directory-banner-image {
        width: 112px;
        height: 112px;
        flex: 0 0 112px;
        border-radius: 999px;
        overflow: hidden;
        border: 5px solid #f1e8ff;
        background: var(--scout-purple);
        box-shadow: 0 0.75rem 1.75rem rgba(0, 0, 0, 0.12);
    }

    .directory-banner-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .directory-eyebrow {
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

    .directory-banner h1 {
        font-size: 2rem;
        font-weight: 900;
        letter-spacing: -0.045em;
        margin: 0 0 0.25rem;
    }

    .directory-banner p {
        color: var(--text-muted);
        font-weight: 700;
        margin: 0;
        max-width: 760px;
    }

    /*
    |--------------------------------------------------------------------------
    | Cards and filters
    |--------------------------------------------------------------------------
    */

    .filter-card,
    .contact-card,
    .empty-state {
        border: 1px solid var(--border-light);
        border-radius: 0;
        background: #ffffff;
        box-shadow: 0 0.45rem 1.25rem rgba(0, 0, 0, 0.04);
    }

    .contact-card {
        height: 100%;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .contact-card:hover {
        border-color: #c8c8c8;
        box-shadow: 0 0.65rem 1.5rem rgba(0, 0, 0, 0.07);
    }

    .directory-avatar {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        overflow: hidden;
        background: var(--scout-purple);
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        flex-shrink: 0;
        font-size: 1rem;
        border: 3px solid #f1e8ff;
    }

    .directory-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .badge-linked {
        background: #00a794;
        color: #ffffff;
    }

    .badge-unlinked {
        background: #707070;
        color: #ffffff;
    }

    .meta-label {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 900;
        color: #707070;
        margin-bottom: 0.15rem;
    }

    .pre-line {
        white-space: pre-line;
    }

    .form-control {
        border-radius: 0;
        min-height: 46px;
    }

    label {
        font-weight: 800;
        font-size: 0.92rem;
    }

    .btn-primary {
        background: var(--scout-purple);
        border-color: var(--scout-purple);
        border-radius: 0;
        font-weight: 800;
    }

    .btn-primary:hover {
        background: var(--scout-purple-dark);
        border-color: var(--scout-purple-dark);
    }

    .btn-outline-secondary {
        border-radius: 0;
        font-weight: 800;
    }

    .empty-state {
        padding: 3rem;
        text-align: center;
    }

    .filter-summary {
        background: #f7f7f7;
        border: 1px solid var(--border-light);
        padding: 0.75rem 1rem;
        margin-bottom: 1.5rem;
        color: var(--text-muted);
        font-weight: 700;
    }

    .filter-pill {
        display: inline-flex;
        align-items: center;
        margin: 0.15rem 0.25rem 0.15rem 0;
        padding: 0.2rem 0.55rem;
        border-radius: 999px;
        background: #f1e8ff;
        color: var(--scout-purple);
        font-size: 0.78rem;
        font-weight: 900;
    }

    .accreditation-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
    }

    .accreditation-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        background: #f1e8ff;
        color: var(--scout-purple);
        padding: 0.25rem 0.6rem;
        font-size: 0.78rem;
        font-weight: 900;
        line-height: 1.2;
    }

    .role-main {
        font-weight: 800;
        color: #333333;
    }

    .directory-contact-link {
        word-break: break-word;
    }

    @media (max-width: 767.98px) {
        .directory-page {
            padding-top: 1.25rem;
        }

        .directory-banner {
            display: block;
            text-align: center;
        }

        .directory-banner-image {
            margin: 0 auto 1rem;
            width: 96px;
            height: 96px;
            flex-basis: 96px;
        }

        .directory-banner h1 {
            font-size: 1.75rem;
        }
    }
</style>

<main class="page-container directory-page">

    <section class="directory-banner">
        <div class="directory-banner-image">
            <img src="/assets/img/cub-on-raft-jpg.jpg"
                 alt="">
        </div>

        <div>
            <span class="directory-eyebrow">District Directory</span>

            <h1>Find people across Irwell Valley</h1>

            <p>
                Search contacts by name, group, role, permits, accreditations or profile details.
            </p>
        </div>
    </section>

    <section class="card filter-card mb-4">
        <div class="card-body p-4">
            <form method="get">
                <div class="form-row">
                    <div class="form-group col-lg-4">
                        <label for="q">Search</label>

                        <input type="search"
                               class="form-control"
                               id="q"
                               name="q"
                               value="<?= e($search) ?>"
                               placeholder="Name, email, role, permit, about me...">
                    </div>

                    <div class="form-group col-lg-3">
                        <label for="group_id">Group</label>

                        <select class="form-control"
                                id="group_id"
                                name="group_id">

                            <option value="0">All groups</option>

                            <?php foreach ($groups as $group): ?>
                                <option value="<?= (int) $group['id'] ?>"
                                    <?= $groupId === (int) $group['id'] ? 'selected' : '' ?>>
                                    <?= e($group['group_name']) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="form-group col-lg-3">
                        <label for="role">Role</label>

                        <select class="form-control"
                                id="role"
                                name="role">

                            <option value="">All roles</option>

                            <?php foreach ($roleOptions as $roleOption): ?>
                                <option value="<?= e($roleOption) ?>"
                                    <?= $role === $roleOption ? 'selected' : '' ?>>
                                    <?= e($roleOption) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="form-group col-lg-2">
                        <label for="accreditation">Permit / accreditation</label>

                        <select class="form-control"
                                id="accreditation"
                                name="accreditation">

                            <option value="">All</option>

                            <?php foreach ($accreditationOptions as $category => $items): ?>
                                <optgroup label="<?= e($category) ?>">
                                    <?php foreach ($items as $item): ?>
                                        <option value="<?= e($item) ?>"
                                            <?= $accreditation === $item ? 'selected' : '' ?>>
                                            <?= e($item) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>

                        </select>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center">
                    <button type="submit"
                            class="btn btn-primary mr-2">

                        Search directory

                    </button>

                    <a href="/directory.php"
                       class="btn btn-outline-secondary">

                        Clear

                    </a>

                    <span class="ml-auto text-muted small mt-3 mt-md-0">
                        <?= count($contacts) ?> result<?= count($contacts) === 1 ? '' : 's' ?>
                    </span>
                </div>
            </form>
        </div>
    </section>

    <?php if (directory_has_active_filters($search, $groupId, $role, $accreditation)): ?>
        <div class="filter-summary">
            <strong>Active filters:</strong>

            <?php if ($search !== ''): ?>
                <span class="filter-pill">Search: <?= e($search) ?></span>
            <?php endif; ?>

            <?php if ($groupId > 0): ?>
                <?php
                    $selectedGroupName = 'Selected group';

                    foreach ($groups as $group) {
                        if ((int) $group['id'] === $groupId) {
                            $selectedGroupName = (string) $group['group_name'];
                            break;
                        }
                    }
                ?>
                <span class="filter-pill">Group: <?= e($selectedGroupName) ?></span>
            <?php endif; ?>

            <?php if ($role !== ''): ?>
                <span class="filter-pill">Role: <?= e(short_role_label($role)) ?></span>
            <?php endif; ?>

            <?php if ($accreditation !== ''): ?>
                <span class="filter-pill">Permit: <?= e($accreditation) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$contacts): ?>

        <section class="empty-state">
            <h2 class="h4">No contacts found</h2>

            <p class="text-muted mb-0">
                Try changing your search filters.
            </p>
        </section>

    <?php else: ?>

        <section class="row">
            <?php foreach ($contacts as $contact): ?>
                <?php
                    $name = directory_value($contact['full_name'] ?? null, 'Unnamed contact');
                    $isLinked = !empty($contact['linked_user_id']) || !empty($contact['microsoft_oid']);
                    $showPhone = (int) ($contact['share_contact_number'] ?? 0) === 1;
                    $photoUrl = directory_photo_url($contact);
                    $contactAccreditations = decode_directory_accreditations($contact['accreditations'] ?? '');
                    $roleLabel = directory_value($contact['scout_role'] ?? null);
                ?>

                <div class="col-lg-6 mb-4">
                    <article class="card contact-card">
                        <div class="card-body p-4">

                            <div class="d-flex align-items-start mb-3">

                                <div class="directory-avatar mr-3">
                                    <?php if ($photoUrl): ?>
                                        <img src="<?= e($photoUrl) ?>"
                                             alt="<?= e($name) ?>"
                                             onerror="this.style.display='none'; this.parentNode.innerHTML='<?= e(directory_initials($name)) ?>';">
                                    <?php else: ?>
                                        <?= e(directory_initials($name)) ?>
                                    <?php endif; ?>
                                </div>

                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h2 class="h5 mb-1">
                                                <?= e($name) ?>
                                            </h2>

                                            <p class="text-muted mb-1">
                                                <span class="role-main"><?= e(short_role_label($roleLabel)) ?></span>
                                            </p>
                                        </div>

                                        <?php if ($isLinked): ?>
                                            <span class="badge badge-linked">
                                                SSO linked
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-unlinked">
                                                Directory only
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            </div>

                            <div class="mb-3">
                                <div class="meta-label">Group</div>
                                <div><?= e(directory_value($contact['group_name'] ?? null)) ?></div>
                            </div>

                            <div class="mb-3">
                                <div class="meta-label">Role</div>
                                <div><?= e($roleLabel) ?></div>
                            </div>

                            <div class="mb-3">
                                <div class="meta-label">Email</div>

                                <div>
                                    <?php if (!empty($contact['email'])): ?>
                                        <a href="mailto:<?= e($contact['email']) ?>" class="directory-contact-link">
                                            <?= e($contact['email']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">Not provided</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="meta-label">Contact number</div>

                                <div>
                                    <?php if ($showPhone && !empty($contact['contact_number'])): ?>
                                        <a href="tel:<?= e($contact['contact_number']) ?>">
                                            <?= e($contact['contact_number']) ?>
                                        </a>
                                    <?php elseif (!empty($contact['contact_number'])): ?>
                                        <span class="text-muted">Hidden by user</span>
                                    <?php else: ?>
                                        <span class="text-muted">Not provided</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($contactAccreditations)): ?>
                                <div class="mb-3">
                                    <div class="meta-label">Permits and accreditations</div>

                                    <div class="accreditation-badges">
                                        <?php foreach ($contactAccreditations as $item): ?>
                                            <span class="accreditation-badge">
                                                <?= e($item) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($contact['about_me'])): ?>
                                <div>
                                    <div class="meta-label">About</div>

                                    <div class="pre-line">
                                        <?= e($contact['about_me']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </section>

    <?php endif; ?>

</main>

<?php include __DIR__ . '/footer.php'; ?>