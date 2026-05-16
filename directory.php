<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

$user = current_user();

$search = trim((string) ($_GET['q'] ?? ''));
$groupId = (int) ($_GET['group_id'] ?? 0);
$role = trim((string) ($_GET['role'] ?? ''));
$accreditation = trim((string) ($_GET['accreditation'] ?? ''));

$pdo = db();

$stmt = $pdo->query("
    SELECT id, group_name
    FROM groups
    WHERE is_active = 1
    ORDER BY group_name ASC
");

$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("
    SELECT DISTINCT scout_role
    FROM group_contacts
    WHERE scout_role IS NOT NULL
      AND scout_role <> ''
    ORDER BY scout_role ASC
");

$roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

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

?>

<?php include __DIR__ . '/header.php'; ?>

<style>
    .directory-page {
        padding-top: 2.5rem;
        padding-bottom: 5rem;
    }

    .directory-hero {
        position: relative;
        overflow: hidden;
        margin-bottom: 2rem;
        color: #ffffff;
        background:
            linear-gradient(
                90deg,
                rgba(59, 0, 120, 0.94) 0%,
                rgba(77, 0, 153, 0.86) 48%,
                rgba(77, 0, 153, 0.62) 100%
            ),
            url('https://images.unsplash.com/photo-1529156069898-49953e39b3ac?auto=format&fit=crop&w=1800&q=80');
        background-size: cover;
        background-position: center;
    }

    .directory-hero::after {
        content: "⚜";
        position: absolute;
        right: 4%;
        top: -1rem;
        font-size: 14rem;
        line-height: 1;
        color: rgba(255, 255, 255, 0.08);
        font-family: serif;
    }

    .directory-hero-inner {
        position: relative;
        z-index: 1;
        padding: 2.75rem 3rem;
    }

    .directory-hero h1 {
        font-size: 2.45rem;
        font-weight: 900;
        letter-spacing: -0.05em;
        margin-bottom: 0.5rem;
    }

    .directory-hero p {
        max-width: 680px;
        color: rgba(255, 255, 255, 0.9);
        font-weight: 600;
        margin-bottom: 0;
    }

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

    @media (max-width: 767.98px) {
        .directory-page {
            padding-top: 1.5rem;
        }

        .directory-hero-inner {
            padding: 2rem 1.5rem;
        }

        .directory-hero h1 {
            font-size: 2rem;
        }

        .directory-hero::after {
            font-size: 8rem;
            top: 1rem;
            right: -0.5rem;
        }
    }
</style>

<main class="page-container directory-page">

    <section class="directory-hero">
        <div class="directory-hero-inner">
            <h1>District Directory</h1>

            <p>
                Search Irwell Valley contacts by name, group, role, accreditation or profile details.
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
                               placeholder="Name, email, role, about me...">
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

                            <?php foreach ($roles as $roleOption): ?>
                                <option value="<?= e($roleOption) ?>"
                                    <?= $role === $roleOption ? 'selected' : '' ?>>
                                    <?= e($roleOption) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="form-group col-lg-2">
                        <label for="accreditation">Accreditation</label>

                        <input type="search"
                               class="form-control"
                               id="accreditation"
                               name="accreditation"
                               value="<?= e($accreditation) ?>"
                               placeholder="Nights Away">
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
                                                <?= e(directory_value($contact['scout_role'] ?? null)) ?>
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
                                <div class="meta-label">Email</div>

                                <div>
                                    <?php if (!empty($contact['email'])): ?>
                                        <a href="mailto:<?= e($contact['email']) ?>">
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

                            <?php if (!empty($contact['accreditations'])): ?>
                                <div class="mb-3">
                                    <div class="meta-label">Accreditations</div>

                                    <div class="pre-line">
                                        <?= e($contact['accreditations']) ?>
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