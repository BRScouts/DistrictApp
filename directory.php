<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (is_file(__DIR__ . '/app/group-manager-helpers.php')) {
    require_once __DIR__ . '/app/group-manager-helpers.php';
}

require_login();

if (user_needs_group_onboarding()) {
    redirect('/onboarding.php');
}

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
$pdo = db();

$pageTitle = 'Directory | ' . $appName;
$heroTitle = 'District Directory';
$heroText = 'Find active volunteers by name, Group, role, section or accreditation.';
$breadcrumb = '<a href="/index.php">Home</a> / Directory';

function directory_table_exists(string $table): bool
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

function directory_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function directory_int_param(string $key, int $default, int $min, int $max): int
{
    $value = isset($_GET[$key]) ? (int) $_GET[$key] : $default;

    if ($value < $min) {
        return $min;
    }

    if ($value > $max) {
        return $max;
    }

    return $value;
}

function directory_query_with(array $changes): string
{
    $query = $_GET;

    foreach ($changes as $key => $value) {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return '/directory.php' . ($query ? '?' . http_build_query($query) : '');
}

function directory_role_label(?string $role): string
{
    if ($role === null || $role === '') {
        return 'Member';
    }

    if (function_exists('gm_role_title_from_membership_role')) {
        return gm_role_title_from_membership_role($role);
    }

    return ucwords(str_replace('_', ' ', $role));
}

function directory_json_list(?string $json): array
{
    if (!$json) {
        return [];
    }

    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter(array_map('strval', $decoded)));
}

function directory_compact(?string $value, int $limit = 90): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strlen') && mb_strlen($value) > $limit) {
        return mb_substr($value, 0, $limit - 1) . '…';
    }

    if (strlen($value) > $limit) {
        return substr($value, 0, $limit - 1) . '…';
    }

    return $value;
}

function directory_profile_photo_url(array $person): string
{
    foreach ([
        'profile_photo_url',
        'photo_url',
        'avatar_url',
        'picture_url',
        'microsoft_photo_url',
        'ms_photo_url',
    ] as $field) {
        if (!empty($person[$field])) {
            return (string) $person[$field];
        }
    }

    if (!empty($person['id'])) {
        $localPath = '/uploads/profile-photos/' . (int) $person['id'] . '.jpg';
        $localFile = __DIR__ . $localPath;

        if (is_file($localFile)) {
            return $localPath;
        }
    }

    return '';
}

$search = trim((string) ($_GET['q'] ?? ''));
$groupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0;
$role = trim((string) ($_GET['role'] ?? ''));
$accreditation = trim((string) ($_GET['accreditation'] ?? ''));
$page = directory_int_param('page', 1, 1, 100000);
$perPage = directory_int_param('per_page', 25, 10, 100);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->query("
    SELECT id, group_name
    FROM groups
    WHERE is_active = 1
    ORDER BY group_name ASC
");
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

$roleOptions = portal_role_options();
$accreditationOptions = portal_accreditation_options();
$allowedAccreditations = portal_flatten_options($accreditationOptions);

if ($role !== '' && !in_array($role, $roleOptions, true)) {
    $role = '';
}

if ($accreditation !== '' && !in_array($accreditation, $allowedAccreditations, true)) {
    $accreditation = '';
}

$hasSections = directory_table_exists('group_membership_sections')
    && directory_table_exists('group_sections');

$photoColumns = [];
foreach ([
    'profile_photo_url',
    'photo_url',
    'avatar_url',
    'picture_url',
    'microsoft_photo_url',
    'ms_photo_url',
] as $column) {
    if (directory_column_exists('people', $column)) {
        $photoColumns[] = 'p.`' . $column . '`';
    }
}

$photoSelect = $photoColumns ? ', ' . implode(', ', $photoColumns) : '';

$sectionJoin = $hasSections
    ? "
        LEFT JOIN group_membership_sections gms
          ON gms.group_membership_id = gm.id
        LEFT JOIN group_sections gs
          ON gs.id = gms.group_section_id
         AND gs.is_active = 1
    "
    : "";

$sectionSelect = $hasSections
    ? "GROUP_CONCAT(DISTINCT gs.section_name ORDER BY gs.section_name SEPARATOR ', ') AS section_names,"
    : "'' AS section_names,";

$where = [
    "p.status = 'active'",
];

$params = [];

if ($search !== '') {
    $where[] = "(
        p.full_name LIKE :search
        OR p.primary_email LIKE :search
        OR dp.role_title LIKE :search
        OR dp.about_me LIKE :search
        OR g.group_name LIKE :search
        " . ($hasSections ? "OR gs.section_name LIKE :search" : "") . "
        OR dp.accreditations_json LIKE :search
    )";
    $params['search'] = '%' . $search . '%';
}

