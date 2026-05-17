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
        JOIN groups g
          ON g.id = gm.group_id
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
        JOIN people p
          ON p.id = gm.person_id
        LEFT JOIN directory_profiles dp
          ON dp.person_id = p.id
        LEFT JOIN user_accounts ua
          ON ua.person_id = p.id
        LEFT JOIN calendar_events ce
          ON ce.group_id = gm.group_id
         AND (
                ce.submitted_by_person_id = p.id
                OR LOWER(ce.leader_email) = LOWER(p.primary_email)
             )
        WHERE gm.group_id = :group_id
          AND gm.status = 'active'
          AND p.status = 'active'
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
            dp.visible_in_directory
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
        $select = implode(', ', array_map('gm_quote_identifier', $columns));

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

    foreach (['m365_account_requests', 'microsoft365_account_requests', 'microsoft_account_requests', 'account_requests', 'requests'] as $table) {
        if (!gm_table_exists($table)) {
            continue;
        }

        if (gm_column_exists($table, 'requested_email')) {
            $checks[] = "SELECT 1 FROM {$table} WHERE LOWER(requested_email) = LOWER(:email) AND status IN ('pending', 'approved', 'processing') LIMIT 1";
        } elseif (gm_column_exists($table, 'email')) {
            $checks[] = "SELECT 1 FROM {$table} WHERE LOWER(email) = LOWER(:email) AND status IN ('pending', 'approved', 'processing') LIMIT 1";
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
    int $actorPersonId,
    int $personId,
    int $groupId,
    string $requestedEmail,
    string $personalEmail,
    string $notes
): bool {
    $details = [
        'person_id' => $personId,
        'group_id' => $groupId,
        'requested_email' => $requestedEmail,
        'personal_email' => $personalEmail,
        'notes' => $notes,
        'request_context' => 'group_manager',
    ];

    $tables = [
        'm365_account_requests',
        'microsoft365_account_requests',
        'microsoft_account_requests',
        'account_requests',
        'requests',
    ];

    foreach ($tables as $table) {
        if (!gm_table_exists($table)) {
            continue;
        }

        try {
            $inserted = gm_insert_flexible($table, [
                'request_type' => 'm365_account',
                'type' => 'm365_account',
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
            ]);

            if ($inserted) {
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
                'body_preview' => mb_substr(strip_tags($body), 0, 800),
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
    $roleOptions = gm_membership_role_options();

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
          AND status = 'active'
    ");
    $stmt->execute([
        'person_id' => $personId,
        'group_id' => $groupId,
    ]);

    if ((int) $stmt->fetchColumn() < 1) {
        throw new RuntimeException('That person is not active in this Group.');
    }

    $stmt = db()->prepare("
        UPDATE group_memberships
        SET membership_role = :membership_role,
            access_level = :access_level
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

    if (gm_table_exists('directory_profiles')) {
        gm_upsert_directory_profile($personId, $roleTitle, 1, 0);
    }

    gm_log_action($actorPersonId, 'group_person_role_changed', 'person', $personId, [
        'group_id' => $groupId,
        'membership_role' => $membershipRole,
        'access_level' => $accessLevel,
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
$selectedGroupId = $requestedGroupId > 0 && gm_group_is_manageable($requestedGroupId, $manageableGroups)
    ? $requestedGroupId
    : (int) $manageableGroups[0]['id'];

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
                    ? 'District email checked and suggested.'
                    : 'District email suggested from local records.';
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

                $pdo->beginTransaction();

                if ($existingPerson) {
                    $personId = (int) $existingPerson['id'];

                    $stmt = $pdo->prepare("
                        UPDATE people
                        SET full_name = CASE
                                WHEN full_name IS NULL OR full_name = '' THEN :full_name
                                ELSE full_name
                            END,
                            phone = COALESCE(NULLIF(phone, ''), :phone),
                            status = 'active'
                        WHERE id = :person_id
                    ");
                    $stmt->execute([
                        'full_name' => $fullName,
                        'phone' => $phone !== '' ? $phone : null,
                        'person_id' => $personId,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO people (
                            full_name,
                            primary_email,
                            phone,
                            status
                        )
                        VALUES (
                            :full_name,
                            :primary_email,
                            :phone,
                            'active'
                        )
                    ");
                    $stmt->execute([
                        'full_name' => $fullName,
                        'primary_email' => $personalEmail,
                        'phone' => $phone !== '' ? $phone : null,
                    ]);

                    $personId = (int) $pdo->lastInsertId();
                }

                gm_upsert_membership($personId, $selectedGroupId, $membershipRole);
                gm_upsert_directory_profile(
                    $personId,
                    gm_role_title_from_membership_role($membershipRole),
                    $visibleInDirectory,
                    $sharePhone
                );

                $requestRecorded = false;

                if (!$noDistrictAccount) {
                    $requestRecorded = gm_create_district_email_request(
                        $actorPersonId,
                        $personId,
                        $selectedGroupId,
                        $requestedDistrictEmail,
                        $personalEmail,
                        $notes
                    );

                    $ssoUrl = gm_absolute_url('/auth/microsoft-start.php');
                    $dashboardUrl = gm_absolute_url('/index.php');
                    $calendarUrl = gm_absolute_url('/dc/');

                    gm_queue_email_and_log(
                        $personId,
                        $personalEmail,
                        $fullName,
                        'Your Irwell Valley District Microsoft 365 account request',
                        "Hello {$firstName},\n\n"
                        . "Your Group Lead Volunteer has requested a District Microsoft 365 account for you.\n\n"
                        . "Requested address: {$requestedDistrictEmail}\n\n"
                        . "Once the account is created, use the Microsoft sign-in button to access the District Dashboard and District Calendar.\n\n"
                        . "Sign in with Microsoft:\n{$ssoUrl}\n\n"
                        . "Dashboard:\n{$dashboardUrl}\n\n"
                        . "District Calendar:\n{$calendarUrl}\n\n"
                        . "Irwell Valley Scout District",
                        'district_account_requested'
                    );
                } else {
                    $createdInviteUrl = gm_create_unique_invite($actorPersonId, $personId, $selectedGroupId);

                    gm_queue_email_and_log(
                        $personId,
                        $personalEmail,
                        $fullName,
                        'Your Irwell Valley District Calendar access',
                        "Hello {$firstName},\n\n"
                        . "Your Group Lead Volunteer has added you to the District Calendar.\n\n"
                        . "Access the calendar here:\n{$createdInviteUrl}\n\n"
                        . "If you later receive a District Microsoft 365 account, please use the Microsoft sign-in button instead.\n\n"
                        . "Irwell Valley Scout District",
                        'group_calendar_invite'
                    );
                }

                gm_log_action($actorPersonId, $existingPerson ? 'group_person_linked' : 'group_person_created', 'person', $personId, [
                    'group_id' => $selectedGroupId,
                    'membership_role' => $membershipRole,
                    'district_account_requested' => !$noDistrictAccount,
                    'requested_district_email' => $requestedDistrictEmail,
                    'request_recorded' => $requestRecorded,
                ]);

                $pdo->commit();

                $success = $existingPerson
                    ? 'Existing person found by email and linked to this Group.'
                    : 'Person added to this Group.';

                if (!$noDistrictAccount) {
                    $success .= ' A Microsoft 365 account request and welcome email have been queued.';
                } else {
                    $success .= ' A calendar access email has been queued.';
                }

                $posted = [];
            }
        } elseif ($action === 'update_role') {
            $tab = 'people';

            $personId = (int) ($_POST['person_id'] ?? 0);
            $membershipRole = (string) ($_POST['membership_role'] ?? '');

            $pdo->beginTransaction();
            gm_update_group_role($personId, $selectedGroupId, $membershipRole, $actorPersonId);
            $pdo->commit();

            $success = 'Role updated.';
        } elseif ($action === 'set_status') {
            $tab = 'people';

            $personId = (int) ($_POST['person_id'] ?? 0);
            $newStatus = (string) ($_POST['new_status'] ?? 'inactive');
            $newStatus = $newStatus === 'active' ? 'active' : 'inactive';

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM group_memberships
                WHERE person_id = :person_id
                  AND group_id = :group_id
            ");
            $stmt->execute([
                'person_id' => $personId,
                'group_id' => $selectedGroupId,
            ]);

            if ((int) $stmt->fetchColumn() < 1) {
                throw new RuntimeException('That person is not linked to this Group.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE group_memberships
                SET status = :status,
                    is_primary = CASE WHEN :primary_status = 'inactive' THEN 0 ELSE is_primary END
                WHERE person_id = :person_id
                  AND group_id = :group_id
            ");
            $stmt->execute([
                'status' => $newStatus,
                'primary_status' => $newStatus,
                'person_id' => $personId,
                'group_id' => $selectedGroupId,
            ]);

            if ($newStatus === 'inactive') {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM group_memberships
                    WHERE person_id = :person_id
                      AND status = 'active'
                ");
                $stmt->execute(['person_id' => $personId]);

                if ((int) $stmt->fetchColumn() === 0) {
                    $stmt = $pdo->prepare("
                        UPDATE people
                        SET status = 'inactive'
                        WHERE id = :person_id
                    ");
                    $stmt->execute(['person_id' => $personId]);
                }
            } else {
                $stmt = $pdo->prepare("
                    UPDATE people
                    SET status = 'active'
                    WHERE id = :person_id
                ");
                $stmt->execute(['person_id' => $personId]);
            }

            gm_log_action($actorPersonId, 'group_person_status_changed', 'person', $personId, [
                'group_id' => $selectedGroupId,
                'status' => $newStatus,
            ]);

            $pdo->commit();

            $success = $newStatus === 'active'
                ? 'Person reactivated for this Group.'
                : 'Person made inactive.';
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
$heroText = 'Manage active leaders for ' . (string) $selectedGroup['group_name'] . ', encourage Microsoft 365 sign-in, and keep the District Directory accurate.';
$breadcrumb = '<a href="/index.php">Home</a> / Group Manager';
?>
<?php include __DIR__ . '/header.php'; ?>

<style>
    .gm-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        margin-bottom: 1rem;
    }

    .gm-tab {
        display: inline-block;
        padding: .75rem 1rem;
        border: 2px solid var(--iv-purple);
        color: var(--iv-purple);
        font-weight: 900;
        text-decoration: none;
        background: #fff;
    }

    .gm-tab:hover {
        color: var(--iv-purple-dark);
        text-decoration: none;
    }

    .gm-tab.active {
        background: var(--iv-purple);
        color: #fff;
    }

    .gm-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 992px) {
        .gm-grid-2 {
            grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
        }
    }

    .gm-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .gm-stat {
        background: #fff;
        border: 2px solid #eee;
        padding: 1rem;
    }

    .gm-stat strong {
        display: block;
        font-size: 2rem;
        line-height: 1;
        color: var(--iv-purple);
    }

    .gm-table-wrap {
        overflow-x: auto;
    }

    .gm-table th {
        white-space: nowrap;
    }

    .gm-badge {
        display: inline-block;
        padding: .2rem .45rem;
        font-weight: 900;
        font-size: .78rem;
        border-radius: .25rem;
    }

    .gm-badge-sso {
        background: #e7f1ff;
        color: #004085;
    }

    .gm-badge-link {
        background: #fff3cd;
        color: #664d03;
    }

    .gm-flow-step {
        border-left: .45rem solid var(--iv-purple);
        padding: 1rem;
        background: #fff;
        margin-bottom: 1rem;
        box-shadow: 0 1px 0 rgba(0, 0, 0, .08);
    }

    .gm-flow-step h3 {
        margin-top: 0;
    }

    .gm-suggested-email {
        font-size: 1.15rem;
        font-weight: 900;
        color: var(--iv-purple);
        word-break: break-word;
    }

    .gm-link-box {
        display: grid;
        gap: .5rem;
    }

    @media (min-width: 768px) {
        .gm-link-box {
            grid-template-columns: minmax(0, 1fr) auto;
        }
    }

    .gm-card-list {
        display: grid;
        gap: 1rem;
    }

    .gm-muted {
        color: #555;
    }

    .gm-coming-soon {
        padding: 2rem;
        background: #f5f3ff;
        border: 2px dashed var(--iv-purple);
    }

    .gm-role-form {
        min-width: 220px;
    }

    .gm-actions {
        display: grid;
        gap: .5rem;
    }

    @media (min-width: 768px) {
        .gm-actions {
            min-width: 150px;
        }
    }
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
                            <option value="<?= (int) $group['id'] ?>" <?= (int) $group['id'] === $selectedGroupId ? 'selected' : '' ?>>
                                <?= e($group['group_name']) ?>
                            </option>
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
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($createdInviteUrl): ?>
        <div class="alert alert-info">
            <strong>Access link:</strong><br>
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
            <div class="gm-stat">
                <strong><?= count($leaders) ?></strong>
                <span>active people</span>
            </div>
            <div class="gm-stat">
                <strong><?= $leadersWithSso ?></strong>
                <span>using Microsoft SSO</span>
            </div>
            <div class="gm-stat">
                <strong><?= (int) $totalEvents ?></strong>
                <span>linked calendar events</span>
            </div>
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
                                    <td>
                                        <strong><?= e($leader['full_name']) ?></strong><br>
                                        <span class="gm-muted">
                                            Directory: <?= (int) $leader['visible_in_directory'] === 1 ? 'visible' : 'hidden' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= e($leader['primary_email']) ?><br>
                                        <?= e($leader['phone'] ?: '') ?>
                                    </td>
                                    <td>
                                        <form method="post" class="gm-role-form">
                                            <input type="hidden" name="action" value="update_role">
                                            <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">
                                            <input type="hidden" name="tab" value="people">
                                            <input type="hidden" name="person_id" value="<?= (int) $leader['person_id'] ?>">

                                            <div class="form-group mb-2">
                                                <label class="sr-only" for="membership_role_<?= (int) $leader['person_id'] ?>">Role</label>
                                                <select class="form-control" id="membership_role_<?= (int) $leader['person_id'] ?>" name="membership_role">
                                                    <?php foreach ($roleOptions as $value => $label): ?>
                                                        <option value="<?= e($value) ?>" <?= (string) $leader['membership_role'] === $value ? 'selected' : '' ?>>
                                                            <?= e($label) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <button class="btn btn-secondary btn-sm" type="submit">Update role</button>
                                        </form>
                                    </td>
                                    <td>
                                        <?php if ((int) $leader['has_microsoft_account'] > 0): ?>
                                            <span class="gm-badge gm-badge-sso">Microsoft SSO</span>
                                        <?php else: ?>
                                            <span class="gm-badge gm-badge-link">No SSO yet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= (int) $leader['total_events'] ?> total<br>
                                        <?= (int) $leader['in_review_events'] ?> in review<br>
                                        <?= (int) $leader['approved_events'] ?> approved
                                    </td>
                                    <td>
                                        <?= $leader['latest_event_at'] ? e(date('d M Y', strtotime((string) $leader['latest_event_at']))) : '—' ?>
                                    </td>
                                    <td>
                                        <div class="gm-actions">
                                            <form method="post" onsubmit="return confirm('Make this person inactive for this Group?');">
                                                <input type="hidden" name="action" value="set_status">
                                                <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">
                                                <input type="hidden" name="tab" value="people">
                                                <input type="hidden" name="person_id" value="<?= (int) $leader['person_id'] ?>">
                                                <input type="hidden" name="new_status" value="inactive">
                                                <button class="btn btn-outline-danger btn-sm" type="submit">Make inactive</button>
                                            </form>
                                        </div>
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
    <?php elseif ($tab === 'add'): ?>
        <div class="gm-grid gm-grid-2">
            <section class="lt-panel">
                <h2 class="lt-section-title">Add a person to <?= e($selectedGroup['group_name']) ?></h2>
                <p class="lt-lede">This flow creates or links the person, adds them to your Group, and starts their access route for the District Calendar.</p>

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
                            <small class="form-text text-muted">This is used for login instructions and contact details.</small>
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
                                        <option value="<?= e($value) ?>" <?= $selectedRole === $value ? 'selected' : '' ?>>
                                            <?= e($label) ?>
                                        </option>
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
                        <h3 class="h5 font-weight-bold">Step 3 — Microsoft 365 access</h3>
                        <p>Preferred route: request a District Microsoft 365 account so they can sign in with the Microsoft button.</p>

                        <label class="lt-check mb-3">
                            <input type="checkbox" name="no_district_account" id="no_district_account" value="1" <?= isset($posted['no_district_account']) ? 'checked' : '' ?>>
                            <span>I do not wish for them to have a District Microsoft 365 account</span>
                        </label>

                        <div id="district-email-block">
                            <p class="mb-1">Suggested District email:</p>
                            <p class="gm-suggested-email" id="gm-email-preview">
                                <?= e($districtEmailSuggestion ?: 'Enter first and last name to generate an address') ?>
                            </p>

                            <input type="hidden" id="requested_district_email" name="requested_district_email" value="<?= e($districtEmailSuggestion ?: ($posted['requested_district_email'] ?? '')) ?>">

                            <button class="btn btn-secondary lt-btn" type="submit" onclick="document.getElementById('gm-action').value='suggest_email';">
                                Check availability
                            </button>

                            <p class="gm-muted mt-2 mb-0">
                                After the request is processed, they will receive instructions by email.
                            </p>
                        </div>

                        <div id="personal-link-block" class="alert alert-info mt-3" style="display:none;">
                            A District Microsoft 365 account will not be requested. They will be sent calendar access instructions by email.
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
                            <label for="notes">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"><?= e($posted['notes'] ?? '') ?></textarea>
                        </div>

                        <button class="btn btn-primary lt-btn" type="submit" onclick="document.getElementById('gm-action').value='add_person';">
                            Add person and start access setup
                        </button>
                    </div>
                </form>
            </section>

            <aside class="lt-panel-grey">
                <h2 class="lt-section-title">What happens next?</h2>
                <ol class="pl-3 font-weight-bold">
                    <li>The person is added to this Group.</li>
                    <li>If Microsoft 365 is requested, an account request is created.</li>
                    <li>An email is queued with their next steps.</li>
                    <li>They should use Microsoft sign-in wherever possible.</li>
                </ol>
                <p class="mb-0">The Group calendar link remains available as a fallback under Calendar access.</p>
            </aside>
        </div>
    <?php elseif ($tab === 'links'): ?>
        <div class="lt-panel mb-4">
            <h2 class="lt-section-title">Group calendar access</h2>
            <p class="lt-lede">Use Microsoft sign-in wherever possible. The Group calendar link is a fallback for leaders who do not yet use District Microsoft 365.</p>

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

                                <p class="gm-muted mt-2 mb-0">
                                    Share only with leaders in <?= e($selectedGroup['group_name']) ?>.
                                </p>
                            <?php else: ?>
                                <div class="alert alert-warning mb-0">This active link is not currently shareable.</div>
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
        if (!first || !last || !preview || !hidden) {
            return;
        }

        if (noAccount && noAccount.checked) {
            return;
        }

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

        if (districtBlock) {
            districtBlock.style.display = disabled ? 'none' : '';
        }

        if (linkBlock) {
            linkBlock.style.display = disabled ? '' : 'none';
        }

        if (!disabled) {
            updatePreview();
        }
    }

    if (first) {
        first.addEventListener('input', updatePreview);
    }

    if (last) {
        last.addEventListener('input', updatePreview);
    }

    if (noAccount) {
        noAccount.addEventListener('change', toggleAccessChoice);
    }

    toggleAccessChoice();

    document.querySelectorAll('.gm-copy').forEach(function (button) {
        button.addEventListener('click', function () {
            var value = button.getAttribute('data-copy') || '';

            if (!value) {
                return;
            }

            navigator.clipboard.writeText(value).then(function () {
                var original = button.textContent;
                button.textContent = 'Copied';

                window.setTimeout(function () {
                    button.textContent = original;
                }, 1500);
            });
        });
    });
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>