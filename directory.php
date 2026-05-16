<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

$appName = app_config('APP_NAME', 'Irwell Valley District Scouts');
$user = current_user();

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = trim((string) ($_GET['q'] ?? ''));
$groupId = (int) ($_GET['group_id'] ?? 0);
$role = trim((string) ($_GET['role'] ?? ''));
$accreditation = trim((string) ($_GET['accreditation'] ?? ''));

$pdo = db();

/*
|--------------------------------------------------------------------------
| Groups for filter dropdown
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
| Roles for filter dropdown
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT DISTINCT scout_role
    FROM group_contacts
    WHERE scout_role IS NOT NULL
      AND scout_role <> ''
    ORDER BY scout_role ASC
");

$roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

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

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>District Directory | <?= e($appName) ?></title>
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

        .directory-hero {
            background: #7413dc;
            color: #ffffff;
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .filter-card,
        .contact-card {
            border: 0;
            border-radius: 1rem;
            box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.06);
        }

        .contact-card {
            height: 100%;
        }

        .avatar {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: #7413dc;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            flex-shrink: 0;
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
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 800;
            color: #707070;
            margin-bottom: 0.15rem;
        }

        .pre-line {
            white-space: pre-line;
        }

        .empty-state {
            background: #ffffff;
            border-radius: 1rem;
            padding: 3rem;
            text-align: center;
            box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.06);
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <a class="navbar-brand" href="/index.php"><?= e($appName) ?></a>

    <div class="ml-auto">
        <a class="btn btn-outline-light btn-sm mr-2" href="/index.php">Dashboard</a>
        <a class="btn btn-outline-light btn-sm mr-2" href="/profile.php">My Profile</a>
        <a class="btn btn-outline-light btn-sm" href="/logout.php">Sign out</a>
    </div>
</nav>

<main class="container py-5">

    <section class="directory-hero">
        <h1 class="h2 mb-2">District Directory</h1>
        <p class="mb-0">
            Search contacts by name, group, role, accreditations or profile details.
        </p>
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
                        <select class="form-control" id="group_id" name="group_id">
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
                        <select class="form-control" id="role" name="role">
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
                    <button type="submit" class="btn btn-primary mr-2">
                        Search directory
                    </button>

                    <a href="/directory.php" class="btn btn-outline-secondary">
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
                ?>

                <div class="col-lg-6 mb-4">
                    <article class="card contact-card">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-start mb-3">
                                <div class="avatar mr-3">
                                    <?= e(directory_initials($name)) ?>
                                </div>

                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h2 class="h5 mb-1"><?= e($name) ?></h2>
                                            <p class="text-muted mb-1">
                                                <?= e(directory_value($contact['scout_role'] ?? null)) ?>
                                            </p>
                                        </div>

                                        <?php if ($isLinked): ?>
                                            <span class="badge badge-linked">SSO linked</span>
                                        <?php else: ?>
                                            <span class="badge badge-unlinked">Directory only</span>
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
                                    <div class="pre-line"><?= e($contact['accreditations']) ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($contact['about_me'])): ?>
                                <div>
                                    <div class="meta-label">About</div>
                                    <div class="pre-line"><?= e($contact['about_me']) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"
        crossorigin="anonymous"></script>

<script src="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/js/bootstrap.min.js"
        crossorigin="anonymous"></script>

</body>
</html>