if ($groupId > 0) {
    $where[] = "gm.group_id = :group_id";
    $params['group_id'] = $groupId;
}

if ($role !== '') {
    $where[] = "dp.role_title = :role_title";
    $params['role_title'] = $role;
}

if ($accreditation !== '') {
    $where[] = "dp.accreditations_json LIKE :accreditation";
    $params['accreditation'] = '%' . $accreditation . '%';
}

$whereSql = implode("\n      AND ", $where);

$countSql = "
    SELECT COUNT(DISTINCT p.id)
    FROM people p
    LEFT JOIN directory_profiles dp
      ON dp.person_id = p.id
    LEFT JOIN group_memberships gm
      ON gm.person_id = p.id
     AND gm.status = 'active'
    LEFT JOIN groups g
      ON g.id = gm.group_id
     AND g.is_active = 1
    {$sectionJoin}
    WHERE {$whereSql}
";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalPeople = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalPeople / $perPage));

if ($page > $totalPages) {
    redirect(directory_query_with(['page' => $totalPages]));
}

$sql = "
    SELECT
        p.id,
        p.full_name,
        p.primary_email,
        p.phone,
        p.status
        {$photoSelect},
        dp.role_title,
        dp.about_me,
        dp.accreditations_json,
        dp.share_phone,
        dp.visible_in_directory,
        GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ') AS group_names,
        GROUP_CONCAT(DISTINCT gm.membership_role ORDER BY gm.membership_role SEPARATOR ', ') AS membership_roles,
        {$sectionSelect}
        MAX(CASE WHEN ua.provider = 'microsoft' THEN 1 ELSE 0 END) AS has_microsoft_account
    FROM people p
    LEFT JOIN directory_profiles dp
      ON dp.person_id = p.id
    LEFT JOIN group_memberships gm
      ON gm.person_id = p.id
     AND gm.status = 'active'
    LEFT JOIN groups g
      ON g.id = gm.group_id
     AND g.is_active = 1
    {$sectionJoin}
    LEFT JOIN user_accounts ua
      ON ua.person_id = p.id
    WHERE {$whereSql}
    GROUP BY
        p.id,
        p.full_name,
        p.primary_email,
        p.phone,
        p.status
        {$photoSelect},
        dp.role_title,
        dp.about_me,
        dp.accreditations_json,
        dp.share_phone,
        dp.visible_in_directory
    ORDER BY p.full_name ASC
    LIMIT {$perPage}
    OFFSET {$offset}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$people = $stmt->fetchAll(PDO::FETCH_ASSOC);

$defaultPhotoUrl = BASE_URL . '/assets/img/default-profile.webp';

$directoryPeople = [];

