<?php

declare(strict_types=1);

function da_table_exists(string $table): bool
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

function da_column_exists(string $table, string $column): bool
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

function da_table_columns(string $table): array
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute(['table_name' => $table]);

        return $cache[$table] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

function da_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
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

    $quotedColumns = array_map('da_quote_identifier', array_keys($insert));
    $placeholders = array_map(static fn(string $column): string => ':' . $column, array_keys($insert));

    $stmt = db()->prepare("
        INSERT INTO " . da_quote_identifier($table) . "
        (" . implode(', ', $quotedColumns) . ")
        VALUES
        (" . implode(', ', $placeholders) . ")
    ");

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
        static fn(string $column): string => da_quote_identifier($column) . ' = :' . $column,
        array_keys($update)
    );

    $update['_id'] = $id;

    $stmt = db()->prepare("
        UPDATE " . da_quote_identifier($table) . "
        SET " . implode(', ', $sets) . "
        WHERE " . da_quote_identifier($idColumn) . " = :_id
    ");

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
        $stmt = db()->prepare("
            SELECT id
            FROM groups
            WHERE slug = :slug
              AND id <> :ignore_group_id
            LIMIT 1
        ");
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
        if (($membership['status'] ?? 'active') !== 'active') {
            continue;
        }

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
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // Audit logging should never block the admin action.
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
            ) AS group_editors,
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
    $stmt = db()->prepare("
        SELECT *
        FROM groups
        WHERE id = :group_id
        LIMIT 1
    ");
    $stmt->execute(['group_id' => $groupId]);

    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    return $group ?: null;
}

function da_fetch_active_people(string $search = '', int $limit = 120): array
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
            GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ') AS group_names,
            MAX(CASE WHEN ua.provider = 'microsoft' THEN 1 ELSE 0 END) AS has_microsoft_account
        FROM people p
        LEFT JOIN group_memberships gm
            ON gm.person_id = p.id
           AND gm.status = 'active'
        LEFT JOIN groups g
            ON g.id = gm.group_id
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

function da_fetch_group_editors(int $groupId): array
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
          AND (
                gm.membership_role = 'group_lead_volunteer'
                OR gm.access_level = 'group_admin'
              )
        ORDER BY
            CASE WHEN gm.membership_role = 'group_lead_volunteer' THEN 0 ELSE 1 END,
            p.full_name ASC
    ");
    $stmt->execute(['group_id' => $groupId]);

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

    $select = implode(', ', array_map('da_quote_identifier', $columns));
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
        throw new RuntimeException('The group_access_links table does not exist.');
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
        throw new RuntimeException('The new Group calendar link could not be created.');
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
        throw new RuntimeException('The Group could not be created.');
    }

    $groupId = (int) db()->lastInsertId();

    da_log_action($actorPersonId, 'group_created', 'group', $groupId, [
        'group_name' => $groupName,
        'slug' => $slug,
    ]);

    return $groupId;
}

function da_update_group_details(int $groupId, array $input, int $actorPersonId): void
{
    $group = da_fetch_group($groupId);

    if (!$group) {
        throw new RuntimeException('Group not found.');
    }

    $groupName = trim((string) ($input['group_name'] ?? ''));

    if ($groupName === '') {
        throw new RuntimeException('Enter the Group name.');
    }

    da_update_flexible('groups', 'id', $groupId, [
        'group_name' => $groupName,
        'name' => $groupName,
        'slug' => da_unique_group_slug($groupName, $groupId),
        'website_url' => trim((string) ($input['website_url'] ?? '')) ?: null,
        'public_email' => trim((string) ($input['public_email'] ?? '')) ?: null,
        'contact_email' => trim((string) ($input['public_email'] ?? '')) ?: null,
        'meeting_place' => trim((string) ($input['meeting_place'] ?? '')) ?: null,
        'postcode' => trim((string) ($input['postcode'] ?? '')) ?: null,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    da_log_action($actorPersonId, 'group_updated', 'group', $groupId, [
        'group_name' => $groupName,
    ]);
}

function da_set_group_status(int $groupId, int $isActive, int $actorPersonId): void
{
    $group = da_fetch_group($groupId);

    if (!$group) {
        throw new RuntimeException('Group not found.');
    }

    da_update_flexible('groups', 'id', $groupId, [
        'is_active' => $isActive === 1 ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    da_log_action(
        $actorPersonId,
        $isActive === 1 ? 'group_activated' : 'group_deactivated',
        'group',
        $groupId
    );
}

function da_assign_group_editor(int $personId, int $groupId, string $editorRole, int $actorPersonId): void
{
    if ($personId < 1 || $groupId < 1) {
        throw new RuntimeException('Choose a person and a Group.');
    }

    if (!da_fetch_group($groupId)) {
        throw new RuntimeException('Group not found.');
    }

    $membershipRole = $editorRole === 'group_lead_volunteer'
        ? 'group_lead_volunteer'
        : 'group_leadership_team_member';

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
            'group_admin',
            'active',
            1,
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            membership_role = VALUES(membership_role),
            access_level = 'group_admin',
            status = 'active',
            approved_at = COALESCE(approved_at, NOW())
    ");
    $stmt->execute([
        'person_id' => $personId,
        'group_id' => $groupId,
        'membership_role' => $membershipRole,
    ]);

    da_log_action($actorPersonId, 'group_editor_assigned', 'person', $personId, [
        'group_id' => $groupId,
        'membership_role' => $membershipRole,
        'access_level' => 'group_admin',
    ]);
}

function da_remove_group_editor(int $personId, int $groupId, int $actorPersonId): void
{
    if ($personId < 1 || $groupId < 1) {
        throw new RuntimeException('Choose a person and a Group.');
    }

    $stmt = db()->prepare("
        UPDATE group_memberships
        SET access_level = 'member',
            membership_role = CASE
                WHEN membership_role = 'group_lead_volunteer'
                THEN 'group_leadership_team_member'
                ELSE membership_role
            END
        WHERE person_id = :person_id
          AND group_id = :group_id
          AND status = 'active'
    ");
    $stmt->execute([
        'person_id' => $personId,
        'group_id' => $groupId,
    ]);

    da_log_action($actorPersonId, 'group_editor_removed', 'person', $personId, [
        'group_id' => $groupId,
    ]);
}