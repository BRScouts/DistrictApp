<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

if (function_exists('user_needs_group_onboarding') && user_needs_group_onboarding()) {
    redirect('/onboarding.php');
}

$pdo = db();
$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

define('GM_DEFAULT_DISTRICT_EMAIL_DOMAIN', app_config('DISTRICT_EMAIL_DOMAIN', 'irvalscouts.org.uk'));

function gm_table_exists(string $table): bool
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

function gm_column_exists(string $table, string $column): bool
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

function gm_table_columns(string $table): array
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

    $quotedColumns = array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`', array_keys($insert));
    $placeholders = array_map(static fn(string $column): string => ':' . $column, array_keys($insert));
    $stmt = db()->prepare('INSERT INTO `' . str_replace('`', '``', $table) . '` (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $placeholders) . ')');
    return $stmt->execute($insert);
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

function gm_actor_is_district_admin(array $user, array $memberships): bool
{
    $levels = [(string) ($user['highest_access_level'] ?? $user['role'] ?? 'member')];
    foreach ($memberships as $membership) {
        $levels[] = (string) ($membership['access_level'] ?? 'member');
    }

    return (bool) array_intersect(array_unique($levels), ['district_admin', 'system_admin']);
}

function gm_manageable_groups(int $personId, bool $isDistrictAdmin): array
{
    if ($isDistrictAdmin) {
        $stmt = db()->query("SELECT id, group_name, slug FROM groups WHERE is_active = 1 ORDER BY group_name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = db()->prepare("
        SELECT DISTINCT g.id, g.group_name, g.slug
        FROM group_memberships gm
        JOIN groups g ON g.id = gm.group_id
        WHERE gm.person_id = :person_id
          AND gm.status = 'active'
          AND g.is_active = 1
          AND (gm.membership_role = 'group_lead_volunteer' OR gm.access_level = 'group_admin')
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

function gm_fetch_group(int $groupId): ?array
{
    $stmt = db()->prepare("SELECT * FROM groups WHERE id = :group_id AND is_active = 1 LIMIT 1");
    $stmt->execute(['group_id' => $groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    return $group ?: null;
}

function gm_fetch_leaders(int $groupId): array
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
         AND (ce.submitted_by_person_id = p.id OR LOWER(ce.leader_email) = LOWER(p.primary_email))
        WHERE gm.group_id = :group_id
          AND gm.status = 'active'
          AND p.status = 'active'
        GROUP BY
            p.id, p.full_name, p.primary_email, p.phone, p.status,
            gm.id, gm.membership_role, gm.access_level, gm.status,
            dp.role_title, dp.visible_in_directory
        ORDER BY p.full_name ASC
    ");
    $stmt->execute(['group_id' => $groupId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function gm_fetch_group_links(int $groupId): array
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
        $select = implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', $columns));
        $stmt = db()->prepare("
            SELECT {$select}
            FROM group_access_links
            WHERE group_id = :group_id
              AND status = 'active'
              AND (" . ($hasExpiresAt ? "expires_at IS NULL OR expires_at > NOW()" : "1 = 1") . ")
            ORDER BY " . ($hasCreatedAt ? "created_at DESC," : "") . " id DESC
            LIMIT 10
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

function gm_membership_role_options(): array
{
    return [
        'group_lead_volunteer' => 'Group Lead Volunteer',
        'section_leader' => 'Section Leader',
        'assistant_section_leader' => 'Assistant Section Leader',
        'section_assistant' => 'Section Assistant',
        'trustee' => 'Trustee',
        'district_volunteer' => 'District Volunteer',
        'other' => 'Other',
    ];
}

function gm_role_title_from_membership_role(string $membershipRole): string
{
    $options = gm_membership_role_options();
    return $options[$membershipRole] ?? 'Other';
}

function gm_access_level_for_membership_role(string $membershipRole): string
{
    return $membershipRole === 'group_lead_volunteer' ? 'group_admin' : 'member';
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
    return $local . '@' . GM_DEFAULT_DISTRICT_EMAIL_DOMAIN;
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
    if (gm_table_exists('requests')) {
        $checks[] = "SELECT 1 FROM requests WHERE LOWER(requested_email) = LOWER(:email) AND status IN ('pending', 'approved', 'processing') LIMIT 1";
    }

    foreach ($checks as $sql) {
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute(['email' => $email]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {
            // Skip incompatible optional tables.
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

    $ch = curl_init('https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token');
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
    $stmt = db()->prepare("SELECT * FROM people WHERE LOWER(primary_email) = LOWER(:email) LIMIT 1");
    $stmt->execute(['email' => $email]);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    return $person ?: null;
}

function gm_find_possible_duplicates(string $firstName, string $lastName, string $email, int $excludePersonId = 0): array
{
    $nameNeedles = array_filter([gm_name_part($firstName), gm_name_part($lastName)]);
    $sql = "
        SELECT p.id, p.full_name, p.primary_email, p.phone, p.status,
               GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ') AS group_names
        FROM people p
        LEFT JOIN group_memberships gm ON gm.person_id = p.id
        LEFT JOIN groups g ON g.id = gm.group_id
        WHERE p.id <> :exclude_person_id
          AND (LOWER(p.primary_email) = LOWER(:email)";
    $params = ['exclude_person_id' => $excludePersonId, 'email' => $email];

    foreach (array_values($nameNeedles) as $index => $needle) {
        if (strlen($needle) < 3) {
            continue;
        }
        $param = 'name_' . $index;
        $sql .= " OR LOWER(p.full_name) LIKE :{$param}";
        $params[$param] = '%' . str_replace('.', '%', $needle) . '%';
    }

    $sql .= ") GROUP BY p.id, p.full_name, p.primary_email, p.phone, p.status ORDER BY p.full_name ASC LIMIT 8";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function gm_upsert_directory_profile(int $personId, string $roleTitle, int $visibleInDirectory, int $sharePhone): void
{
    if (!gm_table_exists('directory_profiles')) {
        return;
    }

    $stmt = db()->prepare("
        INSERT INTO directory_profiles (person_id, role_title, visible_in_directory, share_phone, profile_updated_at)
        VALUES (:person_id, :role_title, :visible_in_directory, :share_phone, NOW())
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
    $stmt = db()->prepare("
        INSERT INTO group_memberships (person_id, group_id, membership_role, access_level, status, is_primary, approved_at)
        VALUES (:person_id, :group_id, :membership_role, :access_level, 'active', 1, NOW())
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
}

function gm_log_action(int $actorPersonId, string $action, string $entityType, int $entityId, array $details = []): void
{
    if (!gm_table_exists('audit_log')) {
        return;
    }

    try {
        $stmt = db()->prepare("
            INSERT INTO audit_log (actor_type, actor_person_id, action, entity_type, entity_id, details_json)
            VALUES ('person', :actor_person_id, :action, :entity_type, :entity_id, :details_json)
        ");
        $stmt->execute([
            'actor_person_id' => $actorPersonId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
    }
}

function gm_create_district_email_request(int $actorPersonId, int $personId, int $groupId, string $requestedEmail, string $personalEmail, string $notes): bool
{
    $details = [
        'person_id' => $personId,
        'group_id' => $groupId,
        'requested_email' => $requestedEmail,
        'personal_email' => $personalEmail,
        'notes' => $notes,
        'request_context' => 'group_manager',
    ];

    foreach (['requests', 'account_requests', 'microsoft_account_requests'] as $table) {
        if (!gm_table_exists($table)) {
            continue;
        }

        try {
            if (gm_insert_flexible($table, [
                'request_type' => 'district_email',
                'type' => 'district_email',
                'status' => 'pending',
                'person_id' => $personId,
                'requested_for_person_id' => $personId,
                'group_id' => $groupId,
                'requested_by_person_id' => $actorPersonId,
                'actor_person_id' => $actorPersonId,
                'requested_email' => $requestedEmail,
                'email' => $requestedEmail,
                'personal_email' => $personalEmail,
                'notes' => $notes !== '' ? $notes : null,
                'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ])) {
                return true;
            }
        } catch (Throwable $e) {
        }
    }

    return false;
}

function gm_create_unique_invite(int $actorPersonId, int $personId, int $groupId): ?string
{
    if (!gm_table_exists('group_user_invites')) {
        return null;
    }

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

    return $inserted ? gm_absolute_url('/login.php?invite=' . urlencode($token)) : null;
}

function gm_queue_onboarding_email(int $personId, string $toEmail, string $toName, string $subject, string $body, string $type): void
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL) || !gm_table_exists('email_queue')) {
        return;
    }

    try {
        gm_insert_flexible('email_queue', [
            'to_email' => $toEmail,
            'to_name' => $toName,
            'subject' => $subject,
            'body' => $body,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if (gm_table_exists('notification_log')) {
            gm_insert_flexible('notification_log', [
                'related_entity_type' => 'person',
                'related_entity_id' => $personId,
                'recipient_name' => $toName,
                'recipient_email' => $toEmail,
                'notification_type' => $type,
                'subject' => $subject,
                'body_preview' => mb_substr(strip_tags($body), 0, 800),
                'sent_successfully' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    } catch (Throwable $e) {
    }
}

function gm_merge_people(int $sourcePersonId, int $targetPersonId, int $groupId, int $actorPersonId): void
{
    if ($sourcePersonId === $targetPersonId || $sourcePersonId < 1 || $targetPersonId < 1) {
        throw new RuntimeException('Choose two different people to merge.');
    }

    $stmt = db()->prepare("SELECT id, full_name, primary_email FROM people WHERE id = :person_id LIMIT 1");
    $stmt->execute(['person_id' => $sourcePersonId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->execute(['person_id' => $targetPersonId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$source || !$target) {
        throw new RuntimeException('One of the selected people could not be found.');
    }

    $stmt = db()->prepare("SELECT COUNT(*) FROM group_memberships WHERE person_id = :person_id AND group_id = :group_id");
    $stmt->execute(['person_id' => $sourcePersonId, 'group_id' => $groupId]);
    $sourceInGroup = ((int) $stmt->fetchColumn()) > 0;
    $stmt->execute(['person_id' => $targetPersonId, 'group_id' => $groupId]);
    $targetInGroup = ((int) $stmt->fetchColumn()) > 0;
    if (!$sourceInGroup || !$targetInGroup) {
        throw new RuntimeException('Both people must be linked to this Group before they can be merged here.');
    }

    $sourceEmail = (string) ($source['primary_email'] ?? '');
    $targetEmail = (string) ($target['primary_email'] ?? '');
    $targetName = (string) ($target['full_name'] ?? '');

    if (gm_table_exists('calendar_events')) {
        if (gm_column_exists('calendar_events', 'submitted_by_person_id')) {
            $stmt = db()->prepare("UPDATE calendar_events SET submitted_by_person_id = :target_id WHERE submitted_by_person_id = :source_id AND group_id = :group_id");
            $stmt->execute(['target_id' => $targetPersonId, 'source_id' => $sourcePersonId, 'group_id' => $groupId]);
        }
        if ($sourceEmail !== '' && gm_column_exists('calendar_events', 'leader_email')) {
            $stmt = db()->prepare("UPDATE calendar_events SET leader_email = :target_email, leader_name = :target_name WHERE group_id = :group_id AND LOWER(leader_email) = LOWER(:source_email)");
            $stmt->execute(['target_email' => $targetEmail, 'target_name' => $targetName, 'group_id' => $groupId, 'source_email' => $sourceEmail]);
        }
    }

    if (gm_table_exists('risk_assessments') && gm_column_exists('risk_assessments', 'uploaded_by_person_id')) {
        $stmt = db()->prepare("UPDATE risk_assessments SET uploaded_by_person_id = :target_id WHERE uploaded_by_person_id = :source_id AND group_id = :group_id");
        $stmt->execute(['target_id' => $targetPersonId, 'source_id' => $sourcePersonId, 'group_id' => $groupId]);
    }

    if (gm_table_exists('user_accounts')) {
        $stmt = db()->prepare("UPDATE user_accounts SET person_id = :target_id WHERE person_id = :source_id");
        $stmt->execute(['target_id' => $targetPersonId, 'source_id' => $sourcePersonId]);
    }

    $stmt = db()->prepare("UPDATE group_memberships SET status = 'inactive', is_primary = 0 WHERE person_id = :source_id AND group_id = :group_id");
    $stmt->execute(['source_id' => $sourcePersonId, 'group_id' => $groupId]);
    $stmt = db()->prepare("UPDATE people SET status = 'inactive' WHERE id = :source_id");
    $stmt->execute(['source_id' => $sourcePersonId]);

    gm_log_action($actorPersonId, 'person_merged', 'person', $targetPersonId, [
        'source_person_id' => $sourcePersonId,
        'target_person_id' => $targetPersonId,
        'group_id' => $groupId,
    ]);
}

$memberships = function_exists('user_group_memberships') ? user_group_memberships((int) $user['id'], false) : [];
$isDistrictAdmin = gm_actor_is_district_admin($user, $memberships);
$manageableGroups = gm_manageable_groups((int) $user['id'], $isDistrictAdmin);

if (!$manageableGroups) {
    http_response_code(403);
    $pageTitle = 'Group Manager | ' . $appName;
    $heroTitle = 'Group Manager';
    $heroText = 'This area is for Group Lead Volunteers and District administrators.';
    $breadcrumb = '<a href="/index.php">Home</a> / Group Manager';
    include __DIR__ . '/header.php';
    echo '<main class="lt-main"><div class="alert alert-danger"><strong>Access denied:</strong> You do not currently manage any Groups.</div></main>';
    include __DIR__ . '/footer.php';
    exit;
}

$requestedGroupId = (int) ($_GET['group_id'] ?? $_POST['group_id'] ?? 0);
$selectedGroupId = $requestedGroupId > 0 && gm_group_is_manageable($requestedGroupId, $manageableGroups) ? $requestedGroupId : (int) $manageableGroups[0]['id'];
$selectedGroup = gm_fetch_group($selectedGroupId);
if (!$selectedGroup) {
    http_response_code(404);
    include __DIR__ . '/header.php';
    echo '<main class="lt-main"><div class="alert alert-danger">Group not found.</div></main>';
    include __DIR__ . '/footer.php';
    exit;
}

$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'people');
$allowedTabs = ['people', 'add', 'links', 'website'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'people';
}

$errors = [];
$success = null;
$createdInviteUrl = null;
$duplicateCandidates = [];
$posted = [];
$actorPersonId = (int) $user['id'];
$districtEmailSuggestion = null;
$graphChecked = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'suggest_email') {
            $tab = 'add';
            $posted = $_POST;
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            if ($firstName === '' || $lastName === '') {
                $errors[] = 'Enter the person\'s first and last name before checking the District email.';
            } else {
                $suggestion = gm_available_district_email($firstName, $lastName);
                $districtEmailSuggestion = $suggestion['email'];
                $graphChecked = (bool) $suggestion['checked_graph'];
                $posted['requested_district_email'] = $districtEmailSuggestion;
                $success = $graphChecked
                    ? 'District email checked against Microsoft 365 and reserved locally for this request.'
                    : 'District email checked locally. Microsoft Graph was not available, so District admins should confirm before creation.';
            }
        } elseif ($action === 'add_person') {
            $tab = 'add';
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $fullName = trim($firstName . ' ' . $lastName);
            $personalEmail = strtolower(trim((string) ($_POST['personal_email'] ?? '')));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $membershipRole = (string) ($_POST['membership_role'] ?? 'section_leader');
            $visibleInDirectory = isset($_POST['visible_in_directory']) ? 1 : 0;
            $sharePhone = isset($_POST['share_phone']) ? 1 : 0;
            $noDistrictAccount = isset($_POST['no_district_account']);
            $requestedDistrictEmail = strtolower(trim((string) ($_POST['requested_district_email'] ?? '')));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $confirmDuplicate = isset($_POST['confirm_duplicate']);

            $posted = $_POST;
            $roleOptions = gm_membership_role_options();

            if ($firstName === '') {
                $errors[] = 'Enter the person\'s first name.';
            }
            if ($lastName === '') {
                $errors[] = 'Enter the person\'s last name.';
            }
            if ($personalEmail === '' || !filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid personal or Scouting email address.';
            }
            if (!array_key_exists($membershipRole, $roleOptions)) {
                $errors[] = 'Choose a valid role.';
            }

            if (!$noDistrictAccount) {
                if ($requestedDistrictEmail === '') {
                    $suggestion = gm_available_district_email($firstName, $lastName);
                    $requestedDistrictEmail = $suggestion['email'];
                    $posted['requested_district_email'] = $requestedDistrictEmail;
                }
                if (!filter_var($requestedDistrictEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'The suggested District email address is not valid.';
                }
            }

            if (!$errors) {
                $existingPerson = gm_find_person_by_email($personalEmail);
                $personId = $existingPerson ? (int) $existingPerson['id'] : 0;

                if (!$existingPerson) {
                    $duplicateCandidates = gm_find_possible_duplicates($firstName, $lastName, $personalEmail);
                    if ($duplicateCandidates && !$confirmDuplicate) {
                        $errors[] = 'Possible duplicate people were found. Review them below, then tick the confirmation box if this really is a new person.';
                    }
                }

                if (!$errors) {
                    $pdo->beginTransaction();

                    if ($existingPerson) {
                        $personId = (int) $existingPerson['id'];
                        $stmt = $pdo->prepare("
                            UPDATE people
                            SET full_name = CASE WHEN full_name IS NULL OR full_name = '' THEN :full_name ELSE full_name END,
                                phone = COALESCE(NULLIF(phone, ''), :phone),
                                status = 'active'
                            WHERE id = :person_id
                        ");
                        $stmt->execute(['full_name' => $fullName, 'phone' => $phone !== '' ? $phone : null, 'person_id' => $personId]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO people (full_name, primary_email, phone, status) VALUES (:full_name, :primary_email, :phone, 'active')");
                        $stmt->execute(['full_name' => $fullName, 'primary_email' => $personalEmail, 'phone' => $phone !== '' ? $phone : null]);
                        $personId = (int) $pdo->lastInsertId();
                    }

                    gm_upsert_membership($personId, $selectedGroupId, $membershipRole);
                    gm_upsert_directory_profile($personId, gm_role_title_from_membership_role($membershipRole), $visibleInDirectory, $sharePhone);

                    $requestRecorded = false;
                    if (!$noDistrictAccount) {
                        $requestRecorded = gm_create_district_email_request($actorPersonId, $personId, $selectedGroupId, $requestedDistrictEmail, $personalEmail, $notes);
                        gm_queue_onboarding_email(
                            $personId,
                            $personalEmail,
                            $fullName,
                            'Your Irwell Valley District account request',
                            "Hello {$firstName},\n\nYour Group Lead Volunteer has requested a District Microsoft 365 account for you.\n\nRequested address: {$requestedDistrictEmail}\n\nOnce created, you will receive details explaining how to sign in to the Leader Tool and District Calendar using Microsoft SSO. This is normally processed within about 5 minutes once the account automation runs.\n\nLeader Tool: " . gm_absolute_url('/login.php') . "\n\nIrwell Valley Scout District",
                            'district_account_requested'
                        );
                    } else {
                        $createdInviteUrl = gm_create_unique_invite($actorPersonId, $personId, $selectedGroupId);
                        if ($createdInviteUrl) {
                            gm_queue_onboarding_email(
                                $personId,
                                $personalEmail,
                                $fullName,
                                'Your Irwell Valley District Calendar access link',
                                "Hello {$firstName},\n\nYour Group Lead Volunteer has added you to the District Calendar.\n\nUse this personal link to access the app:\n{$createdInviteUrl}\n\nA District Microsoft 365 account was not requested. If you later receive a District account, please sign in with Microsoft SSO so your records can be linked.\n\nIrwell Valley Scout District",
                                'group_calendar_invite'
                            );
                        }
                    }

                    gm_log_action($actorPersonId, $existingPerson ? 'group_person_linked' : 'group_person_created', 'person', $personId, [
                        'group_id' => $selectedGroupId,
                        'membership_role' => $membershipRole,
                        'district_account_requested' => !$noDistrictAccount,
                        'requested_district_email' => $requestedDistrictEmail,
                        'request_recorded' => $requestRecorded,
                    ]);

                    $pdo->commit();

                    $success = $existingPerson ? 'Existing person found by personal email and linked to this Group.' : 'Person added to this Group.';
                    if (!$noDistrictAccount) {
                        $success .= ' A District Microsoft 365 account request has been queued. They should receive sign-in details after the account automation runs.';
                        if (!$requestRecorded) {
                            $success .= ' No compatible requests table was found, so check the audit log or apply the requests migration.';
                        }
                    } elseif ($createdInviteUrl) {
                        $success .= ' A personal calendar access link has been created and queued by email.';
                    } else {
                        $success .= ' No personal invite table exists yet, so use the Group calendar link as the fallback.';
                    }

                    $posted = [];
                }
            }
        } elseif ($action === 'set_status') {
            $personId = (int) ($_POST['person_id'] ?? 0);
            $newStatus = (string) ($_POST['new_status'] ?? 'inactive');
            $newStatus = $newStatus === 'active' ? 'active' : 'inactive';

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM group_memberships WHERE person_id = :person_id AND group_id = :group_id");
            $stmt->execute(['person_id' => $personId, 'group_id' => $selectedGroupId]);
            if ((int) $stmt->fetchColumn() < 1) {
                throw new RuntimeException('That person is not linked to this Group.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE group_memberships SET status = :status, is_primary = CASE WHEN :primary_status = 'inactive' THEN 0 ELSE is_primary END WHERE person_id = :person_id AND group_id = :group_id");
            $stmt->execute(['status' => $newStatus, 'primary_status' => $newStatus, 'person_id' => $personId, 'group_id' => $selectedGroupId]);

            if ($newStatus === 'inactive') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM group_memberships WHERE person_id = :person_id AND status = 'active'");
                $stmt->execute(['person_id' => $personId]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $stmt = $pdo->prepare("UPDATE people SET status = 'inactive' WHERE id = :person_id");
                    $stmt->execute(['person_id' => $personId]);
                }
            } else {
                $stmt = $pdo->prepare("UPDATE people SET status = 'active' WHERE id = :person_id");
                $stmt->execute(['person_id' => $personId]);
            }

            gm_log_action($actorPersonId, 'group_person_status_changed', 'person', $personId, ['group_id' => $selectedGroupId, 'status' => $newStatus]);
            $pdo->commit();
            $success = $newStatus === 'active' ? 'Person reactivated for this Group.' : 'Person made inactive. They will no longer appear in active leader pickers.';
        } elseif ($action === 'merge_people') {
            $sourcePersonId = (int) ($_POST['source_person_id'] ?? 0);
            $targetPersonId = (int) ($_POST['target_person_id'] ?? 0);
            $pdo->beginTransaction();
            gm_merge_people($sourcePersonId, $targetPersonId, $selectedGroupId, $actorPersonId);
            $pdo->commit();
            $success = 'People merged. Events, Microsoft login records and Group calendar ownership have been moved to the retained person.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $e->getMessage() ?: 'The request could not be completed.';
    }
}

$leaders = gm_fetch_leaders($selectedGroupId);
$groupLinks = gm_fetch_group_links($selectedGroupId);
$roleOptions = gm_membership_role_options();
$totalEvents = array_sum(array_map(static fn(array $leader): int => (int) $leader['total_events'], $leaders));
$leadersWithSso = count(array_filter($leaders, static fn(array $leader): bool => (int) $leader['has_microsoft_account'] > 0));

if (!$districtEmailSuggestion && !empty($posted['first_name']) && !empty($posted['last_name']) && !isset($posted['no_district_account'])) {
    $districtEmailSuggestion = (string) ($posted['requested_district_email'] ?? gm_district_email_candidate((string) $posted['first_name'], (string) $posted['last_name']));
}

$pageTitle = 'Group Manager | ' . $appName;
$heroTitle = 'Group Manager';
$heroText = 'Manage active leaders for ' . (string) $selectedGroup['group_name'] . ', encourage District Microsoft 365 sign-in, and keep the District Directory accurate.';
$breadcrumb = '<a href="/index.php">Home</a> / Group Manager';
?>
<?php include __DIR__ . '/header.php'; ?>

<style>
    .gm-tabs { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; }
    .gm-tab { display: inline-block; padding: .75rem 1rem; border: 2px solid var(--iv-purple); color: var(--iv-purple); font-weight: 900; text-decoration: none; background: #fff; }
    .gm-tab:hover { color: var(--iv-purple-dark); text-decoration: none; }
    .gm-tab.active { background: var(--iv-purple); color: #fff; }
    .gm-grid { display: grid; gap: 1rem; }
    @media (min-width: 992px) { .gm-grid-2 { grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); } }
    .gm-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
    .gm-stat { background: #fff; border: 2px solid #eee; padding: 1rem; }
    .gm-stat strong { display: block; font-size: 2rem; line-height: 1; color: var(--iv-purple); }
    .gm-table-wrap { overflow-x: auto; }
    .gm-table th { white-space: nowrap; }
    .gm-badge { display: inline-block; padding: .2rem .45rem; font-weight: 900; font-size: .78rem; border-radius: .25rem; }
    .gm-badge-sso { background: #e7f1ff; color: #004085; }
    .gm-badge-link { background: #fff3cd; color: #664d03; }
    .gm-flow-step { border-left: .45rem solid var(--iv-purple); padding: 1rem; background: #fff; margin-bottom: 1rem; box-shadow: 0 1px 0 rgba(0,0,0,.08); }
    .gm-flow-step h3 { margin-top: 0; }
    .gm-suggested-email { font-size: 1.15rem; font-weight: 900; color: var(--iv-purple); word-break: break-word; }
    .gm-link-box { display: grid; gap: .5rem; }
    @media (min-width: 768px) { .gm-link-box { grid-template-columns: minmax(0, 1fr) auto; } }
    .gm-card-list { display: grid; gap: 1rem; }
    .gm-muted { color: #555; }
    .gm-coming-soon { padding: 2rem; background: #f5f3ff; border: 2px dashed var(--iv-purple); }
</style>

<main class="lt-main">
    <?php if (count($manageableGroups) > 1): ?>
        <form class="lt-panel mb-4" method="get">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <div class="form-row align-items-end">
                <div class="form-group col-md-8 mb-md-0">
                    <label for="group_id"><strong>Managing Group</strong></label>
                    <select class="form-control" id="group_id" name="group_id">
                        <?php foreach ($manageableGroups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>" <?= (int) $group['id'] === $selectedGroupId ? 'selected' : '' ?>><?= e($group['group_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-4 mb-0">
                    <button class="btn btn-primary lt-btn btn-block" type="submit">Change Group</button>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <strong>There is a problem:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($createdInviteUrl): ?>
        <div class="alert alert-info">
            <strong>Personal access link created:</strong><br>
            <input class="form-control mt-2" type="text" value="<?= e($createdInviteUrl) ?>" readonly>
        </div>
    <?php endif; ?>

    <nav class="gm-tabs" aria-label="Group Manager tabs">
        <?php $baseTabUrl = '/group-manager.php?group_id=' . $selectedGroupId . '&tab='; ?>
        <a class="gm-tab <?= $tab === 'people' ? 'active' : '' ?>" href="<?= e($baseTabUrl . 'people') ?>">Active people</a>
        <a class="gm-tab <?= $tab === 'add' ? 'active' : '' ?>" href="<?= e($baseTabUrl . 'add') ?>">Add person</a>
        <a class="gm-tab <?= $tab === 'links' ? 'active' : '' ?>" href="<?= e($baseTabUrl . 'links') ?>">Calendar access</a>
        <a class="gm-tab <?= $tab === 'website' ? 'active' : '' ?>" href="<?= e($baseTabUrl . 'website') ?>">Website details</a>
    </nav>

    <?php if ($tab === 'people'): ?>
        <div class="gm-stats">
            <div class="gm-stat"><strong><?= count($leaders) ?></strong><span>active people</span></div>
            <div class="gm-stat"><strong><?= $leadersWithSso ?></strong><span>using Microsoft SSO</span></div>
            <div class="gm-stat"><strong><?= (int) $totalEvents ?></strong><span>linked calendar events</span></div>
        </div>

        <section class="lt-panel mb-4">
            <h2 class="lt-section-title">Active people</h2>
            <p class="lt-lede">Only active people are shown here. Inactive people will not appear in District Calendar leader selectors or normal Group lists.</p>
            <?php if ($leaders): ?>
                <div class="gm-table-wrap">
                    <table class="table gm-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Role</th>
                                <th>Access</th>
                                <th>Events</th>
                                <th>Latest event</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leaders as $leader): ?>
                                <tr>
                                    <td><strong><?= e($leader['full_name']) ?></strong><br><span class="gm-muted">Directory: <?= (int) $leader['visible_in_directory'] === 1 ? 'visible' : 'hidden' ?></span></td>
                                    <td><?= e($leader['primary_email']) ?><br><?= e($leader['phone'] ?: '') ?></td>
                                    <td><?= e($leader['role_title'] ?: gm_role_title_from_membership_role((string) $leader['membership_role'])) ?></td>
                                    <td>
                                        <?php if ((int) $leader['has_microsoft_account'] > 0): ?>
                                            <span class="gm-badge gm-badge-sso">Microsoft SSO</span>
                                        <?php else: ?>
                                            <span class="gm-badge gm-badge-link">No SSO yet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int) $leader['total_events'] ?> total<br><?= (int) $leader['in_review_events'] ?> in review<br><?= (int) $leader['approved_events'] ?> approved</td>
                                    <td><?= $leader['latest_event_at'] ? e(date('d M Y', strtotime((string) $leader['latest_event_at']))) : '—' ?></td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('Make this person inactive for this Group? They will stop appearing in leader selectors.');">
                                            <input type="hidden" name="action" value="set_status">
                                            <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">
                                            <input type="hidden" name="tab" value="people">
                                            <input type="hidden" name="person_id" value="<?= (int) $leader['person_id'] ?>">
                                            <input type="hidden" name="new_status" value="inactive">
                                            <button class="btn btn-outline-danger btn-sm" type="submit">Make inactive</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info mb-0">No active people are currently linked to this Group.</div>
            <?php endif; ?>
        </section>

        <section class="lt-panel-grey">
            <h2 class="lt-section-title">Merge duplicate people</h2>
            <p>Use this if someone was added with a personal email and later signs in with Microsoft 365 as a second person record. Calendar events, risk assessments and Microsoft login records will be reassigned to the person you keep.</p>
            <form method="post" class="form-row align-items-end" onsubmit="return confirm('This will merge records and cannot be automatically undone. Continue?');">
                <input type="hidden" name="action" value="merge_people">
                <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">
                <input type="hidden" name="tab" value="people">
                <div class="form-group col-md-5">
                    <label for="source_person_id">Duplicate person to remove</label>
                    <select class="form-control" id="source_person_id" name="source_person_id" required>
                        <option value="">Choose person</option>
                        <?php foreach ($leaders as $leader): ?><option value="<?= (int) $leader['person_id'] ?>"><?= e($leader['full_name'] . ' — ' . $leader['primary_email']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-5">
                    <label for="target_person_id">Person to keep</label>
                    <select class="form-control" id="target_person_id" name="target_person_id" required>
                        <option value="">Choose person</option>
                        <?php foreach ($leaders as $leader): ?><option value="<?= (int) $leader['person_id'] ?>"><?= e($leader['full_name'] . ' — ' . $leader['primary_email']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <button class="btn btn-primary lt-btn btn-block" type="submit">Merge</button>
                </div>
            </form>
        </section>
    <?php elseif ($tab === 'add'): ?>
        <div class="gm-grid gm-grid-2">
            <section class="lt-panel">
                <h2 class="lt-section-title">Add a person to <?= e($selectedGroup['group_name']) ?></h2>
                <p class="lt-lede">This flow creates or links the person, adds them to your Group, and starts their access route for the District Calendar.</p>

                <?php if ($duplicateCandidates): ?>
                    <div class="alert alert-warning">
                        <h3 class="h5 font-weight-bold">Possible duplicates</h3>
                        <p>Check these before creating a new person.</p>
                        <ul class="mb-0">
                            <?php foreach ($duplicateCandidates as $candidate): ?>
                                <li><strong><?= e($candidate['full_name']) ?></strong> — <?= e($candidate['primary_email']) ?><?= !empty($candidate['group_names']) ? ' — ' . e($candidate['group_names']) : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" id="gm-add-person-form">
                    <input type="hidden" name="action" value="add_person" id="gm-action">
                    <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">
                    <input type="hidden" name="tab" value="add">

                    <div class="gm-flow-step">
                        <h3 class="h5 font-weight-bold">Step 1 — Who are they?</h3>
                        <p>Use their real first and last name. This is used for the District Directory and to suggest a District email address.</p>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="first_name">First name</label>
                                <input class="form-control" type="text" id="first_name" name="first_name" value="<?= e($posted['first_name'] ?? '') ?>" required>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="last_name">Last name</label>
                                <input class="form-control" type="text" id="last_name" name="last_name" value="<?= e($posted['last_name'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="form-group mb-0">
                            <label for="personal_email">Personal or Scouting email</label>
                            <input class="form-control" type="email" id="personal_email" name="personal_email" value="<?= e($posted['personal_email'] ?? '') ?>" required>
                            <small class="form-text text-muted">Always collect this. It is where we send new login details and it helps prevent duplicate people records.</small>
                        </div>
                    </div>

                    <div class="gm-flow-step">
                        <h3 class="h5 font-weight-bold">Step 2 — Choose their role</h3>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="membership_role">Role</label>
                                <select class="form-control" id="membership_role" name="membership_role" required>
                                    <?php $selectedRole = (string) ($posted['membership_role'] ?? 'section_leader'); ?>
                                    <?php foreach ($roleOptions as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= $selectedRole === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="phone">Contact number</label>
                                <input class="form-control" type="text" id="phone" name="phone" value="<?= e($posted['phone'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="gm-flow-step">
                        <h3 class="h5 font-weight-bold">Step 3 — District Microsoft 365 access</h3>
                        <p>Preferred route: create a District Microsoft 365 account, then ask the leader to sign in with the Microsoft SSO button. This gives them normal Leader Tool access and avoids shared calendar links.</p>

                        <label class="lt-check mb-3">
                            <input type="checkbox" name="no_district_account" id="no_district_account" value="1" <?= isset($posted['no_district_account']) ? 'checked' : '' ?>>
                            <span>I do not wish for them to have a District Microsoft 365 account</span>
                        </label>

                        <div id="district-email-block">
                            <p class="mb-1">Suggested District email:</p>
                            <p class="gm-suggested-email" id="gm-email-preview"><?= e($districtEmailSuggestion ?: 'Enter first and last name to generate an address') ?></p>
                            <input type="hidden" id="requested_district_email" name="requested_district_email" value="<?= e($districtEmailSuggestion ?: ($posted['requested_district_email'] ?? '')) ?>">
                            <button class="btn btn-secondary lt-btn" type="submit" onclick="document.getElementById('gm-action').value='suggest_email';">Check availability</button>
                            <p class="gm-muted mt-2 mb-0">The server checks existing app records and, when Microsoft Graph application permissions are configured, checks Office 365 before choosing first.last, first.last1, first.last2 and so on.</p>
                        </div>

                        <div id="personal-link-block" class="alert alert-info mt-3" style="display:none;">
                            A District Microsoft 365 account will not be requested. The app will create a personal link where available and email it to their personal/Scouting email address. The Group calendar link remains a fallback, but SSO is still preferred where possible.
                        </div>
                    </div>

                    <div class="gm-flow-step">
                        <h3 class="h5 font-weight-bold">Step 4 — Directory and confirmation</h3>
                        <label class="lt-check">
                            <input type="checkbox" name="visible_in_directory" value="1" <?= array_key_exists('visible_in_directory', $posted) || !$posted ? 'checked' : '' ?>>
                            <span>Show this person in the District Directory</span>
                        </label>
                        <label class="lt-check mt-2">
                            <input type="checkbox" name="share_phone" value="1" <?= isset($posted['share_phone']) ? 'checked' : '' ?>>
                            <span>Show their phone number in the Directory</span>
                        </label>

                        <div class="form-group mt-3">
                            <label for="notes">Notes for District admins</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"><?= e($posted['notes'] ?? '') ?></textarea>
                        </div>

                        <?php if ($duplicateCandidates): ?>
                            <label class="lt-check mb-3">
                                <input type="checkbox" name="confirm_duplicate" value="1" required>
                                <span>I have checked the possible duplicates and this is a new person.</span>
                            </label>
                        <?php endif; ?>

                        <button class="btn btn-primary lt-btn" type="submit" onclick="document.getElementById('gm-action').value='add_person';">Add person and start access setup</button>
                    </div>
                </form>
            </section>

            <aside class="lt-panel-grey">
                <h2 class="lt-section-title">What happens next?</h2>
                <ol class="pl-3 font-weight-bold">
                    <li>We check for an existing person record by personal email.</li>
                    <li>If they need Microsoft 365, the request is added to the requests table.</li>
                    <li>After the account automation runs, usually within about 5 minutes, they are emailed instructions.</li>
                    <li>If Microsoft 365 is not requested, a personal access link is emailed where the invite table exists.</li>
                </ol>
                <p>The shared Group calendar link is shown under Calendar access, but should be treated as a fallback rather than the normal route.</p>
                <p class="mb-0"><strong>APP_URL note:</strong> calendar and invite links use <code>APP_URL</code> from <code>config.php</code>. If links show the wrong domain, update that constant.</p>
            </aside>
        </div>
    <?php elseif ($tab === 'links'): ?>
        <div class="lt-panel mb-4">
            <h2 class="lt-section-title">Group calendar access</h2>
            <p class="lt-lede">Use SSO wherever possible. The Group calendar link is a fallback bearer link for leaders who do not yet use District Microsoft 365.</p>
            <div class="alert alert-info"><strong>Where this URL is defined:</strong> this page uses <code>APP_URL</code> from <code>config.php</code>. If <code>APP_URL</code> is blank, it falls back to the current request host.</div>

            <?php if ($groupLinks): ?>
                <div class="gm-card-list">
                    <?php foreach ($groupLinks as $link): ?>
                        <?php $url = gm_group_link_url($link); ?>
                        <div class="lt-panel-grey">
                            <h3 class="h5 font-weight-bold"><?= e($link['label'] ?? 'Group calendar link') ?></h3>
                            <?php if ($url): ?>
                                <div class="gm-link-box">
                                    <input class="form-control" type="text" value="<?= e($url) ?>" readonly>
                                    <button class="btn btn-secondary lt-btn gm-copy" type="button" data-copy="<?= e($url) ?>">Copy</button>
                                </div>
                                <p class="gm-muted mt-2 mb-0">Share only with leaders in <?= e($selectedGroup['group_name']) ?>. If it is compromised, ask a District admin to rotate it.</p>
                            <?php else: ?>
                                <div class="alert alert-warning mb-0">This active link has no visible token. A District admin needs to rotate or recreate it before GLVs can share it.</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-warning mb-0">No active Group calendar link is available for this Group.</div>
            <?php endif; ?>
        </div>
    <?php elseif ($tab === 'website'): ?>
        <div class="gm-coming-soon">
            <h2 class="lt-section-title">Group website details</h2>
            
            <a href="group_website_admin.php" class="btn btn-primary lt-btn mt-3">Manage website details</a>
        </div>
    <?php endif; ?>
</main>

<script>
(function () {
    function slugPart(value) {
        return (value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '.')
            .replace(/^\.+|\.+$/g, '');
    }

    var first = document.getElementById('first_name');
    var last = document.getElementById('last_name');
    var preview = document.getElementById('gm-email-preview');
    var hidden = document.getElementById('requested_district_email');
    var noAccount = document.getElementById('no_district_account');
    var districtBlock = document.getElementById('district-email-block');
    var linkBlock = document.getElementById('personal-link-block');
    var domain = <?= json_encode(GM_DEFAULT_DISTRICT_EMAIL_DOMAIN) ?>;

    function updatePreview() {
        if (!first || !last || !preview || !hidden) { return; }
        if (noAccount && noAccount.checked) { return; }
        var local = [slugPart(first.value), slugPart(last.value)].filter(Boolean).join('.');
        if (!local) {
            preview.textContent = 'Enter first and last name to generate an address';
            hidden.value = '';
            return;
        }
        var email = local + '@' + domain;
        preview.textContent = email;
        hidden.value = email;
    }

    function toggleAccessChoice() {
        var disabled = noAccount && noAccount.checked;
        if (districtBlock) { districtBlock.style.display = disabled ? 'none' : ''; }
        if (linkBlock) { linkBlock.style.display = disabled ? '' : 'none'; }
        if (!disabled) { updatePreview(); }
    }

    if (first) { first.addEventListener('input', updatePreview); }
    if (last) { last.addEventListener('input', updatePreview); }
    if (noAccount) { noAccount.addEventListener('change', toggleAccessChoice); }
    toggleAccessChoice();

    document.querySelectorAll('.gm-copy').forEach(function (button) {
        button.addEventListener('click', function () {
            var value = button.getAttribute('data-copy') || '';
            if (!value) { return; }
            navigator.clipboard.writeText(value).then(function () {
                var original = button.textContent;
                button.textContent = 'Copied';
                window.setTimeout(function () { button.textContent = original; }, 1500);
            });
        });
    });
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>