foreach ($people as $person) {
    $accreditations = directory_json_list($person['accreditations_json'] ?? null);
    $photoUrl = directory_profile_photo_url($person);
    $initials = strtoupper(substr(trim((string) ($person['full_name'] ?? 'U')), 0, 1));

    // If no stored photo but they have a Microsoft account, use the proxy endpoint
    if ($photoUrl === '' && (int) ($person['has_microsoft_account'] ?? 0) === 1) {
        $photoUrl = '/auth/directory-photo.php?id=' . (int) $person['id'];
    }

    $directoryPeople[] = [
        'id' => (int) $person['id'],
        'name' => (string) ($person['full_name'] ?? 'Unknown volunteer'),
        'email' => (string) ($person['primary_email'] ?? ''),
        'phone' => ((int) ($person['share_phone'] ?? 0) === 1) ? (string) ($person['phone'] ?? '') : '',
        'role_title' => (string) ($person['role_title'] ?? ''),
        'group_names' => (string) ($person['group_names'] ?? ''),
        'membership_roles' => array_values(array_filter(array_map(
            static fn(string $role): string => directory_role_label($role),
            explode(',', (string) ($person['membership_roles'] ?? ''))
        ))),
        'section_names' => (string) ($person['section_names'] ?? ''),
        'about_me' => (string) ($person['about_me'] ?? ''),
        'accreditations' => $accreditations,
        'accreditation_count' => count($accreditations),
        'has_microsoft_account' => (int) ($person['has_microsoft_account'] ?? 0) === 1,
        'photo_url' => $photoUrl,
        'initials' => $initials,
    ];
}

?>
<?php include __DIR__ . '/header.php'; ?>

