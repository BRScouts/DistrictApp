<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

$pdo = db();
$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

function da_table_exists(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $stmt->execute(['table_name' => $table]);
        return $cache[$table] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function da_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);
        return $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function da_table_columns(string $table): array
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $stmt->execute(['table_name' => $table]);
        return $cache[$table] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

function da_insert_flexible(string $table, array $values): bool
{
    if (!da_table_exists($table)) {
        return false;
    }

    $columns = da_table_columns($table);
    $insert = [];

    foreach ($values as $column => $value) {
        if (in_array((string) $column, $columns, true)) {
            $insert[(string) $column] = $value;
        }
    }

    if (!$insert) {
        return false;
    }

    $quotedColumns = array_map(
        static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
        array_keys($insert)
    );

    $placeholders = array_map(
        static fn(string $column): string => ':' . $column,
        array_keys($insert)
    );

    $stmt = db()->prepare(
        'INSERT INTO `' . str_replace('`', '``', $table) . '` (' .
        implode(', ', $quotedColumns) .
        ') VALUES (' .
        implode(', ', $placeholders) .
        ')'
    );

    return $stmt->execute($insert);
}

function da_update_flexible(string $table, string $idColumn, int $id, array $values): bool
{
    if (!da_table_exists($table) || !da_column_exists($table, $idColumn)) {
        return false;
    }

    $columns = da_table_columns($table);
    $update = [];

    foreach ($values as $column => $value) {
        if ($column !== $idColumn && in_array((string) $column, $columns, true)) {
            $update[(string) $column] = $value;
        }
    }

    if (!$update) {
        return false;
    }

    $sets = array_map(
        static fn(string $column): string => '`' . str_replace('`', '``', $column) . '` = :' . $column,
        array_keys($update)
    );

    $update['_id'] = $id;

    $stmt = db()->prepare(
        'UPDATE `' . str_replace('`', '``', $table) . '` SET ' .
        implode(', ', $sets) .
        ' WHERE `' . str_replace('`', '``', $idColumn) . '` = :_id'
    );

    return $stmt->execute($update);
}

