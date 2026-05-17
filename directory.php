<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

if (user_needs_group_onboarding()) {
    redirect('/onboarding.php');
}

$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
$pdo = db();

$search = trim((string) ($_GET['q'] ?? ''));
$groupId = (int) ($_GET['group_id'] ?? 0);
$role = trim((string) ($_GET['role'] ?? ''));
$sectionId = (int) ($_GET['section_id'] ?? 0);
$accreditation = trim((string) ($_GET['accreditation'] ?? ''));

$groups = $pdo->query("SELECT id, group_name FROM groups WHERE is_active = 1 ORDER BY group_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$sections = $pdo->query("\n    SELECT gs.id, gs.section_name, gs.section_type, g.group_name\n    FROM group_sections gs\n    JOIN groups g ON g.id = gs.group_id\n    WHERE gs.is_active = 1 AND g.is_active = 1\n    ORDER BY g.group_name ASC, gs.sort_order ASC, gs.section_name ASC\n")->fetchAll(PDO::FETCH_ASSOC);
$roles = $pdo->query("\n    SELECT DISTINCT role_title\n    FROM directory_profiles\n    WHERE role_title IS NOT NULL AND role_title <> ''\n    ORDER BY role_title ASC\n")->fetchAll(PDO::FETCH_COLUMN);

$accreditationOptions = portal_accreditation_options();
$flatAccreditations = portal_flatten_options($accreditationOptions);

$sql = "\n    SELECT\n        p.id, p.full_name, p.primary_email, p.phone,\n        dp.role_title, dp.about_me, dp.accreditations_json, dp.share_phone,\n        GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ') AS group_names,\n        GROUP_CONCAT(DISTINCT gs.section_name ORDER BY gs.section_name SEPARATOR ', ') AS section_names,\n        MAX(CASE WHEN ua.provider = 'microsoft' THEN 1 ELSE 0 END) AS has_microsoft_account\n    FROM people p\n    JOIN directory_profiles dp ON dp.person_id = p.id\n    LEFT JOIN group_memberships gm ON gm.person_id = p.id AND gm.status = 'active'\n    LEFT JOIN groups g ON g.id = gm.group_id AND g.is_active = 1\n    LEFT JOIN group_membership_sections gms ON gms.group_membership_id = gm.id\n    LEFT JOIN group_sections gs ON gs.id = gms.group_section_id AND gs.is_active = 1\n    LEFT JOIN user_accounts ua ON ua.person_id = p.id\n    WHERE p.status = 'active'\n      AND dp.visible_in_directory = 1\n";
$params = [];

if ($search !== '') {
    $sql .= "\n      AND (\n        p.full_name LIKE :search\n        OR p.primary_email LIKE :search\n        OR dp.role_title LIKE :search\n        OR dp.about_me LIKE :search\n        OR dp.accreditations_json LIKE :search\n        OR g.group_name LIKE :search\n        OR gs.section_name LIKE :search\n      )\n    ";
    $params['search'] = '%' . $search . '%';
}

if ($groupId > 0) {
    $sql .= " AND gm.group_id = :group_id";
    $params['group_id'] = $groupId;
}

if ($role !== '') {
    $sql .= " AND dp.role_title = :role";
    $params['role'] = $role;
}

if ($sectionId > 0) {
    $sql .= " AND gs.id = :section_id";
    $params['section_id'] = $sectionId;
}

if ($accreditation !== '') {
    $sql .= " AND dp.accreditations_json LIKE :accreditation";
    $params['accreditation'] = '%' . $accreditation . '%';
}

$sql .= "\n    GROUP BY p.id, p.full_name, p.primary_email, p.phone, dp.role_title, dp.about_me, dp.accreditations_json, dp.share_phone\n    ORDER BY p.full_name ASC\n";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$people = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Directory | ' . $appName;
$heroTitle = 'District Directory';
$heroText = 'Find volunteers by name, Group, section, role or accreditation.';
$breadcrumb = '<a href="/index.php">Home</a> / Directory';
?>
<?php include __DIR__ . '/header.php'; ?>

<main class="lt-main">
    <form method="get" class="lt-panel mb-4">
        <h2 class="lt-section-title">Search directory</h2>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label for="q">Search</label>
                <input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>">
            </div>
            <div class="form-group col-md-3">
                <label for="group_id">Group</label>
                <select class="form-control" id="group_id" name="group_id">
                    <option value="0">All Groups</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>" <?= $groupId === (int) $group['id'] ? 'selected' : '' ?>><?= e($group['group_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-md-3">
                <label for="role">Role</label>
                <select class="form-control" id="role" name="role">
                    <option value="">All roles</option>
                    <?php foreach ($roles as $roleOption): ?>
                        <option value="<?= e($roleOption) ?>" <?= $role === $roleOption ? 'selected' : '' ?>><?= e($roleOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-md-2 d-flex align-items-end">
                <button class="btn btn-primary lt-btn btn-block" type="submit">Search</button>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label for="section_id">Section</label>
                <select class="form-control" id="section_id" name="section_id">
                    <option value="0">All sections</option>
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>><?= e($section['group_name'] . ' — ' . $section['section_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-md-6">
                <label for="accreditation">Permit or accreditation</label>
                <select class="form-control" id="accreditation" name="accreditation">
                    <option value="">All permits and accreditations</option>
                    <?php foreach ($flatAccreditations as $item): ?>
                        <option value="<?= e($item) ?>" <?= $accreditation === $item ? 'selected' : '' ?>><?= e($item) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>

    <h2 class="lt-section-title"><?= count($people) ?> result<?= count($people) === 1 ? '' : 's' ?></h2>

    <div class="row">
        <?php foreach ($people as $person): ?>
            <?php $accreditations = portal_decode_json_list($person['accreditations_json'] ?? null); ?>
            <div class="col-md-6 col-xl-4 mb-4">
                <article class="lt-task-card">
                    <h3><?= e($person['full_name']) ?></h3>
                    <p class="mb-2"><strong><?= e($person['role_title'] ?: 'Volunteer') ?></strong></p>
                    <p class="mb-2"><?= e($person['group_names'] ?: 'No Group listed') ?></p>
                    <?php if (!empty($person['section_names'])): ?><p class="mb-2">Sections: <?= e($person['section_names']) ?></p><?php endif; ?>
                    <p class="mb-2"><a href="mailto:<?= e($person['primary_email']) ?>"><?= e($person['primary_email']) ?></a></p>
                    <?php if ((int) $person['share_phone'] === 1 && !empty($person['phone'])): ?><p class="mb-2"><?= e($person['phone']) ?></p><?php endif; ?>
                    <?php if (!empty($person['about_me'])): ?><p><?= e($person['about_me']) ?></p><?php endif; ?>
                    <?php if ($accreditations): ?>
                        <div>
                            <?php foreach (array_slice($accreditations, 0, 4) as $item): ?>
                                <span class="lt-badge mb-1"><?= e($item) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>