<style>
    .directory-toolbar {
        background: #ffffff;
        border: 1px solid #e6e6e6;
        border-radius: .75rem;
        padding: 1rem;
        margin-bottom: 1rem;
        box-shadow: 0 2px 12px rgba(0, 0, 0, .04);
    }

    .directory-toolbar-grid {
        display: grid;
        gap: .75rem;
    }

    @media (min-width: 960px) {
        .directory-toolbar-grid {
            grid-template-columns: minmax(220px, 1.6fr) minmax(160px, 1fr) minmax(160px, 1fr) minmax(180px, 1fr) auto;
            align-items: end;
        }
    }

    .directory-toolbar-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        align-items: center;
    }

    .directory-results-head {
        display: flex;
        flex-direction: column;
        gap: .35rem;
        margin-bottom: .75rem;
    }

    @media (min-width: 700px) {
        .directory-results-head {
            flex-direction: row;
            align-items: center;
            justify-content: space-between;
        }
    }

    .directory-count {
        color: #555;
        font-weight: 700;
    }

    .directory-table-wrap {
        background: #ffffff;
        border: 1px solid #e6e6e6;
        border-radius: .75rem;
        overflow-x: auto;
        box-shadow: 0 2px 12px rgba(0, 0, 0, .04);
    }

    .directory-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
    }

    .directory-table th,
    .directory-table td {
        padding: .85rem;
        border-bottom: 1px solid #e6e6e6;
        vertical-align: middle;
    }

    .directory-table th {
        background: #f7f5fb;
        color: #4d0b93;
        font-weight: 900;
        white-space: nowrap;
    }

    .directory-table tr:last-child td {
        border-bottom: 0;
    }

    .directory-row {
        cursor: pointer;
    }

    .directory-row:hover {
        background: #fafafa;
    }

    .directory-person-cell {
        display: grid;
        grid-template-columns: 44px minmax(180px, 1fr);
        gap: .7rem;
        align-items: center;
    }

    .directory-avatar {
        width: 44px;
        height: 44px;
        border-radius: 999px;
        background: #7413dc;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        overflow: hidden;
    }

    .directory-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .directory-name {
        display: block;
        color: #1d1d1b;
        font-weight: 900;
        line-height: 1.15;
    }

    .directory-email {
        display: block;
        color: #555;
        font-size: .9rem;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .directory-muted {
        color: #555;
        font-weight: 700;
    }

    .directory-open-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 36px;
        padding: .35rem .7rem;
        border-radius: .35rem;
        border: 0;
        background: #7413dc;
        color: #ffffff;
        font-weight: 900;
        cursor: pointer;
    }

    .directory-open-btn:hover,
    .directory-open-btn:focus {
        background: #4d0b93;
        color: #ffffff;
    }

    .directory-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        background: #f3f2f1;
        color: #333;
        padding: .15rem .5rem;
        font-size: .78rem;
        font-weight: 900;
        margin: .1rem;
    }

    .directory-badge-purple {
        background: #f7f5fb;
        color: #4d0b93;
    }

    .directory-empty {
        background: #ffffff;
        border: 1px solid #e6e6e6;
        border-radius: .75rem;
        padding: 1rem;
    }

    .directory-pagination {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
        align-items: center;
        margin-top: 1rem;
    }

    .directory-pagination a,
    .directory-pagination span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 40px;
        min-height: 40px;
        padding: .35rem .65rem;
        border-radius: .35rem;
        border: 1px solid #d6d6d6;
        background: #ffffff;
        color: #4d0b93;
        font-weight: 900;
        text-decoration: none;
    }

    .directory-pagination .current {
        background: #4d0b93;
        color: #ffffff;
        border-color: #4d0b93;
    }

    .directory-pagination .disabled {
        color: #777;
        background: #f3f2f1;
    }

    .directory-drawer-backdrop {
        position: fixed;
        inset: 0;
        display: none;
        background: rgba(0, 0, 0, .5);
        z-index: 2000;
    }

    .directory-drawer-backdrop.is-open {
        display: block;
    }

    .directory-drawer {
        position: fixed;
        top: 0;
        right: 0;
        width: min(520px, 100%);
        height: 100%;
        background: #ffffff;
        border-left: 1px solid #e6e6e6;
        box-shadow: -12px 0 32px rgba(0, 0, 0, .22);
        overflow-y: auto;
        transform: translateX(100%);
        transition: transform .18s ease-in-out;
        z-index: 2001;
    }

    .directory-drawer.is-open {
        transform: translateX(0);
    }

    .directory-drawer-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: flex-start;
        padding: 1rem;
        background: #f7f5fb;
        border-bottom: 1px solid #e6e6e6;
    }

    .directory-drawer-title {
        display: grid;
        grid-template-columns: 58px minmax(0, 1fr);
        gap: .85rem;
        align-items: center;
    }

    .directory-drawer-title h2 {
        margin: 0;
        color: #4d0b93;
        font-size: 1.35rem;
        font-weight: 900;
        line-height: 1.15;
    }

    .directory-drawer-close {
        border: 0;
        background: transparent;
        color: #1d1d1b;
        font-size: 1.75rem;
        font-weight: 900;
        line-height: 1;
        cursor: pointer;
    }

    .directory-drawer-body {
        padding: 1rem;
    }

    .directory-detail-section {
        margin-bottom: 1.25rem;
    }

    .directory-detail-section h3 {
        margin: 0 0 .5rem;
        color: #4d0b93;
        font-size: 1.05rem;
        font-weight: 900;
    }

    .directory-detail-list {
        display: grid;
        gap: .4rem;
        margin: 0;
    }

    @media (min-width: 500px) {
        .directory-detail-list {
            grid-template-columns: 130px minmax(0, 1fr);
        }
    }

    .directory-detail-list dt {
        font-weight: 900;
    }

    .directory-detail-list dd {
        margin: 0;
        overflow-wrap: anywhere;
    }

    .directory-tag-list {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
    }

    .directory-mobile-cards {
        display: none;
    }

    @media (max-width: 760px) {
        .directory-table-wrap {
            display: none;
        }

        .directory-mobile-cards {
            display: grid;
            gap: .75rem;
        }

        .directory-mobile-card {
            background: #ffffff;
            border: 1px solid #e6e6e6;
            border-radius: .75rem;
            padding: 1rem;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .04);
        }

        .directory-mobile-card .directory-open-btn {
            margin-top: .75rem;
            width: 100%;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .directory-drawer {
            transition: none;
        }
    }
</style>

