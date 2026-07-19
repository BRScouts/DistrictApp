<?php

declare(strict_types=1);

function gm_table_exists(string $table): bool
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

function gm_column_exists(string $table, string $column): bool
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

function gm_table_columns(string $table): array
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

function gm_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function gm_insert_flexible(string $table, array $values): bool
{
    if (!gm_table_exists($table)) {
        return false;
    }

    $columns = gm_table_columns($table);
    $insert = [];

    foreach ($values as $column => $value) {
        if (in_array((string) $column, $columns, true)) {
            $insert[(string) $column] = $value;
        }
    }

    if (!$insert) {
        return false;
    }

    $quotedColumns = array_map('gm_quote_identifier', array_keys($insert));
    $placeholders = array_map(static fn(string $column): string => ':' . $column, array_keys($insert));

    $stmt = db()->prepare("
        INSERT INTO " . gm_quote_identifier($table) . "
        (" . implode(', ', $quotedColumns) . ")
        VALUES
        (" . implode(', ', $placeholders) . ")
    ");

    return $stmt->execute($insert);
}

function gm_update_flexible(string $table, string $idColumn, int $id, array $values): bool
{
    if (!gm_table_exists($table) || !gm_column_exists($table, $idColumn)) {
        return false;
    }

    $columns = gm_table_columns($table);
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
        static fn(string $column): string => gm_quote_identifier($column) . ' = :' . $column,
        array_keys($update)
    );

    $update['_id'] = $id;

    $stmt = db()->prepare("
        UPDATE " . gm_quote_identifier($table) . "
        SET " . implode(', ', $sets) . "
        WHERE " . gm_quote_identifier($idColumn) . " = :_id
    ");

    return $stmt->execute($update);
}

function gm_absolute_url(string $path): string
{
    $base = rtrim((string) app_config('APP_URL', ''), '/');

    if ($base !== '') {
        return $base . '/' . ltrim($path, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'app.irvalscouts.org.uk';

    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

function gm_default_district_email_domain(): string
{
    return (string) app_config('DISTRICT_EMAIL_DOMAIN', 'irvalscouts.org.uk');
}

function gm_current_memberships(array $user): array
{
    if (function_exists('user_group_memberships')) {
        return user_group_memberships((int) $user['id'], false);
    }

    $stmt = db()->prepare("
        SELECT gm.*, g.group_name
        FROM group_memberships gm
        JOIN groups g ON g.id = gm.group_id
        WHERE gm.person_id = :person_id
    ");
    $stmt->execute(['person_id' => (int) $user['id']]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function gm_actor_is_district_admin(array $user, array $memberships): bool
{
    $levels = [(string) ($user['highest_access_level'] ?? $user['role'] ?? 'member')];

    foreach ($memberships as $membership) {
        if (($membership['status'] ?? 'active') !== 'active') {
            continue;
        }

        $levels[] = (string) ($membership['access_level'] ?? 'member');
    }

    return (bool) array_intersect(array_unique($levels), ['district_admin', 'system_admin']);
}

function gm_manageable_groups(int $personId, bool $isDistrictAdmin): array
{
    if ($isDistrictAdmin) {
        $stmt = db()->query("
            SELECT id, group_name, slug
            FROM groups
            WHERE is_active = 1
            ORDER BY group_name ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = db()->prepare("
        SELECT DISTINCT
            g.id,
            g.group_name,
            g.slug
        FROM group_memberships gm
        JOIN groups g ON g.id = gm.group_id
        WHERE gm.person_id = :person_id
          AND gm.status = 'active'
          AND g.is_active = 1
          AND (
                gm.membership_role = 'group_lead_volunteer'
                OR gm.access_level = 'group_admin'
              )
        ORDER BY g.group_name ASC
    ");
    $stmt->execute(['person_id' => $personId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function gm_group_is_manageable(int $groupId, array $groups): bool
{
    foreach ($groups as $group) {
        if ((int) $group['id'] === $groupId) {
            return true;
        }
    }

    return false;
}

function gm_selected_group_id(array $manageableGroups): int
{
    $requestedGroupId = (int) ($_GET['group_id'] ?? $_POST['group_id'] ?? 0);

    if ($requestedGroupId > 0 && gm_group_is_manageable($requestedGroupId, $manageableGroups)) {
        return $requestedGroupId;
    }

    return (int) ($manageableGroups[0]['id'] ?? 0);
}

function gm_fetch_group(int $groupId): ?array
{
    $stmt = db()->prepare("
        SELECT *
        FROM groups
        WHERE id = :group_id
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute(['group_id' => $groupId]);

    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    return $group ?: null;
}

function gm_membership_role_options(?int $groupId = null): array
{
    $roles = [
        'group_lead_volunteer' => 'Group Lead Volunteer',
        'group_leadership_team_member' => 'Group Leadership Team Member',
        'squirrel_section_team_leader' => 'Squirrel Section Team Leader',
        'squirrel_section_team_member' => 'Squirrel Section Team Member',
        'beaver_section_team_leader' => 'Beaver Section Team Leader',
        'beaver_section_team_member' => 'Beaver Section Team Member',
        'cub_section_team_leader' => 'Cub Section Team Leader',
        'cub_section_team_member' => 'Cub Section Team Member',
        'scout_section_team_leader' => 'Scout Section Team Leader',
        'scout_section_team_member' => 'Scout Section Team Member',
        'explorer_team_leader' => 'Explorer Team Leader',
        'explorer_team_member' => 'Explorer Team Member',
        'group_chair' => 'Group Chair',
        'group_treasurer' => 'Group Treasurer',
        'group_trustee' => 'Group Trustee',
    ];

    // District-specific roles only available for the District Team (group ID 3).
    if ($groupId === 3) {
        $roles = array_merge($roles, gm_district_role_options());
    }

    return $roles;
}

/**
 * Additional roles exclusive to the District Team (group ID 3).
 */
function gm_district_role_options(): array
{
    return [
        'district_lead_volunteer' => 'District Lead Volunteer',
        'district_youth_lead' => 'District Youth Lead',
        'district_leadership_team_member' => 'District Leadership Team Member',
        'district_14_24_team_leader' => 'District 14–24 Team Leader',
        'district_14_24_team_member' => 'District 14–24 Team Member',
        'explorer_section_team_leader' => 'Explorer Section Team Leader',
        'explorer_section_team_member' => 'Explorer Section Team Member',
        'young_leader_unit_team_leader' => 'Young Leader Unit Team Leader',
        'young_leader_unit_team_member' => 'Young Leader Unit Team Member',
        'scout_network_section_team_leader' => 'Scout Network Section Team Leader',
        'scout_network_section_team_member' => 'Scout Network Section Team Member',
        'district_programme_team_leader' => 'District Programme Team Leader',
        'district_programme_team_member' => 'District Programme Team Member',
        'district_volunteering_development_team_leader' => 'District Volunteering Development Team Leader',
        'district_volunteering_development_team_member' => 'District Volunteering Development Team Member',
        'district_support_team_leader' => 'District Support Team Leader',
        'district_support_team_member' => 'District Support Team Member',
        'district_sub_team_leader' => 'District Sub-team Leader',
        'district_chair' => 'District Chair',
        'district_treasurer' => 'District Treasurer',
        'district_trustee' => 'District Trustee',
        'co_opted_district_trustee' => 'Co-opted District Trustee',
        'district_president' => 'District President',
        'district_vice_president' => 'District Vice President',
    ];
}

function gm_role_title_from_membership_role(string $membershipRole): string
{
    // Check standard roles first, then district roles.
    $options = gm_membership_role_options(null);
    if (isset($options[$membershipRole])) {
        return $options[$membershipRole];
    }

    $districtOptions = gm_district_role_options();
    return $districtOptions[$membershipRole] ?? 'Other';
}

function gm_access_level_for_membership_role(string $membershipRole): string
{
    return $membershipRole === 'group_lead_volunteer' ? 'group_admin' : 'member';
}

function gm_fetch_people(int $groupId, string $membershipStatus = 'active', string $search = ''): array
{
    $params = [
        'group_id' => $groupId,
        'membership_status' => $membershipStatus,
    ];

    $where = "gm.group_id = :group_id AND gm.status = :membership_status";

    if ($membershipStatus === 'active') {
        $where .= " AND p.status = 'active'";
    }

    if ($search !== '') {
        $where .= " AND (p.full_name LIKE :search OR p.primary_email LIKE :search OR p.phone LIKE :search)";
        $params['search'] = '%' . $search . '%';
    }

    $stmt = db()->prepare("
        SELECT
            p.id AS person_id,
            p.full_name,
            p.primary_email,
            p.phone,
            p.status AS person_status,
            gm.id AS membership_id,
            gm.membership_role,
            gm.access_level,
            gm.status AS membership_status,
            dp.role_title,
            dp.visible_in_directory,
            dp.share_phone,
            MAX(CASE WHEN ua.provider = 'microsoft' THEN 1 ELSE 0 END) AS has_microsoft_account,
            COUNT(DISTINCT ce.id) AS total_events,
            SUM(CASE WHEN ce.status IN ('submitted', 'under_review') THEN 1 ELSE 0 END) AS in_review_events,
            SUM(CASE WHEN ce.status = 'approved' THEN 1 ELSE 0 END) AS approved_events,
            MAX(ce.starts_at) AS latest_event_at
        FROM group_memberships gm
        JOIN people p ON p.id = gm.person_id
        LEFT JOIN directory_profiles dp ON dp.person_id = p.id
        LEFT JOIN user_accounts ua ON ua.person_id = p.id
        LEFT JOIN calendar_events ce
          ON ce.group_id = gm.group_id
         AND (
                ce.submitted_by_person_id = p.id
                OR LOWER(ce.leader_email) = LOWER(p.primary_email)
             )
        WHERE {$where}
        GROUP BY
            p.id,
            p.full_name,
            p.primary_email,
            p.phone,
            p.status,
            gm.id,
            gm.membership_role,
            gm.access_level,
            gm.status,
            dp.role_title,
            dp.visible_in_directory,
            dp.share_phone
        ORDER BY p.full_name ASC
    ");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function gm_fetch_person_for_group(int $groupId, int $personId): ?array
{
    $stmt = db()->prepare("
        SELECT
            p.id AS person_id,
            p.full_name,
            p.primary_email,
            p.phone,
            p.status AS person_status,
            gm.id AS membership_id,
            gm.membership_role,
            gm.access_level,
            gm.status AS membership_status,
            dp.role_title,
            dp.visible_in_directory,
            dp.share_phone,
            MAX(CASE WHEN ua.provider = 'microsoft' THEN 1 ELSE 0 END) AS has_microsoft_account,
            COUNT(DISTINCT ce.id) AS total_events,
            SUM(CASE WHEN ce.status IN ('submitted', 'under_review') THEN 1 ELSE 0 END) AS in_review_events,
            SUM(CASE WHEN ce.status = 'approved' THEN 1 ELSE 0 END) AS approved_events,
            MAX(ce.starts_at) AS latest_event_at
        FROM group_memberships gm
        JOIN people p ON p.id = gm.person_id
        LEFT JOIN directory_profiles dp ON dp.person_id = p.id
        LEFT JOIN user_accounts ua ON ua.person_id = p.id
        LEFT JOIN calendar_events ce
          ON ce.group_id = gm.group_id
         AND (
                ce.submitted_by_person_id = p.id
                OR LOWER(ce.leader_email) = LOWER(p.primary_email)
             )
        WHERE gm.group_id = :group_id
          AND gm.person_id = :person_id
        GROUP BY
            p.id,
            p.full_name,
            p.primary_email,
            p.phone,
            p.status,
            gm.id,
            gm.membership_role,
            gm.access_level,
            gm.status,
            dp.role_title,
            dp.visible_in_directory,
            dp.share_phone
        LIMIT 1
    ");
    $stmt->execute([
        'group_id' => $groupId,
        'person_id' => $personId,
    ]);

    $person = $stmt->fetch(PDO::FETCH_ASSOC);

    return $person ?: null;
}

function gm_fetch_group_links(int $groupId, bool $activeOnly = true): array
{
    if (!gm_table_exists('group_access_links')) {
        return [];
    }

    $columns = ['id', 'group_id', 'token_hash', 'status'];

    foreach (['label', 'token_plain', 'scope', 'created_at', 'expires_at', 'last_used_at'] as $column) {
        if (gm_column_exists('group_access_links', $column)) {
            $columns[] = $column;
        }
    }

    try {
        $hasExpiresAt = gm_column_exists('group_access_links', 'expires_at');
        $hasCreatedAt = gm_column_exists('group_access_links', 'created_at');
        $select = implode(', ', array_map('gm_quote_identifier', $columns));
        $statusSql = $activeOnly ? "AND status = 'active'" : '';
        $expirySql = $activeOnly && $hasExpiresAt ? "AND (expires_at IS NULL OR expires_at > NOW())" : '';

        $stmt = db()->prepare("
            SELECT {$select}
            FROM group_access_links
            WHERE group_id = :group_id
              {$statusSql}
              {$expirySql}
            ORDER BY " . ($hasCreatedAt ? "created_at DESC," : "") . " id DESC
            LIMIT 25
        ");
        $stmt->execute(['group_id' => $groupId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function gm_group_link_url(array $link): ?string
{
    if (!empty($link['token_plain'])) {
        return gm_absolute_url('/dc/login.php?token=' . urlencode((string) $link['token_plain']));
    }

    return null;
}

function gm_generate_group_link(int $groupId, int $actorPersonId, string $label, bool $disableExisting): string
{
    if (!gm_table_exists('group_access_links')) {
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

    $inserted = gm_insert_flexible('group_access_links', [
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

    gm_log_action(
        $actorPersonId,
        $disableExisting ? 'group_link_rotated_by_group_manager' : 'group_link_created_by_group_manager',
        'group',
        $groupId,
        [
            'label' => $label,
            'disabled_existing' => $disableExisting,
        ]
    );

    return gm_absolute_url('/dc/login.php?token=' . urlencode($tokenPlain));
}

function gm_disable_group_link(int $groupId, int $linkId, int $actorPersonId): void
{
    if ($linkId < 1) {
        throw new RuntimeException('Choose a link to disable.');
    }

    $stmt = db()->prepare("
        UPDATE group_access_links
        SET status = 'disabled'
        WHERE id = :link_id
          AND group_id = :group_id
    ");
    $stmt->execute([
        'link_id' => $linkId,
        'group_id' => $groupId,
    ]);

    gm_log_action($actorPersonId, 'group_link_disabled_by_group_manager', 'group', $groupId, [
        'link_id' => $linkId,
    ]);
}

function gm_name_part(string $value): string
{
    $value = trim($value);
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '.', $value) ?? '';
    $value = trim($value, '.');

    return $value;
}

function gm_district_email_candidate(string $firstName, string $lastName, int $suffix = 0): string
{
    $first = gm_name_part($firstName);
    $last = gm_name_part($lastName);
    $local = trim($first . '.' . $last, '.');

    if ($local === '') {
        $local = 'new.leader';
    }

    if ($suffix > 0) {
        $local .= (string) $suffix;
    }

    return $local . '@' . gm_default_district_email_domain();
}

function gm_local_email_known(string $email): bool
{
    $checks = [];

    if (gm_table_exists('people')) {
        $checks[] = "SELECT 1 FROM people WHERE LOWER(primary_email) = LOWER(:email) LIMIT 1";
    }

    if (gm_table_exists('user_accounts')) {
        $checks[] = "SELECT 1 FROM user_accounts WHERE LOWER(email) = LOWER(:email) LIMIT 1";
    }

    foreach (['m365_account_requests', 'microsoft365_account_requests', 'microsoft_account_requests', 'account_requests', 'requests'] as $table) {
        if (!gm_table_exists($table)) {
            continue;
        }

        $quotedTable = gm_quote_identifier($table);

        if (gm_column_exists($table, 'requested_email') && gm_column_exists($table, 'status')) {
            $checks[] = "SELECT 1 FROM {$quotedTable} WHERE LOWER(requested_email) = LOWER(:email) AND status IN ('pending', 'approved', 'processing') LIMIT 1";
        } elseif (gm_column_exists($table, 'email') && gm_column_exists($table, 'status')) {
            $checks[] = "SELECT 1 FROM {$quotedTable} WHERE LOWER(email) = LOWER(:email) AND status IN ('pending', 'approved', 'processing') LIMIT 1";
        }
    }

    foreach ($checks as $sql) {
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute(['email' => $email]);

            if ($stmt->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {
        }
    }

    return false;
}

function gm_graph_access_token(): ?string
{
    $tenantId = app_config('MS_TENANT_ID', '');
    $clientId = app_config('MS_CLIENT_ID', '');
    $clientSecret = app_config('MS_CLIENT_SECRET', '');

    if (!$tenantId || !$clientId || !$clientSecret || !function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init('https://login.microsoftonline.com/' . rawurlencode((string) $tenantId) . '/oauth2/v2.0/token');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300 || !is_string($raw)) {
        return null;
    }

    $json = json_decode($raw, true);

    return is_array($json) ? (string) ($json['access_token'] ?? '') ?: null : null;
}

function gm_graph_user_exists(string $email): ?bool
{
    $token = gm_graph_access_token();

    if (!$token || !function_exists('curl_init')) {
        return null;
    }

    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($email) . '?$select=id,userPrincipalName,mail';
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status === 404) {
        return false;
    }

    if ($status >= 200 && $status < 300) {
        return true;
    }

    return null;
}

function gm_available_district_email(string $firstName, string $lastName): array
{
    for ($suffix = 0; $suffix <= 50; $suffix++) {
        $candidate = gm_district_email_candidate($firstName, $lastName, $suffix);

        if (gm_local_email_known($candidate)) {
            continue;
        }

        $graphExists = gm_graph_user_exists($candidate);

        if ($graphExists === true) {
            continue;
        }

        return [
            'email' => $candidate,
            'checked_graph' => $graphExists !== null,
            'graph_available' => $graphExists === false,
            'suffix' => $suffix,
        ];
    }

    return [
        'email' => gm_district_email_candidate($firstName, $lastName, 51),
        'checked_graph' => false,
        'graph_available' => null,
        'suffix' => 51,
    ];
}

function gm_find_person_by_email(string $email): ?array
{
    $stmt = db()->prepare("
        SELECT *
        FROM people
        WHERE LOWER(primary_email) = LOWER(:email)
        LIMIT 1
    ");
    $stmt->execute(['email' => $email]);

    $person = $stmt->fetch(PDO::FETCH_ASSOC);

    return $person ?: null;
}

function gm_upsert_directory_profile(int $personId, string $roleTitle, int $visibleInDirectory, int $sharePhone): void
{
    if (!gm_table_exists('directory_profiles')) {
        return;
    }

    $stmt = db()->prepare("
        INSERT INTO directory_profiles (
            person_id,
            role_title,
            visible_in_directory,
            share_phone,
            profile_updated_at
        )
        VALUES (
            :person_id,
            :role_title,
            :visible_in_directory,
            :share_phone,
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            role_title = VALUES(role_title),
            visible_in_directory = VALUES(visible_in_directory),
            share_phone = VALUES(share_phone),
            profile_updated_at = NOW()
    ");
    $stmt->execute([
        'person_id' => $personId,
        'role_title' => $roleTitle,
        'visible_in_directory' => $visibleInDirectory,
        'share_phone' => $sharePhone,
    ]);
}

function gm_upsert_membership(int $personId, int $groupId, string $membershipRole): void
{
    $accessLevel = gm_access_level_for_membership_role($membershipRole);

    // Only set is_primary = 1 if this person has no other active memberships yet.
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM group_memberships
        WHERE person_id = :person_id
          AND group_id <> :group_id
          AND status = 'active'
    ");
    $stmt->execute([
        'person_id' => $personId,
        'group_id' => $groupId,
    ]);
    $hasOtherMemberships = ((int) $stmt->fetchColumn()) > 0;
    $isPrimary = $hasOtherMemberships ? 0 : 1;

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
            :is_primary,
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
        'is_primary' => $isPrimary,
    ]);
}

function gm_log_action(int $actorPersonId, string $action, string $entityType, int $entityId, array $details = []): void
{
    if (!gm_table_exists('audit_log')) {
        return;
    }

    try {
        gm_insert_flexible('audit_log', [
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
    }
}

function gm_create_district_email_request(
    int $requestedByPersonId,
    int $personId,
    int $groupId,
    string $requestedUpn,
    string $contactEmail,
    string $notes = ''
): bool {
    $requestedUpn = strtolower(trim($requestedUpn));
    $contactEmail = strtolower(trim($contactEmail));
    $notes = trim($notes);

    if ($requestedUpn === '' || !filter_var($requestedUpn, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $stmt = db()->prepare("
        INSERT INTO m365_account_requests (
            person_id,
            requested_by_person_id,
            requested_upn,
            status,
            admin_notes,
            provision_status,
            provision_attempts,
            created_at,
            updated_at
        )
        VALUES (
            :person_id,
            :requested_by_person_id,
            :requested_upn,
            'requested',
            :admin_notes,
            'pending',
            0,
            NOW(),
            NOW()
        )
    ");

    return $stmt->execute([
        'person_id' => $personId,
        'requested_by_person_id' => $requestedByPersonId,
        'requested_upn' => $requestedUpn,
        'admin_notes' => $notes !== '' ? $notes : null,
    ]);
}

function gm_person_has_pending_account_request(int $personId, string $personalEmail): bool
{
    foreach (['m365_account_requests', 'microsoft365_account_requests', 'microsoft_account_requests', 'account_requests', 'requests'] as $table) {
        if (!gm_table_exists($table) || !gm_column_exists($table, 'status')) {
            continue;
        }

        $quotedTable = gm_quote_identifier($table);
        $conditions = [];
        $params = [
            'person_id' => $personId,
            'personal_email' => $personalEmail,
        ];

        if (gm_column_exists($table, 'person_id')) {
            $conditions[] = 'person_id = :person_id';
        }

        if (gm_column_exists($table, 'requested_for_person_id')) {
            $conditions[] = 'requested_for_person_id = :person_id';
        }

        if (gm_column_exists($table, 'personal_email')) {
            $conditions[] = 'LOWER(personal_email) = LOWER(:personal_email)';
        }

        if (!$conditions) {
            continue;
        }

        try {
            $stmt = db()->prepare("
                SELECT 1
                FROM {$quotedTable}
                WHERE (" . implode(' OR ', $conditions) . ")
                  AND status IN ('pending', 'approved', 'processing')
                LIMIT 1
            ");
            $stmt->execute($params);

            if ($stmt->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {
        }
    }

    return false;
}

function gm_create_unique_invite(int $actorPersonId, int $personId, int $groupId): ?string
{
    if (gm_table_exists('group_user_invites')) {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);

        try {
            $inserted = gm_insert_flexible('group_user_invites', [
                'group_id' => $groupId,
                'person_id' => $personId,
                'token_hash' => $hash,
                'token_plain' => $token,
                'status' => 'active',
                'created_by_person_id' => $actorPersonId,
                'created_at' => date('Y-m-d H:i:s'),
                'expires_at' => date('Y-m-d H:i:s', strtotime('+90 days')),
            ]);
        } catch (Throwable $e) {
            $inserted = false;
        }

        if ($inserted) {
            return gm_absolute_url('/login.php?invite=' . urlencode($token));
        }
    }

    $links = gm_fetch_group_links($groupId);

    foreach ($links as $link) {
        $url = gm_group_link_url($link);

        if ($url) {
            return $url;
        }
    }

    return gm_absolute_url('/dc/');
}

function gm_queue_email_and_log(
    int $personId,
    string $toEmail,
    string $toName,
    string $subject,
    string $body,
    string $type
): void {
    $toEmail = strtolower(trim($toEmail));

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $bodyHtml = '<p>' . str_replace("\n", '<br>', htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
    $preview = strip_tags($body);
    $preview = function_exists('mb_substr') ? mb_substr($preview, 0, 800) : substr($preview, 0, 800);

    try {
        if (gm_table_exists('email_queue')) {
            gm_insert_flexible('email_queue', [
                'to_email' => $toEmail,
                'to_name' => $toName,
                'subject' => $subject,
                'body' => $body,
                'body_html' => $bodyHtml,
                'status' => 'pending',
                'notification_type' => $type,
                'related_entity_type' => 'person',
                'related_entity_id' => $personId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        if (gm_table_exists('notification_log')) {
            gm_insert_flexible('notification_log', [
                'related_entity_type' => 'person',
                'related_entity_id' => $personId,
                'recipient_name' => $toName,
                'recipient_email' => $toEmail,
                'notification_type' => $type,
                'subject' => $subject,
                'body_preview' => $preview,
                'sent_successfully' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        if (gm_table_exists('emails')) {
            gm_insert_flexible('emails', [
                'EmailTo' => $toEmail,
                'EmailSubject' => $subject,
                'EmailContent' => nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                'Submitted' => date('Y-m-d H:i:s'),
                'Sent' => 'no',
            ]);
        }
    } catch (Throwable $e) {
    }
}

function gm_update_group_role(int $personId, int $groupId, string $membershipRole, int $actorPersonId): void
{
    $roleOptions = gm_membership_role_options($groupId);

    if (!array_key_exists($membershipRole, $roleOptions)) {
        throw new RuntimeException('Choose a valid role.');
    }

    $accessLevel = gm_access_level_for_membership_role($membershipRole);
    $roleTitle = gm_role_title_from_membership_role($membershipRole);

    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM group_memberships
        WHERE person_id = :person_id
          AND group_id = :group_id
    ");
    $stmt->execute([
        'person_id' => $personId,
        'group_id' => $groupId,
    ]);

    if ((int) $stmt->fetchColumn() < 1) {
        throw new RuntimeException('That person is not linked to this Group.');
    }

    $stmt = db()->prepare("
        UPDATE group_memberships
        SET membership_role = :membership_role,
            access_level = :access_level,
            status = 'active',
            approved_at = COALESCE(approved_at, NOW())
        WHERE person_id = :person_id
          AND group_id = :group_id
        LIMIT 1
    ");
    $stmt->execute([
        'membership_role' => $membershipRole,
        'access_level' => $accessLevel,
        'person_id' => $personId,
        'group_id' => $groupId,
    ]);

    gm_upsert_directory_profile($personId, $roleTitle, 1, 0);

    gm_log_action($actorPersonId, 'group_person_role_changed', 'person', $personId, [
        'group_id' => $groupId,
        'membership_role' => $membershipRole,
        'access_level' => $accessLevel,
    ]);
}

function gm_update_person_details(
    int $personId,
    int $groupId,
    string $fullName,
    string $primaryEmail,
    string $phone,
    int $visibleInDirectory,
    int $sharePhone,
    int $actorPersonId
): void {
    $fullName = trim($fullName);
    $primaryEmail = strtolower(trim($primaryEmail));

    if ($fullName === '') {
        throw new RuntimeException('Enter the person\'s name.');
    }

    if ($primaryEmail === '' || !filter_var($primaryEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }

    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM group_memberships
        WHERE person_id = :person_id
          AND group_id = :group_id
    ");
    $stmt->execute([
        'person_id' => $personId,
        'group_id' => $groupId,
    ]);

    if ((int) $stmt->fetchColumn() < 1) {
        throw new RuntimeException('That person is not linked to this Group.');
    }

    $stmt = db()->prepare("
        UPDATE people
        SET full_name = :full_name,
            primary_email = :primary_email,
            phone = :phone
        WHERE id = :person_id
    ");
    $stmt->execute([
        'full_name' => $fullName,
        'primary_email' => $primaryEmail,
        'phone' => $phone !== '' ? $phone : null,
        'person_id' => $personId,
    ]);

    $person = gm_fetch_person_for_group($groupId, $personId);
    $roleTitle = gm_role_title_from_membership_role((string) ($person['membership_role'] ?? 'group_leadership_team_member'));

    gm_upsert_directory_profile($personId, $roleTitle, $visibleInDirectory, $sharePhone);

    gm_log_action($actorPersonId, 'group_person_details_changed', 'person', $personId, [
        'group_id' => $groupId,
    ]);
}

function gm_set_person_membership_status(int $personId, int $groupId, string $newStatus, int $actorPersonId): void
{
    $newStatus = $newStatus === 'active' ? 'active' : 'inactive';

    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM group_memberships
        WHERE person_id = :person_id
          AND group_id = :group_id
    ");
    $stmt->execute([
        'person_id' => $personId,
        'group_id' => $groupId,
    ]);

    if ((int) $stmt->fetchColumn() < 1) {
        throw new RuntimeException('That person is not linked to this Group.');
    }

    $stmt = db()->prepare("
        UPDATE group_memberships
        SET status = :status,
            is_primary = CASE WHEN :primary_status = 'inactive' THEN 0 ELSE is_primary END,
            approved_at = CASE WHEN :approved_status = 'active' THEN COALESCE(approved_at, NOW()) ELSE approved_at END
        WHERE person_id = :person_id
          AND group_id = :group_id
    ");
    $stmt->execute([
        'status' => $newStatus,
        'primary_status' => $newStatus,
        'approved_status' => $newStatus,
        'person_id' => $personId,
        'group_id' => $groupId,
    ]);

    if ($newStatus === 'inactive') {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM group_memberships
            WHERE person_id = :person_id
              AND status = 'active'
        ");
        $stmt->execute(['person_id' => $personId]);

        if ((int) $stmt->fetchColumn() === 0) {
            $stmt = db()->prepare("
                UPDATE people
                SET status = 'inactive'
                WHERE id = :person_id
            ");
            $stmt->execute(['person_id' => $personId]);
        }
    } else {
        $stmt = db()->prepare("
            UPDATE people
            SET status = 'active'
            WHERE id = :person_id
        ");
        $stmt->execute(['person_id' => $personId]);
    }

    gm_log_action($actorPersonId, 'group_person_status_changed', 'person', $personId, [
        'group_id' => $groupId,
        'status' => $newStatus,
    ]);
}

function gm_access_status_label(array $person): string
{
    if ((int) ($person['has_microsoft_account'] ?? 0) > 0) {
        return 'Microsoft SSO';
    }

    if (gm_person_has_pending_account_request((int) $person['person_id'], (string) $person['primary_email'])) {
        return 'Account requested';
    }

    return 'No SSO yet';
}

function gm_send_microsoft_instructions(array $person, int $groupId, int $actorPersonId): void
{
    $firstName = explode(' ', trim((string) $person['full_name']))[0] ?: 'there';
    $ssoUrl = gm_absolute_url('/auth/microsoft-start.php');

    gm_queue_email_and_log(
        (int) $person['person_id'],
        (string) $person['primary_email'],
        (string) $person['full_name'],
        'How to sign in to the Irwell Valley District App',
        "Hello {$firstName},\n\n"
        . "Here are your sign-in instructions for the Irwell Valley District App.\n\n"
        . "\n"
        . "HOW TO SIGN IN\n\n"
        . "Go to the link below and click \"Sign in with Microsoft\". Use your District Microsoft 365 email address and password to sign in.\n\n"
        . "{$ssoUrl}\n\n"
        . "\n"
        . "WHAT YOU CAN ACCESS\n\n"
        . "Once signed in you will have access to:\n\n"
        . "- The District Dashboard\n"
        . "- The District Directory\n"
        . "- The District Calendar\n"
        . "- Your Scout email and OneDrive\n\n"
        . "\n"
        . "NEED HELP?\n\n"
        . "If you have trouble signing in, check that you are using your @" . gm_default_district_email_domain() . " email address. If you have forgotten your password, use the \"Forgot password\" option on the Microsoft sign-in page.\n\n"
        . "If you still need help, ask your Group Lead Volunteer.\n\n"
        . "\n"
        . "Irwell Valley Scout District",
        'microsoft_signin_instructions'
    );

    gm_log_action($actorPersonId, 'microsoft_instructions_resent', 'person', (int) $person['person_id'], [
        'group_id' => $groupId,
    ]);
}

function gm_send_calendar_link_instructions(array $person, int $groupId, int $actorPersonId): ?string
{
    $firstName = explode(' ', trim((string) $person['full_name']))[0] ?: 'there';
    $inviteUrl = gm_create_unique_invite($actorPersonId, (int) $person['person_id'], $groupId);

    gm_queue_email_and_log(
        (int) $person['person_id'],
        (string) $person['primary_email'],
        (string) $person['full_name'],
        'Your Irwell Valley District Calendar link',
        "Hello {$firstName},\n\n"
        . "Here is your personal link to access the Irwell Valley District Calendar.\n\n"
        . "\n"
        . "YOUR CALENDAR LINK\n\n"
        . "{$inviteUrl}\n\n"
        . "Bookmark this link or save this email so you can find it again easily.\n\n"
        . "\n"
        . "WHAT YOU CAN ACCESS\n\n"
        . "This link gives you access to the District Calendar where you can view upcoming events and activities.\n\n"
        . "This link does not give access to the District Dashboard, Directory, Scout email, or OneDrive.\n\n"
        . "\n"
        . "IMPORTANT\n\n"
        . "This link is personal to you. Please do not share it with others.\n\n"
        . "If you later receive a District Microsoft 365 account, you should use the Microsoft sign-in button instead of this link.\n\n"
        . "\n"
        . "Irwell Valley Scout District",
        'group_calendar_invite_resent'
    );

    gm_log_action($actorPersonId, 'calendar_link_instructions_resent', 'person', (int) $person['person_id'], [
        'group_id' => $groupId,
    ]);

    return $inviteUrl;
}

function gm_nav_url(string $page, int $groupId): string
{
    return $page . '?group_id=' . $groupId;
}

/**
 * Set a membership as the person's primary role.
 *
 * Clears is_primary on all other memberships for this person,
 * then sets is_primary = 1 on the specified group membership.
 * Only district admins should call this.
 */
function gm_set_primary_membership(int $personId, int $groupId, int $actorPersonId): void
{
    if (!gm_column_exists('group_memberships', 'is_primary')) {
        throw new RuntimeException('The is_primary column has not been added yet. Please run the migration first.');
    }

    // Verify the membership exists and is active.
    $stmt = db()->prepare("
        SELECT id
        FROM group_memberships
        WHERE person_id = :person_id
          AND group_id = :group_id
          AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([
        'person_id' => $personId,
        'group_id' => $groupId,
    ]);

    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('That person does not have an active membership in this Group.');
    }

    // Clear primary from all this person's memberships.
    $stmt = db()->prepare("
        UPDATE group_memberships
        SET is_primary = 0
        WHERE person_id = :person_id
    ");
    $stmt->execute(['person_id' => $personId]);

    // Set this one as primary.
    $stmt = db()->prepare("
        UPDATE group_memberships
        SET is_primary = 1
        WHERE person_id = :person_id
          AND group_id = :group_id
          AND status = 'active'
    ");
    $stmt->execute([
        'person_id' => $personId,
        'group_id' => $groupId,
    ]);

    gm_log_action($actorPersonId, 'primary_membership_set', 'person', $personId, [
        'group_id' => $groupId,
    ]);
}

/**
 * Search for potential duplicate people by name or email.
 *
 * Returns matching people from any group with their group memberships,
 * so the user can be warned before creating a duplicate.
 */
function gm_find_potential_duplicates(string $fullName, string $email, int $excludeGroupId = 0): array
{
    $pdo = db();
    $duplicates = [];

    $fullName = trim($fullName);
    $email = strtolower(trim($email));

    if ($fullName === '' && $email === '') {
        return [];
    }

    $conditions = [];
    $params = [];

    // Match by email (exact, case-insensitive).
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $conditions[] = "LOWER(p.primary_email) = :email";
        $params['email'] = $email;
    }

    // Match by full name (exact, case-insensitive).
    if ($fullName !== '') {
        $conditions[] = "LOWER(p.full_name) = LOWER(:full_name)";
        $params['full_name'] = $fullName;
    }

    if (!$conditions) {
        return [];
    }

    $whereSql = '(' . implode(' OR ', $conditions) . ')';

    $excludeJoin = '';
    if ($excludeGroupId > 0) {
        // Exclude people who are already active in the target group.
        $excludeJoin = "
            LEFT JOIN group_memberships excl_gm
                ON excl_gm.person_id = p.id
                AND excl_gm.group_id = :exclude_group_id
                AND excl_gm.status = 'active'
        ";
        $params['exclude_group_id'] = $excludeGroupId;
    }

    $sql = "
        SELECT
            p.id AS person_id,
            p.full_name,
            p.primary_email,
            p.status AS person_status,
            GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ') AS groups_list
        FROM people p
        LEFT JOIN group_memberships gm
            ON gm.person_id = p.id
            AND gm.status = 'active'
        LEFT JOIN groups g
            ON g.id = gm.group_id
            AND g.is_active = 1
        {$excludeJoin}
        WHERE {$whereSql}
    ";

    if ($excludeGroupId > 0) {
        $sql .= " AND excl_gm.id IS NULL";
    }

    $sql .= "
        GROUP BY p.id, p.full_name, p.primary_email, p.status
        ORDER BY p.full_name ASC
        LIMIT 10
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Non-fatal: duplicate check should not block adding.
    }

    return $duplicates;
}