function da_absolute_url(string $path): string
{
    $base = rtrim((string) app_config('APP_URL', ''), '/');

    if ($base !== '') {
        return $base . '/' . ltrim($path, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'app.irvalscouts.org.uk';

    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

function da_slugify(string $value): string
{
    $value = trim($value);
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'group';
}

function da_unique_group_slug(string $groupName, int $ignoreGroupId = 0): string
{
    $base = da_slugify($groupName);
    $slug = $base;
    $i = 1;

    while (true) {
        $stmt = db()->prepare("SELECT id FROM groups WHERE slug = :slug AND id <> :ignore_group_id LIMIT 1");
        $stmt->execute([
            'slug' => $slug,
            'ignore_group_id' => $ignoreGroupId,
        ]);

        if (!$stmt->fetchColumn()) {
            return $slug;
        }

        $slug = $base . '-' . $i;
        $i++;
    }
}

function da_current_memberships(int $personId): array
{
    if (function_exists('user_group_memberships')) {
        return user_group_memberships($personId, false);
    }

    $stmt = db()->prepare("
        SELECT gm.*, g.group_name
        FROM group_memberships gm
        JOIN groups g ON g.id = gm.group_id
        WHERE gm.person_id = :person_id
    ");
    $stmt->execute(['person_id' => $personId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function da_current_access_level(array $user, array $memberships): string
{
    $levels = [(string) ($user['highest_access_level'] ?? $user['role'] ?? 'member')];

    foreach ($memberships as $membership) {
        $levels[] = (string) ($membership['access_level'] ?? 'member');
    }

    $rank = [
        'system_admin' => 5,
        'district_admin' => 4,
        'district_reviewer' => 3,
        'group_admin' => 2,
        'member' => 1,
    ];

    usort(
        $levels,
        static fn(string $a, string $b): int => ($rank[$b] ?? 0) <=> ($rank[$a] ?? 0)
    );

    return $levels[0] ?? 'member';
}

function da_is_district_admin(string $accessLevel): bool
{
    return in_array($accessLevel, ['district_admin', 'system_admin'], true);
}

function da_can_assign_level(string $actorAccessLevel, string $targetAccessLevel): bool
{
    if ($actorAccessLevel === 'system_admin') {
        return in_array(
            $targetAccessLevel,
            ['system_admin', 'district_admin', 'district_reviewer', 'group_admin', 'member'],
            true
        );
    }

    if ($actorAccessLevel === 'district_admin') {
        return in_array(
            $targetAccessLevel,
            ['district_reviewer', 'group_admin', 'member'],
            true
        );
    }

    return false;
}

function da_permission_options(string $actorAccessLevel): array
{
    $options = [
        'member' => 'Member / normal leader',
        'group_admin' => 'Group admin / GLV access',
        'district_reviewer' => 'District reviewer',
    ];

    if ($actorAccessLevel === 'system_admin') {
        $options['district_admin'] = 'District admin';
        $options['system_admin'] = 'System admin';
    }

    return $options;
}

function da_membership_role_for_access_level(string $accessLevel): string
{
    return match ($accessLevel) {
        'group_admin' => 'group_lead_volunteer',
        'district_reviewer', 'district_admin', 'system_admin' => 'district_volunteer',
        default => 'section_leader',
    };
}

function da_log_action(int $actorPersonId, string $action, string $entityType, int $entityId, array $details = []): void
{
    if (!da_table_exists('audit_log')) {
        return;
    }

    try {
        da_insert_flexible('audit_log', [
            'actor_type' => 'person',
            'actor_person_id' => $actorPersonId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
    }
}

function da_fetch_groups(): array
{
    $hasLinksTable = da_table_exists('group_access_links');

    $linkSelect = $hasLinksTable
        ? "COUNT(DISTINCT CASE WHEN gal.status = 'active' THEN gal.id END) AS active_link_count"
        : "0 AS active_link_count";

    $linkJoin = $hasLinksTable
        ? "LEFT JOIN group_access_links gal ON gal.group_id = g.id"
        : "";

    $stmt = db()->query("
        SELECT
            g.*,
            COUNT(DISTINCT CASE WHEN gm.status = 'active' THEN gm.person_id END) AS active_people_count,
            GROUP_CONCAT(
                DISTINCT CASE
                    WHEN gm.status = 'active'
                     AND (
                        gm.membership_role = 'group_lead_volunteer'
                        OR gm.access_level = 'group_admin'
                     )
                    THEN p.full_name
                END
                ORDER BY p.full_name
                SEPARATOR ', '
            ) AS lead_volunteers,
            {$linkSelect}
        FROM groups g
        LEFT JOIN group_memberships gm ON gm.group_id = g.id
        LEFT JOIN people p ON p.id = gm.person_id
        {$linkJoin}
        GROUP BY g.id
        ORDER BY g.is_active DESC, g.group_name ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function da_fetch_group(int $groupId): ?array
{
    $stmt = db()->prepare("SELECT * FROM groups WHERE id = :group_id LIMIT 1");
    $stmt->execute(['group_id' => $groupId]);

    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    return $group ?: null;
}

function da_fetch_active_people(string $search = '', int $limit = 80): array
{
    $params = [];
    $where = "WHERE p.status = 'active'";

    if ($search !== '') {
        $where .= " AND (p.full_name LIKE :search OR p.primary_email LIKE :search)";
        $params['search'] = '%' . $search . '%';
    }

    $stmt = db()->prepare("
        SELECT
            p.id,
            p.full_name,
            p.primary_email,
            p.phone,
            p.status,
            COALESCE((
                SELECT gm.access_level
                FROM group_memberships gm
                WHERE gm.person_id = p.id
                  AND gm.status = 'active'
                ORDER BY FIELD(
                    gm.access_level,
                    'system_admin',
                    'district_admin',
                    'district_reviewer',
                    'group_admin',
                    'member'
                ) ASC
                LIMIT 1
            ), 'member') AS highest_access_level,
            GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ') AS group_names,
            MAX(CASE WHEN ua.provider = 'microsoft' THEN 1 ELSE 0 END) AS has_microsoft_account
        FROM people p
        LEFT JOIN group_memberships gm_all
            ON gm_all.person_id = p.id
           AND gm_all.status = 'active'
        LEFT JOIN groups g
            ON g.id = gm_all.group_id
           AND g.is_active = 1
        LEFT JOIN user_accounts ua
            ON ua.person_id = p.id
        {$where}
        GROUP BY p.id, p.full_name, p.primary_email, p.phone, p.status
        ORDER BY p.full_name ASC
        LIMIT {$limit}
    ");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function da_fetch_group_people(int $groupId): array
{
    $stmt = db()->prepare("
        SELECT
            p.id,
            p.full_name,
            p.primary_email,
            p.phone,
            gm.membership_role,
            gm.access_level,
            gm.status AS membership_status
        FROM group_memberships gm
        JOIN people p ON p.id = gm.person_id
        WHERE gm.group_id = :group_id
          AND p.status = 'active'
          AND gm.status = 'active'
        ORDER BY p.full_name ASC
    ");
    $stmt->execute(['group_id' => $groupId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function da_fetch_permission_people(): array
{
    $stmt = db()->query("
        SELECT
            p.id,
            p.full_name,
            p.primary_email,
            p.status,
            GROUP_CONCAT(
                DISTINCT CONCAT(g.group_name, ': ', gm.access_level)
                ORDER BY g.group_name
                SEPARATOR ' | '
            ) AS permissions,
            COALESCE((
                SELECT gm2.access_level
                FROM group_memberships gm2
                WHERE gm2.person_id = p.id
                  AND gm2.status = 'active'
                ORDER BY FIELD(
                    gm2.access_level,
                    'system_admin',
                    'district_admin',
                    'district_reviewer',
                    'group_admin',
                    'member'
                ) ASC
                LIMIT 1
            ), 'member') AS highest_access_level
        FROM people p
        JOIN group_memberships gm
            ON gm.person_id = p.id
           AND gm.status = 'active'
        JOIN groups g ON g.id = gm.group_id
        WHERE p.status = 'active'
          AND gm.access_level IN (
            'system_admin',
            'district_admin',
            'district_reviewer',
            'group_admin'
          )
        GROUP BY p.id, p.full_name, p.primary_email, p.status
        ORDER BY FIELD(
            highest_access_level,
            'system_admin',
            'district_admin',
            'district_reviewer',
            'group_admin',
            'member'
        ) ASC,
        p.full_name ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function da_fetch_group_links(int $groupId): array
{
    if (!da_table_exists('group_access_links')) {
        return [];
    }

    $columns = ['id', 'group_id', 'token_hash', 'status'];

    foreach (['label', 'token_plain', 'scope', 'created_at', 'expires_at', 'last_used_at'] as $column) {
        if (da_column_exists('group_access_links', $column)) {
            $columns[] = $column;
        }
    }

    $select = implode(
        ', ',
        array_map(
            static fn(string $column): string => '`' . $column . '`',
            $columns
        )
    );

    $order = da_column_exists('group_access_links', 'created_at')
        ? 'created_at DESC, id DESC'
        : 'id DESC';

    $stmt = db()->prepare("
        SELECT {$select}
        FROM group_access_links
        WHERE group_id = :group_id
        ORDER BY {$order}
    ");
    $stmt->execute(['group_id' => $groupId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function da_group_link_url(array $link): ?string
{
    if (!empty($link['token_plain'])) {
        return da_absolute_url('/dc/login.php?token=' . urlencode((string) $link['token_plain']));
    }

    return null;
}

function da_generate_group_link(int $groupId, int $actorPersonId, string $label, bool $disableExisting): string
{
    if (!da_table_exists('group_access_links')) {
        throw new RuntimeException('The group_access_links table does not exist. Apply the District Calendar schema first.');
    }

    if ($disableExisting) {
        $stmt = db()->prepare("
            UPDATE group_access_links
            SET status = 'disabled'
            WHERE group_id = :group_id
              AND status = 'active'
        ");
        $stmt->execute(['group_id' => $groupId]);
    }

    $tokenPlain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $tokenHash = hash('sha256', $tokenPlain);

    $inserted = da_insert_flexible('group_access_links', [
        'group_id' => $groupId,
        'token_hash' => $tokenHash,
        'token_plain' => $tokenPlain,
        'label' => $label !== '' ? $label : 'Main Group calendar link',
        'scope' => 'group',
        'status' => 'active',
        'created_by_person_id' => $actorPersonId,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    if (!$inserted) {
        throw new RuntimeException('The new Group calendar link could not be created. Check the group_access_links columns.');
    }

    da_log_action(
        $actorPersonId,
        $disableExisting ? 'group_link_rotated' : 'group_link_created',
        'group',
        $groupId,
        [
            'label' => $label,
            'disabled_existing' => $disableExisting,
        ]
    );

    return da_absolute_url('/dc/login.php?token=' . urlencode($tokenPlain));
}

function da_assign_membership(
    int $personId,
    int $groupId,
    string $membershipRole,
    string $accessLevel,
    int $actorPersonId
): void {
    $stmt = db()->prepare("
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
            1,
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            membership_role = VALUES(membership_role),
            access_level = VALUES(access_level),
            status = 'active',
            approved_at = COALESCE(approved_at, NOW())
    ");
    $stmt->execute([
        'person_id' => $personId,
        'group_id' => $groupId,
        'membership_role' => $membershipRole,
        'access_level' => $accessLevel,
    ]);

    da_log_action($actorPersonId, 'membership_assigned', 'person', $personId, [
        'group_id' => $groupId,
        'membership_role' => $membershipRole,
        'access_level' => $accessLevel,
    ]);
}

function da_create_group(array $input, int $actorPersonId): int
{
    $groupName = trim((string) ($input['group_name'] ?? ''));

    if ($groupName === '') {
        throw new RuntimeException('Enter the Group name.');
    }

    $slug = da_unique_group_slug($groupName);

    $inserted = da_insert_flexible('groups', [
        'group_name' => $groupName,
        'name' => $groupName,
        'slug' => $slug,
        'website_url' => trim((string) ($input['website_url'] ?? '')) ?: null,
        'public_email' => trim((string) ($input['public_email'] ?? '')) ?: null,
        'contact_email' => trim((string) ($input['public_email'] ?? '')) ?: null,
        'meeting_place' => trim((string) ($input['meeting_place'] ?? '')) ?: null,
        'postcode' => trim((string) ($input['postcode'] ?? '')) ?: null,
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    if (!$inserted) {
        throw new RuntimeException('The Group could not be created. Check the groups table columns.');
    }

    $groupId = (int) db()->lastInsertId();

    da_log_action($actorPersonId, 'group_created', 'group', $groupId, [
        'group_name' => $groupName,
        'slug' => $slug,
    ]);

    return $groupId;
}

$memberships = da_current_memberships((int) $user['id']);
$actorAccessLevel = da_current_access_level($user, $memberships);
$actorIsAdmin = da_is_district_admin($actorAccessLevel);
$actorPersonId = (int) $user['id'];

if (!$actorIsAdmin) {
    http_response_code(403);

    $pageTitle = 'District Admin | ' . $appName;
    $heroTitle = 'District Admin';
    $heroText = 'This area is for District administrators only.';
    $breadcrumb = '<a href="/index.php">Home</a> / District Admin';

    include __DIR__ . '/header.php';

    echo '<main class="lt-main"><div class="alert alert-danger"><strong>Access denied:</strong> You must be a District Admin or System Admin to use this page.</div></main>';

    include __DIR__ . '/footer.php';
    exit;
}

$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'groups');
$allowedTabs = ['groups', 'new-group', 'links', 'permissions', 'users'];

if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'groups';
}

$errors = [];
$success = null;
$newLinkUrl = null;
$selectedGroupId = (int) ($_GET['group_id'] ?? $_POST['group_id'] ?? 0);
$userSearch = trim((string) ($_GET['user_search'] ?? $_POST['user_search'] ?? ''));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create_group') {
            $tab = 'new-group';

            $pdo->beginTransaction();

            $groupId = da_create_group($_POST, $actorPersonId);

            $leadPersonId = (int) ($_POST['lead_person_id'] ?? 0);

            if ($leadPersonId > 0) {
                da_assign_membership(
                    $leadPersonId,
                    $groupId,
                    'group_lead_volunteer',
                    'group_admin',
                    $actorPersonId
                );
            }

            if (isset($_POST['create_group_link'])) {
                $newLinkUrl = da_generate_group_link(
                    $groupId,
                    $actorPersonId,
                    'Main Group calendar link',
                    true
                );
            }

            $pdo->commit();

            $selectedGroupId = $groupId;
            $success = 'Group created. ' .
                ($leadPersonId > 0 ? 'The selected Group Lead Volunteer has been assigned. ' : '') .
                ($newLinkUrl ? 'A Group calendar link has been generated.' : '');
        } elseif ($action === 'update_group') {
            $tab = 'groups';

            $groupId = (int) ($_POST['group_id'] ?? 0);
            $group = da_fetch_group($groupId);

            if (!$group) {
                throw new RuntimeException('Group not found.');
            }

            $groupName = trim((string) ($_POST['group_name'] ?? ''));

            if ($groupName === '') {
                throw new RuntimeException('Enter the Group name.');
            }

            da_update_flexible('groups', 'id', $groupId, [
                'group_name' => $groupName,
                'name' => $groupName,
                'slug' => da_unique_group_slug($groupName, $groupId),
                'website_url' => trim((string) ($_POST['website_url'] ?? '')) ?: null,
                'public_email' => trim((string) ($_POST['public_email'] ?? '')) ?: null,
                'contact_email' => trim((string) ($_POST['public_email'] ?? '')) ?: null,
                'meeting_place' => trim((string) ($_POST['meeting_place'] ?? '')) ?: null,
                'postcode' => trim((string) ($_POST['postcode'] ?? '')) ?: null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            da_log_action($actorPersonId, 'group_updated', 'group', $groupId, [
                'group_name' => $groupName,
            ]);

            $success = 'Group details updated.';
            $selectedGroupId = $groupId;
        } elseif ($action === 'set_group_status') {
            $tab = 'groups';

            $groupId = (int) ($_POST['group_id'] ?? 0);
            $newStatus = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            $group = da_fetch_group($groupId);

            if (!$group) {
                throw new RuntimeException('Group not found.');
            }

            da_update_flexible('groups', 'id', $groupId, [
                'is_active' => $newStatus,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            da_log_action(
                $actorPersonId,
                $newStatus ? 'group_activated' : 'group_deactivated',
                'group',
                $groupId
            );

            $success = $newStatus ? 'Group reactivated.' : 'Group deactivated.';
        } elseif ($action === 'assign_lead') {
            $tab = 'groups';

            $groupId = (int) ($_POST['group_id'] ?? 0);
            $personId = (int) ($_POST['person_id'] ?? 0);

            if (!da_fetch_group($groupId)) {
                throw new RuntimeException('Group not found.');
            }

            if ($personId < 1) {
                throw new RuntimeException('Choose a person to assign as Group Lead Volunteer.');
            }

            da_assign_membership(
                $personId,
                $groupId,
                'group_lead_volunteer',
                'group_admin',
                $actorPersonId
            );

            $success = 'Group Lead Volunteer assigned. Use Group Manager to add more people to this Group.';
            $selectedGroupId = $groupId;
        } elseif ($action === 'generate_group_link') {
            $tab = 'links';

            $groupId = (int) ($_POST['group_id'] ?? 0);
            $group = da_fetch_group($groupId);

            if (!$group) {
                throw new RuntimeException('Group not found.');
            }

            $disableExisting = isset($_POST['disable_existing']);
            $label = trim((string) ($_POST['label'] ?? 'Main Group calendar link'));

            $newLinkUrl = da_generate_group_link(
                $groupId,
                $actorPersonId,
                $label,
                $disableExisting
            );

            $success = $disableExisting
                ? 'Group link rotated. Existing active links were disabled.'
                : 'New Group link generated.';

            $selectedGroupId = $groupId;
        } elseif ($action === 'disable_group_link') {
            $tab = 'links';

            $linkId = (int) ($_POST['link_id'] ?? 0);
            $groupId = (int) ($_POST['group_id'] ?? 0);

            $stmt = $pdo->prepare("
                UPDATE group_access_links
                SET status = 'disabled'
                WHERE id = :link_id
                  AND group_id = :group_id
            ");
            $stmt->execute([
                'link_id' => $linkId,
                'group_id' => $groupId,
            ]);

            da_log_action($actorPersonId, 'group_link_disabled', 'group', $groupId, [
                'link_id' => $linkId,
            ]);

            $success = 'Group link disabled.';
            $selectedGroupId = $groupId;
        } elseif ($action === 'assign_permission') {
            $tab = 'permissions';

            $personId = (int) ($_POST['person_id'] ?? 0);
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $accessLevel = (string) ($_POST['access_level'] ?? 'member');

            if ($personId < 1 || $groupId < 1) {
                throw new RuntimeException('Choose a person and a Group.');
            }

            if (!da_can_assign_level($actorAccessLevel, $accessLevel)) {
                throw new RuntimeException('You do not have permission to assign that access level.');
            }

            $membershipRole = da_membership_role_for_access_level($accessLevel);

            da_assign_membership(
                $personId,
                $groupId,
                $membershipRole,
                $accessLevel,
                $actorPersonId
            );

            $success = 'Permission updated. The person may need to sign out and back in before their session reflects the change.';
        } elseif ($action === 'remove_permission') {
            $tab = 'permissions';

            $personId = (int) ($_POST['person_id'] ?? 0);
            $groupId = (int) ($_POST['group_id'] ?? 0);

            if ($personId < 1 || $groupId < 1) {
                throw new RuntimeException('Choose a person and a Group.');
            }

            $stmt = $pdo->prepare("
                UPDATE group_memberships
                SET access_level = 'member',
                    membership_role = CASE
                        WHEN membership_role IN ('district_volunteer', 'administrator')
                        THEN 'section_leader'
                        ELSE membership_role
                    END
                WHERE person_id = :person_id
                  AND group_id = :group_id
            ");
            $stmt->execute([
                'person_id' => $personId,
                'group_id' => $groupId,
            ]);

            da_log_action($actorPersonId, 'permission_removed', 'person', $personId, [
                'group_id' => $groupId,
            ]);

            $success = 'Elevated permission removed for that Group membership.';
        } elseif ($action === 'edit_person') {
            $tab = 'users';

            $personId = (int) ($_POST['person_id'] ?? 0);

            if ($personId < 1) {
                throw new RuntimeException('Choose a person to edit.');
            }

            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['primary_email'] ?? '')));

            if ($fullName === '') {
                throw new RuntimeException('Enter the person\'s name.');
            }

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid email address.');
            }

            $stmt = $pdo->prepare("
                UPDATE people
                SET full_name = :full_name,
                    primary_email = :primary_email,
                    phone = :phone,
                    status = :status
                WHERE id = :person_id
            ");
            $stmt->execute([
                'full_name' => $fullName,
                'primary_email' => $email,
                'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
                'status' => (string) ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
                'person_id' => $personId,
            ]);

            da_log_action($actorPersonId, 'person_edited_by_admin', 'person', $personId);

            $success = 'Person updated. To add them to a Group, use the Group Manager page.';
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $errors[] = $e->getMessage() ?: 'The request could not be completed.';
}

$groups = da_fetch_groups();

if ($selectedGroupId < 1 && $groups) {
    $selectedGroupId = (int) $groups[0]['id'];
}

$selectedGroup = $selectedGroupId > 0 ? da_fetch_group($selectedGroupId) : null;
$activePeople = da_fetch_active_people($userSearch, 120);
$groupPeople = $selectedGroupId > 0 ? da_fetch_group_people($selectedGroupId) : [];
$permissionPeople = da_fetch_permission_people();
$groupLinks = $selectedGroupId > 0 ? da_fetch_group_links($selectedGroupId) : [];
$permissionOptions = da_permission_options($actorAccessLevel);

$pageTitle = 'District Admin | ' . $appName;
$heroTitle = 'District Admin';
$heroText = 'Create Groups, assign Group Lead Volunteers, rotate Group calendar links, and manage reviewer/admin permissions.';
$breadcrumb = '<a href="/index.php">Home</a> / District Admin';
?>
<?php include __DIR__ . '/header.php'; ?>

<style>
    .da-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        margin-bottom: 1rem;
    }

    .da-tab {
        display: inline-block;
        padding: .75rem 1rem;
        border: 2px solid var(--iv-purple);
        color: var(--iv-purple);
        font-weight: 900;
        text-decoration: none;
        background: #fff;
    }

    .da-tab:hover {
        color: var(--iv-purple-dark);
        text-decoration: none;
    }

    .da-tab.active {
        background: var(--iv-purple);
        color: #fff;
    }

    .da-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 992px) {
        .da-grid-2 {
            grid-template-columns: minmax(0, 2fr) minmax(320px, 1fr);
        }
    }

    .da-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .da-stat {
        background: #fff;
        border: 2px solid #eee;
        padding: 1rem;
    }

    .da-stat strong {
        display: block;
        font-size: 2rem;
        line-height: 1;
        color: var(--iv-purple);
    }

    .da-flow-step {
        border-left: .45rem solid var(--iv-purple);
        padding: 1rem;
        background: #fff;
        margin-bottom: 1rem;
        box-shadow: 0 1px 0 rgba(0,0,0,.08);
    }

    .da-table-wrap {
        overflow-x: auto;
    }

    .da-badge {
        display: inline-block;
        padding: .2rem .45rem;
        font-weight: 900;
        font-size: .78rem;
        border-radius: .25rem;
    }

    .da-badge-active {
        background: #d1e7dd;
        color: #0f5132;
    }

    .da-badge-inactive {
        background: #f8d7da;
        color: #842029;
    }

    .da-badge-admin {
        background: #e7f1ff;
        color: #084298;
    }

    .da-muted {
        color: #555;
    }

    .da-link-box {
        display: grid;
        gap: .5rem;
    }

    @media (min-width: 768px) {
        .da-link-box {
            grid-template-columns: minmax(0, 1fr) auto;
        }
    }
</style>

<main class="lt-main">
    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <strong>There is a problem:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($newLinkUrl): ?>
        <div class="alert alert-info">
            <strong>New Group calendar link:</strong>
            <div class="da-link-box mt-2">
                <input class="form-control" type="text" value="<?= e($newLinkUrl) ?>" readonly>
                <button class="btn btn-secondary lt-btn da-copy" type="button" data-copy="<?= e($newLinkUrl) ?>">Copy</button>
            </div>
        </div>
    <?php endif; ?>

    <nav class="da-tabs" aria-label="District Admin tabs">
        <a class="da-tab <?= $tab === 'groups' ? 'active' : '' ?>" href="/district-admin.php?tab=groups">Groups</a>
        <a class="da-tab <?= $tab === 'new-group' ? 'active' : '' ?>" href="/district-admin.php?tab=new-group">Add Group</a>
        <a class="da-tab <?= $tab === 'links' ? 'active' : '' ?>" href="/district-admin.php?tab=links<?= $selectedGroupId ? '&group_id=' . $selectedGroupId : '' ?>">Group links</a>
        <a class="da-tab <?= $tab === 'permissions' ? 'active' : '' ?>" href="/district-admin.php?tab=permissions">Permissions</a>
        <a class="da-tab <?= $tab === 'users' ? 'active' : '' ?>" href="/district-admin.php?tab=users">Edit users</a>
    </nav>

    <?php if ($tab === 'groups'): ?>
        <div class="da-stats">
            <div class="da-stat">
                <strong><?= count($groups) ?></strong>
                <span>total Groups</span>
            </div>
            <div class="da-stat">
                <strong><?= count(array_filter($groups, static fn(array $g): bool => (int) ($g['is_active'] ?? 0) === 1)) ?></strong>
                <span>active Groups</span>
            </div>
            <div class="da-stat">
                <strong><?= array_sum(array_map(static fn(array $g): int => (int) ($g['active_people_count'] ?? 0), $groups)) ?></strong>
                <span>active memberships</span>
            </div>
        </div>

        <section class="lt-panel mb-4">
            <h2 class="lt-section-title">Groups</h2>
            <p class="lt-lede">Use this page for Group-level administration. To add people to a Group, open the Group Manager for that Group.</p>

            <div class="da-table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Group</th>
                            <th>Lead volunteer(s)</th>
                            <th>People</th>
                            <th>Links</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($groups as $group): ?>
                        <tr>
                            <td>
                                <strong><?= e($group['group_name'] ?? $group['name'] ?? 'Unnamed Group') ?></strong><br>
                                <span class="da-muted">Slug: <?= e($group['slug'] ?? '') ?></span>
                            </td>
                            <td><?= e($group['lead_volunteers'] ?: 'None assigned') ?></td>
                            <td><?= (int) ($group['active_people_count'] ?? 0) ?></td>
                            <td><?= (int) ($group['active_link_count'] ?? 0) ?> active</td>
                            <td>
                                <?= (int) ($group['is_active'] ?? 0) === 1
                                    ? '<span class="da-badge da-badge-active">Active</span>'
                                    : '<span class="da-badge da-badge-inactive">Inactive</span>' ?>
                            </td>
                            <td>
                                <a class="btn btn-sm btn-primary" href="/group-manager.php?group_id=<?= (int) $group['id'] ?>">Group Manager</a>
                                <a class="btn btn-sm btn-secondary" href="/district-admin.php?tab=links&group_id=<?= (int) $group['id'] ?>">Links</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($selectedGroup): ?>
            <section class="lt-panel-grey">
                <h2 class="lt-section-title">Edit selected Group</h2>

                <form method="get" class="form-row align-items-end mb-3">
                    <input type="hidden" name="tab" value="groups">
                    <div class="form-group col-md-8">
                        <label for="group_id">Choose Group</label>
                        <select class="form-control" id="group_id" name="group_id">
                            <?php foreach ($groups as $group): ?>
                                <option value="<?= (int) $group['id'] ?>" <?= (int) $group['id'] === $selectedGroupId ? 'selected' : '' ?>>
                                    <?= e($group['group_name'] ?? $group['name'] ?? 'Unnamed Group') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <button class="btn btn-secondary lt-btn btn-block" type="submit">Load Group</button>
                    </div>
                </form>

                <form method="post">
                    <input type="hidden" name="action" value="update_group">
                    <input type="hidden" name="tab" value="groups">
                    <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="edit_group_name">Group name</label>
                            <input class="form-control" type="text" id="edit_group_name" name="group_name" value="<?= e($selectedGroup['group_name'] ?? $selectedGroup['name'] ?? '') ?>" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="edit_public_email">Public/contact email</label>
                            <input class="form-control" type="email" id="edit_public_email" name="public_email" value="<?= e($selectedGroup['public_email'] ?? $selectedGroup['contact_email'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="edit_website_url">Website URL</label>
                            <input class="form-control" type="url" id="edit_website_url" name="website_url" value="<?= e($selectedGroup['website_url'] ?? '') ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="edit_postcode">Postcode</label>
                            <input class="form-control" type="text" id="edit_postcode" name="postcode" value="<?= e($selectedGroup['postcode'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="edit_meeting_place">Meeting place</label>
                        <input class="form-control" type="text" id="edit_meeting_place" name="meeting_place" value="<?= e($selectedGroup['meeting_place'] ?? '') ?>">
                    </div>

                    <button class="btn btn-primary lt-btn" type="submit">Save Group details</button>
                </form>

                <hr>

                <h3 class="h5 font-weight-bold">Assign Group Lead Volunteer</h3>
                <p>
                    This only assigns or updates the GLV permission.
                    To add normal leaders to this Group, use
                    <a href="/group-manager.php?group_id=<?= $selectedGroupId ?>">Group Manager</a>.
                </p>

                <form method="post" class="form-row align-items-end">
                    <input type="hidden" name="action" value="assign_lead">
                    <input type="hidden" name="tab" value="groups">
                    <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">

                    <div class="form-group col-md-8">
                        <label for="person_id">Existing active person</label>
                        <select class="form-control" id="person_id" name="person_id" required>
                            <option value="">Choose person</option>
                            <?php foreach ($activePeople as $person): ?>
                                <option value="<?= (int) $person['id'] ?>">
                                    <?= e($person['full_name'] . ' — ' . $person['primary_email']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-4">
                        <button class="btn btn-primary lt-btn btn-block" type="submit">Assign GLV</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    <?php elseif ($tab === 'new-group'): ?>
        <div class="da-grid da-grid-2">
            <section class="lt-panel">
                <h2 class="lt-section-title">Add a new Group</h2>
                <p class="lt-lede">This flow creates the Group, optionally assigns a Group Lead Volunteer, and optionally generates the first Group calendar link.</p>

                <form method="post">
                    <input type="hidden" name="action" value="create_group">
                    <input type="hidden" name="tab" value="new-group">

                    <div class="da-flow-step">
                        <h3 class="h5 font-weight-bold">Step 1 — Group details</h3>

                        <div class="form-group">
                            <label for="group_name">Group name</label>
                            <input class="form-control" type="text" id="group_name" name="group_name" placeholder="Example: 1st Irwell Valley Scout Group" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="public_email">Public/contact email</label>
                                <input class="form-control" type="email" id="public_email" name="public_email">
                            </div>
                            <div class="form-group col-md-6">
                                <label for="website_url">Website URL</label>
                                <input class="form-control" type="url" id="website_url" name="website_url">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-8">
                                <label for="meeting_place">Meeting place</label>
                                <input class="form-control" type="text" id="meeting_place" name="meeting_place">
                            </div>
                            <div class="form-group col-md-4">
                                <label for="postcode">Postcode</label>
                                <input class="form-control" type="text" id="postcode" name="postcode">
                            </div>
                        </div>
                    </div>

                    <div class="da-flow-step">
                        <h3 class="h5 font-weight-bold">Step 2 — Assign the Group Lead Volunteer</h3>
                        <p>Choose an existing active person. If they do not exist yet, create the Group first, then use Group Manager to add them and assign them.</p>

                        <div class="form-group mb-0">
                            <label for="lead_person_id">Existing person</label>
                            <select class="form-control" id="lead_person_id" name="lead_person_id">
                                <option value="">Not yet / assign later</option>
                                <?php foreach ($activePeople as $person): ?>
                                    <option value="<?= (int) $person['id'] ?>">
                                        <?= e($person['full_name'] . ' — ' . $person['primary_email']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="da-flow-step">
                        <h3 class="h5 font-weight-bold">Step 3 — Calendar access</h3>
                        <p>Prefer SSO for leaders. The Group calendar link is a fallback for leaders who are not using a District Microsoft 365 account yet.</p>

                        <label class="lt-check mb-0">
                            <input type="checkbox" name="create_group_link" value="1" checked>
                            <span>Generate the main Group calendar link now</span>
                        </label>
                    </div>

                    <button class="btn btn-primary lt-btn" type="submit">Create Group</button>
                </form>
            </section>

            <aside class="lt-panel-grey">
                <h2 class="lt-section-title">After creating the Group</h2>
                <ol class="pl-3 font-weight-bold">
                    <li>Open Group Manager for the new Group.</li>
                    <li>Add the GLV or leaders if they are not already in the app.</li>
                    <li>Encourage each person to use District Microsoft 365 SSO.</li>
                    <li>Share the Group calendar link only where SSO is not yet practical.</li>
                </ol>
            </aside>
        </div>
    <?php elseif ($tab === 'links'): ?>
        <section class="lt-panel mb-4">
            <h2 class="lt-section-title">Generate or rotate Group calendar links</h2>
            <p class="lt-lede">This creates visible bearer-token links for Group calendar access. Rotating a link disables existing active links for that Group.</p>

            <form method="get" class="form-row align-items-end mb-3">
                <input type="hidden" name="tab" value="links">

                <div class="form-group col-md-8">
                    <label for="links_group_id">Group</label>
                    <select class="form-control" id="links_group_id" name="group_id">
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>" <?= (int) $group['id'] === $selectedGroupId ? 'selected' : '' ?>>
                                <?= e($group['group_name'] ?? $group['name'] ?? 'Unnamed Group') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group col-md-4">
                    <button class="btn btn-secondary lt-btn btn-block" type="submit">Load links</button>
                </div>
            </form>

            <?php if ($selectedGroup): ?>
                <form method="post" class="lt-panel-grey mb-4">
                    <input type="hidden" name="action" value="generate_group_link">
                    <input type="hidden" name="tab" value="links">
                    <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">

                    <h3 class="h5 font-weight-bold">Create link for <?= e($selectedGroup['group_name'] ?? $selectedGroup['name'] ?? 'this Group') ?></h3>

                    <div class="form-group">
                        <label for="label">Link label</label>
                        <input class="form-control" type="text" id="label" name="label" value="Main Group calendar link">
                    </div>

                    <label class="lt-check mb-3">
                        <input type="checkbox" name="disable_existing" value="1" checked>
                        <span>Disable existing active links for this Group</span>
                    </label>

                    <button class="btn btn-primary lt-btn" type="submit">Generate link</button>
                </form>

                <h3 class="h5 font-weight-bold">Existing links</h3>

                <?php if ($groupLinks): ?>
                    <div class="da-table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Label</th>
                                    <th>Status</th>
                                    <th>Link</th>
                                    <th>Created</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($groupLinks as $link): ?>
                                <?php $url = da_group_link_url($link); ?>
                                <tr>
                                    <td><?= e($link['label'] ?? 'Group calendar link') ?></td>
                                    <td><?= e($link['status'] ?? '') ?></td>
                                    <td>
                                        <?php if ($url): ?>
                                            <div class="da-link-box">
                                                <input class="form-control" type="text" value="<?= e($url) ?>" readonly>
                                                <button class="btn btn-secondary btn-sm da-copy" type="button" data-copy="<?= e($url) ?>">Copy</button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-warning font-weight-bold">No visible token; rotate this link.</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($link['created_at'] ?? '—') ?></td>
                                    <td>
                                        <?php if (($link['status'] ?? '') === 'active'): ?>
                                            <form method="post" onsubmit="return confirm('Disable this Group calendar link?');">
                                                <input type="hidden" name="action" value="disable_group_link">
                                                <input type="hidden" name="tab" value="links">
                                                <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">
                                                <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                                                <button class="btn btn-outline-danger btn-sm" type="submit">Disable</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning mb-0">No links exist for this Group yet.</div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php elseif ($tab === 'permissions'): ?>
        <div class="da-grid da-grid-2">
            <section class="lt-panel">
                <h2 class="lt-section-title">Assign admin and reviewer permissions</h2>
                <p class="lt-lede">Permissions are stored on a Group membership. District-level permissions will then be detected by the app from any active membership.</p>

                <form method="post">
                    <input type="hidden" name="action" value="assign_permission">
                    <input type="hidden" name="tab" value="permissions">

                    <div class="form-group">
                        <label for="permission_person_id">Person</label>
                        <select class="form-control" id="permission_person_id" name="person_id" required>
                            <option value="">Choose active person</option>
                            <?php foreach ($activePeople as $person): ?>
                                <option value="<?= (int) $person['id'] ?>">
                                    <?= e($person['full_name'] . ' — ' . $person['primary_email']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="permission_group_id">Group / permission anchor</label>
                        <select class="form-control" id="permission_group_id" name="group_id" required>
                            <option value="">Choose Group</option>
                            <?php foreach ($groups as $group): ?>
                                <?php if ((int) ($group['is_active'] ?? 0) === 1): ?>
                                    <option value="<?= (int) $group['id'] ?>">
                                        <?= e($group['group_name'] ?? $group['name'] ?? 'Unnamed Group') ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">For District Reviewer/Admin access, choose their normal Group or a District Team Group if you have one.</small>
                    </div>

                    <div class="form-group">
                        <label for="access_level">Permission</label>
                        <select class="form-control" id="access_level" name="access_level" required>
                            <?php foreach ($permissionOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button class="btn btn-primary lt-btn" type="submit">Assign permission</button>
                </form>
            </section>

            <aside class="lt-panel-grey">
                <h2 class="lt-section-title">Rules</h2>
                <ul class="pl-3">
                    <li>Only District Admins and System Admins can use this page.</li>
                    <li>District Admins can assign reviewers and Group admins.</li>
                    <li>Only System Admins can assign District Admin or System Admin.</li>
                    <li>Adding normal Group users should happen in Group Manager.</li>
                </ul>
            </aside>
        </div>

        <section class="lt-panel mt-4">
            <h2 class="lt-section-title">Current elevated permissions</h2>

            <?php if ($permissionPeople): ?>
                <div class="da-table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Person</th>
                                <th>Highest permission</th>
                                <th>Membership permissions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($permissionPeople as $person): ?>
                            <tr>
                                <td>
                                    <strong><?= e($person['full_name']) ?></strong><br>
                                    <?= e($person['primary_email']) ?>
                                </td>
                                <td>
                                    <span class="da-badge da-badge-admin">
                                        <?= e(str_replace('_', ' ', (string) $person['highest_access_level'])) ?>
                                    </span>
                                </td>
                                <td><?= e($person['permissions'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info mb-0">No elevated permissions found.</div>
            <?php endif; ?>
        </section>
    <?php elseif ($tab === 'users'): ?>
        <section class="lt-panel mb-4">
            <h2 class="lt-section-title">Edit users</h2>
            <p class="lt-lede">This is for correcting existing person records. To add someone to a Group, use that Group’s Group Manager page.</p>

            <form method="get" class="form-row align-items-end mb-3">
                <input type="hidden" name="tab" value="users">

                <div class="form-group col-md-8">
                    <label for="user_search">Search active users</label>
                    <input class="form-control" type="search" id="user_search" name="user_search" value="<?= e($userSearch) ?>" placeholder="Name or email">
                </div>

                <div class="form-group col-md-4">
                    <button class="btn btn-secondary lt-btn btn-block" type="submit">Search</button>
                </div>
            </form>

            <div class="da-table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Groups</th>
                            <th>Permission</th>
                            <th>Edit</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activePeople as $person): ?>
                        <tr>
                            <form method="post">
                                <input type="hidden" name="action" value="edit_person">
                                <input type="hidden" name="tab" value="users">
                                <input type="hidden" name="person_id" value="<?= (int) $person['id'] ?>">

                                <td>
                                    <input class="form-control" type="text" name="full_name" value="<?= e($person['full_name']) ?>" required>
                                </td>
                                <td>
                                    <input class="form-control" type="email" name="primary_email" value="<?= e($person['primary_email']) ?>" required>
                                </td>
                                <td>
                                    <input class="form-control" type="text" name="phone" value="<?= e($person['phone'] ?? '') ?>">
                                </td>
                                <td>
                                    <?= e($person['group_names'] ?: 'No active Group') ?><br>
                                    <a href="/group-manager.php">Open Group Manager</a>
                                </td>
                                <td><?= e(str_replace('_', ' ', (string) $person['highest_access_level'])) ?></td>
                                <td>
                                    <select class="form-control mb-2" name="status">
                                        <option value="active" selected>Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                    <button class="btn btn-primary btn-sm" type="submit">Save</button>
                                </td>
                            </form>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>

<script>
(function () {
    document.querySelectorAll('.da-copy').forEach(function (button) {
        button.addEventListener('click', function () {
            var value = button.getAttribute('data-copy') || '';

            if (!value) {
                return;
            }

            navigator.clipboard.writeText(value).then(function () {
                var old = button.textContent;
                button.textContent = 'Copied';

                window.setTimeout(function () {
                    button.textContent = old;
                }, 1500);
            });
        });
    });
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>