<main class="lt-main">
    <section class="directory-toolbar" aria-label="Directory search">
        <form method="get">
            <div class="directory-toolbar-grid">
                <div class="form-group mb-lg-0">
                    <label for="q">Search</label>
                    <input
                        type="search"
                        id="q"
                        name="q"
                        class="form-control"
                        value="<?= e($search) ?>"
                        placeholder="Name, Group, role, section or accreditation"
                    >
                </div>

                <div class="form-group mb-lg-0">
                    <label for="group_id">Group</label>
                    <select id="group_id" name="group_id" class="form-control">
                        <option value="0">All Groups</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>" <?= $groupId === (int) $group['id'] ? 'selected' : '' ?>>
                                <?= e((string) $group['group_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-lg-0">
                    <label for="role">Role</label>
                    <select id="role" name="role" class="form-control">
                        <option value="">All roles</option>
                        <?php foreach ($roleOptions as $roleOption): ?>
                            <option value="<?= e($roleOption) ?>" <?= $role === $roleOption ? 'selected' : '' ?>>
                                <?= e($roleOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-lg-0">
                    <label for="accreditation">Accreditation</label>
                    <select id="accreditation" name="accreditation" class="form-control">
                        <option value="">All accreditations</option>
                        <?php foreach ($accreditationOptions as $category => $items): ?>
                            <optgroup label="<?= e((string) $category) ?>">
                                <?php foreach ($items as $item): ?>
                                    <option value="<?= e($item) ?>" <?= $accreditation === $item ? 'selected' : '' ?>>
                                        <?= e($item) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="directory-toolbar-actions">
                    <button class="btn btn-primary lt-btn" type="submit">Search</button>
                    <a class="btn lt-btn lt-btn-secondary" href="/directory.php">Clear</a>
                </div>
            </div>

            <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
        </form>
    </section>

    <div class="directory-results-head">
        <div>
            <h2 class="lt-section-title mb-1">Active volunteers</h2>
            <div class="directory-count">
                <?= number_format($totalPeople) ?> result<?= $totalPeople === 1 ? '' : 's' ?>
                <?php if ($totalPeople > 0): ?>
                    · page <?= number_format($page) ?> of <?= number_format($totalPages) ?>
                <?php endif; ?>
            </div>
        </div>

        <form method="get" class="form-inline">
            <?php foreach ($_GET as $key => $value): ?>
                <?php if ($key === 'per_page' || $key === 'page') {
                    continue;
                } ?>
                <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
            <?php endforeach; ?>

            <label class="font-weight-bold mr-2" for="per_page">Show</label>
            <select id="per_page" name="per_page" class="form-control" onchange="this.form.submit()">
                <option value="10" <?= $perPage === 10 ? 'selected' : '' ?>>10</option>
                <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
                <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
            </select>
        </form>
    </div>

    <?php if (!$directoryPeople): ?>
        <div class="directory-empty">
            <h3 class="h5 font-weight-bold">No active volunteers found</h3>
            <p class="mb-0">Try a broader search or remove one of the filters.</p>
        </div>
    <?php else: ?>
        <div class="directory-table-wrap">
            <table class="directory-table">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th>Group</th>
                        <th>Role</th>
                        <th>Sections</th>
                        <th>Accreditations</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($directoryPeople as $person): ?>
                        <tr class="directory-row" data-directory-person-id="<?= (int) $person['id'] ?>" tabindex="0">
                            <td>
                                <div class="directory-person-cell">
                                    <span class="directory-avatar" aria-hidden="true">
                                        <?php if ($person['photo_url'] !== ''): ?>
                                            <img src="<?= e($person['photo_url']) ?>" alt="" onerror="this.src='<?= e($defaultPhotoUrl) ?>'; this.onerror=null;">
                                        <?php else: ?>
                                            <img src="<?= e($defaultPhotoUrl) ?>" alt="">
                                        <?php endif; ?>
                                    </span>

                                    <span>
                                        <span class="directory-name"><?= e($person['name']) ?></span>
                                        <span class="directory-email"><?= e($person['email']) ?></span>
                                    </span>
                                </div>
                            </td>
                            <td><?= e($person['group_names'] ?: '—') ?></td>
                            <td><?= !empty($person['membership_roles']) ? e(implode(', ', $person['membership_roles'])) : e($person['role_title'] ?: '—') ?></td>
                            <td><?= e($person['section_names'] ?: '—') ?></td>
                            <td>
                                <?php if ($person['accreditation_count'] > 0): ?>
                                    <span class="directory-badge directory-badge-purple">
                                        <?= (int) $person['accreditation_count'] ?> listed
                                    </span>
                                <?php else: ?>
                                    <span class="directory-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="directory-open-btn" data-directory-open="<?= (int) $person['id'] ?>">
                                    Open
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="directory-mobile-cards">
            <?php foreach ($directoryPeople as $person): ?>
                <article class="directory-mobile-card">
                    <div class="directory-person-cell">
                        <span class="directory-avatar" aria-hidden="true">
                            <?php if ($person['photo_url'] !== ''): ?>
                                <img src="<?= e($person['photo_url']) ?>" alt="" onerror="this.src='<?= e($defaultPhotoUrl) ?>'; this.onerror=null;">
                            <?php else: ?>
                                <img src="<?= e($defaultPhotoUrl) ?>" alt="">
                            <?php endif; ?>
                        </span>

                        <span>
                            <span class="directory-name"><?= e($person['name']) ?></span>
                            <span class="directory-email"><?= e($person['email']) ?></span>
                        </span>
                    </div>

                    <p class="mb-1 mt-2"><strong>Group:</strong> <?= e($person['group_names'] ?: '—') ?></p>
                    <p class="mb-1"><strong>Role:</strong> <?= !empty($person['membership_roles']) ? e(implode(', ', $person['membership_roles'])) : e($person['role_title'] ?: '—') ?></p>
                    <p class="mb-0"><strong>Accreditations:</strong> <?= (int) $person['accreditation_count'] ?></p>

                    <button type="button" class="directory-open-btn" data-directory-open="<?= (int) $person['id'] ?>">
                        Open profile
                    </button>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="directory-pagination" aria-label="Directory pages">
                <?php if ($page > 1): ?>
                    <a href="<?= e(directory_query_with(['page' => $page - 1])) ?>">Previous</a>
                <?php else: ?>
                    <span class="disabled">Previous</span>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                ?>

                <?php if ($startPage > 1): ?>
                    <a href="<?= e(directory_query_with(['page' => 1])) ?>">1</a>
                    <?php if ($startPage > 2): ?><span class="disabled">…</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                    <?php if ($p === $page): ?>
                        <span class="current"><?= $p ?></span>
                    <?php else: ?>
                        <a href="<?= e(directory_query_with(['page' => $p])) ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?><span class="disabled">…</span><?php endif; ?>
                    <a href="<?= e(directory_query_with(['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(directory_query_with(['page' => $page + 1])) ?>">Next</a>
                <?php else: ?>
                    <span class="disabled">Next</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</main>

<div class="directory-drawer-backdrop" id="directory-drawer-backdrop" aria-hidden="true"></div>

<aside class="directory-drawer" id="directory-drawer" aria-hidden="true" aria-labelledby="directory-drawer-title">
    <div class="directory-drawer-header">
        <div class="directory-drawer-title">
            <span class="directory-avatar" id="directory-drawer-avatar" aria-hidden="true"></span>
            <div>
                <h2 id="directory-drawer-title">Volunteer profile</h2>
                <div class="directory-muted" id="directory-drawer-subtitle"></div>
            </div>
        </div>

        <button type="button" class="directory-drawer-close" id="directory-drawer-close" aria-label="Close profile">×</button>
    </div>

    <div class="directory-drawer-body" id="directory-drawer-body"></div>
</aside>

<script <?= csp_nonce_attr() ?>>
(function () {
    var people = <?= json_encode($directoryPeople, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var peopleById = {};

    people.forEach(function (person) {
        peopleById[String(person.id)] = person;
    });

    var backdrop = document.getElementById('directory-drawer-backdrop');
    var drawer = document.getElementById('directory-drawer');
    var closeButton = document.getElementById('directory-drawer-close');
    var title = document.getElementById('directory-drawer-title');
    var subtitle = document.getElementById('directory-drawer-subtitle');
    var body = document.getElementById('directory-drawer-body');
    var avatar = document.getElementById('directory-drawer-avatar');

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function listTags(items) {
        if (!items || !items.length) {
            return '<p class="mb-0 directory-muted">None listed.</p>';
        }

        return '<div class="directory-tag-list">' + items.map(function (item) {
            return '<span class="directory-badge directory-badge-purple">' + escapeHtml(item) + '</span>';
        }).join('') + '</div>';
    }

    function splitList(value) {
        return String(value || '')
            .split(',')
            .map(function (item) { return item.trim(); })
            .filter(Boolean);
    }

    function profileHtml(person) {
        var contactRows = '' +
            '<dt>Email</dt><dd>' + (person.email ? '<a href="mailto:' + escapeHtml(person.email) + '">' + escapeHtml(person.email) + '</a>' : '—') + '</dd>' +
            '<dt>Phone</dt><dd>' + (person.phone ? escapeHtml(person.phone) : 'Not shared') + '</dd>' +
            '<dt>Microsoft</dt><dd>' + (person.has_microsoft_account ? 'Linked Microsoft account' : 'Not linked') + '</dd>';

        return '' +
            '<section class="directory-detail-section">' +
                '<h3>Contact</h3>' +
                '<dl class="directory-detail-list">' + contactRows + '</dl>' +
            '</section>' +

            '<section class="directory-detail-section">' +
                '<h3>Group and role</h3>' +
                '<dl class="directory-detail-list">' +
                    '<dt>Group</dt><dd>' + escapeHtml(person.group_names || '—') + '</dd>' +
                    '<dt>Directory role</dt><dd>' + escapeHtml(person.role_title || '—') + '</dd>' +
                    '<dt>Access role</dt><dd>' + (person.membership_roles && person.membership_roles.length ? listTags(person.membership_roles) : '—') + '</dd>' +
                    '<dt>Sections</dt><dd>' + (person.section_names ? escapeHtml(person.section_names) : '—') + '</dd>' +
                '</dl>' +
            '</section>' +

            '<section class="directory-detail-section">' +
                '<h3>About</h3>' +
                '<p class="mb-0">' + escapeHtml(person.about_me || 'No profile information added yet.') + '</p>' +
            '</section>' +

            '<section class="directory-detail-section">' +
                '<h3>Permits and accreditations</h3>' +
                listTags(person.accreditations || []) +
            '</section>';
    }

    var defaultPhotoUrl = <?= json_encode($defaultPhotoUrl) ?>;

    function renderAvatar(person) {
        if (!avatar) {
            return;
        }

        avatar.innerHTML = '';

        var img = document.createElement('img');
        img.alt = '';

        if (person.photo_url) {
            img.src = person.photo_url;
            img.onerror = function () {
                img.src = defaultPhotoUrl;
                img.onerror = null;
            };
        } else {
            img.src = defaultPhotoUrl;
        }

        avatar.appendChild(img);
    }

    function openProfile(personId) {
        var person = peopleById[String(personId)];

        if (!person || !drawer || !backdrop || !title || !body) {
            return;
        }

        title.textContent = person.name || 'Volunteer profile';
        subtitle.textContent = person.group_names || person.role_title || '';
        body.innerHTML = profileHtml(person);
        renderAvatar(person);

        drawer.classList.add('is-open');
        backdrop.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        backdrop.setAttribute('aria-hidden', 'false');

        if (closeButton) {
            closeButton.focus();
        }
    }

    function closeProfile() {
        if (!drawer || !backdrop) {
            return;
        }

        drawer.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        backdrop.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('[data-directory-open]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            openProfile(button.getAttribute('data-directory-open'));
        });
    });

    document.querySelectorAll('.directory-row').forEach(function (row) {
        row.addEventListener('click', function () {
            openProfile(row.getAttribute('data-directory-person-id'));
        });

        row.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openProfile(row.getAttribute('data-directory-person-id'));
            }
        });
    });

    if (closeButton) {
        closeButton.addEventListener('click', closeProfile);
    }

    if (backdrop) {
        backdrop.addEventListener('click', closeProfile);
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeProfile();
        }
    });
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>